<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_launchpad;

/**
 * Admin portal dashboard (site admins / moodle/site:config).
 *
 * Overview reads live Moodle state for LaunchPad `lp-*` track courses.
 * Does not invent curriculum readiness — only teachers, activities, visibility,
 * enrolments, and pending assign reviews.
 *
 * @package    local_launchpad
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class admin_dashboard {

    /** Marker for automated checks. */
    public const DATA_REGION = 'local-launchpad-admin';

    /** Orientation courses share the lp- prefix but are not track courses. */
    public const ORIENT_IDNUMBER_PREFIX = 'lp-orient-';

    /**
     * Whether the user may open the Admin shell.
     */
    public static function can_access(int $userid): bool {
        if ($userid <= 0) {
            return false;
        }
        return account_persona::is_admin($userid);
    }

    /**
     * Whether an idnumber is a LaunchPad track course (excludes orientation).
     */
    public static function is_track_idnumber(string $idnumber): bool {
        $idnumber = strtolower(trim($idnumber));
        if ($idnumber === '' || !str_starts_with($idnumber, account_persona::COURSE_IDNUMBER_PREFIX)) {
            return false;
        }
        return !str_starts_with($idnumber, self::ORIENT_IDNUMBER_PREFIX);
    }

    /**
     * Overview page context from live Moodle data.
     *
     * @param \stdClass $user
     * @return array<string, mixed>
     */
    public static function overview_context(\stdClass $user): array {
        $firstname = format_string($user->firstname ?? '');
        $tracks = self::list_track_courses();
        $kpis = self::kpi_counts($tracks);
        $visibility = self::visibility_context($tracks);
        $attention = self::needs_attention($tracks);

        $ready = (int) $kpis['tracks_ready'];
        $total = (int) $kpis['tracks_total'];
        $pendingsetup = max(0, $total - $ready);
        $trackspct = $total > 0 ? (int) round(($ready / $total) * 100) : 0;
        $reviewspending = (int) $kpis['reviews_pending'];
        $fellows = (int) $kpis['fellows'];
        $teachers = (int) $kpis['teachers'];

        $kpiicon = static function (string $paths): string {
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">'
                . $paths . '</svg>';
        };

        return [
            'firstname' => $firstname,
            'welcome_back' => get_string('admin_welcome_back', 'local_launchpad', $firstname),
            'eyebrow' => get_string('admin_overview_eyebrow', 'local_launchpad'),
            'heading' => get_string('admin_overview_heading', 'local_launchpad'),
            'lead' => get_string('admin_overview_lead', 'local_launchpad'),
            'moodle_admin_url' => (new \moodle_url('/admin/search.php'))->out(false),
            'moodle_admin_label' => get_string('admin_open_moodle_admin', 'local_launchpad'),
            'tracks_url' => (new \moodle_url(persona_home::ADMIN_PATH, ['view' => 'tracks']))->out(false),
            'tracks_cta_label' => get_string('admin_overview_tracks_cta', 'local_launchpad'),
            'reviews_url' => (new \moodle_url(persona_home::ADMIN_PATH, ['view' => 'reviews']))->out(false),
            'kpi_tracks_ready' => $ready,
            'kpi_tracks_total' => $total,
            'kpi_tracks_pct' => $trackspct,
            'kpi_tracks_pending' => $pendingsetup,
            'kpi_fellows' => $fellows,
            'kpi_teachers' => $teachers,
            'kpi_reviews_pending' => $reviewspending,
            'kpi_tracks_label' => get_string('admin_kpi_tracks_ready', 'local_launchpad'),
            'kpi_fellows_label' => get_string('admin_kpi_fellows', 'local_launchpad'),
            'kpi_teachers_label' => get_string('admin_kpi_teachers', 'local_launchpad'),
            'kpi_reviews_label' => get_string('admin_kpi_reviews', 'local_launchpad'),
            'kpi_tracks_meta' => get_string(
                'admin_kpi_tracks_meta',
                'local_launchpad',
                (object) ['ready' => $ready, 'total' => $total]
            ),
            'kpi_tracks_sub' => $pendingsetup > 0
                ? get_string('admin_kpi_tracks_pending_sub', 'local_launchpad', $pendingsetup)
                : get_string('admin_kpi_tracks_ready_sub', 'local_launchpad'),
            'kpi_fellows_sub' => get_string('admin_kpi_fellows_sub', 'local_launchpad'),
            'kpi_teachers_sub' => get_string('admin_kpi_teachers_sub', 'local_launchpad'),
            'kpi_reviews_sub' => $reviewspending > 0
                ? get_string('admin_kpi_reviews_sub', 'local_launchpad', $reviewspending)
                : get_string('admin_kpi_reviews_sub_clear', 'local_launchpad'),
            'kpi_reviews_needs_action' => $reviewspending > 0,
            'kpi_reviews_badge' => get_string('admin_kpi_reviews_badge', 'local_launchpad'),
            'kpi_icon_tracks' => $kpiicon(
                '<polygon points="12 2 2 7 12 12 22 7 12 2"/>'
                . '<polyline points="2 17 12 22 22 17"/>'
                . '<polyline points="2 12 12 17 22 12"/>'
            ),
            'kpi_icon_fellows' => $kpiicon(
                '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>'
                . '<circle cx="9" cy="7" r="4"/>'
                . '<path d="M22 21v-2a4 4 0 0 0-3-3.87"/>'
                . '<path d="M16 3.13a4 4 0 0 1 0 7.75"/>'
            ),
            'kpi_icon_teachers' => $kpiicon(
                '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>'
                . '<circle cx="12" cy="7" r="4"/>'
            ),
            'kpi_icon_reviews' => $kpiicon(
                '<path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/>'
                . '<path d="M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>'
                . '<path d="M9 14l2 2 4-4"/>'
            ),
        ] + $visibility + $attention;
    }

    /** Substring that marks unfinished structure-CLI placeholder activities. */
    public const PLACEHOLDER_MARKER = '[placeholder';

    /**
     * Shared empty-state fields for {{> local_launchpad/admin_empty}}.
     *
     * @return array<string, mixed>
     */
    public static function empty_state(
        string $bodykey,
        ?string $titlekey = null,
        ?string $ctaurl = null,
        ?string $ctalabelkey = null,
        string $mark = 'AD'
    ): array {
        $hascta = $ctaurl !== null && $ctaurl !== '' && $ctalabelkey !== null;
        return [
            'empty_mark' => $mark,
            'empty_title' => $titlekey !== null
                ? get_string($titlekey, 'local_launchpad')
                : '',
            'empty_body' => get_string($bodykey, 'local_launchpad'),
            'has_empty_cta' => $hascta,
            'empty_cta_url' => $hascta ? $ctaurl : '',
            'empty_cta_label' => $hascta
                ? get_string($ctalabelkey, 'local_launchpad')
                : '',
        ];
    }

    /**
     * Flash / empty error text — capability failures stay plain; no raw stack dumps.
     */
    public static function flash_error_message(\Throwable $e): string {
        if ($e instanceof \required_capability_exception) {
            return get_string('admin_err_capability', 'local_launchpad');
        }
        $msg = trim($e->getMessage());
        return $msg !== '' ? $msg : get_string('admin_err_generic', 'local_launchpad');
    }

    /**
     * Tracks & courses page (HTML a-tracks) — card grid from live Moodle.
     *
     * @param \stdClass $user
     * @return array<string, mixed>
     */
    public static function tracks_context(\stdClass $user): array {
        $firstname = format_string($user->firstname ?? '');
        $tracks = self::list_track_courses();
        $cards = [];
        foreach ($tracks as $course) {
            $cards[] = self::track_card_context($course);
        }

        $root = self::fellowship_root_category();
        $catvisible = $root && (int) $root->visible === 1;
        $builderurl = (new \moodle_url(persona_home::ADMIN_PATH, ['view' => 'builder']))->out(false);

        return array_merge([
            'firstname' => $firstname,
            'eyebrow' => get_string('admin_tracks_eyebrow', 'local_launchpad'),
            'heading' => get_string('admin_tracks_heading', 'local_launchpad'),
            'lead' => get_string('admin_tracks_lead', 'local_launchpad'),
            'category_chip' => $catvisible
                ? get_string('admin_tracks_category_visible', 'local_launchpad')
                : get_string('admin_tracks_category_hidden', 'local_launchpad'),
            'category_chip_ok' => $catvisible,
            'has_tracks' => $cards !== [],
            'tracks' => $cards,
        ], self::empty_state(
            'admin_tracks_empty',
            'admin_empty_title_no_tracks',
            $builderurl,
            'admin_empty_cta_builder'
        ));
    }

    /**
     * Resolve a LaunchPad track course by id (0 / invalid → first track or null).
     */
    public static function resolve_track_course(int $trackid): ?\stdClass {
        $tracks = self::list_track_courses();
        if ($tracks === []) {
            return null;
        }
        if ($trackid > 0) {
            foreach ($tracks as $course) {
                if ((int) $course->id === $trackid) {
                    return $course;
                }
            }
        }
        return $tracks[0];
    }

    /**
     * M5 Course ops hub — track-scoped in-shell home (not a Moodle link farm).
     *
     * @param \stdClass $user
     * @param int $trackid
     * @param string $section hub|settings|participants|grades|completion|reports|qbank
     * @param array{ok?:string,error?:string} $flash
     * @return array<string, mixed>
     */
    public static function courseops_context(
        \stdClass $user,
        int $trackid = 0,
        string $section = 'hub',
        array $flash = [],
        int $reportuserid = 0
    ): array {
        global $CFG, $DB;

        require_once($CFG->libdir . '/completionlib.php');

        $allowed = ['hub', 'settings', 'participants', 'grades', 'completion', 'reports', 'qbank'];
        if (!in_array($section, $allowed, true)) {
            $section = 'hub';
        }

        $tracks = self::list_track_courses();
        if ($tracks === []) {
            $builderurl = (new \moodle_url(persona_home::ADMIN_PATH, ['view' => 'builder']))->out(false);
            return array_merge([
                'eyebrow' => get_string('admin_courseops_eyebrow', 'local_launchpad'),
                'heading' => get_string('admin_courseops_heading', 'local_launchpad'),
                'lead' => get_string('admin_courseops_lead', 'local_launchpad'),
                'has_tracks' => false,
                'is_hub' => true,
                'is_settings' => false,
                'is_participants' => false,
                'is_grades' => false,
                'is_completion' => false,
                'is_reports' => false,
                'is_qbank' => false,
                'is_soon' => false,
                'flash_ok' => !empty($flash['ok']),
                'flash_ok_body' => (string) ($flash['ok'] ?? ''),
                'flash_err' => !empty($flash['error']),
                'flash_err_body' => (string) ($flash['error'] ?? ''),
            ], self::empty_state(
                'admin_tracks_empty',
                'admin_empty_title_no_tracks',
                $builderurl,
                'admin_empty_cta_builder'
            ));
        }

        $selected = self::resolve_track_course($trackid);
        $trackid = (int) $selected->id;
        // Full row for settings (list query is slim).
        $fullcourse = $DB->get_record('course', ['id' => $trackid], '*', MUST_EXIST);
        $visible = (int) ($fullcourse->visible ?? 0) === 1;
        $teachers = self::teacher_names($trackid);
        $fellows = self::fellow_count($trackid);
        $foundationsready = self::foundations_ready($trackid);

        $opsurl = static function (string $sec) use ($trackid): string {
            return (new \moodle_url(persona_home::ADMIN_PATH, [
                'view' => 'courseops',
                'trackid' => $trackid,
                'section' => $sec,
            ]))->out(false);
        };

        $icon = static function (string $paths): string {
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">'
                . $paths . '</svg>';
        };

        $primary = [
            [
                'key' => 'settings',
                'label' => get_string('admin_courseops_nav_settings', 'local_launchpad'),
                'blurb' => get_string('admin_courseops_nav_settings_blurb', 'local_launchpad'),
                'url' => $opsurl('settings'),
                'current' => $section === 'settings',
                'icon_svg' => $icon('<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M2 12h3M19 12h3M4.9 19.1L7 17M17 7l2.1-2.1"/>'),
            ],
            [
                'key' => 'participants',
                'label' => get_string('admin_courseops_nav_participants', 'local_launchpad'),
                'blurb' => get_string('admin_courseops_nav_participants_blurb', 'local_launchpad'),
                'url' => $opsurl('participants'),
                'current' => $section === 'participants',
                'icon_svg' => $icon(
                    '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>'
                    . '<circle cx="9" cy="7" r="4"/>'
                    . '<path d="M23 21v-2a4 4 0 0 0-3-3.87"/>'
                    . '<path d="M16 3.13a4 4 0 0 1 0 7.75"/>'
                ),
            ],
            [
                'key' => 'grades',
                'label' => get_string('admin_courseops_nav_grades', 'local_launchpad'),
                'blurb' => get_string('admin_courseops_nav_grades_blurb', 'local_launchpad'),
                'url' => $opsurl('grades'),
                'current' => $section === 'grades',
                'icon_svg' => $icon('<path d="M4 19V5M4 19h16"/><path d="M8 15l3-4 3 2 4-6"/>'),
            ],
            [
                'key' => 'completion',
                'label' => get_string('admin_courseops_nav_completion', 'local_launchpad'),
                'blurb' => get_string('admin_courseops_nav_completion_blurb', 'local_launchpad'),
                'url' => $opsurl('completion'),
                'current' => $section === 'completion',
                'icon_svg' => $icon('<path d="M4 12l5 5L20 6"/>'),
            ],
            [
                'key' => 'reports',
                'label' => get_string('admin_courseops_nav_reports', 'local_launchpad'),
                'blurb' => get_string('admin_courseops_nav_reports_blurb', 'local_launchpad'),
                'url' => $opsurl('reports'),
                'current' => $section === 'reports',
                'icon_svg' => $icon('<path d="M4 19V5h4v14H4zM10 19V9h4v10h-4zM16 19v-6h4v6h-4z"/>'),
            ],
            [
                'key' => 'qbank',
                'label' => get_string('admin_courseops_nav_qbank', 'local_launchpad'),
                'blurb' => get_string('admin_courseops_nav_qbank_blurb', 'local_launchpad'),
                'url' => $opsurl('qbank'),
                'current' => $section === 'qbank',
                'icon_svg' => $icon('<path d="M4 5h16v14H4z"/><path d="M8 9h8M8 13h5"/><circle cx="17" cy="16" r="1.5"/>'),
            ],
        ];

        $tracktabs = [];
        foreach ($tracks as $course) {
            $cid = (int) $course->id;
            $tracktabs[] = [
                'id' => $cid,
                'label' => self::builder_track_tab_label($course),
                'title' => format_string($course->fullname),
                'url' => (new \moodle_url(persona_home::ADMIN_PATH, [
                    'view' => 'courseops',
                    'trackid' => $cid,
                    'section' => $section === 'hub' ? 'hub' : $section,
                ]))->out(false),
                'current' => $cid === $trackid,
            ];
        }

        $ishub = $section === 'hub';
        $issettings = $section === 'settings';
        $isparticipants = $section === 'participants';
        $isgrades = $section === 'grades';
        $iscompletion = $section === 'completion';
        $isreports = $section === 'reports';
        $isqbank = $section === 'qbank';

        $sitecompletion = !empty($CFG->enablecompletion);
        $coursecompletion = $sitecompletion
            && (int) ($fullcourse->enablecompletion ?? 0) === COMPLETION_ENABLED;
        $startdate = (int) ($fullcourse->startdate ?? 0);
        $enddate = (int) ($fullcourse->enddate ?? 0);
        $summaryplain = trim(html_to_text((string) ($fullcourse->summary ?? ''), 0, false));

        $ctx = [
            'eyebrow' => get_string('admin_courseops_eyebrow', 'local_launchpad'),
            'heading' => get_string('admin_courseops_heading', 'local_launchpad'),
            'lead' => get_string('admin_courseops_lead', 'local_launchpad'),
            'has_tracks' => true,
            'track_name' => format_string($fullcourse->fullname),
            'track_idnumber' => s($fullcourse->idnumber ?? ''),
            'trackid' => $trackid,
            'track_tabs' => $tracktabs,
            'is_visible' => $visible,
            'visibility_chip' => $visible
                ? get_string('admin_courseops_visible', 'local_launchpad')
                : get_string('admin_courseops_hidden', 'local_launchpad'),
            'ready_chip' => $foundationsready
                ? get_string('admin_tracks_foundations_ready', 'local_launchpad')
                : get_string('admin_tracks_outline_only', 'local_launchpad'),
            'ready_ok' => $foundationsready,
            'teacher_kv_label' => get_string('admin_builder_kv_teacher', 'local_launchpad'),
            'teacher_summary' => $teachers !== []
                ? implode(', ', $teachers)
                : get_string('admin_tracks_no_teacher', 'local_launchpad'),
            'has_teacher' => $teachers !== [],
            'fellows_kv_label' => get_string('admin_builder_kv_enrolled', 'local_launchpad'),
            'fellows_summary' => get_string('admin_tracks_fellows', 'local_launchpad', $fellows),
            'hub_url' => $opsurl('hub'),
            'back_tracks_url' => (new \moodle_url(persona_home::ADMIN_PATH, ['view' => 'tracks']))->out(false),
            'back_tracks_label' => get_string('admin_courseops_back_tracks', 'local_launchpad'),
            'preview_url' => (new \moodle_url('/course/view.php', ['id' => $trackid]))->out(false),
            'preview_label' => get_string('admin_tracks_open_course', 'local_launchpad'),
            'status_title' => get_string('admin_courseops_status_title', 'local_launchpad'),
            'ops_title' => get_string('admin_courseops_ops_title', 'local_launchpad'),
            'ops_lead' => get_string('admin_courseops_ops_lead', 'local_launchpad'),
            'primary_nav' => $primary,
            'is_hub' => $ishub,
            'is_settings' => $issettings,
            'is_participants' => $isparticipants,
            'is_grades' => $isgrades,
            'is_completion' => $iscompletion,
            'is_reports' => $isreports,
            'is_qbank' => $isqbank,
            'is_soon' => false,
            'section' => $section,
            'section_title' => '',
            'section_body' => '',
            'soon_badge' => get_string('admin_courseops_soon_badge', 'local_launchpad'),
            'back_hub_label' => get_string('admin_courseops_back_hub', 'local_launchpad'),
            'flash_ok' => !empty($flash['ok']),
            'flash_ok_body' => (string) ($flash['ok'] ?? ''),
            'flash_err' => !empty($flash['error']),
            'flash_err_body' => (string) ($flash['error'] ?? ''),
            // Settings form (Slice B).
            'settings_title' => get_string('admin_courseops_nav_settings', 'local_launchpad'),
            'settings_lead' => get_string('admin_courseops_settings_lead', 'local_launchpad'),
            'form_action' => $opsurl('settings'),
            'sesskey' => sesskey(),
            'fullname_label' => get_string('admin_courseops_field_fullname', 'local_launchpad'),
            'fullname_value' => (string) $fullcourse->fullname,
            'shortname_label' => get_string('admin_courseops_field_shortname', 'local_launchpad'),
            'shortname_value' => (string) $fullcourse->shortname,
            'idnumber_label' => get_string('admin_courseops_field_idnumber', 'local_launchpad'),
            'idnumber_help' => get_string('admin_courseops_field_idnumber_help', 'local_launchpad'),
            'visible_label' => get_string('admin_courseops_field_visible', 'local_launchpad'),
            'visible_help' => get_string('admin_courseops_field_visible_help', 'local_launchpad'),
            'visible_checked' => $visible,
            'startdate_label' => get_string('admin_courseops_field_startdate', 'local_launchpad'),
            'startdate_value' => self::format_course_date_value($startdate),
            'enddate_label' => get_string('admin_courseops_field_enddate', 'local_launchpad'),
            'enddate_help' => get_string('admin_courseops_field_enddate_help', 'local_launchpad'),
            'enddate_value' => self::format_course_date_value($enddate),
            'completion_site_on' => $sitecompletion,
            'completion_label' => get_string('admin_courseops_field_completion', 'local_launchpad'),
            'completion_help' => get_string('admin_courseops_field_completion_help', 'local_launchpad'),
            'completion_checked' => $coursecompletion,
            'completion_site_off' => get_string('admin_courseops_completion_site_off', 'local_launchpad'),
            'summary_label' => get_string('admin_courseops_field_summary', 'local_launchpad'),
            'summary_value' => $summaryplain,
            'save_label' => get_string('admin_courseops_settings_save', 'local_launchpad'),
            'advanced_url' => (new \moodle_url('/course/edit.php', ['id' => $trackid]))->out(false),
            'advanced_label' => get_string('admin_courseops_settings_advanced', 'local_launchpad'),
            'advanced_help' => get_string('admin_courseops_settings_advanced_help', 'local_launchpad'),
        ];

        // Keep the user's draft after a failed save (validate → error → reload).
        $posted = $flash['posted'] ?? null;
        if (is_array($posted)) {
            if (array_key_exists('fullname', $posted)) {
                $ctx['fullname_value'] = (string) $posted['fullname'];
            }
            if (array_key_exists('shortname', $posted)) {
                $ctx['shortname_value'] = (string) $posted['shortname'];
            }
            if (array_key_exists('visible', $posted)) {
                $ctx['visible_checked'] = !empty($posted['visible']);
            }
            if (array_key_exists('startdate', $posted)) {
                $ctx['startdate_value'] = (string) $posted['startdate'];
            }
            if (array_key_exists('enddate', $posted)) {
                $ctx['enddate_value'] = (string) $posted['enddate'];
            }
            if (array_key_exists('enablecompletion', $posted)) {
                $ctx['completion_checked'] = !empty($posted['enablecompletion']);
            }
            if (array_key_exists('summary', $posted)) {
                $ctx['summary_value'] = (string) $posted['summary'];
            }
        }

        if ($isparticipants) {
            $ctx = array_merge($ctx, self::courseops_participants_context($user, $trackid, $fullcourse));
        }
        if ($isgrades) {
            $ctx = array_merge(
                $ctx,
                self::courseops_grades_context($user, $trackid, $fullcourse, $reportuserid)
            );
        }
        if ($iscompletion) {
            $ctx = array_merge($ctx, self::courseops_completion_context($user, $trackid, $fullcourse));
        }
        if ($isreports) {
            $ctx = array_merge($ctx, self::courseops_reports_context($user, $trackid, $fullcourse));
        }
        if ($isqbank) {
            $ctx = array_merge($ctx, self::courseops_qbank_context($user, $trackid, $fullcourse));
        }

        return $ctx;
    }

    /**
     * Participants section: track roster + reuse links to Cohorts / Mentors (Slice C).
     *
     * @return array<string, mixed>
     */
    public static function courseops_participants_context(
        \stdClass $user,
        int $trackid,
        \stdClass $course
    ): array {
        $canunenrol = account_provision::actor_may_unenrol($user, $trackid);
        $fellows = self::list_track_fellows($trackid);
        $mentors = self::list_track_roster_mentors($trackid);
        $formaction = (new \moodle_url(persona_home::ADMIN_PATH, [
            'view' => 'courseops',
            'trackid' => $trackid,
            'section' => 'participants',
        ]))->out(false);
        $sk = sesskey();
        $unenrollabel = get_string('admin_courseops_unenrol', 'local_launchpad');
        $removementorlabel = get_string('admin_courseops_remove_mentor', 'local_launchpad');

        $fellowrows = [];
        foreach ($fellows as $row) {
            $fellowrows[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'email' => $row['email'],
                'initials' => $row['initials'],
                'role_label' => get_string('admin_courseops_role_fellow', 'local_launchpad'),
                'can_remove' => $canunenrol,
                'remove_confirm' => get_string(
                    'admin_courseops_unenrol_confirm',
                    'local_launchpad',
                    $row['name']
                ),
                'trackid' => $trackid,
                'sesskey' => $sk,
                'participants_form_action' => $formaction,
                'unenrol_label' => $unenrollabel,
            ];
        }

        $mentorrows = [];
        foreach ($mentors as $row) {
            $mentorrows[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'email' => $row['email'],
                'initials' => $row['initials'],
                'role_label' => $row['role_label'],
                'can_remove' => $canunenrol,
                'remove_confirm' => get_string(
                    'admin_courseops_remove_mentor_confirm',
                    'local_launchpad',
                    $row['name']
                ),
                'trackid' => $trackid,
                'sesskey' => $sk,
                'participants_form_action' => $formaction,
                'remove_mentor_label' => $removementorlabel,
            ];
        }

        $cohortsurl = (new \moodle_url(persona_home::ADMIN_PATH, [
            'view' => 'cohorts',
            'trackid' => $trackid,
        ]))->out(false);
        $mentorsurl = (new \moodle_url(persona_home::ADMIN_PATH, [
            'view' => 'mentors',
            'trackid' => $trackid,
        ]))->out(false) . '#lp-mentor-track-' . $trackid;

        return [
            'participants_title' => get_string('admin_courseops_nav_participants', 'local_launchpad'),
            'participants_lead' => get_string('admin_courseops_participants_lead', 'local_launchpad'),
            'participants_form_action' => $formaction,
            'enrol_fellow_url' => $cohortsurl,
            'enrol_fellow_label' => get_string('admin_courseops_enrol_fellow', 'local_launchpad'),
            'enrol_fellow_help' => get_string('admin_courseops_enrol_fellow_help', 'local_launchpad'),
            'assign_mentor_url' => $mentorsurl,
            'assign_mentor_label' => get_string('admin_courseops_assign_mentor', 'local_launchpad'),
            'assign_mentor_help' => get_string('admin_courseops_assign_mentor_help', 'local_launchpad'),
            'fellows_section_title' => get_string('admin_courseops_fellows_title', 'local_launchpad', count($fellowrows)),
            'mentors_section_title' => get_string('admin_courseops_mentors_title', 'local_launchpad', count($mentorrows)),
            'col_name' => get_string('admin_people_col_name', 'local_launchpad'),
            'col_email' => get_string('admin_people_col_email', 'local_launchpad'),
            'col_role' => get_string('admin_people_col_role', 'local_launchpad'),
            'col_actions' => get_string('admin_courseops_col_actions', 'local_launchpad'),
            'unenrol_label' => $unenrollabel,
            'remove_mentor_label' => $removementorlabel,
            'fellow_rows' => $fellowrows,
            'has_fellows' => $fellowrows !== [],
            'fellows_empty' => get_string('admin_courseops_fellows_empty', 'local_launchpad'),
            'mentor_rows' => $mentorrows,
            'has_mentors' => $mentorrows !== [],
            'mentors_empty' => get_string('admin_courseops_mentors_empty', 'local_launchpad'),
            'can_unenrol' => $canunenrol,
            'unenrol_no_cap' => get_string('admin_courseops_err_unenrol_cap', 'local_launchpad'),
            'track_fullname_plain' => format_string($course->fullname),
        ];
    }

    /**
     * Grades section: course-total roster + in-shell fellow user report (LP boundary).
     * Reviews owns pending assign grading — not a second review queue.
     * One Advanced escape: Moodle gradebook setup (weights/scales/categories).
     *
     * @return array<string, mixed>
     */
    public static function courseops_grades_context(
        \stdClass $user,
        int $trackid,
        \stdClass $course,
        int $reportuserid = 0
    ): array {
        global $CFG;

        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->libdir . '/grade/grade_item.php');
        require_once($CFG->libdir . '/grade/grade_grade.php');

        $context = \context_course::instance($trackid);
        $canview = has_capability('moodle/grade:viewall', $context, $user);
        $reviewsurl = (new \moodle_url(persona_home::ADMIN_PATH, ['view' => 'reviews']))->out(false);
        $advancedurl = (new \moodle_url('/grade/edit/tree/index.php', ['id' => $trackid]))->out(false);
        $rosterurl = (new \moodle_url(persona_home::ADMIN_PATH, [
            'view' => 'courseops',
            'trackid' => $trackid,
            'section' => 'grades',
        ]))->out(false);

        $base = [
            'grades_title' => get_string('admin_courseops_nav_grades', 'local_launchpad'),
            'grades_lead' => get_string('admin_courseops_grades_lead', 'local_launchpad'),
            'grades_can_view' => $canview,
            'grades_no_cap' => get_string('admin_courseops_err_grades_cap', 'local_launchpad'),
            'grades_reviews_url' => $reviewsurl,
            'grades_reviews_label' => get_string('admin_courseops_grades_reviews', 'local_launchpad'),
            'grades_reviews_help' => get_string('admin_courseops_grades_reviews_help', 'local_launchpad'),
            'grades_advanced_url' => $advancedurl,
            'grades_advanced_label' => get_string('admin_courseops_grades_advanced', 'local_launchpad'),
            'grades_advanced_help' => get_string('admin_courseops_grades_advanced_help', 'local_launchpad'),
            'grades_fellows_title' => get_string('admin_courseops_grades_fellows_title', 'local_launchpad'),
            'grades_items_title' => get_string('admin_courseops_grades_items_title', 'local_launchpad'),
            'col_name' => get_string('admin_people_col_name', 'local_launchpad'),
            'col_grade' => get_string('admin_courseops_col_grade', 'local_launchpad'),
            'col_item_grade' => get_string('admin_courseops_col_item_grade', 'local_launchpad'),
            'col_percent' => get_string('admin_courseops_col_percent', 'local_launchpad'),
            'col_actions' => get_string('admin_courseops_col_actions', 'local_launchpad'),
            'col_activity' => get_string('admin_courseops_col_activity', 'local_launchpad'),
            'col_type' => get_string('admin_courseops_col_type', 'local_launchpad'),
            'col_max' => get_string('admin_courseops_col_max', 'local_launchpad'),
            'col_feedback' => get_string('admin_courseops_col_feedback', 'local_launchpad'),
            'grades_empty_fellows' => get_string('admin_courseops_grades_empty_fellows', 'local_launchpad'),
            'grades_empty_items' => get_string('admin_courseops_grades_empty_items', 'local_launchpad'),
            'grades_open_user_label' => get_string('admin_courseops_grades_open_user', 'local_launchpad'),
            'grades_hidden_course' => get_string('admin_courseops_grades_hidden_course', 'local_launchpad'),
            'grades_back_roster_label' => get_string('admin_courseops_grades_back_roster', 'local_launchpad'),
            'grades_back_roster_url' => $rosterurl,
            'grades_report_heading' => get_string('admin_courseops_grades_report_heading', 'local_launchpad'),
            'grades_course_total_label' => get_string('admin_courseops_grades_course_total', 'local_launchpad'),
            'grades_not_graded' => get_string('admin_courseops_grades_not_graded', 'local_launchpad'),
            'grades_empty_detail' => get_string('admin_courseops_grades_empty_detail', 'local_launchpad'),
            'grades_unknown_fellow' => get_string('admin_courseops_grades_unknown_fellow', 'local_launchpad'),
            'grade_rows' => [],
            'has_grade_rows' => false,
            'item_rows' => [],
            'has_item_rows' => false,
            'grades_stat_graded' => '',
            'grades_stat_avg' => '',
            'grades_showgrades' => !empty($course->showgrades),
            'grades_is_user_report' => false,
            'grades_report_name' => '',
            'grades_report_initials' => '',
            'grades_report_total' => '—',
            'grades_report_percent' => '—',
            'grades_report_has_total' => false,
            'grades_report_rows' => [],
            'has_grades_report_rows' => false,
            'grades_report_ok' => false,
        ];

        if (!$canview) {
            return $base;
        }

        $fellows = self::list_track_fellows($trackid);

        // In-shell user report for one fellow (section=grades&userid=…).
        if ($reportuserid > 0) {
            $fellow = null;
            foreach ($fellows as $row) {
                if ((int) $row['id'] === $reportuserid) {
                    $fellow = $row;
                    break;
                }
            }
            $base['grades_is_user_report'] = true;
            $base['grades_lead'] = get_string('admin_courseops_grades_detail_lead', 'local_launchpad');
            if ($fellow === null) {
                return $base;
            }
            $detail = self::courseops_grades_user_detail($trackid, $reportuserid);
            return array_merge($base, [
                'grades_report_ok' => true,
                'grades_report_name' => $fellow['name'],
                'grades_report_initials' => $fellow['initials'],
                'grades_report_total' => $detail['course_total'],
                'grades_report_percent' => $detail['course_percent'],
                'grades_report_has_total' => $detail['has_course_total'],
                'grades_report_rows' => $detail['rows'],
                'has_grades_report_rows' => $detail['rows'] !== [],
            ]);
        }

        $courseitem = \grade_item::fetch_course_item($trackid);
        $graderows = [];
        $gradedcount = 0;
        $pctsum = 0.0;
        $pctn = 0;

        foreach ($fellows as $fellow) {
            $uid = (int) $fellow['id'];
            $display = '—';
            $pctlabel = '—';
            $hasgrade = false;
            if ($courseitem) {
                $grade = new \grade_grade(['itemid' => $courseitem->id, 'userid' => $uid]);
                $grade->grade_item = $courseitem;
                $final = $grade->finalgrade;
                if ($final !== null) {
                    $hasgrade = true;
                    $gradedcount++;
                    $display = grade_format_gradevalue($final, $courseitem);
                    if ($courseitem->grademax > $courseitem->grademin) {
                        $fraction = ((float) $final - (float) $courseitem->grademin)
                            / ((float) $courseitem->grademax - (float) $courseitem->grademin);
                        $pct = (int) round($fraction * 100);
                        $pctlabel = $pct . '%';
                        $pctsum += $pct;
                        $pctn++;
                    }
                }
            }
            $graderows[] = [
                'id' => $uid,
                'name' => $fellow['name'],
                'initials' => $fellow['initials'],
                'grade_display' => $display,
                'percent_display' => $pctlabel,
                'has_grade' => $hasgrade,
                'user_report_url' => (new \moodle_url(persona_home::ADMIN_PATH, [
                    'view' => 'courseops',
                    'trackid' => $trackid,
                    'section' => 'grades',
                    'userid' => $uid,
                ]))->out(false),
            ];
        }

        $itemrows = [];
        $items = \grade_item::fetch_all(['courseid' => $trackid]) ?: [];
        foreach ($items as $item) {
            if ($item->itemtype !== 'mod' || (int) $item->gradetype === GRADE_TYPE_NONE) {
                continue;
            }
            if (!empty($item->outcomeid)) {
                continue;
            }
            $modlabel = '';
            if (!empty($item->itemmodule)) {
                $modlabel = get_string('pluginname', 'mod_' . $item->itemmodule);
            }
            $isassign = $item->itemmodule === 'assign';
            $itemrows[] = [
                'name' => $item->get_name(),
                'type_label' => $modlabel !== '' ? $modlabel : (string) $item->itemtype,
                'max_display' => format_float((float) $item->grademax, 2),
                'is_assign' => $isassign,
                'reviews_url' => $reviewsurl,
            ];
        }

        $avglabel = $pctn > 0
            ? get_string('admin_courseops_grades_stat_avg', 'local_launchpad', (int) round($pctsum / $pctn))
            : get_string('admin_courseops_grades_stat_avg_na', 'local_launchpad');

        return array_merge($base, [
            'grade_rows' => $graderows,
            'has_grade_rows' => $graderows !== [],
            'item_rows' => $itemrows,
            'has_item_rows' => $itemrows !== [],
            'grades_stat_graded' => get_string(
                'admin_courseops_grades_stat_graded',
                'local_launchpad',
                (object) ['graded' => $gradedcount, 'total' => count($fellows)]
            ),
            'grades_stat_avg' => $avglabel,
            'grades_open_item_label' => get_string('admin_courseops_grades_reviews', 'local_launchpad'),
        ]);
    }

    /**
     * In-shell per-fellow grade items for Course ops Grades (grade_item / grade_grade).
     *
     * @return array{course_total:string,course_percent:string,has_course_total:bool,rows:array<int,array<string,mixed>>}
     */
    private static function courseops_grades_user_detail(int $trackid, int $userid): array {
        $notgraded = get_string('admin_courseops_grades_not_graded', 'local_launchpad');
        $coursetotal = '—';
        $coursepercent = '—';
        $hascoursetotal = false;
        $rows = [];

        $courseitem = \grade_item::fetch_course_item($trackid);
        if ($courseitem) {
            $grade = new \grade_grade(['itemid' => $courseitem->id, 'userid' => $userid]);
            $grade->grade_item = $courseitem;
            if ($grade->finalgrade !== null) {
                $hascoursetotal = true;
                $coursetotal = grade_format_gradevalue($grade->finalgrade, $courseitem);
                if ($courseitem->grademax > $courseitem->grademin) {
                    $fraction = ((float) $grade->finalgrade - (float) $courseitem->grademin)
                        / ((float) $courseitem->grademax - (float) $courseitem->grademin);
                    $coursepercent = ((int) round($fraction * 100)) . '%';
                }
            }
        }

        $items = \grade_item::fetch_all(['courseid' => $trackid]) ?: [];
        foreach ($items as $item) {
            if ($item->itemtype !== 'mod' || (int) $item->gradetype === GRADE_TYPE_NONE) {
                continue;
            }
            if (!empty($item->outcomeid)) {
                continue;
            }
            $modlabel = '';
            if (!empty($item->itemmodule)) {
                $modlabel = get_string('pluginname', 'mod_' . $item->itemmodule);
            }
            $gg = new \grade_grade(['itemid' => $item->id, 'userid' => $userid]);
            $gg->grade_item = $item;
            $gradedisplay = $notgraded;
            $pctdisplay = '—';
            $hasgrade = false;
            if ($gg->finalgrade !== null) {
                $hasgrade = true;
                $gradedisplay = grade_format_gradevalue($gg->finalgrade, $item);
                if ($item->grademax > $item->grademin) {
                    $fraction = ((float) $gg->finalgrade - (float) $item->grademin)
                        / ((float) $item->grademax - (float) $item->grademin);
                    $pctdisplay = ((int) round($fraction * 100)) . '%';
                }
            }
            $feedback = trim(html_to_text((string) ($gg->feedback ?? ''), 0, false));
            $rows[] = [
                'name' => $item->get_name(),
                'type_label' => $modlabel !== '' ? $modlabel : (string) $item->itemtype,
                'grade_display' => $gradedisplay,
                'percent_display' => $pctdisplay,
                'feedback_display' => $feedback !== '' ? $feedback : '—',
                'has_feedback' => $feedback !== '',
                'has_grade' => $hasgrade,
                'is_assign' => $item->itemmodule === 'assign',
            ];
        }

        return [
            'course_total' => $coursetotal,
            'course_percent' => $coursepercent,
            'has_course_total' => $hascoursetotal,
            'rows' => $rows,
        ];
    }

    /**
     * Course module id for a mod instance on a track (0 if missing).
     */
    private static function courseops_cmid_for_mod(int $courseid, string $modname, int $instanceid): int {
        try {
            $modinfo = get_fast_modinfo($courseid);
            foreach ($modinfo->get_instances_of($modname) as $cm) {
                if ((int) $cm->instance === $instanceid) {
                    return (int) $cm->id;
                }
            }
        } catch (\Throwable $e) {
            debugging('courseops_cmid_for_mod: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        return 0;
    }

    /**
     * Completion section: fellow status table + enable toggle (Slice E).
     * Activity-level completion rules stay in Manage.
     *
     * @return array<string, mixed>
     */
    public static function courseops_completion_context(
        \stdClass $user,
        int $trackid,
        \stdClass $course
    ): array {
        global $CFG;

        require_once($CFG->libdir . '/completionlib.php');

        $context = \context_course::instance($trackid);
        $canview = has_capability('report/completion:view', $context, $user)
            || has_capability('moodle/course:view', $context, $user)
            || has_capability('moodle/course:update', $context, $user);
        $canupdate = has_capability('moodle/course:update', $context, $user);
        $siteon = !empty($CFG->enablecompletion);
        $courseon = $siteon && (int) ($course->enablecompletion ?? 0) === COMPLETION_ENABLED;
        $manageurl = (new \moodle_url(persona_home::ADMIN_PATH, [
            'view' => 'manage',
            'trackid' => $trackid,
        ]))->out(false);
        $settingsurl = (new \moodle_url(persona_home::ADMIN_PATH, [
            'view' => 'courseops',
            'trackid' => $trackid,
            'section' => 'settings',
        ]))->out(false);
        $formaction = (new \moodle_url(persona_home::ADMIN_PATH, [
            'view' => 'courseops',
            'trackid' => $trackid,
            'section' => 'completion',
        ]))->out(false);

        $base = [
            'completion_section_title' => get_string('admin_courseops_nav_completion', 'local_launchpad'),
            'completion_section_lead' => get_string('admin_courseops_completion_lead', 'local_launchpad'),
            'completion_can_view' => $canview,
            'completion_no_cap' => get_string('admin_courseops_err_completion_cap', 'local_launchpad'),
            'completion_site_on' => $siteon,
            'completion_course_on' => $courseon,
            'completion_site_off' => get_string('admin_courseops_completion_site_off', 'local_launchpad'),
            'completion_course_off' => get_string('admin_courseops_completion_course_off', 'local_launchpad'),
            'completion_enable_label' => get_string('admin_courseops_field_completion', 'local_launchpad'),
            'completion_enable_help' => get_string('admin_courseops_field_completion_help', 'local_launchpad'),
            'completion_enable_save' => get_string('admin_courseops_completion_enable_save', 'local_launchpad'),
            'completion_can_update' => $canupdate,
            'completion_form_action' => $formaction,
            'sesskey' => sesskey(),
            'trackid' => $trackid,
            'completion_checked' => $courseon,
            'completion_manage_url' => $manageurl,
            'completion_manage_label' => get_string('admin_courseops_completion_manage', 'local_launchpad'),
            'completion_manage_help' => get_string('admin_courseops_completion_manage_help', 'local_launchpad'),
            'completion_settings_url' => $settingsurl,
            'completion_settings_label' => get_string('admin_courseops_nav_settings', 'local_launchpad'),
            'col_name' => get_string('admin_people_col_name', 'local_launchpad'),
            'col_status' => get_string('admin_courseops_col_status', 'local_launchpad'),
            'col_progress' => get_string('admin_courseops_col_progress', 'local_launchpad'),
            'completion_empty_fellows' => get_string('admin_courseops_completion_empty_fellows', 'local_launchpad'),
            'completion_rows' => [],
            'has_completion_rows' => false,
            'completion_stat_complete' => '',
            'completion_stat_progress' => '',
            'completion_stat_new' => '',
        ];

        if (!$canview) {
            return $base;
        }

        $fellows = self::list_track_fellows($trackid);
        $rows = [];
        $ncomplete = 0;
        $nprogress = 0;
        $nnew = 0;

        if ($courseon && $fellows !== []) {
            $fullcourse = get_course($trackid);
            foreach ($fellows as $fellow) {
                $uid = (int) $fellow['id'];
                $outline = fellowship_dashboard::course_outline($fullcourse, $uid);
                $completed = (int) ($outline['completed'] ?? 0);
                $trackable = (int) ($outline['trackable'] ?? 0);
                $percent = $outline['percent'] ?? null;
                $haspercent = $percent !== null;

                if ($haspercent && (int) $percent >= 100) {
                    $status = 'complete';
                    $statuslabel = get_string('admin_courseops_completion_status_complete', 'local_launchpad');
                    $chip = 'ok';
                    $ncomplete++;
                } else if (($haspercent && (int) $percent > 0) || $completed > 0) {
                    $status = 'progress';
                    $statuslabel = get_string('admin_courseops_completion_status_progress', 'local_launchpad');
                    $chip = 'warn';
                    $nprogress++;
                } else {
                    $status = 'new';
                    $statuslabel = get_string('admin_courseops_completion_status_new', 'local_launchpad');
                    $chip = 'line';
                    $nnew++;
                }

                $progresslabel = $haspercent
                    ? get_string('admin_courseops_completion_progress', 'local_launchpad', [
                        'done' => $completed,
                        'total' => $trackable,
                        'pct' => (int) $percent,
                    ])
                    : ($trackable > 0
                        ? get_string('admin_courseops_completion_progress', 'local_launchpad', [
                            'done' => $completed,
                            'total' => $trackable,
                            'pct' => (int) round(($completed / max(1, $trackable)) * 100),
                        ])
                        : get_string('admin_courseops_completion_progress_untracked', 'local_launchpad'));

                $rows[] = [
                    'id' => $uid,
                    'name' => $fellow['name'],
                    'initials' => $fellow['initials'],
                    'status' => $status,
                    'status_label' => $statuslabel,
                    'chip' => $chip,
                    'progress_label' => $progresslabel,
                    'percent' => $haspercent ? (int) $percent : ($trackable > 0
                        ? (int) round(($completed / max(1, $trackable)) * 100) : 0),
                    'has_percent' => $haspercent || $trackable > 0,
                ];
            }
        } else if (!$courseon) {
            $nnew = count($fellows);
        }

        return array_merge($base, [
            'completion_rows' => $rows,
            'has_completion_rows' => $rows !== [],
            'completion_stat_complete' => get_string(
                'admin_courseops_completion_stat_complete',
                'local_launchpad',
                $ncomplete
            ),
            'completion_stat_progress' => get_string(
                'admin_courseops_completion_stat_progress',
                'local_launchpad',
                $nprogress
            ),
            'completion_stat_new' => get_string(
                'admin_courseops_completion_stat_new',
                'local_launchpad',
                $nnew
            ),
            'has_completion_stats' => $courseon && $fellows !== [],
        ]);
    }

    /**
     * Toggle course-level completion enablement from Course ops (Slice E).
     *
     * @return array{ok?:string,error?:string}
     */
    public static function save_courseops_completion(\stdClass $user, int $trackid, bool $enablecompletion): array {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->libdir . '/completionlib.php');

        $course = self::resolve_track_course($trackid);
        if ($course === null || (int) $course->id !== $trackid) {
            return ['error' => get_string('admin_courseops_err_track', 'local_launchpad')];
        }
        $full = $DB->get_record('course', ['id' => $trackid], '*', IGNORE_MISSING);
        if (!$full || !self::is_track_idnumber((string) ($full->idnumber ?? ''))) {
            return ['error' => get_string('admin_courseops_err_track', 'local_launchpad')];
        }
        $modcontext = \context_course::instance($trackid);
        if (!has_capability('moodle/course:update', $modcontext, $user)) {
            return ['error' => get_string('admin_courseops_err_cap', 'local_launchpad')];
        }
        if (empty($CFG->enablecompletion)) {
            return ['error' => get_string('admin_courseops_completion_site_off', 'local_launchpad')];
        }

        $data = (object) [
            'id' => $trackid,
            'enablecompletion' => $enablecompletion ? COMPLETION_ENABLED : COMPLETION_DISABLED,
        ];
        try {
            update_course($data);
        } catch (\Throwable $e) {
            return ['error' => self::flash_error_message($e)];
        }

        return ['ok' => $enablecompletion
            ? get_string('admin_courseops_completion_enable_ok', 'local_launchpad')
            : get_string('admin_courseops_completion_disable_ok', 'local_launchpad')];
    }

    /**
     * Reports section: participation + activity completion scans (Slice F).
     * Does not re-clone the completion roster — links to Slice E.
     *
     * @return array<string, mixed>
     */
    public static function courseops_reports_context(
        \stdClass $user,
        int $trackid,
        \stdClass $course
    ): array {
        global $CFG, $DB;

        require_once($CFG->libdir . '/completionlib.php');

        $context = \context_course::instance($trackid);
        $canview = has_capability('report/participation:view', $context, $user)
            || has_capability('report/outline:view', $context, $user)
            || has_capability('moodle/course:view', $context, $user)
            || has_capability('moodle/course:update', $context, $user);

        $completionurl = (new \moodle_url(persona_home::ADMIN_PATH, [
            'view' => 'courseops',
            'trackid' => $trackid,
            'section' => 'completion',
        ]))->out(false);
        $participationurl = (new \moodle_url('/report/participation/index.php', ['id' => $trackid]))->out(false);
        $outlineurl = (new \moodle_url('/report/outline/index.php', ['id' => $trackid]))->out(false);
        $progressurl = (new \moodle_url('/report/progress/index.php', ['course' => $trackid]))->out(false);

        $base = [
            'reports_title' => get_string('admin_courseops_nav_reports', 'local_launchpad'),
            'reports_lead' => get_string('admin_courseops_reports_lead', 'local_launchpad'),
            'reports_can_view' => $canview,
            'reports_no_cap' => get_string('admin_courseops_err_reports_cap', 'local_launchpad'),
            'reports_engagement_title' => get_string('admin_courseops_reports_engagement_title', 'local_launchpad'),
            'reports_activity_title' => get_string('admin_courseops_reports_activity_title', 'local_launchpad'),
            'reports_completion_url' => $completionurl,
            'reports_completion_label' => get_string('admin_courseops_reports_completion_link', 'local_launchpad'),
            'reports_completion_help' => get_string('admin_courseops_reports_completion_help', 'local_launchpad'),
            'reports_advanced_participation_url' => $participationurl,
            'reports_advanced_outline_url' => $outlineurl,
            'reports_advanced_progress_url' => $progressurl,
            'reports_advanced_participation' => get_string('admin_courseops_reports_adv_participation', 'local_launchpad'),
            'reports_advanced_outline' => get_string('admin_courseops_reports_adv_outline', 'local_launchpad'),
            'reports_advanced_progress' => get_string('admin_courseops_reports_adv_progress', 'local_launchpad'),
            'reports_advanced_help' => get_string('admin_courseops_reports_advanced_help', 'local_launchpad'),
            'col_name' => get_string('admin_people_col_name', 'local_launchpad'),
            'col_lastaccess' => get_string('admin_courseops_col_lastaccess', 'local_launchpad'),
            'col_activity' => get_string('admin_courseops_col_activity', 'local_launchpad'),
            'col_type' => get_string('admin_courseops_col_type', 'local_launchpad'),
            'col_complete' => get_string('admin_courseops_col_complete', 'local_launchpad'),
            'reports_empty_fellows' => get_string('admin_courseops_reports_empty_fellows', 'local_launchpad'),
            'reports_empty_activities' => get_string('admin_courseops_reports_empty_activities', 'local_launchpad'),
            'engagement_rows' => [],
            'has_engagement_rows' => false,
            'activity_rows' => [],
            'has_activity_rows' => false,
        ];

        if (!$canview) {
            return $base;
        }

        $fellows = self::list_track_fellows($trackid);
        $engagement = [];
        if ($fellows !== []) {
            $ids = array_map(static fn(array $f): int => (int) $f['id'], $fellows);
            list($insql, $params) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
            $params['courseid'] = $trackid;
            $access = $DB->get_records_select(
                'user_lastaccess',
                "courseid = :courseid AND userid $insql",
                $params,
                '',
                'userid, timeaccess'
            );
            foreach ($fellows as $fellow) {
                $uid = (int) $fellow['id'];
                $ts = isset($access[$uid]) ? (int) $access[$uid]->timeaccess : 0;
                $engagement[] = [
                    'id' => $uid,
                    'name' => $fellow['name'],
                    'initials' => $fellow['initials'],
                    'lastaccess_label' => $ts > 0
                        ? userdate($ts, get_string('strftimedatetimeshort', 'langconfig'))
                        : get_string('admin_courseops_reports_never', 'local_launchpad'),
                    'has_access' => $ts > 0,
                    'lastaccess_ts' => $ts,
                ];
            }
            usort($engagement, static function (array $a, array $b): int {
                return ((int) $b['lastaccess_ts']) <=> ((int) $a['lastaccess_ts']);
            });
        }

        $activityrows = [];
        $siteon = !empty($CFG->enablecompletion);
        $courseon = $siteon && (int) ($course->enablecompletion ?? 0) === COMPLETION_ENABLED;
        if ($courseon && $fellows !== []) {
            $fullcourse = get_course($trackid);
            $completion = new \completion_info($fullcourse);
            $modinfo = get_fast_modinfo($fullcourse);
            $fellowids = array_map(static fn(array $f): int => (int) $f['id'], $fellows);
            $fellowtotal = count($fellowids);
            foreach ($modinfo->get_cms() as $cm) {
                if (!$cm->uservisible || $cm->deletioninprogress || $cm->modname === 'label') {
                    continue;
                }
                if ($completion->is_enabled($cm) == COMPLETION_TRACKING_NONE) {
                    continue;
                }
                $done = 0;
                foreach ($fellowids as $uid) {
                    $data = $completion->get_data($cm, false, $uid);
                    $state = (int) $data->completionstate;
                    if ($state === COMPLETION_COMPLETE || $state === COMPLETION_COMPLETE_PASS) {
                        $done++;
                    }
                }
                $pct = $fellowtotal > 0 ? (int) round(($done / $fellowtotal) * 100) : 0;
                $activityrows[] = [
                    'name' => format_string($cm->get_formatted_name()),
                    'modlabel' => get_string('modulename', 'mod_' . $cm->modname),
                    'complete_label' => get_string(
                        'admin_courseops_reports_activity_complete',
                        'local_launchpad',
                        (object) ['done' => $done, 'total' => $fellowtotal, 'pct' => $pct]
                    ),
                    'pct' => $pct,
                ];
            }
            usort($activityrows, static function (array $a, array $b): int {
                return ((int) $a['pct']) <=> ((int) $b['pct']);
            });
        }

        return array_merge($base, [
            'engagement_rows' => $engagement,
            'has_engagement_rows' => $engagement !== [],
            'activity_rows' => $activityrows,
            'has_activity_rows' => $activityrows !== [],
            'reports_completion_off' => !$courseon
                ? get_string('admin_courseops_reports_completion_off', 'local_launchpad')
                : '',
            'reports_completion_off_show' => !$courseon,
        ]);
    }

    /**
     * Question bank subset: browse track banks + recent questions (Slice F).
     *
     * @return array<string, mixed>
     */
    public static function courseops_qbank_context(
        \stdClass $user,
        int $trackid,
        \stdClass $course
    ): array {
        global $CFG, $DB;

        require_once($CFG->libdir . '/questionlib.php');

        $coursecontext = \context_course::instance($trackid);
        $bankslisturl = \core_question\local\bank\question_bank_helper::get_url_for_qbank_list($trackid)->out(false);

        $caps = ['moodle/question:viewall', 'moodle/question:viewmine', 'moodle/question:add'];
        $shared = [];
        $private = [];
        try {
            $shared = \core_question\local\bank\question_bank_helper::get_activity_instances_with_shareable_questions(
                [$trackid],
                [],
                $caps,
                true
            );
            $private = \core_question\local\bank\question_bank_helper::get_activity_instances_with_private_questions(
                [$trackid],
                [],
                $caps,
                true
            );
        } catch (\Throwable $e) {
            debugging('courseops_qbank banks: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        $canview = $shared !== [] || $private !== []
            || has_capability('moodle/course:manageactivities', $coursecontext, $user)
            || has_capability('moodle/question:add', $coursecontext, $user);

        $bankrows = [];
        $questionrows = [];
        $firstaddurl = '';
        $allbanks = array_merge($shared, $private);
        foreach ($allbanks as $bank) {
            /** @var \core_question\local\bank\formatted_bank $bank */
            $cm = $bank->cminfo;
            $modcontext = \context_module::instance((int) $cm->id);
            $qcount = 0;
            try {
                $qcount = (int) $DB->count_records_sql(
                    "SELECT COUNT(1)
                       FROM {question_bank_entries} qbe
                       JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                      WHERE qc.contextid = ?",
                    [$modcontext->id]
                );
            } catch (\Throwable $e) {
                $qcount = 0;
            }
            $openurl = (new \moodle_url('/question/edit.php', ['cmid' => (int) $cm->id]))->out(false);
            $cats = $bank->questioncategories ?? [];
            $firstcat = $cats[0] ?? null;
            $addurl = '';
            if ($firstcat && has_capability('moodle/question:add', $modcontext, $user)) {
                $addurl = (new \moodle_url('/question/bank/editquestion/addquestion.php', [
                    'category' => (int) $firstcat->id,
                    'cmid' => (int) $cm->id,
                ]))->out(false);
                if ($firstaddurl === '') {
                    $firstaddurl = $addurl;
                }
            }
            $bankrows[] = [
                'name' => $cm->get_formatted_name(),
                'shared' => !empty($bank->shared),
                'kind_label' => !empty($bank->shared)
                    ? get_string('admin_courseops_qbank_shared', 'local_launchpad')
                    : get_string('admin_courseops_qbank_private', 'local_launchpad'),
                'question_count' => $qcount,
                'count_label' => get_string('admin_courseops_qbank_count', 'local_launchpad', $qcount),
                'open_url' => $openurl,
                'open_label' => get_string('admin_courseops_qbank_open', 'local_launchpad'),
                'add_url' => $addurl,
                'has_add' => $addurl !== '',
                'add_label' => get_string('admin_courseops_qbank_add', 'local_launchpad'),
            ];
        }

        // Browse recent questions across shared banks (cap for scan).
        $contextids = [];
        foreach ($shared as $bank) {
            $contextids[] = \context_module::instance((int) $bank->cminfo->id)->id;
        }
        if ($contextids !== []) {
            list($insql, $params) = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED);
            $sql = "SELECT q.id, q.name, q.qtype, qc.name AS categoryname, qbe.idnumber
                      FROM {question} q
                      JOIN {question_versions} qv ON qv.questionid = q.id
                      JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                      JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                      JOIN (
                            SELECT questionbankentryid, MAX(version) AS maxversion
                              FROM {question_versions}
                          GROUP BY questionbankentryid
                      ) latest ON latest.questionbankentryid = qbe.id AND latest.maxversion = qv.version
                     WHERE qc.contextid $insql
                       AND q.parent = 0
                  ORDER BY q.timemodified DESC, q.id DESC";
            try {
                $records = $DB->get_records_sql($sql, $params, 0, 40);
                foreach ($records as $q) {
                    $typename = $q->qtype;
                    try {
                        $typename = \question_bank::get_qtype_name($q->qtype);
                    } catch (\Throwable $e) {
                        // Keep raw qtype.
                    }
                    $questionrows[] = [
                        'name' => format_string($q->name),
                        'type_label' => $typename,
                        'category' => format_string($q->categoryname),
                    ];
                }
            } catch (\Throwable $e) {
                debugging('courseops_qbank questions: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        return [
            'qbank_title' => get_string('admin_courseops_nav_qbank', 'local_launchpad'),
            'qbank_lead' => get_string('admin_courseops_qbank_lead', 'local_launchpad'),
            'qbank_can_view' => $canview,
            'qbank_no_cap' => get_string('admin_courseops_err_qbank_cap', 'local_launchpad'),
            'qbank_banks_title' => get_string('admin_courseops_qbank_banks_title', 'local_launchpad'),
            'qbank_questions_title' => get_string('admin_courseops_qbank_questions_title', 'local_launchpad'),
            'qbank_banks_url' => $bankslisturl,
            'qbank_banks_label' => get_string('admin_courseops_qbank_manage_banks', 'local_launchpad'),
            'qbank_banks_help' => get_string('admin_courseops_qbank_manage_banks_help', 'local_launchpad'),
            'qbank_add_url' => $firstaddurl,
            'qbank_has_add' => $firstaddurl !== '',
            'qbank_add_label' => get_string('admin_courseops_qbank_add', 'local_launchpad'),
            'bank_rows' => $bankrows,
            'has_bank_rows' => $bankrows !== [],
            'qbank_empty_banks' => get_string('admin_courseops_qbank_empty_banks', 'local_launchpad'),
            'question_rows' => $questionrows,
            'has_question_rows' => $questionrows !== [],
            'qbank_empty_questions' => get_string('admin_courseops_qbank_empty_questions', 'local_launchpad'),
            'col_name' => get_string('admin_people_col_name', 'local_launchpad'),
            'col_type' => get_string('admin_courseops_col_type', 'local_launchpad'),
            'col_category' => get_string('admin_courseops_col_category', 'local_launchpad'),
            'col_count' => get_string('admin_courseops_col_count', 'local_launchpad'),
            'col_actions' => get_string('admin_courseops_col_actions', 'local_launchpad'),
        ];
    }

    /**
     * Active student enrolments on one track (roster rows).
     *
     * @return list<array{id:int,name:string,email:string,initials:string}>
     */
    public static function list_track_fellows(int $courseid): array {
        global $CFG, $DB;

        if ($courseid <= 0) {
            return [];
        }
        require_once($CFG->libdir . '/enrollib.php');

        $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                       u.middlename, u.alternatename, u.email
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                  JOIN {context} ctx ON ctx.instanceid = e.courseid AND ctx.contextlevel = :ctxlevel
                  JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.userid = ue.userid
                  JOIN {role} r ON r.id = ra.roleid AND r.archetype = :arch
                  JOIN {user} u ON u.id = ue.userid
                 WHERE e.courseid = :courseid
                   AND ue.status = :uestatus
                   AND e.status = :estatus
                   AND u.deleted = 0
              ORDER BY u.lastname ASC, u.firstname ASC";
        $users = $DB->get_records_sql($sql, [
            'courseid' => $courseid,
            'ctxlevel' => CONTEXT_COURSE,
            'arch' => 'student',
            'uestatus' => ENROL_USER_ACTIVE,
            'estatus' => ENROL_INSTANCE_ENABLED,
        ]);
        $out = [];
        foreach ($users as $u) {
            $out[] = [
                'id' => (int) $u->id,
                'name' => fullname($u),
                'email' => s($u->email),
                'initials' => fellowship_dashboard::initials($u),
            ];
        }
        return $out;
    }

    /**
     * Mentors on one track for the Course ops roster (includes email + role label).
     *
     * @return list<array{id:int,name:string,email:string,initials:string,role_label:string}>
     */
    public static function list_track_roster_mentors(int $courseid): array {
        global $DB;

        if ($courseid <= 0) {
            return [];
        }
        $context = \context_course::instance($courseid);
        $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                       u.middlename, u.alternatename, u.email, r.shortname AS roleshortname, r.archetype
                  FROM {role_assignments} ra
                  JOIN {role} r ON r.id = ra.roleid
                  JOIN {user} u ON u.id = ra.userid
                 WHERE ra.contextid = :ctx
                   AND r.archetype IN ('teacher', 'editingteacher')
                   AND u.deleted = 0
              ORDER BY u.lastname ASC, u.firstname ASC";
        $users = $DB->get_records_sql($sql, ['ctx' => $context->id]);
        $out = [];
        $seen = [];
        foreach ($users as $u) {
            $uid = (int) $u->id;
            if (isset($seen[$uid])) {
                continue;
            }
            $seen[$uid] = true;
            $rolelabel = ((string) ($u->roleshortname ?? '') === 'editingteacher')
                ? get_string('admin_courseops_role_editingteacher', 'local_launchpad')
                : get_string('admin_courseops_role_teacher', 'local_launchpad');
            $out[] = [
                'id' => $uid,
                'name' => fullname($u),
                'email' => s($u->email),
                'initials' => fellowship_dashboard::initials($u),
                'role_label' => $rolelabel,
            ];
        }
        return $out;
    }

    /**
     * Unenrol a fellow from this track (Course ops roster). Caps fail-closed.
     *
     * @return array{ok?:string,error?:string}
     */
    public static function unenrol_fellow_from_track(\stdClass $actor, int $userid, int $trackcourseid): array {
        if ($userid <= 0 || $trackcourseid <= 0) {
            return ['error' => get_string('admin_courseops_err_roster_params', 'local_launchpad')];
        }

        try {
            $course = account_provision::require_launchpad_course($trackcourseid);
        } catch (account_provision_exception $e) {
            return ['error' => self::flash_error_message($e)];
        }
        if (!self::is_track_idnumber((string) $course->idnumber)) {
            return ['error' => get_string('admin_courseops_err_track', 'local_launchpad')];
        }

        try {
            $result = account_provision::unenrol_fellow($actor, $userid, $trackcourseid);
        } catch (account_provision_exception $e) {
            if ($e->errorcode === 'forbidden') {
                return ['error' => get_string('admin_courseops_err_unenrol_cap', 'local_launchpad')];
            }
            return ['error' => self::flash_error_message($e)];
        }

        $target = \core_user::get_user($userid, '*', IGNORE_MISSING);
        $name = $target ? fullname($target) : (string) $userid;
        if ($result['status'] === 'not_enrolled') {
            return ['error' => get_string('admin_courseops_err_not_fellow', 'local_launchpad', $name)];
        }

        return [
            'ok' => get_string('admin_courseops_unenrol_ok', 'local_launchpad', (object) [
                'name' => $name,
                'track' => format_string($course->fullname),
            ]),
        ];
    }

    /**
     * Remove a mentor from this track (Course ops roster). Caps fail-closed.
     *
     * @return array{ok?:string,error?:string}
     */
    public static function remove_mentor_from_track(\stdClass $actor, int $userid, int $trackcourseid): array {
        if ($userid <= 0 || $trackcourseid <= 0) {
            return ['error' => get_string('admin_courseops_err_roster_params', 'local_launchpad')];
        }

        try {
            $course = account_provision::require_launchpad_course($trackcourseid);
        } catch (account_provision_exception $e) {
            return ['error' => self::flash_error_message($e)];
        }
        if (!self::is_track_idnumber((string) $course->idnumber)) {
            return ['error' => get_string('admin_courseops_err_track', 'local_launchpad')];
        }

        try {
            $result = account_provision::remove_mentor($actor, $userid, $trackcourseid);
        } catch (account_provision_exception $e) {
            if ($e->errorcode === 'forbidden') {
                return ['error' => get_string('admin_courseops_err_unenrol_cap', 'local_launchpad')];
            }
            return ['error' => self::flash_error_message($e)];
        }

        $target = \core_user::get_user($userid, '*', IGNORE_MISSING);
        $name = $target ? fullname($target) : (string) $userid;
        if ($result['status'] === 'not_enrolled') {
            return ['error' => get_string('admin_courseops_err_not_mentor', 'local_launchpad', $name)];
        }

        return [
            'ok' => get_string('admin_courseops_remove_mentor_ok', 'local_launchpad', (object) [
                'name' => $name,
                'track' => format_string($course->fullname),
            ]),
        ];
    }

    /**
     * Save LaunchPad weekly course settings for a track (Slice B).
     *
     * @return array{ok?:string,error?:string,posted?:array<string,mixed>}
     */
    public static function save_courseops_settings(
        \stdClass $user,
        int $trackid,
        string $fullname,
        string $shortname,
        bool $visible,
        string $startraw,
        string $endraw,
        bool $enablecompletion,
        string $summary
    ): array {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->libdir . '/completionlib.php');

        $posted = self::courseops_settings_posted(
            $fullname,
            $shortname,
            $visible,
            $startraw,
            $endraw,
            $enablecompletion,
            $summary
        );
        $fail = static function (string $error) use ($posted): array {
            return ['error' => $error, 'posted' => $posted];
        };

        $fullname = trim($fullname);
        $shortname = trim($shortname);
        if ($fullname === '') {
            return $fail(get_string('admin_courseops_err_fullname', 'local_launchpad'));
        }
        if ($shortname === '') {
            return $fail(get_string('admin_courseops_err_shortname', 'local_launchpad'));
        }

        $course = self::resolve_track_course($trackid);
        if ($course === null || (int) $course->id !== $trackid) {
            return $fail(get_string('admin_courseops_err_track', 'local_launchpad'));
        }
        $full = $DB->get_record('course', ['id' => $trackid], '*', IGNORE_MISSING);
        if (!$full || !self::is_track_idnumber((string) ($full->idnumber ?? ''))) {
            return $fail(get_string('admin_courseops_err_track', 'local_launchpad'));
        }

        $modcontext = \context_course::instance($trackid);
        if (!has_capability('moodle/course:update', $modcontext, $user)) {
            return $fail(get_string('admin_courseops_err_cap', 'local_launchpad'));
        }
        $wantvisible = $visible ? 1 : 0;
        if ($wantvisible !== (int) $full->visible
                && !has_capability('moodle/course:visibility', $modcontext, $user)) {
            return $fail(get_string('admin_courseops_err_cap_visibility', 'local_launchpad'));
        }

        $startdate = self::parse_course_date($startraw);
        if ($startdate === null && trim($startraw) !== '') {
            return $fail(get_string('admin_courseops_err_startdate', 'local_launchpad'));
        }
        if ($startdate === null) {
            $startdate = (int) ($full->startdate ?? 0);
        }

        $enddate = self::parse_course_date($endraw);
        if ($enddate === null && trim($endraw) !== '') {
            return $fail(get_string('admin_courseops_err_enddate', 'local_launchpad'));
        }
        if (trim($endraw) === '') {
            $enddate = 0;
        }
        if ($enddate > 0 && $startdate > 0 && $enddate < $startdate) {
            return $fail(get_string('admin_courseops_err_date_order', 'local_launchpad'));
        }

        $completion = COMPLETION_DISABLED;
        if (!empty($CFG->enablecompletion) && $enablecompletion) {
            $completion = COMPLETION_ENABLED;
        }

        $data = (object) [
            'id' => $trackid,
            'fullname' => $fullname,
            'shortname' => $shortname,
            'visible' => $wantvisible,
            'startdate' => $startdate,
            'enddate' => $enddate,
            'enablecompletion' => $completion,
            'summary' => clean_text($summary, FORMAT_HTML),
            'summaryformat' => FORMAT_HTML,
        ];

        try {
            update_course($data);
        } catch (\Throwable $e) {
            return $fail(self::flash_error_message($e));
        }

        return ['ok' => get_string('admin_courseops_settings_ok', 'local_launchpad')];
    }

    /**
     * Snapshot of Settings form fields to re-show after a failed save.
     *
     * @return array<string, mixed>
     */
    private static function courseops_settings_posted(
        string $fullname,
        string $shortname,
        bool $visible,
        string $startraw,
        string $endraw,
        bool $enablecompletion,
        string $summary
    ): array {
        return [
            'fullname' => $fullname,
            'shortname' => $shortname,
            'visible' => $visible,
            'startdate' => $startraw,
            'enddate' => $endraw,
            'enablecompletion' => $enablecompletion,
            'summary' => $summary,
        ];
    }

    /**
     * Parse HTML date (YYYY-MM-DD) in the current user's timezone → unix midnight.
     * Empty → null.
     */
    public static function parse_course_date(string $raw): ?int {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
            return null;
        }
        $year = (int) $m[1];
        $month = (int) $m[2];
        $day = (int) $m[3];
        if ($year < 1970 || $month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return null;
        }
        return make_timestamp($year, $month, $day, 0, 0, 0);
    }

    /**
     * Value for HTML date inputs.
     */
    public static function format_course_date_value(int $ts): string {
        if ($ts <= 0) {
            return '';
        }
        return sprintf(
            '%04d-%02d-%02d',
            (int) userdate($ts, '%Y'),
            (int) userdate($ts, '%m'),
            (int) userdate($ts, '%d')
        );
    }

    /**
     * Course builder (HTML builder) — stage panel around Moodle activities.
     *
     * @param \stdClass $user
     * @param int $trackid Selected track course id (0 = first)
     * @param array{ok?:string,error?:string} $flash
     * @return array<string, mixed>
     */
    public static function builder_context(\stdClass $user, int $trackid = 0, array $flash = []): array {
        unset($user);
        $tracks = self::list_track_courses();
        if ($tracks === []) {
            return array_merge([
                'eyebrow' => get_string('admin_builder_eyebrow', 'local_launchpad'),
                'heading' => get_string('admin_builder_heading', 'local_launchpad'),
                'lead' => get_string('admin_builder_lead', 'local_launchpad'),
                'has_tracks' => false,
                'flash_ok' => !empty($flash['ok']),
                'flash_ok_body' => (string) ($flash['ok'] ?? ''),
                'flash_err' => !empty($flash['error']),
                'flash_err_body' => (string) ($flash['error'] ?? ''),
            ], self::empty_state(
                'admin_builder_empty',
                'admin_empty_title_no_tracks'
            ));
        }

        $selected = null;
        foreach ($tracks as $course) {
            if ($trackid > 0 && (int) $course->id === $trackid) {
                $selected = $course;
                break;
            }
        }
        if ($selected === null) {
            $selected = $tracks[0];
            $trackid = (int) $selected->id;
        }

        $pluginbase = (new \moodle_url('/local/launchpad'))->out(false);
        $sesskey = sesskey();
        $addlabel = get_string('admin_builder_add', 'local_launchpad');
        $editlabel = get_string('admin_builder_edit', 'local_launchpad');
        $marklabel = get_string('admin_builder_mark_reviewed', 'local_launchpad');
        $showlabel = get_string('admin_builder_show_section', 'local_launchpad');
        $hidelabel = get_string('admin_builder_hide_section', 'local_launchpad');
        $showblocked = get_string('admin_builder_show_blocked', 'local_launchpad');
        $noacts = get_string('admin_builder_no_activities', 'local_launchpad');
        $teacherkv = get_string('admin_builder_kv_teacher', 'local_launchpad');
        $enrolledkv = get_string('admin_builder_kv_enrolled', 'local_launchpad');
        $guestkv = get_string('admin_builder_kv_guest', 'local_launchpad');
        $guestofflabel = get_string('admin_builder_guest_off', 'local_launchpad');
        $guestonlabel = get_string('admin_builder_guest_on', 'local_launchpad');
        $noteacher = get_string('admin_tracks_no_teacher', 'local_launchpad');
        $opencourse = get_string('admin_tracks_open_course', 'local_launchpad');
        $tipbody = get_string('admin_builder_tip', 'local_launchpad');
        $tipurl = $pluginbase . '/assets/img/prof.webp';

        $tabs = [];
        $panels = [];
        foreach ($tracks as $course) {
            $cid = (int) $course->id;
            $fullname = format_string($course->fullname);
            $tablabel = self::builder_track_tab_label($course);
            $current = $cid === $trackid;
            $tabs[] = [
                'id' => $cid,
                'label' => $tablabel,
                'title' => $fullname,
                'url' => (new \moodle_url(persona_home::ADMIN_PATH, [
                    'view' => 'builder',
                    'trackid' => $cid,
                ]))->out(false),
                'current' => $current,
            ];

            $formaction = (new \moodle_url(persona_home::ADMIN_PATH, [
                'view' => 'builder',
                'trackid' => $cid,
            ]))->out(false);
            $phases = self::builder_phase_cards($course);
            foreach ($phases as &$phase) {
                $phase['form_action'] = $formaction;
                $phase['sesskey'] = $sesskey;
                $phase['trackid'] = $cid;
                $phase['add_label'] = $addlabel;
                $phase['show_label'] = $showlabel;
                $phase['hide_label'] = $hidelabel;
                $phase['show_blocked_title'] = $showblocked;
                $phase['no_acts'] = $noacts;
                foreach ($phase['activities'] as &$act) {
                    $act['form_action'] = $formaction;
                    $act['sesskey'] = $sesskey;
                    $act['trackid'] = $cid;
                $act['edit_label'] = $editlabel;
                $act['mark_label'] = $marklabel;
                // Prefer LaunchPad manage deep-link when present.
                if (empty($act['manage_url'])) {
                    $act['manage_url'] = $act['edit_url'];
                }
            }
            unset($act);
        }
        unset($phase);

        $teachers = self::teacher_names($cid);
            $panels[] = [
                'id' => $cid,
                'current' => $current,
                'phases' => $phases,
                'side_title' => $fullname,
                'side_capstone' => self::capstone_line($course),
                'side_teacher_label' => $teacherkv,
                'side_teacher' => $teachers !== [] ? implode(', ', $teachers) : $noteacher,
                'side_enrolled_label' => $enrolledkv,
                'side_enrolled' => (string) self::fellow_count($cid),
                'side_guest_label' => $guestkv,
                'side_guest' => self::guest_enrol_enabled($cid) ? $guestonlabel : $guestofflabel,
                'tip_prof_url' => $tipurl,
                'tip_body' => $tipbody,
                'course_url' => (new \moodle_url('/course/view.php', ['id' => $cid]))->out(false),
                'open_course_label' => $opencourse,
            ];
        }

        return [
            'eyebrow' => get_string('admin_builder_eyebrow', 'local_launchpad'),
            'heading' => get_string('admin_builder_heading', 'local_launchpad'),
            'lead' => get_string('admin_builder_lead', 'local_launchpad'),
            'has_tracks' => true,
            'initial_trackid' => (string) $trackid,
            'track_tabs' => $tabs,
            'track_panels' => $panels,
            'flash_ok' => !empty($flash['ok']),
            'flash_ok_body' => (string) ($flash['ok'] ?? ''),
            'flash_err' => !empty($flash['error']),
            'flash_err_body' => (string) ($flash['error'] ?? ''),
        ];
    }

    /**
     * Compact track tab label (not the shared "LaunchPad" course prefix).
     */
    public static function builder_track_tab_label(\stdClass $course): string {
        $idnumber = strtolower(trim((string) ($course->idnumber ?? '')));
        if (preg_match('/^lp-([a-z0-9]+)-/', $idnumber, $m)) {
            $code = $m[1];
            foreach (blueprint::track_categories() as $catid => $name) {
                if (str_replace('launchpad-', '', $catid) === $code) {
                    return match ($code) {
                        'ai' => 'AI',
                        'fullstack' => 'Full Stack',
                        'pm' => 'Product',
                        'marketing' => 'Marketing',
                        'startup' => 'Startup',
                        default => $name,
                    };
                }
            }
        }

        $fullname = format_string($course->fullname ?? '');
        // "LaunchPad — AI & Automation — Cohort Pilot" → middle segment.
        if (preg_match('/LaunchPad\s*[—–-]\s*(.+?)\s*[—–-]/u', $fullname, $m)) {
            return trim($m[1]);
        }
        return $fullname !== '' ? $fullname : get_string('admin_builder_untitled', 'local_launchpad');
    }

    /**
     * Four phase cards with activity rows for the builder.
     *
     * @param \stdClass $course
     * @return list<array<string, mixed>>
     */
    public static function builder_phase_cards(\stdClass $course): array {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/course/lib.php');

        $courseid = (int) $course->id;
        $names = blueprint::phase_sections();
        $out = [];

        foreach ($names as $sectionnum => $title) {
            $section = $DB->get_record('course_sections', [
                'course' => $courseid,
                'section' => $sectionnum,
            ]);
            $sectionvisible = $section && (int) $section->visible === 1;
            $sectionid = $section ? (int) $section->id : 0;
            $activities = self::builder_activities_for_section($courseid, $section, $sectionvisible);

            $hasready = false;
            foreach ($activities as $act) {
                if (($act['state'] ?? '') !== 'draft') {
                    $hasready = true;
                    break;
                }
            }

            $canshow = $hasready || $sectionvisible;
            $count = count($activities);
            $out[] = [
                'sectionnum' => $sectionnum,
                'sectionid' => $sectionid,
                'n' => (string) $sectionnum,
                'title' => $title,
                'shown' => $sectionvisible,
                'meta' => get_string(
                    'admin_builder_phase_meta',
                    'local_launchpad',
                    (object) [
                        'count' => $count,
                        'state' => $sectionvisible
                            ? get_string('admin_builder_section_shown', 'local_launchpad')
                            : get_string('admin_builder_section_hidden', 'local_launchpad'),
                    ]
                ),
                'activities' => $activities,
                'has_activities' => $activities !== [],
                'can_show' => $canshow,
                // LaunchPad manage UI (phase 1+) — not stock Moodle section.php.
                'add_url' => (new \moodle_url(persona_home::ADMIN_PATH, [
                    'view' => 'manage',
                    'trackid' => $courseid,
                    'section' => $sectionnum,
                ]))->out(false),
                'manage_url' => (new \moodle_url(persona_home::ADMIN_PATH, [
                    'view' => 'manage',
                    'trackid' => $courseid,
                    'section' => $sectionnum,
                ]))->out(false),
                'toggle_show' => !$sectionvisible,
            ];
        }

        return $out;
    }

    /**
     * One phase manage screen in the admin shell (LaunchPad chrome, Moodle data).
     *
     * @param \stdClass $user
     * @param array{ok?:string,error?:string} $flash
     * @return array<string, mixed>
     */
    public static function manage_context(
        \stdClass $user,
        int $trackid,
        int $sectionnum,
        array $flash = []
    ): array {
        unset($user);
        $tracks = self::list_track_courses();
        $course = null;
        foreach ($tracks as $c) {
            if ((int) $c->id === $trackid) {
                $course = $c;
                break;
            }
        }
        if ($course === null && $tracks !== []) {
            $course = $tracks[0];
            $trackid = (int) $course->id;
        }
        if ($course === null) {
            $builderurl = (new \moodle_url(persona_home::ADMIN_PATH, ['view' => 'builder']))->out(false);
            return array_merge([
                'has_course' => false,
                'builder_url' => $builderurl,
                'builder_label' => get_string('admin_nav_builder', 'local_launchpad'),
                'flash_ok' => !empty($flash['ok']),
                'flash_ok_body' => (string) ($flash['ok'] ?? ''),
                'flash_err' => !empty($flash['error']),
                'flash_err_body' => (string) ($flash['error'] ?? ''),
            ], self::empty_state(
                'admin_builder_empty',
                'admin_empty_title_no_tracks',
                $builderurl,
                'admin_empty_cta_builder'
            ));
        }

        $phases = blueprint::phase_sections();
        if ($sectionnum < 1 || $sectionnum > 4 || !isset($phases[$sectionnum])) {
            $sectionnum = 1;
        }
        $phasetitle = $phases[$sectionnum];
        $section = null;
        global $DB;
        $section = $DB->get_record('course_sections', [
            'course' => $trackid,
            'section' => $sectionnum,
        ]);
        $sectionvisible = $section && (int) $section->visible === 1;
        $activities = self::builder_activities_for_section($trackid, $section, $sectionvisible);

        $formaction = (new \moodle_url(persona_home::ADMIN_PATH, [
            'view' => 'manage',
            'trackid' => $trackid,
            'section' => $sectionnum,
        ]))->out(false);
        $sesskey = sesskey();
        $editlabel = get_string('admin_manage_settings', 'local_launchpad');
        $marklabel = get_string('admin_builder_mark_reviewed', 'local_launchpad');
        $hidelabel = get_string('admin_manage_hide_activity', 'local_launchpad');
        $showcmlabel = get_string('admin_manage_show_activity', 'local_launchpad');
        $hiddenbadge = get_string('admin_manage_hidden_badge', 'local_launchpad');
        $deletelabel = get_string('admin_manage_delete_activity', 'local_launchpad');
        $deleteconfirm = get_string('admin_manage_delete_confirm', 'local_launchpad');
        $duplicatelabel = get_string('admin_manage_duplicate', 'local_launchpad');
        $renamelabel = get_string('admin_manage_rename', 'local_launchpad');
        $draghandlelabel = get_string('admin_manage_drag_handle', 'local_launchpad');
        foreach ($activities as &$act) {
            $act['form_action'] = $formaction;
            $act['sesskey'] = $sesskey;
            $act['trackid'] = $trackid;
            $act['sectionnum'] = $sectionnum;
            $act['settings_label'] = $editlabel;
            $act['mark_label'] = $marklabel;
            $act['hide_label'] = $hidelabel;
            $act['show_cm_label'] = $showcmlabel;
            $act['hidden_badge'] = $hiddenbadge;
            $act['delete_label'] = $deletelabel;
            $act['delete_confirm'] = $deleteconfirm;
            $act['duplicate_label'] = $duplicatelabel;
            $act['rename_label'] = $renamelabel;
            $act['drag_handle_label'] = $draghandlelabel;
            $act['settings_url'] = (new \moodle_url(persona_home::ADMIN_PATH, [
                'view' => 'activity',
                'trackid' => $trackid,
                'cmid' => (int) $act['cmid'],
            ]))->out(false);
            $act['open_label'] = get_string('admin_manage_open', 'local_launchpad');
        }
        unset($act);

        $addtypes = [];
        foreach (['page', 'resource', 'quiz', 'assign'] as $mod) {
            $addtypes[] = [
                'mod' => $mod,
                'label' => self::builder_mod_kind($mod),
                'form_action' => $formaction,
                'sesskey' => $sesskey,
                'trackid' => $trackid,
                'sectionnum' => $sectionnum,
            ];
        }

        $phaselinks = [];
        foreach ($phases as $num => $title) {
            $phaselinks[] = [
                'sectionnum' => $num,
                'title' => $title,
                'current' => $num === $sectionnum,
                'url' => (new \moodle_url(persona_home::ADMIN_PATH, [
                    'view' => 'manage',
                    'trackid' => $trackid,
                    'section' => $num,
                ]))->out(false),
            ];
        }

        return [
            'has_course' => true,
            'eyebrow' => get_string('admin_manage_eyebrow', 'local_launchpad'),
            'heading' => $phasetitle,
            'lead' => get_string('admin_manage_lead', 'local_launchpad'),
            'track_name' => format_string($course->fullname),
            'track_tab_label' => self::builder_track_tab_label($course),
            'section_state' => $sectionvisible
                ? get_string('admin_builder_section_shown', 'local_launchpad')
                : get_string('admin_builder_section_hidden', 'local_launchpad'),
            'section_shown' => $sectionvisible,
            'activities' => $activities,
            'has_activities' => $activities !== [],
            'empty_acts' => get_string('admin_builder_no_activities', 'local_launchpad'),
            'phase_links' => $phaselinks,
            'add_types' => $addtypes,
            'add_label' => get_string('admin_builder_add', 'local_launchpad'),
            'add_chooser_title' => get_string('admin_manage_add_chooser', 'local_launchpad'),
            'add_name_label' => get_string('admin_manage_add_name', 'local_launchpad'),
            'add_name_placeholder' => get_string('admin_manage_add_name_placeholder', 'local_launchpad'),
            'edit_mode_label' => get_string('admin_manage_edit_mode', 'local_launchpad'),
            'edit_mode_on_label' => get_string('admin_manage_edit_on', 'local_launchpad'),
            'edit_mode_off_label' => get_string('admin_manage_edit_off', 'local_launchpad'),
            'builder_url' => (new \moodle_url(persona_home::ADMIN_PATH, [
                'view' => 'builder',
                'trackid' => $trackid,
            ]))->out(false),
            'builder_label' => get_string('admin_nav_builder', 'local_launchpad'),
            'back_label' => get_string('admin_manage_back', 'local_launchpad'),
            'form_action' => $formaction,
            'sesskey' => $sesskey,
            'trackid' => $trackid,
            'sectionnum' => $sectionnum,
            'reorder_hint' => get_string('admin_manage_reorder_hint', 'local_launchpad'),
            'save_order_label' => get_string('admin_manage_save_order', 'local_launchpad'),
            'cancel_order_label' => get_string('admin_manage_cancel_order', 'local_launchpad'),
            'flash_ok' => !empty($flash['ok']),
            'flash_ok_body' => (string) ($flash['ok'] ?? ''),
            'flash_err' => !empty($flash['error']),
            'flash_err_body' => (string) ($flash['error'] ?? ''),
        ];
    }

    /**
     * Create a Page / File / Quiz / Assign in a track phase (Moodle create_module).
     *
     * @return array{ok:string}|array{error:string}
     */
    public static function create_phase_activity(
        \stdClass $actor,
        int $trackcourseid,
        int $sectionnum,
        string $modname,
        string $name = ''
    ): array {
        global $CFG, $DB, $USER;

        unset($actor);
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->libdir . '/resourcelib.php');
        require_once($CFG->libdir . '/completionlib.php');
        if ($modname === 'quiz') {
            require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        }

        $modname = strtolower(trim($modname));
        $allowed = ['page', 'resource', 'quiz', 'assign'];
        if (!in_array($modname, $allowed, true)) {
            return ['error' => get_string('admin_manage_err_mod', 'local_launchpad')];
        }
        if ($trackcourseid <= 0 || $sectionnum < 1 || $sectionnum > 4) {
            return ['error' => get_string('admin_builder_err_params', 'local_launchpad')];
        }

        try {
            $course = account_provision::require_launchpad_course($trackcourseid);
        } catch (account_provision_exception $e) {
            return ['error' => self::flash_error_message($e)];
        }
        if (!self::is_track_idnumber((string) $course->idnumber)) {
            return ['error' => get_string('admin_mentors_err_not_track', 'local_launchpad')];
        }

        $context = \context_course::instance($trackcourseid);
        try {
            require_capability('moodle/course:manageactivities', $context);
        } catch (\required_capability_exception $e) {
            return ['error' => self::flash_error_message($e)];
        }

        $name = trim($name);
        if ($name === '') {
            $name = get_string('admin_manage_default_name_' . $modname, 'local_launchpad');
        }

        // Completion rules need the course flag on.
        if (empty($course->enablecompletion)) {
            $DB->set_field('course', 'enablecompletion', 1, ['id' => $trackcourseid]);
            $course->enablecompletion = 1;
            rebuild_course_cache($trackcourseid, true);
        }

        $moduleinfo = self::build_new_moduleinfo($course, $sectionnum, $modname, $name);
        try {
            // create_module uses $USER for capability checks.
            $created = create_module($moduleinfo);
        } catch (\Throwable $e) {
            return ['error' => self::flash_error_message($e)];
        }

        $createdname = format_string($name);
        return [
            'ok' => get_string('admin_manage_ok_created', 'local_launchpad', (object) [
                'name' => $createdname,
                'kind' => self::builder_mod_kind($modname),
            ]),
            'cmid' => (int) ($created->coursemodule ?? 0),
        ];
    }

    /**
     * Hide or show one activity (course module) on a track.
     *
     * @return array{ok:string}|array{error:string}
     */
    public static function set_activity_visible(
        \stdClass $actor,
        int $trackcourseid,
        int $cmid,
        bool $show
    ): array {
        global $CFG, $DB;

        unset($actor);
        require_once($CFG->dirroot . '/course/lib.php');

        if ($trackcourseid <= 0 || $cmid <= 0) {
            return ['error' => get_string('admin_builder_err_params', 'local_launchpad')];
        }
        try {
            $course = account_provision::require_launchpad_course($trackcourseid);
        } catch (account_provision_exception $e) {
            return ['error' => self::flash_error_message($e)];
        }
        if (!self::is_track_idnumber((string) $course->idnumber)) {
            return ['error' => get_string('admin_mentors_err_not_track', 'local_launchpad')];
        }

        $context = \context_course::instance($trackcourseid);
        try {
            require_capability('moodle/course:activityvisibility', $context);
        } catch (\required_capability_exception $e) {
            // Fall back to manageactivities (site admins / editing teachers).
            try {
                require_capability('moodle/course:manageactivities', $context);
            } catch (\required_capability_exception $e2) {
                return ['error' => self::flash_error_message($e2)];
            }
        }

        $cm = $DB->get_record('course_modules', ['id' => $cmid, 'course' => $trackcourseid], '*', IGNORE_MISSING);
        if (!$cm) {
            return ['error' => get_string('admin_builder_err_cm', 'local_launchpad')];
        }

        set_coursemodule_visible($cmid, $show ? 1 : 0);
        rebuild_course_cache($trackcourseid, true);

        $mod = $DB->get_record('modules', ['id' => (int) $cm->module], '*', MUST_EXIST);
        $title = (string) $mod->name;
        if ($DB->get_manager()->table_exists($mod->name)) {
            $n = $DB->get_field($mod->name, 'name', ['id' => (int) $cm->instance]);
            if ($n) {
                $title = format_string((string) $n);
            }
        }

        return [
            'ok' => $show
                ? get_string('admin_manage_ok_cm_shown', 'local_launchpad', $title)
                : get_string('admin_manage_ok_cm_hidden', 'local_launchpad', $title),
        ];
    }

    /**
     * Delete a LaunchPad track activity (full course module) via Moodle.
     *
     * @return array{ok:string}|array{error:string}
     */
    public static function delete_activity(\stdClass $actor, int $trackcourseid, int $cmid): array {
        global $CFG, $DB;

        unset($actor);
        require_once($CFG->dirroot . '/course/lib.php');

        if ($trackcourseid <= 0 || $cmid <= 0) {
            return ['error' => get_string('admin_builder_err_params', 'local_launchpad')];
        }
        try {
            $course = account_provision::require_launchpad_course($trackcourseid);
        } catch (account_provision_exception $e) {
            return ['error' => self::flash_error_message($e)];
        }
        if (!self::is_track_idnumber((string) $course->idnumber)) {
            return ['error' => get_string('admin_mentors_err_not_track', 'local_launchpad')];
        }

        $context = \context_course::instance($trackcourseid);
        try {
            require_capability('moodle/course:manageactivities', $context);
        } catch (\required_capability_exception $e) {
            return ['error' => self::flash_error_message($e)];
        }

        $cm = get_coursemodule_from_id('', $cmid, $trackcourseid, false, IGNORE_MISSING);
        if (!$cm) {
            return ['error' => get_string('admin_builder_err_cm', 'local_launchpad')];
        }
        $modname = (string) $cm->modname;
        if (!in_array($modname, ['page', 'resource', 'quiz', 'assign'], true)) {
            return ['error' => get_string('admin_manage_err_mod', 'local_launchpad')];
        }

        $title = format_string((string) $cm->name);
        $instance = $DB->get_record($modname, ['id' => (int) $cm->instance], 'name', IGNORE_MISSING);
        if ($instance && !empty($instance->name)) {
            $title = format_string((string) $instance->name);
        }

        try {
            \core_courseformat\formatactions::cm($trackcourseid)->delete($cmid, false);
            rebuild_course_cache($trackcourseid, true);
        } catch (\Throwable $e) {
            return ['error' => self::flash_error_message($e)];
        }

        return [
            'ok' => get_string('admin_manage_ok_cm_deleted', 'local_launchpad', $title),
        ];
    }

    /**
     * Duplicate a LaunchPad track activity in the same phase (Moodle backup/restore copy).
     *
     * @return array{ok:string,cmid?:int}|array{error:string}
     */
    public static function duplicate_activity(\stdClass $actor, int $trackcourseid, int $cmid): array {
        global $CFG;

        unset($actor);
        require_once($CFG->dirroot . '/course/lib.php');

        $gate = self::require_track_activity($trackcourseid, $cmid, true);
        if (isset($gate['error'])) {
            return $gate;
        }
        ['course' => $course, 'cm' => $cm] = $gate;

        try {
            $newcm = \core_courseformat\formatactions::cm($course)->duplicate((int) $cm->id);
            rebuild_course_cache($trackcourseid, true);
        } catch (\Throwable $e) {
            return ['error' => self::flash_error_message($e)];
        }
        if (!$newcm) {
            return ['error' => get_string('admin_manage_err_duplicate', 'local_launchpad')];
        }

        return [
            'ok' => get_string('admin_manage_ok_cm_duplicated', 'local_launchpad', format_string($newcm->name)),
            'cmid' => (int) $newcm->id,
        ];
    }

    /**
     * Save a full activity order for a phase section (from Manage drag + Save).
     *
     * @param int[] $orderedcmids Desired cmids top→bottom; must match the section's current set.
     * @return array{ok:string}|array{error:string}
     */
    public static function reorder_section_activities(
        \stdClass $actor,
        int $trackcourseid,
        int $sectionnum,
        array $orderedcmids
    ): array {
        global $CFG, $DB;

        unset($actor);
        require_once($CFG->dirroot . '/course/lib.php');

        if ($trackcourseid <= 0 || $sectionnum < 1 || $sectionnum > 4) {
            return ['error' => get_string('admin_manage_err_reorder_params', 'local_launchpad')];
        }

        try {
            $course = account_provision::require_launchpad_course($trackcourseid);
        } catch (account_provision_exception $e) {
            return ['error' => self::flash_error_message($e)];
        }
        if (!self::is_track_idnumber((string) $course->idnumber)) {
            return ['error' => get_string('admin_mentors_err_not_track', 'local_launchpad')];
        }

        $context = \context_course::instance($trackcourseid);
        try {
            require_capability('moodle/course:manageactivities', $context);
        } catch (\required_capability_exception $e) {
            return ['error' => self::flash_error_message($e)];
        }

        $orderedcmids = array_values(array_filter(array_map('intval', $orderedcmids)));
        if ($orderedcmids === []) {
            return ['error' => get_string('admin_manage_err_reorder_empty', 'local_launchpad')];
        }
        if (count($orderedcmids) !== count(array_unique($orderedcmids))) {
            return ['error' => get_string('admin_manage_err_reorder_params', 'local_launchpad')];
        }

        $section = $DB->get_record('course_sections', [
            'course' => $trackcourseid,
            'section' => $sectionnum,
        ], '*', IGNORE_MISSING);
        if (!$section || (string) $section->sequence === '') {
            return ['error' => get_string('admin_builder_err_cm', 'local_launchpad')];
        }

        // Same membership as the Manage list (skip missing / deletion-in-progress cms).
        $listed = self::builder_activities_for_section($trackcourseid, $section, true);
        $current = array_map(static fn(array $a): int => (int) $a['cmid'], $listed);
        $currentsorted = $current;
        $orderedsorted = $orderedcmids;
        sort($currentsorted);
        sort($orderedsorted);
        if ($currentsorted !== $orderedsorted) {
            return ['error' => get_string('admin_manage_err_reorder_stale', 'local_launchpad')];
        }
        if ($current === $orderedcmids) {
            return ['ok' => get_string('admin_manage_ok_order_saved', 'local_launchpad')];
        }

        foreach ($orderedcmids as $cmid) {
            $cm = get_coursemodule_from_id('', $cmid, $trackcourseid, false, IGNORE_MISSING);
            if (!$cm || (int) $cm->section !== (int) $section->id) {
                return ['error' => get_string('admin_builder_err_cm', 'local_launchpad')];
            }
        }

        $actions = \core_courseformat\formatactions::cm($course);
        try {
            $last = $orderedcmids[count($orderedcmids) - 1];
            $actions->move_end_section($last, (int) $section->id);
            for ($i = count($orderedcmids) - 2; $i >= 0; $i--) {
                $actions->move_before($orderedcmids[$i], $orderedcmids[$i + 1]);
            }
            rebuild_course_cache($trackcourseid, true);
        } catch (\Throwable $e) {
            return ['error' => self::flash_error_message($e)];
        }

        return ['ok' => get_string('admin_manage_ok_order_saved', 'local_launchpad')];
    }

    /**
     * Rename an activity from the Manage list (instance name only).
     *
     * @return array{ok:string}|array{error:string}
     */
    public static function rename_activity(
        \stdClass $actor,
        int $trackcourseid,
        int $cmid,
        string $name
    ): array {
        global $CFG;

        unset($actor);
        require_once($CFG->dirroot . '/course/lib.php');

        $gate = self::require_track_activity($trackcourseid, $cmid, true);
        if (isset($gate['error'])) {
            return $gate;
        }
        ['course' => $course, 'cm' => $cm] = $gate;

        $name = trim($name);
        if ($name === '') {
            return ['error' => get_string('admin_activity_err_name', 'local_launchpad')];
        }

        try {
            $ok = \core_courseformat\formatactions::cm($course)->rename($cmid, $name);
            rebuild_course_cache($trackcourseid, true);
        } catch (\Throwable $e) {
            return ['error' => self::flash_error_message($e)];
        }
        if (!$ok) {
            // Same name — treat as success (no-op).
            return [
                'ok' => get_string('admin_manage_ok_cm_renamed', 'local_launchpad', format_string($name)),
            ];
        }

        return [
            'ok' => get_string('admin_manage_ok_cm_renamed', 'local_launchpad', format_string($name)),
        ];
    }

    /**
     * Shared gate for Manage activity mutations.
     *
     * @return array{course:\stdClass,cm:\stdClass}|array{error:string}
     */
    private static function require_track_activity(
        int $trackcourseid,
        int $cmid,
        bool $needmanage
    ): array {
        if ($trackcourseid <= 0 || $cmid <= 0) {
            return ['error' => get_string('admin_builder_err_params', 'local_launchpad')];
        }
        try {
            $course = account_provision::require_launchpad_course($trackcourseid);
        } catch (account_provision_exception $e) {
            return ['error' => self::flash_error_message($e)];
        }
        if (!self::is_track_idnumber((string) $course->idnumber)) {
            return ['error' => get_string('admin_mentors_err_not_track', 'local_launchpad')];
        }

        $context = \context_course::instance($trackcourseid);
        try {
            if ($needmanage) {
                require_capability('moodle/course:manageactivities', $context);
            }
        } catch (\required_capability_exception $e) {
            return ['error' => self::flash_error_message($e)];
        }

        $cm = get_coursemodule_from_id('', $cmid, $trackcourseid, false, IGNORE_MISSING);
        if (!$cm) {
            return ['error' => get_string('admin_builder_err_cm', 'local_launchpad')];
        }
        $modname = (string) $cm->modname;
        if (!in_array($modname, ['page', 'resource', 'quiz', 'assign'], true)) {
            return ['error' => get_string('admin_manage_err_mod', 'local_launchpad')];
        }

        return ['course' => $course, 'cm' => $cm];
    }

    /**
     * Slim activity settings screen in the admin shell.
     *
     * @param array{ok?:string,error?:string} $flash
     * @return array<string, mixed>
     */
    public static function activity_settings_context(
        \stdClass $user,
        int $trackid,
        int $cmid,
        array $flash = []
    ): array {
        global $CFG, $DB;

        unset($user);
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->libdir . '/completionlib.php');

        $manageurl = (new \moodle_url(persona_home::ADMIN_PATH, array_filter([
            'view' => 'manage',
            'trackid' => $trackid > 0 ? $trackid : null,
        ])))->out(false);

        $activityempty = static function (string $body, array $flash = []) use ($manageurl): array {
            return array_merge([
                'has_activity' => false,
                'manage_url' => $manageurl,
                'back_label' => get_string('admin_activity_back', 'local_launchpad'),
                'flash_ok' => !empty($flash['ok']),
                'flash_ok_body' => (string) ($flash['ok'] ?? ''),
                'flash_err' => !empty($flash['error']),
                'flash_err_body' => (string) ($flash['error'] ?? ''),
                'empty_mark' => 'AD',
                'empty_title' => get_string('admin_empty_title_no_activity', 'local_launchpad'),
                'empty_body' => $body,
                'has_empty_cta' => true,
                'empty_cta_url' => $manageurl,
                'empty_cta_label' => get_string('admin_activity_back', 'local_launchpad'),
            ]);
        };

        if ($trackid <= 0 || $cmid <= 0) {
            return $activityempty(get_string('admin_builder_err_params', 'local_launchpad'), $flash);
        }

        try {
            $course = account_provision::require_launchpad_course($trackid);
        } catch (account_provision_exception $e) {
            // Empty block carries the message — avoid duplicate flash.
            return $activityempty(self::flash_error_message($e));
        }

        $cm = get_coursemodule_from_id('', $cmid, $trackid, false, IGNORE_MISSING);
        if (!$cm) {
            return $activityempty(get_string('admin_builder_err_cm', 'local_launchpad'), $flash);
        }

        $sectionnum = 1;
        $section = $DB->get_record('course_sections', ['id' => (int) $cm->section], 'section');
        if ($section) {
            $sectionnum = max(1, (int) $section->section);
        }
        $manageurl = (new \moodle_url(persona_home::ADMIN_PATH, [
            'view' => 'manage',
            'trackid' => $trackid,
            'section' => $sectionnum,
        ]))->out(false);

        $modname = (string) $cm->modname;
        $allowed = ['page', 'resource', 'quiz', 'assign'];
        if (!in_array($modname, $allowed, true)) {
            return [
                'has_activity' => false,
                'manage_url' => $manageurl,
                'back_label' => get_string('admin_activity_back', 'local_launchpad'),
                'flash_ok' => false,
                'flash_ok_body' => '',
                'flash_err' => false,
                'flash_err_body' => '',
                'empty_mark' => 'AD',
                'empty_title' => get_string('admin_empty_title_no_activity', 'local_launchpad'),
                'empty_body' => get_string('admin_manage_err_mod', 'local_launchpad'),
                'has_empty_cta' => true,
                'empty_cta_url' => $manageurl,
                'empty_cta_label' => get_string('admin_activity_back', 'local_launchpad'),
            ];
        }

        $instance = $DB->get_record($modname, ['id' => (int) $cm->instance], '*', MUST_EXIST);
        $intro = '';
        if (property_exists($instance, 'intro')) {
            $intro = html_to_text((string) ($instance->intro ?? ''), 0, false);
        }
        $content = '';
        if ($modname === 'page') {
            $content = (string) ($instance->content ?? '');
            // Keep simple HTML for textarea editing.
            $content = trim($content);
        }

        $grade = '';
        if (property_exists($instance, 'grade') && $instance->grade !== null) {
            $grade = (string) (float) $instance->grade;
        }

        $filesummary = [
            'has_file' => false,
            'file_count' => 0,
            'file_status_label' => '',
            'file_names' => [],
            'maxbytes' => 0,
            'maxbytes_display' => '',
        ];
        if ($modname === 'resource') {
            $filesummary = self::resource_files_editor_context($course, $cmid);
        }
        $filelist = $filesummary['file_cards'] ?? [];

        return [
            'has_activity' => true,
            'eyebrow' => get_string('admin_activity_eyebrow', 'local_launchpad'),
            'heading' => format_string((string) $instance->name),
            'lead' => get_string('admin_activity_lead', 'local_launchpad', self::builder_mod_kind($modname)),
            'kind' => self::builder_mod_kind($modname),
            'modname' => $modname,
            'is_page' => $modname === 'page',
            'is_resource' => $modname === 'resource',
            'is_quiz' => $modname === 'quiz',
            'is_assign' => $modname === 'assign',
            'has_grade' => $modname === 'quiz' || $modname === 'assign',
            'name' => (string) $instance->name,
            'intro' => $intro,
            'content' => $content,
            'grade' => $grade,
            'track_completion' => ((int) $cm->completion !== COMPLETION_TRACKING_NONE),
            'name_label' => get_string('admin_activity_name', 'local_launchpad'),
            'intro_label' => get_string('admin_activity_intro', 'local_launchpad'),
            'content_label' => get_string('admin_activity_content', 'local_launchpad'),
            'grade_label' => get_string('admin_activity_grade', 'local_launchpad'),
            'track_completion_label' => get_string('admin_activity_track_completion', 'local_launchpad'),
            'track_completion_hint' => get_string('admin_activity_track_completion_hint', 'local_launchpad'),
            'files_label' => get_string('admin_activity_files', 'local_launchpad'),
            'file_hint' => get_string('admin_activity_file_hint', 'local_launchpad'),
            'file_replace_hint' => get_string('admin_activity_file_replace_hint', 'local_launchpad'),
            'file_empty_warn' => get_string('admin_activity_file_empty_warn', 'local_launchpad'),
            'has_file' => !empty($filesummary['has_file']),
            'file_status_label' => (string) ($filesummary['file_status_label'] ?? ''),
            'file_list' => $filelist,
            'has_file_list' => $filelist !== [],
            'choose_files_label' => (string) ($filesummary['choose_files_label']
                ?? get_string('admin_activity_choose_files', 'local_launchpad')),
            'picker_empty_label' => (string) ($filesummary['picker_empty_label']
                ?? get_string('admin_activity_picker_empty', 'local_launchpad')),
            'remove_file_label' => (string) ($filesummary['remove_file_label']
                ?? get_string('admin_activity_remove_file', 'local_launchpad')),
            'pending_heading' => (string) ($filesummary['pending_heading']
                ?? get_string('admin_activity_pending_heading', 'local_launchpad')),
            'maxbytes_display' => (string) ($filesummary['maxbytes_display'] ?? ''),
            'has_maxbytes' => !empty($filesummary['maxbytes_display']),
            'form_enctype_multipart' => $modname === 'resource',
            'quiz_hint' => get_string('admin_activity_quiz_hint', 'local_launchpad'),
            'assign_hint' => get_string('admin_activity_assign_hint', 'local_launchpad'),
            'save_label' => get_string('admin_activity_save', 'local_launchpad'),
            'cancel_label' => get_string('admin_activity_cancel', 'local_launchpad'),
            'delete_label' => get_string('admin_manage_delete_activity', 'local_launchpad'),
            'delete_confirm' => get_string('admin_manage_delete_confirm', 'local_launchpad'),
            'delete_form_action' => (new \moodle_url(persona_home::ADMIN_PATH, [
                'view' => 'manage',
                'trackid' => $trackid,
                'section' => $sectionnum,
            ]))->out(false),
            'advanced_url' => (new \moodle_url('/course/modedit.php', [
                'update' => $cmid,
                'return' => 1,
            ]))->out(false),
            'advanced_label' => get_string('admin_activity_advanced', 'local_launchpad'),
            'manage_url' => $manageurl,
            'back_label' => get_string('admin_activity_back', 'local_launchpad'),
            'form_action' => (new \moodle_url(persona_home::ADMIN_PATH, [
                'view' => 'activity',
                'trackid' => $trackid,
                'cmid' => $cmid,
            ]))->out(false),
            'sesskey' => sesskey(),
            'trackid' => $trackid,
            'cmid' => $cmid,
            'sectionnum' => $sectionnum,
            'flash_ok' => !empty($flash['ok']),
            'flash_ok_body' => (string) ($flash['ok'] ?? ''),
            'flash_err' => !empty($flash['error']),
            'flash_err_body' => (string) ($flash['error'] ?? ''),
        ];
    }

    /**
     * Save slim activity settings (name / intro / page content / grade / file draft).
     * Uses direct instance updates so we skip Moodle editor draft plumbing for text.
     * File activities accept optional multipart uploads into mod_resource content.
     *
     * @param array|null $uploadfiles Optional $_FILES['resourcefiles'] payload
     * @param list<string> $removefiles Filenames to delete from an existing resource (ignored if upload replaces all)
     * @return array{ok:string}|array{error:string}
     */
    public static function save_activity_settings(
        \stdClass $actor,
        int $trackcourseid,
        int $cmid,
        string $name,
        string $intro,
        string $content = '',
        ?float $grade = null,
        ?bool $trackcompletion = null,
        ?array $uploadfiles = null,
        array $removefiles = []
    ): array {
        global $CFG, $DB;

        unset($actor);
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->libdir . '/completionlib.php');
        require_once($CFG->libdir . '/filelib.php');

        if ($trackcourseid <= 0 || $cmid <= 0) {
            return ['error' => get_string('admin_builder_err_params', 'local_launchpad')];
        }
        try {
            $course = account_provision::require_launchpad_course($trackcourseid);
        } catch (account_provision_exception $e) {
            return ['error' => self::flash_error_message($e)];
        }
        if (!self::is_track_idnumber((string) $course->idnumber)) {
            return ['error' => get_string('admin_mentors_err_not_track', 'local_launchpad')];
        }

        $cm = get_coursemodule_from_id('', $cmid, $trackcourseid, false, IGNORE_MISSING);
        if (!$cm) {
            return ['error' => get_string('admin_builder_err_cm', 'local_launchpad')];
        }
        $modname = (string) $cm->modname;
        if (!in_array($modname, ['page', 'resource', 'quiz', 'assign'], true)) {
            return ['error' => get_string('admin_manage_err_mod', 'local_launchpad')];
        }

        $context = \context_course::instance($trackcourseid);
        try {
            require_capability('moodle/course:manageactivities', $context);
        } catch (\required_capability_exception $e) {
            return ['error' => self::flash_error_message($e)];
        }

        $name = trim($name);
        if ($name === '') {
            return ['error' => get_string('admin_activity_err_name', 'local_launchpad')];
        }

        $instance = $DB->get_record($modname, ['id' => (int) $cm->instance], '*', IGNORE_MISSING);
        if (!$instance) {
            return ['error' => get_string('admin_builder_err_cm', 'local_launchpad')];
        }

        $introhtml = trim($intro) === ''
            ? ''
            : purify_html(nl2br(s($intro)));

        $update = (object) [
            'id' => (int) $instance->id,
            'name' => $name,
            'timemodified' => time(),
        ];
        if (property_exists($instance, 'intro')) {
            $update->intro = $introhtml;
            $update->introformat = FORMAT_HTML;
        }
        if ($modname === 'page') {
            $update->content = purify_html($content);
            $update->contentformat = FORMAT_HTML;
        }
        if (($modname === 'quiz' || $modname === 'assign') && $grade !== null
                && property_exists($instance, 'grade')) {
            $update->grade = max(0, $grade);
        }

        try {
            $DB->update_record($modname, $update);
            if ($trackcompletion !== null) {
                if ($trackcompletion && empty($course->enablecompletion)) {
                    $DB->set_field('course', 'enablecompletion', 1, ['id' => $trackcourseid]);
                }
                self::set_cm_completion_tracking($cm, $modname, $trackcompletion);
            }
            if ($modname === 'resource') {
                $uploaded = false;
                if (is_array($uploadfiles)) {
                    $fileresult = self::save_resource_files_from_upload(
                        $course,
                        $cmid,
                        (int) $instance->id,
                        $uploadfiles
                    );
                    if (!empty($fileresult['error'])) {
                        return ['error' => (string) $fileresult['error']];
                    }
                    $uploaded = empty($fileresult['skipped']);
                }
                // New upload replaces the whole area; only apply removals when not replacing.
                if (!$uploaded && $removefiles !== []) {
                    self::remove_resource_files_by_name($cmid, (int) $instance->id, $removefiles);
                }
            }
            rebuild_course_cache($trackcourseid, true);
        } catch (\Throwable $e) {
            return ['error' => self::flash_error_message($e)];
        }

        $ok = get_string('admin_activity_ok_saved', 'local_launchpad', format_string($name));
        if ($modname === 'resource') {
            $summary = self::resource_files_summary($cmid);
            if (empty($summary['has_file'])) {
                $ok .= ' ' . get_string('admin_activity_file_empty_warn', 'local_launchpad');
            }
        }

        return ['ok' => $ok];
    }

    /**
     * File picker options for LaunchPad File (mod_resource) settings.
     *
     * @return array<string,mixed>
     */
    public static function resource_file_options(\stdClass $course): array {
        global $CFG;

        require_once($CFG->libdir . '/filelib.php');

        $coursebytes = (int) ($course->maxbytes ?? 0);
        $maxbytes = get_user_max_upload_file_size(
            \context_course::instance((int) $course->id),
            $CFG->maxbytes,
            $coursebytes
        );

        return [
            'subdirs' => 0,
            'maxbytes' => $maxbytes,
            'maxfiles' => -1,
            'accepted_types' => '*',
        ];
    }

    /**
     * Attached files for a resource CM (non-directories).
     *
     * @return list<\stored_file>
     */
    public static function resource_stored_files(int $cmid): array {
        if ($cmid <= 0) {
            return [];
        }
        try {
            $context = \context_module::instance($cmid);
        } catch (\Throwable $e) {
            return [];
        }
        $fs = get_file_storage();
        $stored = $fs->get_area_files($context->id, 'mod_resource', 'content', 0, 'sortorder DESC, id ASC', false);
        $out = [];
        foreach ($stored as $file) {
            if ($file->is_directory()) {
                continue;
            }
            $out[] = $file;
        }
        return $out;
    }

    /**
     * Short status for manage list / settings banner (+ card rows for settings UI).
     *
     * @return array{
     *   has_file:bool,
     *   file_count:int,
     *   file_status_label:string,
     *   file_names:list<string>,
     *   file_cards:list<array<string,mixed>>
     * }
     */
    public static function resource_files_summary(int $cmid): array {
        $files = self::resource_stored_files($cmid);
        $names = [];
        $cards = [];
        $modcontext = null;
        try {
            $modcontext = \context_module::instance($cmid);
        } catch (\Throwable $e) {
            $modcontext = null;
        }
        foreach ($files as $file) {
            $names[] = $file->get_filename();
            if ($modcontext) {
                $card = activity_portal::describe_resource_file($file, $modcontext);
                $preview = '';
                if (!empty($card['isimage'])) {
                    $preview = \moodle_url::make_pluginfile_url(
                        $modcontext->id,
                        'mod_resource',
                        'content',
                        $file->get_itemid(),
                        $file->get_filepath(),
                        $file->get_filename(),
                        false
                    )->out(false);
                }
                $cards[] = array_merge($card, [
                    'previewurl' => $preview,
                    'has_preview' => $preview !== '',
                ]);
            }
        }
        $count = count($names);
        if ($count === 0) {
            $label = get_string('admin_activity_file_status_empty', 'local_launchpad');
        } else if ($count === 1) {
            $friendly = $cards[0]['displayname'] ?? $names[0];
            $label = get_string('admin_activity_file_status_one', 'local_launchpad', $friendly);
        } else {
            $label = get_string('admin_activity_file_status_many', 'local_launchpad', $count);
        }
        return [
            'has_file' => $count > 0,
            'file_count' => $count,
            'file_status_label' => $label,
            'file_names' => $names,
            'file_cards' => $cards,
        ];
    }

    /**
     * Current file status for File activity settings (native upload input — admin shell has no Moodle filemanager JS).
     *
     * @return array<string,mixed>
     */
    public static function resource_files_editor_context(\stdClass $course, int $cmid): array {
        $summary = self::resource_files_summary($cmid);
        $options = self::resource_file_options($course);
        $maxbytes = (int) ($options['maxbytes'] ?? 0);
        return array_merge($summary, [
            'maxbytes' => $maxbytes,
            'maxbytes_display' => $maxbytes > 0 ? display_size($maxbytes) : '',
            'choose_files_label' => get_string('admin_activity_choose_files', 'local_launchpad'),
            'picker_empty_label' => get_string('admin_activity_picker_empty', 'local_launchpad'),
            'remove_file_label' => get_string('admin_activity_remove_file', 'local_launchpad'),
            'pending_heading' => get_string('admin_activity_pending_heading', 'local_launchpad'),
        ]);
    }

    /**
     * Delete named files from a resource CM content area and bump revision.
     *
     * @param list<string> $filenames
     */
    public static function remove_resource_files_by_name(int $cmid, int $resourceid, array $filenames): void {
        global $DB;

        $want = [];
        foreach ($filenames as $name) {
            $clean = clean_param((string) $name, PARAM_FILE);
            if ($clean !== '') {
                $want[$clean] = true;
            }
        }
        if ($want === [] || $cmid <= 0 || $resourceid <= 0) {
            return;
        }

        $deleted = 0;
        foreach (self::resource_stored_files($cmid) as $file) {
            if (isset($want[$file->get_filename()])) {
                $file->delete();
                $deleted++;
            }
        }
        if ($deleted <= 0) {
            return;
        }

        $revision = (int) $DB->get_field('resource', 'revision', ['id' => $resourceid]);
        $DB->update_record('resource', (object) [
            'id' => $resourceid,
            'revision' => $revision + 1,
            'timemodified' => time(),
        ]);
    }

    /**
     * Store uploaded $_FILES onto the resource CM (replaces existing content files) and bump revision.
     *
     * @param array $fileinfo PHP $_FILES['resourcefiles'] shape (name/tmp_name/error/size arrays or scalars)
     * @return array{ok:true,count:int}|array{error:string}|array{skipped:true}
     */
    public static function save_resource_files_from_upload(
        \stdClass $course,
        int $cmid,
        int $resourceid,
        array $fileinfo
    ): array {
        global $CFG, $DB, $USER;

        require_once($CFG->libdir . '/filelib.php');

        if ($cmid <= 0 || $resourceid <= 0) {
            return ['skipped' => true];
        }

        $names = $fileinfo['name'] ?? null;
        if ($names === null) {
            return ['skipped' => true];
        }
        // Normalise single-file uploads to arrays.
        if (!is_array($names)) {
            $fileinfo = [
                'name' => [$fileinfo['name'] ?? ''],
                'type' => [$fileinfo['type'] ?? ''],
                'tmp_name' => [$fileinfo['tmp_name'] ?? ''],
                'error' => [$fileinfo['error'] ?? UPLOAD_ERR_NO_FILE],
                'size' => [$fileinfo['size'] ?? 0],
            ];
        }

        $pending = [];
        $count = count($fileinfo['name']);
        for ($i = 0; $i < $count; $i++) {
            $err = (int) ($fileinfo['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            if ($err === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($err !== UPLOAD_ERR_OK) {
                return ['error' => get_string('admin_activity_file_err_upload', 'local_launchpad')];
            }
            $tmp = (string) ($fileinfo['tmp_name'][$i] ?? '');
            $filename = clean_param((string) ($fileinfo['name'][$i] ?? ''), PARAM_FILE);
            if ($tmp === '' || !is_uploaded_file($tmp) || $filename === '') {
                return ['error' => get_string('admin_activity_file_err_upload', 'local_launchpad')];
            }
            $options = self::resource_file_options($course);
            $maxbytes = (int) ($options['maxbytes'] ?? 0);
            $size = (int) ($fileinfo['size'][$i] ?? 0);
            if ($maxbytes > 0 && $size > $maxbytes) {
                return ['error' => get_string('admin_activity_file_err_size', 'local_launchpad', display_size($maxbytes))];
            }
            $pending[] = [
                'tmp' => $tmp,
                'filename' => $filename,
            ];
        }
        if ($pending === []) {
            return ['skipped' => true];
        }

        $modcontext = \context_module::instance($cmid);
        $fs = get_file_storage();
        $fs->delete_area_files($modcontext->id, 'mod_resource', 'content');

        $sort = 1;
        foreach ($pending as $item) {
            $record = [
                'contextid' => $modcontext->id,
                'component' => 'mod_resource',
                'filearea' => 'content',
                'itemid' => 0,
                'filepath' => '/',
                'filename' => $item['filename'],
                'userid' => (int) $USER->id,
                'source' => $item['filename'],
            ];
            $stored = $fs->create_file_from_pathname($record, $item['tmp']);
            if (count($pending) === 1) {
                file_set_sortorder(
                    $modcontext->id,
                    'mod_resource',
                    'content',
                    0,
                    $stored->get_filepath(),
                    $stored->get_filename(),
                    1
                );
            } else {
                file_set_sortorder(
                    $modcontext->id,
                    'mod_resource',
                    'content',
                    0,
                    $stored->get_filepath(),
                    $stored->get_filename(),
                    $sort
                );
                $sort++;
            }
        }

        $revision = (int) $DB->get_field('resource', 'revision', ['id' => $resourceid]);
        $DB->update_record('resource', (object) [
            'id' => $resourceid,
            'revision' => $revision + 1,
            'timemodified' => time(),
        ]);

        return ['ok' => true, 'count' => count($pending)];
    }

    /**
     * Apply LaunchPad default completion rules (or clear them) on a course module.
     */
    public static function set_cm_completion_tracking(
        \stdClass $cm,
        string $modname,
        bool $enable
    ): void {
        global $CFG, $DB;

        require_once($CFG->libdir . '/completionlib.php');

        $cmid = (int) $cm->id;
        $instanceid = (int) $cm->instance;

        if (!$enable) {
            $DB->update_record('course_modules', (object) [
                'id' => $cmid,
                'completion' => COMPLETION_TRACKING_NONE,
                'completionview' => COMPLETION_VIEW_NOT_REQUIRED,
                'completionexpected' => 0,
                'completiongradeitemnumber' => null,
                'completionpassgrade' => 0,
            ]);
            if ($modname === 'assign') {
                $DB->set_field('assign', 'completionsubmit', 0, ['id' => $instanceid]);
            } else if ($modname === 'quiz') {
                $DB->set_field('quiz', 'completionminattempts', 0, ['id' => $instanceid]);
                $DB->set_field('quiz', 'completionattemptsexhausted', 0, ['id' => $instanceid]);
            }
            return;
        }

        $cmupdate = (object) [
            'id' => $cmid,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionview' => COMPLETION_VIEW_NOT_REQUIRED,
            'completionexpected' => 0,
            'completiongradeitemnumber' => null,
            'completionpassgrade' => 0,
        ];

        if (in_array($modname, ['page', 'resource', 'url'], true)) {
            $cmupdate->completionview = COMPLETION_VIEW_REQUIRED;
        } else if ($modname === 'assign') {
            $DB->set_field('assign', 'completionsubmit', 1, ['id' => $instanceid]);
        } else if ($modname === 'quiz') {
            $cmupdate->completiongradeitemnumber = 0;
            $DB->set_field('quiz', 'completionminattempts', 1, ['id' => $instanceid]);
        }

        $DB->update_record('course_modules', $cmupdate);
    }

    /**
     * Minimal moduleinfo for create_module (LaunchPad-allowed types).
     */
    public static function build_new_moduleinfo(
        \stdClass $course,
        int $sectionnum,
        string $modname,
        string $name
    ): \stdClass {
        global $CFG;

        require_once($CFG->libdir . '/resourcelib.php');
        require_once($CFG->libdir . '/completionlib.php');

        $moduleinfo = new \stdClass();
        $moduleinfo->modulename = $modname;
        $moduleinfo->course = (int) $course->id;
        $moduleinfo->section = $sectionnum;
        // Start visible inside section; section visibility still gates fellows.
        $moduleinfo->visible = 1;
        $moduleinfo->visibleoncoursepage = 1;
        $moduleinfo->cmidnumber = '';
        $moduleinfo->name = $name;
        $moduleinfo->introeditor = [
            'text' => '<p>' . get_string('admin_manage_new_intro', 'local_launchpad') . '</p>',
            'format' => FORMAT_HTML,
            'itemid' => 0,
        ];
        // Default: track completion (LaunchPad Course builder).
        $moduleinfo->completion = COMPLETION_TRACKING_AUTOMATIC;
        $moduleinfo->completionview = COMPLETION_VIEW_NOT_REQUIRED;
        $moduleinfo->completionexpected = 0;
        $moduleinfo->completionpassgrade = 0;
        $moduleinfo->completiongradeitemnumber = null;

        switch ($modname) {
            case 'page':
                // Structured lesson template (E7 markers) — edit sections in Settings.
                $moduleinfo->content = get_string('admin_manage_new_page_body', 'local_launchpad');
                $moduleinfo->contentformat = FORMAT_HTML;
                $moduleinfo->display = RESOURCELIB_DISPLAY_OPEN;
                $moduleinfo->printintro = 0;
                $moduleinfo->printlastmodified = 0;
                $moduleinfo->completionview = COMPLETION_VIEW_REQUIRED;
                break;
            case 'url':
                $moduleinfo->externalurl = '';
                $moduleinfo->display = RESOURCELIB_DISPLAY_NEW;
                $moduleinfo->completionview = COMPLETION_VIEW_REQUIRED;
                break;
            case 'resource':
                // Empty file resource — upload in Settings / Advanced Moodle for now.
                $moduleinfo->display = RESOURCELIB_DISPLAY_AUTO;
                $moduleinfo->showsize = 0;
                $moduleinfo->showtype = 0;
                $moduleinfo->showdate = 0;
                $moduleinfo->filterfiles = 0;
                $moduleinfo->files = file_get_unused_draft_itemid();
                $moduleinfo->completionview = COMPLETION_VIEW_REQUIRED;
                break;
            case 'quiz':
                require_once($CFG->dirroot . '/mod/quiz/locallib.php');
                $moduleinfo->introeditor = [
                    'text' => get_string('admin_manage_new_quiz_intro', 'local_launchpad'),
                    'format' => FORMAT_HTML,
                    'itemid' => 0,
                ];
                $moduleinfo->timeopen = 0;
                $moduleinfo->timeclose = 0;
                $moduleinfo->timelimit = 0;
                $moduleinfo->overduehandling = 'autosubmit';
                $moduleinfo->graceperiod = 0;
                $moduleinfo->preferredbehaviour = 'deferredfeedback';
                $moduleinfo->canredoquestions = 0;
                $moduleinfo->attempts = 0;
                $moduleinfo->attemptonlast = 0;
                $moduleinfo->grademethod = QUIZ_GRADEHIGHEST;
                $moduleinfo->decimalpoints = 2;
                $moduleinfo->questiondecimalpoints = -1;
                $moduleinfo->reviewattempt = 0;
                $moduleinfo->reviewcorrectness = 0;
                $moduleinfo->reviewmaxmarks = 0;
                $moduleinfo->reviewmarks = 0;
                $moduleinfo->reviewspecificfeedback = 0;
                $moduleinfo->reviewgeneralfeedback = 0;
                $moduleinfo->reviewrightanswer = 0;
                $moduleinfo->reviewoverallfeedback = 0;
                $moduleinfo->questionsperpage = 1;
                $moduleinfo->navmethod = 'free';
                $moduleinfo->shuffleanswers = 1;
                $moduleinfo->sumgrades = 0;
                $moduleinfo->grade = 100;
                $moduleinfo->timecreated = time();
                $moduleinfo->timemodified = time();
                $moduleinfo->quizpassword = '';
                $moduleinfo->subnet = '';
                $moduleinfo->browsersecurity = '-';
                $moduleinfo->delay1 = 0;
                $moduleinfo->delay2 = 0;
                $moduleinfo->showuserpicture = 0;
                $moduleinfo->showblocks = 0;
                $moduleinfo->completionattemptsexhausted = 0;
                $moduleinfo->completionminattempts = 1;
                $moduleinfo->completionusegrade = 1;
                $moduleinfo->completiongradeitemnumber = 0;
                $moduleinfo->allowofflineattempts = 0;
                break;
            case 'assign':
                $moduleinfo->introeditor = [
                    'text' => get_string('admin_manage_new_assign_intro', 'local_launchpad'),
                    'format' => FORMAT_HTML,
                    'itemid' => 0,
                ];
                $moduleinfo->alwaysshowdescription = 1;
                $moduleinfo->submissiondrafts = 1;
                $moduleinfo->requiresubmissionstatement = 0;
                $moduleinfo->sendnotifications = 0;
                $moduleinfo->sendlatenotifications = 0;
                $moduleinfo->sendstudentnotifications = 1;
                $moduleinfo->duedate = 0;
                $moduleinfo->cutoffdate = 0;
                $moduleinfo->gradingduedate = 0;
                $moduleinfo->allowsubmissionsfromdate = 0;
                $moduleinfo->grade = 100;
                $moduleinfo->teamsubmission = 0;
                $moduleinfo->requireallteammemberssubmit = 0;
                $moduleinfo->teamsubmissiongroupingid = 0;
                $moduleinfo->blindmarking = 0;
                $moduleinfo->hidegrader = 0;
                $moduleinfo->attemptreopenmethod = 'untilpass';
                $moduleinfo->maxattempts = 1;
                $moduleinfo->markingworkflow = 0;
                $moduleinfo->markingallocation = 0;
                $moduleinfo->markinganonymous = 0;
                $moduleinfo->preventsubmissionnotingroup = 0;
                $moduleinfo->timelimit = 0;
                $moduleinfo->submissionattachments = 0;
                $moduleinfo->gradepenalty = 0;
                $moduleinfo->assignsubmission_onlinetext_enabled = 1;
                $moduleinfo->assignsubmission_file_enabled = 1;
                $moduleinfo->assignsubmission_file_maxfiles = 20;
                $moduleinfo->assignsubmission_file_maxsizebytes = 0;
                $moduleinfo->completionsubmit = 1;
                break;
            default:
                throw new \coding_exception('Unsupported module: ' . $modname);
        }

        return $moduleinfo;
    }

    /**
     * Activity rows for one phase section.
     *
     * @param \stdClass|false|null $section
     * @return list<array<string, mixed>>
     */
    public static function builder_activities_for_section(
        int $courseid,
        $section,
        bool $sectionvisible
    ): array {
        global $DB;

        if (!$section || empty($section->sequence)) {
            return [];
        }
        $cmids = array_filter(array_map('intval', explode(',', $section->sequence)));
        if ($cmids === []) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED, 'cm');
        $params['del'] = 0;
        $params['course'] = $courseid;
        $sql = "SELECT cm.id, cm.visible, cm.instance, m.name AS modname
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                 WHERE cm.id {$insql}
                   AND cm.course = :course
                   AND cm.deletioninprogress = :del";
        $cms = $DB->get_records_sql($sql, $params);

        // Preserve section order.
        $ordered = [];
        foreach ($cmids as $cmid) {
            if (isset($cms[$cmid])) {
                $ordered[] = $cms[$cmid];
            }
        }

        $sectionnum = (int) ($section->section ?? 0);
        $out = [];
        foreach ($ordered as $cm) {
            $modname = (string) $cm->modname;
            $instancename = '';
            if ($DB->get_manager()->table_exists($modname)) {
                $instancename = (string) $DB->get_field($modname, 'name', ['id' => (int) $cm->instance]);
            }
            $isdraft = $instancename !== '' && stripos($instancename, self::PLACEHOLDER_MARKER) !== false;
            if ($isdraft) {
                $state = 'draft';
                $statechip = 'line';
            } else if ($sectionvisible) {
                $state = 'shown';
                $statechip = 'ok';
            } else {
                $state = 'ready';
                $statechip = '';
            }

            $cmid = (int) $cm->id;
            $cmvisible = (int) $cm->visible === 1;
            $isresource = $modname === 'resource';
            $hasfile = false;
            $filestatus = '';
            if ($isresource) {
                $filesummary = self::resource_files_summary($cmid);
                $hasfile = !empty($filesummary['has_file']);
                $filestatus = (string) $filesummary['file_status_label'];
            }
            $out[] = [
                'cmid' => $cmid,
                'modname' => $modname,
                'kind' => self::builder_mod_kind($modname),
                'title' => format_string($instancename !== '' ? $instancename : $modname),
                'name_raw' => $instancename !== '' ? $instancename : $modname,
                'state' => $state,
                'state_label' => get_string('admin_builder_state_' . $state, 'local_launchpad'),
                'state_chip' => $statechip,
                'is_draft' => $isdraft,
                'cm_visible' => $cmvisible,
                'cm_hidden' => !$cmvisible,
                'is_resource' => $isresource,
                'has_file' => $hasfile,
                'file_missing' => $isresource && !$hasfile,
                'file_status_label' => $filestatus,
                'manage_url' => (new \moodle_url(persona_home::ADMIN_PATH, [
                    'view' => 'manage',
                    'trackid' => $courseid,
                    'section' => max(1, $sectionnum),
                ]))->out(false) . '#cm-' . $cmid,
                'edit_url' => (new \moodle_url('/course/modedit.php', [
                    'update' => $cmid,
                    'return' => 1,
                ]))->out(false),
            ];
        }
        return $out;
    }

    /**
     * Short activity kind label for builder rows.
     */
    public static function builder_mod_kind(string $modname): string {
        return match ($modname) {
            'page' => get_string('admin_builder_kind_page', 'local_launchpad'),
            'resource' => get_string('admin_builder_kind_file', 'local_launchpad'),
            'quiz' => get_string('admin_builder_kind_quiz', 'local_launchpad'),
            'assign' => get_string('admin_builder_kind_assign', 'local_launchpad'),
            default => ucfirst($modname),
        };
    }

    /**
     * Whether guest enrolment is enabled on the course.
     */
    public static function guest_enrol_enabled(int $courseid): bool {
        global $DB, $CFG;

        if ($courseid <= 0) {
            return false;
        }
        require_once($CFG->libdir . '/enrollib.php');
        return $DB->record_exists('enrol', [
            'courseid' => $courseid,
            'enrol' => 'guest',
            'status' => ENROL_INSTANCE_ENABLED,
        ]);
    }

    /**
     * Strip placeholder marker from an activity name (Mark reviewed).
     *
     * @return array{ok:string}|array{error:string}
     */
    public static function mark_activity_reviewed(\stdClass $actor, int $trackcourseid, int $cmid): array {
        global $CFG, $DB;

        unset($actor);
        if ($trackcourseid <= 0 || $cmid <= 0) {
            return ['error' => get_string('admin_builder_err_params', 'local_launchpad')];
        }
        try {
            $course = account_provision::require_launchpad_course($trackcourseid);
        } catch (account_provision_exception $e) {
            return ['error' => self::flash_error_message($e)];
        }
        if (!self::is_track_idnumber((string) $course->idnumber)) {
            return ['error' => get_string('admin_mentors_err_not_track', 'local_launchpad')];
        }

        $context = \context_course::instance($trackcourseid);
        try {
            require_capability('moodle/course:manageactivities', $context);
        } catch (\required_capability_exception $e) {
            return ['error' => self::flash_error_message($e)];
        }

        $cm = $DB->get_record('course_modules', ['id' => $cmid, 'course' => $trackcourseid], '*', IGNORE_MISSING);
        if (!$cm) {
            return ['error' => get_string('admin_builder_err_cm', 'local_launchpad')];
        }
        $mod = $DB->get_record('modules', ['id' => (int) $cm->module], '*', MUST_EXIST);
        $modname = (string) $mod->name;
        if (!$DB->get_manager()->table_exists($modname)) {
            return ['error' => get_string('admin_builder_err_cm', 'local_launchpad')];
        }
        $instance = $DB->get_record($modname, ['id' => (int) $cm->instance], '*', MUST_EXIST);
        $oldname = (string) ($instance->name ?? '');
        $newname = trim(preg_replace('/\s*\[[^\]]*placeholder[^\]]*\]\s*/i', ' ', $oldname) ?? $oldname);
        $newname = trim(preg_replace('/\s+/', ' ', $newname) ?? $newname);
        if ($newname === '') {
            $newname = get_string('admin_builder_untitled', 'local_launchpad');
        }

        require_once($CFG->dirroot . '/course/lib.php');
        $DB->set_field($modname, 'name', $newname, ['id' => (int) $instance->id]);
        // Placeholders start hidden; reviewed content should be visible inside its section.
        if ((int) $cm->visible !== 1) {
            set_coursemodule_visible($cmid, 1);
        }
        rebuild_course_cache($trackcourseid, true);

        return [
            'ok' => get_string('admin_builder_ok_reviewed', 'local_launchpad', format_string($newname)),
        ];
    }

    /**
     * Show or hide a phase section (never deletes).
     *
     * @return array{ok:string}|array{error:string}
     */
    public static function set_phase_visible(
        \stdClass $actor,
        int $trackcourseid,
        int $sectionnum,
        bool $show
    ): array {
        global $CFG, $DB;

        unset($actor);
        require_once($CFG->dirroot . '/course/lib.php');

        if ($trackcourseid <= 0 || $sectionnum < 1 || $sectionnum > 4) {
            return ['error' => get_string('admin_builder_err_params', 'local_launchpad')];
        }
        try {
            $course = account_provision::require_launchpad_course($trackcourseid);
        } catch (account_provision_exception $e) {
            return ['error' => self::flash_error_message($e)];
        }
        if (!self::is_track_idnumber((string) $course->idnumber)) {
            return ['error' => get_string('admin_mentors_err_not_track', 'local_launchpad')];
        }

        $context = \context_course::instance($trackcourseid);
        try {
            require_capability('moodle/course:update', $context);
        } catch (\required_capability_exception $e) {
            return ['error' => self::flash_error_message($e)];
        }

        $section = $DB->get_record('course_sections', [
            'course' => $trackcourseid,
            'section' => $sectionnum,
        ]);
        if (!$section) {
            return ['error' => get_string('admin_builder_err_section', 'local_launchpad')];
        }

        if ($show) {
            // Require at least one non-placeholder activity first.
            $acts = self::builder_activities_for_section(
                $trackcourseid,
                $section,
                (int) $section->visible === 1
            );
            $hasready = false;
            foreach ($acts as $act) {
                if (($act['state'] ?? '') !== 'draft') {
                    $hasready = true;
                    break;
                }
            }
            if (!$hasready) {
                return ['error' => get_string('admin_builder_show_blocked', 'local_launchpad')];
            }
        }

        $modinfo = get_fast_modinfo($trackcourseid);
        $sectioninfo = $modinfo->get_section_info($sectionnum);
        if (!$sectioninfo) {
            return ['error' => get_string('admin_builder_err_section', 'local_launchpad')];
        }
        \core_courseformat\formatactions::section($trackcourseid)->set_visibility($sectioninfo, $show);

        $phasename = blueprint::phase_sections()[$sectionnum] ?? (string) $sectionnum;
        return [
            'ok' => $show
                ? get_string('admin_builder_ok_shown', 'local_launchpad', $phasename)
                : get_string('admin_builder_ok_hidden', 'local_launchpad', $phasename),
        ];
    }

    /**
     * Cohort & enrolment page (HTML cohorts).
     *
     * @param \stdClass $user
     * @param array{ok?:string,error?:string} $flash
     * @return array<string, mixed>
     */
    public static function cohorts_context(\stdClass $user, array $flash = [], int $preselecttrackid = 0): array {
        unset($user);
        $tracks = self::list_track_courses();
        $slug = self::primary_cohort_slug($tracks);
        $totalfellows = 0;
        $counts = [];
        $options = [];
        foreach ($tracks as $course) {
            $n = self::fellow_count((int) $course->id);
            $totalfellows += $n;
            $counts[] = $n;
            $ready = self::foundations_ready((int) $course->id);
            $options[] = [
                'id' => (int) $course->id,
                'label' => format_string($course->fullname) . ($ready ? '' : ' '
                    . get_string('admin_cohorts_option_not_ready', 'local_launchpad')),
                'disabled' => !$ready,
            ];
        }
        $maxfellows = max(1, ...($counts !== [] ? $counts : [1]));
        $bytrack = [];
        foreach ($tracks as $i => $course) {
            $n = $counts[$i];
            $bytrack[] = [
                'name' => format_string($course->fullname),
                'idnumber' => s($course->idnumber),
                'count' => $n,
                'pct' => (int) round(($n / $maxfellows) * 100),
            ];
        }

        $hasready = false;
        $selectedset = false;
        // Prefer Course ops trackid when that track is ready; else first ready track.
        if ($preselecttrackid > 0) {
            foreach ($options as &$opt) {
                if ((int) $opt['id'] === $preselecttrackid && empty($opt['disabled'])) {
                    $opt['selected'] = true;
                    $selectedset = true;
                    $hasready = true;
                } else {
                    $opt['selected'] = false;
                    if (empty($opt['disabled'])) {
                        $hasready = true;
                    }
                }
            }
            unset($opt);
        }
        if (!$selectedset) {
            foreach ($options as &$opt) {
                if (!$selectedset && empty($opt['disabled'])) {
                    $opt['selected'] = true;
                    $selectedset = true;
                    $hasready = true;
                } else {
                    $opt['selected'] = false;
                    if (empty($opt['disabled'])) {
                        $hasready = true;
                    }
                }
            }
            unset($opt);
        }

        $startdate = self::earliest_track_startdate($tracks);
        $selfon = self::any_self_enrolment_enabled($tracks);
        $orient = $slug !== null ? self::orientation_course_for_slug($slug) : null;
        $cohortcount = self::distinct_cohort_count($tracks);

        $recent = self::recent_track_enrolments(12);

        $icon = static function (string $paths): string {
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">'
                . $paths . '</svg>';
        };

        $cohortsub = $slug !== null
            ? get_string(
                'admin_cohorts_tile_cohort_sub',
                'local_launchpad',
                self::cohort_display_label($slug, $orient)
            )
            : get_string('admin_cohorts_tile_cohort_sub_none', 'local_launchpad');

        $tiles = [
            [
                'label' => get_string('admin_cohorts_tile_cohort', 'local_launchpad'),
                'value' => (string) $cohortcount,
                'sub' => $cohortsub,
                'icon_svg' => $icon(
                    '<circle cx="9" cy="8" r="3.5"/>'
                    . '<path d="M2 20c1-4 4-6 7-6s6 2 7 6"/>'
                    . '<path d="M16 4.5a3.5 3.5 0 010 7M22 20c-.6-2.6-2-4.4-4-5.3"/>'
                ),
            ],
            [
                'label' => get_string('admin_cohorts_tile_start', 'local_launchpad'),
                'value' => $startdate > 0
                    ? userdate($startdate, get_string('strftimedatefullshort', 'langconfig'))
                    : get_string('admin_cohorts_tile_start_tbc', 'local_launchpad'),
                'sub' => get_string('admin_cohorts_tile_start_sub', 'local_launchpad'),
                'icon_svg' => $icon(
                    '<rect x="3" y="5" width="18" height="16" rx="2"/>'
                    . '<path d="M3 10h18M8 3v4M16 3v4"/>'
                ),
            ],
            [
                'label' => get_string('admin_cohorts_tile_enrolled', 'local_launchpad'),
                'value' => (string) $totalfellows,
                'sub' => get_string('admin_cohorts_tile_enrolled_sub', 'local_launchpad'),
                'icon_svg' => $icon('<path d="M4 12l5 5L20 6"/>'),
            ],
            [
                'label' => get_string('admin_cohorts_tile_self', 'local_launchpad'),
                'value' => $selfon
                    ? get_string('admin_cohorts_tile_self_on', 'local_launchpad')
                    : get_string('admin_cohorts_tile_self_off', 'local_launchpad'),
                'sub' => $selfon
                    ? get_string('admin_cohorts_tile_self_sub_on', 'local_launchpad')
                    : get_string('admin_cohorts_tile_self_sub_off', 'local_launchpad'),
                'icon_svg' => $icon('<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/>'),
            ],
        ];

        return [
            'eyebrow' => get_string('admin_cohorts_eyebrow', 'local_launchpad'),
            'heading' => get_string('admin_cohorts_heading', 'local_launchpad'),
            'lead' => get_string('admin_cohorts_lead', 'local_launchpad'),
            'tiles' => $tiles,
            'form_title' => get_string('admin_cohorts_form_title', 'local_launchpad'),
            'email_label' => get_string('admin_cohorts_email_label', 'local_launchpad'),
            'email_placeholder' => get_string('admin_cohorts_email_placeholder', 'local_launchpad'),
            'track_label' => get_string('admin_cohorts_track_label', 'local_launchpad'),
            'orient_label' => get_string('admin_cohorts_orient_label', 'local_launchpad'),
            'orient_available' => $orient !== null,
            'orient_checked' => $orient !== null,
            'orient_missing' => $orient === null && $slug !== null,
            'orient_missing_body' => get_string('admin_cohorts_orient_missing', 'local_launchpad'),
            'submit_label' => get_string('admin_cohorts_submit', 'local_launchpad'),
            'has_track_options' => $options !== [],
            'has_ready_track' => $hasready,
            'no_ready_body' => get_string('admin_cohorts_no_ready', 'local_launchpad'),
            'track_options' => $options,
            'bytrack_title' => get_string('admin_cohorts_bytrack_title', 'local_launchpad'),
            'bytrack' => $bytrack,
            'has_bytrack' => $bytrack !== [],
            'tip_body' => get_string('admin_cohorts_tip', 'local_launchpad'),
            'tip_prof_url' => (new \moodle_url('/local/launchpad/assets/img/prof.webp'))->out(false),
            'recent_title' => get_string('admin_cohorts_recent_title', 'local_launchpad'),
            'recent_empty' => get_string('admin_cohorts_recent_empty', 'local_launchpad'),
            'bytrack_empty' => get_string('admin_cohorts_bytrack_empty', 'local_launchpad'),
            'col_fellow' => get_string('admin_cohorts_col_fellow', 'local_launchpad'),
            'col_track' => get_string('admin_cohorts_col_track', 'local_launchpad'),
            'col_role' => get_string('admin_cohorts_col_role', 'local_launchpad'),
            'col_when' => get_string('admin_cohorts_col_when', 'local_launchpad'),
            'role_student' => get_string('admin_cohorts_role_student', 'local_launchpad'),
            'recent' => $recent,
            'has_recent' => $recent !== [],
            'flash_ok' => !empty($flash['ok']),
            'flash_ok_body' => (string) ($flash['ok'] ?? ''),
            'flash_err' => !empty($flash['error']),
            'flash_err_body' => (string) ($flash['error'] ?? ''),
            'form_action' => (new \moodle_url(persona_home::ADMIN_PATH, ['view' => 'cohorts']))->out(false),
            'sesskey' => sesskey(),
        ];
    }

    /**
     * Enrol an existing user (by email) on a track; optionally Shared orientation.
     *
     * @return array{ok:string}|array{error:string}
     */
    public static function enrol_fellow_by_email(
        \stdClass $actor,
        string $email,
        int $trackcourseid,
        bool $alsoorientation
    ): array {
        global $CFG, $DB;

        $email = trim($email);
        if ($email === '' || strpos($email, '@') === false) {
            return ['error' => get_string('admin_cohorts_err_email', 'local_launchpad')];
        }
        if ($trackcourseid <= 0) {
            return ['error' => get_string('admin_cohorts_err_track', 'local_launchpad')];
        }

        $user = $DB->get_record_select(
            'user',
            'deleted = 0 AND mnethostid = :mnet AND ' . $DB->sql_equal('email', ':email', false, false),
            [
                'mnet' => $CFG->mnet_localhost_id,
                'email' => $email,
            ]
        );
        if (!$user) {
            return ['error' => get_string('admin_cohorts_err_nouser', 'local_launchpad', s($email))];
        }

        try {
            $course = account_provision::require_launchpad_course($trackcourseid);
        } catch (account_provision_exception $e) {
            return ['error' => self::flash_error_message($e)];
        }
        if (!self::is_track_idnumber((string) $course->idnumber)) {
            return ['error' => get_string('admin_cohorts_err_not_track', 'local_launchpad')];
        }
        if (!self::foundations_ready($trackcourseid)) {
            return ['error' => get_string('admin_cohorts_no_ready', 'local_launchpad')];
        }

        try {
            $result = account_provision::enrol_fellow($actor, (int) $user->id, $trackcourseid);
        } catch (account_provision_exception $e) {
            return ['error' => self::flash_error_message($e)];
        }

        $parts = [
            get_string(
                $result['status'] === 'already_enrolled'
                    ? 'admin_cohorts_ok_already'
                    : 'admin_cohorts_ok_enrolled',
                'local_launchpad',
                (object) [
                    'name' => fullname($user),
                    'track' => format_string($course->fullname),
                ]
            ),
        ];

        if ($alsoorientation) {
            $slug = self::cohort_slug_from_track_idnumber((string) $course->idnumber);
            $orient = $slug !== null ? self::orientation_course_for_slug($slug) : null;
            if ($orient) {
                try {
                    $ores = account_provision::enrol_fellow($actor, (int) $user->id, (int) $orient->id);
                    $parts[] = get_string(
                        $ores['status'] === 'already_enrolled'
                            ? 'admin_cohorts_ok_orient_already'
                            : 'admin_cohorts_ok_orient',
                        'local_launchpad'
                    );
                } catch (account_provision_exception $e) {
                    $parts[] = get_string('admin_cohorts_err_orient', 'local_launchpad', $e->getMessage());
                }
            } else {
                $parts[] = get_string('admin_cohorts_orient_missing', 'local_launchpad');
            }
        }

        return ['ok' => implode(' ', $parts)];
    }

    /**
     * People directory (HTML people) — fellows, mentors, admins from live Moodle.
     *
     * @param \stdClass $user
     * @param string $tab all|fellow|mentor|admin
     * @return array<string, mixed>
     */
    public static function people_context(\stdClass $user, string $tab = 'all'): array {
        $tab = strtolower(trim($tab));
        if (!in_array($tab, ['all', 'fellow', 'mentor', 'admin'], true)) {
            $tab = 'all';
        }

        $all = self::list_people_rows();
        $counts = [
            'all' => count($all),
            'fellow' => 0,
            'mentor' => 0,
            'admin' => 0,
        ];
        foreach ($all as $row) {
            $counts[$row['role_key']] = ($counts[$row['role_key']] ?? 0) + 1;
        }

        // Always render the full list; client filters tabs without a reload.
        $tabs = [];
        foreach (['all', 'fellow', 'mentor', 'admin'] as $key) {
            $tabs[] = [
                'key' => $key,
                'label' => get_string('admin_people_tab_' . $key, 'local_launchpad'),
                'count' => (string) $counts[$key],
                'url' => (new \moodle_url(persona_home::ADMIN_PATH, array_filter([
                    'view' => 'people',
                    'tab' => $key !== 'all' ? $key : null,
                ])))->out(false),
                'current' => $tab === $key,
            ];
        }

        $cohortsurl = (new \moodle_url(persona_home::ADMIN_PATH, ['view' => 'cohorts']))->out(false);
        return array_merge([
            'eyebrow' => get_string('admin_people_eyebrow', 'local_launchpad'),
            'heading' => get_string('admin_people_heading', 'local_launchpad'),
            'lead' => get_string('admin_people_lead', 'local_launchpad'),
            'initial_tab' => $tab,
            'tabs' => $tabs,
            'col_name' => get_string('admin_people_col_name', 'local_launchpad'),
            'col_email' => get_string('admin_people_col_email', 'local_launchpad'),
            'col_role' => get_string('admin_people_col_role', 'local_launchpad'),
            'col_reach' => get_string('admin_people_col_reach', 'local_launchpad'),
            'people' => $all,
            'has_people' => $all !== [],
            'footnote' => get_string('admin_people_footnote', 'local_launchpad'),
        ], self::empty_state(
            'admin_people_empty',
            'admin_people_empty_title',
            $cohortsurl,
            'admin_empty_cta_cohorts'
        ));
    }

    /**
     * People rows: one row per user×role (fellow / mentor / admin).
     *
     * @return list<array<string, mixed>>
     */
    public static function list_people_rows(): array {
        $fellows = self::list_track_people_by_archetype(['student'], true);
        $mentors = self::list_track_people_by_archetype(['teacher', 'editingteacher'], false);
        $admins = self::list_admin_people();

        $rows = [];
        foreach ($fellows as $row) {
            $rows[] = self::people_row_context($row, 'fellow');
        }
        foreach ($mentors as $row) {
            $rows[] = self::people_row_context($row, 'mentor');
        }
        foreach ($admins as $row) {
            $rows[] = self::people_row_context($row, 'admin');
        }

        usort($rows, static function (array $a, array $b): int {
            $cmp = strcasecmp($a['sort_lastname'], $b['sort_lastname']);
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = strcasecmp($a['sort_firstname'], $b['sort_firstname']);
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp($a['role_key'], $b['role_key']);
        });

        return $rows;
    }

    /**
     * Users with given role archetypes on track courses, courses listed for "Can reach".
     *
     * @param list<string> $archetypes
     * @return list<array{user:\stdClass,courses:list<string>}>
     */
    public static function list_track_people_by_archetype(array $archetypes, bool $requireactiveenrol): array {
        global $CFG, $DB;

        $tracks = self::list_track_courses();
        $ids = array_map(static fn($c) => (int) $c->id, $tracks);
        $ids = array_values(array_filter($ids));
        if ($ids === [] || $archetypes === []) {
            return [];
        }

        require_once($CFG->libdir . '/enrollib.php');

        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'c');
        [$archsql, $archparams] = $DB->get_in_or_equal($archetypes, SQL_PARAMS_NAMED, 'arch');
        $params += $archparams;
        $params['ctxlevel'] = CONTEXT_COURSE;

        if ($requireactiveenrol) {
            $params['uestatus'] = ENROL_USER_ACTIVE;
            $params['estatus'] = ENROL_INSTANCE_ENABLED;
            $sql = "SELECT u.id AS userid, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                           u.middlename, u.alternatename, u.email, c.id AS courseid, c.fullname AS coursefullname
                      FROM {user_enrolments} ue
                      JOIN {enrol} e ON e.id = ue.enrolid
                      JOIN {course} c ON c.id = e.courseid
                      JOIN {user} u ON u.id = ue.userid AND u.deleted = 0
                      JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = :ctxlevel
                      JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.userid = u.id
                      JOIN {role} r ON r.id = ra.roleid AND r.archetype {$archsql}
                     WHERE ue.status = :uestatus
                       AND e.status = :estatus
                       AND c.id {$insql}
                  ORDER BY u.lastname ASC, u.firstname ASC, c.fullname ASC";
        } else {
            $sql = "SELECT u.id AS userid, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                           u.middlename, u.alternatename, u.email, c.id AS courseid, c.fullname AS coursefullname
                      FROM {role_assignments} ra
                      JOIN {role} r ON r.id = ra.roleid AND r.archetype {$archsql}
                      JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :ctxlevel
                      JOIN {course} c ON c.id = ctx.instanceid
                      JOIN {user} u ON u.id = ra.userid AND u.deleted = 0
                     WHERE c.id {$insql}
                  ORDER BY u.lastname ASC, u.firstname ASC, c.fullname ASC";
        }

        $raw = $DB->get_recordset_sql($sql, $params);
        $byuser = [];
        foreach ($raw as $row) {
            $uid = (int) $row->userid;
            if (!isset($byuser[$uid])) {
                $byuser[$uid] = [
                    'user' => $row,
                    'courses' => [],
                ];
            }
            $cname = format_string($row->coursefullname);
            if (!in_array($cname, $byuser[$uid]['courses'], true)) {
                $byuser[$uid]['courses'][] = $cname;
            }
        }
        $raw->close();

        return array_values($byuser);
    }

    /**
     * Site admins for the People directory.
     *
     * @return list<array{user:\stdClass,courses:list<string>}>
     */
    public static function list_admin_people(): array {
        $reach = get_string('admin_people_reach_admin', 'local_launchpad');
        $root = self::fellowship_root_category();
        if ($root) {
            $reach = format_string($root->name);
        }

        $out = [];
        foreach (get_admins() as $admin) {
            if (empty($admin->id) || !empty($admin->deleted)) {
                continue;
            }
            $out[] = [
                'user' => $admin,
                'courses' => [$reach],
            ];
        }
        return $out;
    }

    /**
     * One People table row.
     *
     * @param array{user:\stdClass,courses:list<string>} $row
     * @param string $rolekey fellow|mentor|admin
     * @return array<string, mixed>
     */
    public static function people_row_context(array $row, string $rolekey): array {
        $user = $row['user'];
        $courses = $row['courses'];
        $tone = match ($rolekey) {
            'mentor' => 'green',
            'admin' => 'navy',
            default => 'blue',
        };
        $chip = match ($rolekey) {
            'mentor' => 'ok',
            'admin' => 'line',
            default => '',
        };

        return [
            'name' => fullname($user),
            'email' => s($user->email ?? ''),
            'initials' => fellowship_dashboard::initials($user),
            'avatar_tone' => $tone,
            'role_key' => $rolekey,
            'role_label' => get_string('admin_people_role_' . $rolekey, 'local_launchpad'),
            'role_chip' => $chip,
            'reach' => $courses !== [] ? implode(', ', $courses) : '—',
            'sort_lastname' => (string) ($user->lastname ?? ''),
            'sort_firstname' => (string) ($user->firstname ?? ''),
        ];
    }

    /**
     * Review oversight (HTML a-reviews) — pending assign reviews across tracks.
     *
     * @param \stdClass $user
     * @return array<string, mixed>
     */
    public static function reviews_context(\stdClass $user, array $flash = []): array {
        unset($user);
        $pending = self::list_pending_reviews(50);
        $pendingtotal = self::count_reviews_pending();
        $overduetotal = self::count_reviews_overdue();
        $released = self::count_reviews_released();
        $tracks = self::list_track_courses();
        $bytrackpending = self::pending_counts_by_course($tracks);

        $toptrack = get_string('admin_reviews_tile_pending_sub_none', 'local_launchpad');
        if ($bytrackpending !== []) {
            arsort($bytrackpending);
            $topid = (int) array_key_first($bytrackpending);
            foreach ($tracks as $course) {
                if ((int) $course->id === $topid) {
                    $toptrack = format_string($course->fullname);
                    break;
                }
            }
        }

        $icon = static function (string $paths): string {
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">'
                . $paths . '</svg>';
        };

        $sladays = (int) max(1, (int) round(self::REVIEW_SLA_SECONDS / DAYSECS));
        $tiles = [
            [
                'label' => get_string('admin_reviews_tile_pending', 'local_launchpad'),
                'value' => (string) $pendingtotal,
                'sub' => $toptrack,
                'icon_svg' => $icon(
                    '<path d="M4 5h16v14H4z"/><path d="M8 9h8M8 13h5"/>'
                ),
            ],
            [
                'label' => get_string('admin_reviews_tile_overdue', 'local_launchpad'),
                'value' => (string) $overduetotal,
                'sub' => get_string('admin_reviews_tile_overdue_sub', 'local_launchpad', $sladays),
                'tone' => $overduetotal > 0 ? 'warn' : '',
                'icon_svg' => $icon(
                    '<circle cx="12" cy="12" r="9"/>'
                    . '<path d="M12 7v6l4 2"/>'
                ),
            ],
            [
                'label' => get_string('admin_reviews_tile_released', 'local_launchpad'),
                'value' => (string) $released,
                'sub' => get_string('admin_reviews_tile_released_sub', 'local_launchpad'),
                'icon_svg' => $icon('<path d="M4 12l5 5L20 6"/>'),
            ],
        ];

        return array_merge([
            'eyebrow' => get_string('admin_reviews_eyebrow', 'local_launchpad'),
            'heading' => get_string('admin_reviews_heading', 'local_launchpad'),
            'lead' => get_string('admin_reviews_lead', 'local_launchpad', $sladays),
            'tiles' => $tiles,
            'col_fellow' => get_string('admin_reviews_col_fellow', 'local_launchpad'),
            'col_track' => get_string('admin_reviews_col_track', 'local_launchpad'),
            'col_submitted' => get_string('admin_reviews_col_submitted', 'local_launchpad'),
            'col_teacher' => get_string('admin_reviews_col_teacher', 'local_launchpad'),
            'col_state' => get_string('admin_reviews_col_state', 'local_launchpad'),
            'pending' => $pending,
            'has_pending' => $pending !== [],
            'open_label' => get_string('admin_reviews_open', 'local_launchpad'),
            'ping_label' => get_string('admin_reviews_ping', 'local_launchpad'),
            'ping_cooled_label' => get_string('admin_reviews_ping_cooled', 'local_launchpad'),
            'ping_target_help' => get_string('admin_reviews_ping_target_help', 'local_launchpad'),
            'ping_target_scope' => get_string('admin_reviews_ping_target_scope', 'local_launchpad'),
            'sesskey' => sesskey(),
            'ping_action_url' => (new \moodle_url(persona_home::ADMIN_PATH, ['view' => 'reviews']))->out(false),
            'flash_ok' => !empty($flash['ok']),
            'flash_ok_body' => (string) ($flash['ok'] ?? ''),
            'flash_err' => !empty($flash['error']),
            'flash_err_body' => (string) ($flash['error'] ?? ''),
        ], self::empty_state(
            'admin_reviews_empty',
            'admin_reviews_empty_title'
        ));
    }

    /**
     * Pending assign submissions on LaunchPad courses (overdue first, then oldest).
     *
     * @return list<array<string, mixed>>
     */
    public static function list_pending_reviews(int $limit = 50): array {
        global $DB;

        $tracks = self::list_track_courses();
        $ids = array_map(static fn($c) => (int) $c->id, $tracks);
        $ids = array_values(array_filter($ids));
        if ($ids === []) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'c');
        $params['status'] = 'submitted';
        // Fetch a bit more than the page limit so overdue-first sort is meaningful.
        $fetch = max(1, min(100, $limit * 2));

        $sql = "SELECT s.id, s.userid, s.timemodified, s.timecreated, a.id AS assignid, a.name AS assignname,
                       a.course AS courseid, c.fullname AS coursefullname,
                       u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                       u.middlename, u.alternatename, u.email,
                       cm.id AS cmid
                  FROM {assign_submission} s
                  JOIN {assign} a ON a.id = s.assignment
                  JOIN {course} c ON c.id = a.course
                  JOIN {user} u ON u.id = s.userid AND u.deleted = 0
                  JOIN {modules} m ON m.name = 'assign'
                  JOIN {course_modules} cm ON cm.module = m.id AND cm.instance = a.id AND cm.course = a.course
             LEFT JOIN {assign_grades} g
                    ON g.assignment = s.assignment
                   AND g.userid = s.userid
                   AND g.attemptnumber = s.attemptnumber
                   AND g.grade IS NOT NULL
                   AND g.grade >= 0
                 WHERE s.latest = 1
                   AND s.status = :status
                   AND g.id IS NULL
                   AND c.id {$insql}
              ORDER BY s.timemodified ASC, s.id ASC";

        $rows = $DB->get_records_sql($sql, $params, 0, $fetch);
        $teachercache = [];
        $out = [];
        $now = time();
        foreach ($rows as $row) {
            $courseid = (int) $row->courseid;
            if (!isset($teachercache[$courseid])) {
                $teachercache[$courseid] = self::teacher_rows($courseid);
            }
            $teachers = $teachercache[$courseid];
            $submissionid = (int) $row->id;
            $preferred = self::get_review_ping_target($submissionid);
            $teacherids = [];
            foreach ($teachers as $t) {
                $teacherids[(int) $t['id']] = true;
            }
            $teacherid = ($preferred > 0 && isset($teacherids[$preferred]))
                ? $preferred
                : (int) (($teachers[0]['id'] ?? 0));
            $teacheroptions = [];
            $teacherlabel = get_string('admin_tracks_no_teacher', 'local_launchpad');
            foreach ($teachers as $t) {
                $tid = (int) $t['id'];
                $selected = $tid === $teacherid;
                if ($selected) {
                    $teacherlabel = (string) $t['name'];
                }
                $teacheroptions[] = [
                    'id' => $tid,
                    'name' => (string) $t['name'],
                    'selected' => $selected,
                    'cooled' => self::review_ping_is_cooled($submissionid, $tid),
                ];
            }
            $cooled = $teacherid > 0 && self::review_ping_is_cooled($submissionid, $teacherid);
            $submitted = (int) ($row->timemodified ?: $row->timecreated);
            $age = $submitted > 0 ? max(0, $now - $submitted) : 0;
            $isoverdue = $submitted > 0 && $age >= self::REVIEW_SLA_SECONDS;
            $out[] = [
                'submissionid' => $submissionid,
                'courseid' => $courseid,
                'fellow' => fullname($row),
                'email' => s($row->email),
                'track' => format_string($row->coursefullname),
                'assign' => format_string($row->assignname),
                'submitted_ts' => $submitted,
                'submitted' => $submitted > 0
                    ? userdate($submitted, get_string('strftimedatetimeshort', 'langconfig'))
                    : '—',
                'age_seconds' => $age,
                'age_label' => $submitted > 0
                    ? get_string('admin_reviews_age', 'local_launchpad', format_time($age))
                    : '',
                'has_age' => $submitted > 0,
                'is_overdue' => $isoverdue,
                'overdue_badge' => $isoverdue
                    ? get_string('admin_reviews_overdue_badge', 'local_launchpad')
                    : '',
                'teacher' => $teacherlabel,
                'teacherid' => $teacherid,
                'teacher_options' => $teacheroptions,
                'has_teacher_choice' => count($teacheroptions) > 1,
                'has_teachers' => $teacheroptions !== [],
                'can_ping' => $teacherid > 0 && !$cooled,
                'ping_cooled' => $cooled,
                'state' => $isoverdue
                    ? get_string('admin_reviews_state_overdue', 'local_launchpad')
                    : get_string('admin_reviews_state_pending', 'local_launchpad'),
                'state_chip_class' => $isoverdue ? 'is-overdue' : 'warn',
                'grader_url' => (new \moodle_url('/mod/assign/view.php', [
                    'id' => (int) $row->cmid,
                    'action' => 'grader',
                    'userid' => (int) $row->userid,
                ]))->out(false),
            ];
        }

        usort($out, static function (array $a, array $b): int {
            $oa = !empty($a['is_overdue']) ? 1 : 0;
            $ob = !empty($b['is_overdue']) ? 1 : 0;
            if ($oa !== $ob) {
                return $ob <=> $oa;
            }
            $ta = (int) ($a['submitted_ts'] ?? 0);
            $tb = (int) ($b['submitted_ts'] ?? 0);
            if ($ta !== $tb) {
                return $ta <=> $tb;
            }
            return ((int) ($a['submissionid'] ?? 0)) <=> ((int) ($b['submissionid'] ?? 0));
        });

        return array_slice($out, 0, max(1, min(100, $limit)));
    }

    /** Pending review older than this is overdue (3 days). */
    public const REVIEW_SLA_SECONDS = 259200;

    /** Seconds before the same admin→teacher review ping may fire again. */
    public const REVIEW_PING_COOLDOWN = 1800;

    /**
     * Teachers/mentors on one LaunchPad track only (that course context).
     * Never includes mentors from other tracks or site-wide roles.
     *
     * @return list<array{id:int,name:string}>
     */
    public static function teacher_rows(int $courseid): array {
        global $DB;

        if ($courseid <= 0) {
            return [];
        }
        $context = \context_course::instance($courseid);
        $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                       u.middlename, u.alternatename
                  FROM {role_assignments} ra
                  JOIN {role} r ON r.id = ra.roleid
                  JOIN {user} u ON u.id = ra.userid
                 WHERE ra.contextid = :ctx
                   AND r.archetype IN ('teacher', 'editingteacher')
                   AND u.deleted = 0
                   AND u.suspended = 0
              ORDER BY u.lastname ASC, u.firstname ASC";
        $users = $DB->get_records_sql($sql, ['ctx' => $context->id]);
        $out = [];
        foreach ($users as $u) {
            $out[] = [
                'id' => (int) $u->id,
                'name' => fullname($u),
            ];
        }
        return $out;
    }

    /**
     * Whether this submission/teacher pair was pinged within the cooldown window.
     */
    public static function review_ping_is_cooled(int $submissionid, int $teacherid): bool {
        if ($submissionid <= 0 || $teacherid <= 0) {
            return false;
        }
        $raw = get_user_preferences('local_launchpad_review_ping_' . $submissionid . '_' . $teacherid, 0);
        $last = (int) $raw;
        return $last > 0 && (time() - $last) < self::REVIEW_PING_COOLDOWN;
    }

    /**
     * Mark a review ping as sent (starts cooldown for this admin).
     */
    public static function review_ping_mark(int $submissionid, int $teacherid): void {
        if ($submissionid <= 0 || $teacherid <= 0) {
            return;
        }
        set_user_preference(
            'local_launchpad_review_ping_' . $submissionid . '_' . $teacherid,
            time()
        );
    }

    /**
     * Last soft ping-target teacher for a pending submission (this admin).
     */
    public static function get_review_ping_target(int $submissionid): int {
        if ($submissionid <= 0) {
            return 0;
        }
        return (int) get_user_preferences('local_launchpad_review_ping_target_' . $submissionid, 0);
    }

    /**
     * Remember soft ping-target for reload (grading stays shared — not Moodle allocation).
     */
    public static function set_review_ping_target(int $submissionid, int $teacherid): void {
        if ($submissionid <= 0 || $teacherid <= 0) {
            return;
        }
        set_user_preference('local_launchpad_review_ping_target_' . $submissionid, $teacherid);
    }

    /**
     * Persist ping-target after validating the teacher is on that submission's track.
     *
     * @return array{ok?:string,error?:string}
     */
    public static function save_review_ping_target(\stdClass $admin, int $submissionid, int $teacherid): array {
        global $DB;

        $adminid = (int) ($admin->id ?? 0);
        if ($adminid <= 0 || (!self::can_access($adminid) && !is_siteadmin($adminid))) {
            return ['error' => get_string('admin_reviews_ping_err_permission', 'local_launchpad')];
        }
        if ($submissionid <= 0 || $teacherid <= 0) {
            return ['error' => get_string('admin_reviews_ping_err_params', 'local_launchpad')];
        }

        $sql = "SELECT s.id, a.course AS courseid, c.idnumber AS courseidn
                  FROM {assign_submission} s
                  JOIN {assign} a ON a.id = s.assignment
                  JOIN {course} c ON c.id = a.course
                 WHERE s.id = :sid
                   AND s.latest = 1
                   AND s.status = :status";
        $row = $DB->get_record_sql($sql, [
            'sid' => $submissionid,
            'status' => 'submitted',
        ]);
        if (!$row || !self::is_track_idnumber((string) ($row->courseidn ?? ''))) {
            return ['error' => get_string('admin_reviews_ping_err_gone', 'local_launchpad')];
        }

        $isteacher = false;
        foreach (self::teacher_rows((int) $row->courseid) as $t) {
            if ((int) $t['id'] === $teacherid) {
                $isteacher = true;
                break;
            }
        }
        if (!$isteacher) {
            return ['error' => get_string('admin_reviews_ping_err_teacher', 'local_launchpad')];
        }

        self::set_review_ping_target($submissionid, $teacherid);
        return ['ok' => '1'];
    }

    /**
     * Admin pings the track teacher about a still-pending assign submission.
     *
     * @return array{ok?:string,error?:string}
     */
    public static function ping_pending_review_mentor(
        \stdClass $admin,
        int $submissionid,
        int $teacherid
    ): array {
        global $DB;

        $adminid = (int) ($admin->id ?? 0);
        if ($adminid <= 0 || (!self::can_access($adminid) && !is_siteadmin($adminid))) {
            return ['error' => get_string('admin_reviews_ping_err_permission', 'local_launchpad')];
        }
        if ($submissionid <= 0 || $teacherid <= 0) {
            return ['error' => get_string('admin_reviews_ping_err_params', 'local_launchpad')];
        }
        if (self::review_ping_is_cooled($submissionid, $teacherid)) {
            return ['error' => get_string('admin_reviews_ping_err_cooled', 'local_launchpad')];
        }

        $sql = "SELECT s.id, s.userid, s.timemodified, s.timecreated, s.attemptnumber,
                       a.id AS assignid, a.name AS assignname, a.course AS courseid,
                       c.fullname AS coursefullname, c.idnumber AS courseidn,
                       cm.id AS cmid,
                       u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                       u.middlename, u.alternatename
                  FROM {assign_submission} s
                  JOIN {assign} a ON a.id = s.assignment
                  JOIN {course} c ON c.id = a.course
                  JOIN {user} u ON u.id = s.userid AND u.deleted = 0
                  JOIN {modules} m ON m.name = 'assign'
                  JOIN {course_modules} cm ON cm.module = m.id AND cm.instance = a.id AND cm.course = a.course
             LEFT JOIN {assign_grades} g
                    ON g.assignment = s.assignment
                   AND g.userid = s.userid
                   AND g.attemptnumber = s.attemptnumber
                   AND g.grade IS NOT NULL
                   AND g.grade >= 0
                 WHERE s.id = :sid
                   AND s.latest = 1
                   AND s.status = :status
                   AND g.id IS NULL";
        $row = $DB->get_record_sql($sql, [
            'sid' => $submissionid,
            'status' => 'submitted',
        ]);
        if (!$row) {
            return ['error' => get_string('admin_reviews_ping_err_gone', 'local_launchpad')];
        }
        if (!self::is_track_idnumber((string) ($row->courseidn ?? ''))) {
            return ['error' => get_string('admin_reviews_ping_err_params', 'local_launchpad')];
        }

        $courseid = (int) $row->courseid;
        $isteacher = false;
        foreach (self::teacher_rows($courseid) as $t) {
            if ((int) $t['id'] === $teacherid) {
                $isteacher = true;
                break;
            }
        }
        if (!$isteacher) {
            return ['error' => get_string('admin_reviews_ping_err_teacher', 'local_launchpad')];
        }

        $fellow = fullname($row);
        $track = format_string($row->coursefullname);
        $assign = format_string($row->assignname);
        $submittedts = (int) ($row->timemodified ?: $row->timecreated);
        $submitted = $submittedts > 0
            ? userdate($submittedts, get_string('strftimedatetimeshort', 'langconfig'))
            : '—';
        $graderurl = (new \moodle_url('/mod/assign/view.php', [
            'id' => (int) $row->cmid,
            'action' => 'grader',
            'userid' => (int) $row->userid,
        ]))->out(false);

        $a = (object) [
            'fellow' => $fellow,
            'track' => $track,
            'assign' => $assign,
            'submitted' => $submitted,
            'graderurl' => $graderurl,
        ];
        $msg = get_string('admin_reviews_ping_message', 'local_launchpad', $a);
        $emailsubject = get_string('admin_reviews_ping_email_subject', 'local_launchpad', $a);
        $emailbody = get_string('admin_reviews_ping_email_body', 'local_launchpad', $a);

        $sent = messaging::send_admin_review_nudge($adminid, $teacherid, $msg, $emailsubject, $emailbody);
        if (empty($sent['ok'])) {
            return ['error' => (string) ($sent['error']
                ?? get_string('admin_reviews_ping_err_send', 'local_launchpad'))];
        }

        self::review_ping_mark($submissionid, $teacherid);
        self::set_review_ping_target($submissionid, $teacherid);
        $teacher = \core_user::get_user($teacherid, '*', IGNORE_MISSING);
        $teachername = $teacher ? fullname($teacher) : get_string('admin_reviews_col_teacher', 'local_launchpad');
        return [
            'ok' => get_string('admin_reviews_ping_ok', 'local_launchpad', $teachername),
        ];
    }

    /**
     * Pending review counts keyed by course id.
     *
     * @param list<\stdClass> $tracks
     * @return array<int, int>
     */
    public static function pending_counts_by_course(array $tracks): array {
        global $DB;

        $ids = array_map(static fn($c) => (int) $c->id, $tracks);
        $ids = array_values(array_filter($ids));
        if ($ids === []) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'c');
        $params['status'] = 'submitted';
        $sql = "SELECT a.course AS courseid, COUNT(1) AS pendingcount
                  FROM {assign_submission} s
                  JOIN {assign} a ON a.id = s.assignment
             LEFT JOIN {assign_grades} g
                    ON g.assignment = s.assignment
                   AND g.userid = s.userid
                   AND g.attemptnumber = s.attemptnumber
                   AND g.grade IS NOT NULL
                   AND g.grade >= 0
                 WHERE s.latest = 1
                   AND s.status = :status
                   AND g.id IS NULL
                   AND a.course {$insql}
              GROUP BY a.course";
        $rows = $DB->get_records_sql($sql, $params);
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->courseid] = (int) $row->pendingcount;
        }
        return $out;
    }

    /**
     * Graded (released) latest submissions on LaunchPad track courses.
     */
    public static function count_reviews_released(): int {
        global $DB;

        $tracks = self::list_track_courses();
        $ids = array_map(static fn($c) => (int) $c->id, $tracks);
        $ids = array_values(array_filter($ids));
        if ($ids === []) {
            return 0;
        }
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'c');
        $params['status'] = 'submitted';
        $sql = "SELECT COUNT(1)
                  FROM {assign_submission} s
                  JOIN {assign} a ON a.id = s.assignment
                  JOIN {course} c ON c.id = a.course
                  JOIN {assign_grades} g
                    ON g.assignment = s.assignment
                   AND g.userid = s.userid
                   AND g.attemptnumber = s.attemptnumber
                   AND g.grade IS NOT NULL
                   AND g.grade >= 0
                 WHERE s.latest = 1
                   AND s.status = :status
                   AND c.id {$insql}";
        return (int) $DB->count_records_sql($sql, $params);
    }

    /**
     * Mentors & capacity (HTML a-mentors) — one card per track.
     *
     * Capacity hours are not stored in Moodle yet; fellow counts and teachers are live.
     *
     * @param \stdClass $user
     * @param array{ok?:string,error?:string} $flash
     * @return array<string, mixed>
     */
    public static function mentors_context(\stdClass $user, array $flash = [], int $highlighttrackid = 0): array {
        unset($user);
        $tracks = self::list_track_courses();
        $cards = [];
        foreach ($tracks as $course) {
            $cards[] = self::mentor_track_card_context($course, $highlighttrackid);
        }

        $builderurl = (new \moodle_url(persona_home::ADMIN_PATH, ['view' => 'builder']))->out(false);
        return array_merge([
            'eyebrow' => get_string('admin_mentors_eyebrow', 'local_launchpad'),
            'heading' => get_string('admin_mentors_heading', 'local_launchpad'),
            'lead' => get_string('admin_mentors_lead', 'local_launchpad'),
            'has_tracks' => $cards !== [],
            'tracks' => $cards,
            'flash_ok' => !empty($flash['ok']),
            'flash_ok_body' => (string) ($flash['ok'] ?? ''),
            'flash_err' => !empty($flash['error']),
            'flash_err_body' => (string) ($flash['error'] ?? ''),
            'form_action' => (new \moodle_url(persona_home::ADMIN_PATH, ['view' => 'mentors']))->out(false),
            'sesskey' => sesskey(),
        ], self::empty_state(
            'admin_mentors_empty',
            'admin_empty_title_no_tracks',
            $builderurl,
            'admin_empty_cta_builder'
        ));
    }

    /**
     * One Mentors & capacity track card.
     *
     * @param \stdClass $course
     * @return array<string, mixed>
     */
    public static function mentor_track_card_context(\stdClass $course, int $highlighttrackid = 0): array {
        $courseid = (int) $course->id;
        $teachers = self::list_track_teachers($courseid);
        $fellows = self::fellow_count($courseid);
        $hasteacher = $teachers !== [];
        $primary = $hasteacher ? $teachers[0] : null;

        return [
            'id' => $courseid,
            'anchor_id' => 'lp-mentor-track-' . $courseid,
            'is_highlight' => $highlighttrackid > 0 && $highlighttrackid === $courseid,
            'name' => format_string($course->fullname),
            'idnumber' => s($course->idnumber),
            'has_teacher' => $hasteacher,
            'teacher_name' => $primary ? $primary['name'] : '',
            'teacher_initials' => $primary ? $primary['initials'] : '',
            'teacher_role_label' => get_string('admin_mentors_teacher_role', 'local_launchpad'),
            'more_teachers' => count($teachers) > 1
                ? get_string('admin_mentors_more_teachers', 'local_launchpad', count($teachers) - 1)
                : '',
            'has_more_teachers' => count($teachers) > 1,
            'capacity_label' => get_string('admin_mentors_capacity', 'local_launchpad'),
            'capacity_value' => get_string('admin_mentors_capacity_unset', 'local_launchpad'),
            'fellows_label' => get_string('admin_mentors_fellows', 'local_launchpad'),
            'fellows_value' => (string) $fellows,
            'email_label' => get_string('admin_mentors_email_label', 'local_launchpad'),
            'email_placeholder' => get_string('admin_mentors_email_placeholder', 'local_launchpad'),
            'submit_label' => get_string('admin_mentors_submit', 'local_launchpad'),
            'assign_teacher_url' => self::assign_teacher_url($courseid)->out(false),
            'assign_moodle_label' => get_string('admin_attention_assign_teacher', 'local_launchpad'),
        ];
    }

    /**
     * Assign mentor as non-editing teacher (Moodle shortname teacher) by email.
     *
     * @return array{ok:string}|array{error:string}
     */
    public static function assign_mentor_by_email(
        \stdClass $actor,
        string $email,
        int $trackcourseid
    ): array {
        global $CFG, $DB;

        $email = trim($email);
        if ($email === '' || strpos($email, '@') === false) {
            return ['error' => get_string('admin_mentors_err_email', 'local_launchpad')];
        }
        if ($trackcourseid <= 0) {
            return ['error' => get_string('admin_mentors_err_track', 'local_launchpad')];
        }

        $user = $DB->get_record_select(
            'user',
            'deleted = 0 AND mnethostid = :mnet AND ' . $DB->sql_equal('email', ':email', false, false),
            [
                'mnet' => $CFG->mnet_localhost_id,
                'email' => $email,
            ]
        );
        if (!$user) {
            return ['error' => get_string('admin_mentors_err_nouser', 'local_launchpad', s($email))];
        }

        try {
            $course = account_provision::require_launchpad_course($trackcourseid);
        } catch (account_provision_exception $e) {
            return ['error' => self::flash_error_message($e)];
        }
        if (!self::is_track_idnumber((string) $course->idnumber)) {
            return ['error' => get_string('admin_mentors_err_not_track', 'local_launchpad')];
        }

        try {
            // Admin UI always assigns non-editing teacher (shortname teacher).
            $result = account_provision::assign_mentor(
                $actor,
                (int) $user->id,
                $trackcourseid,
                'teacher'
            );
        } catch (account_provision_exception $e) {
            return ['error' => self::flash_error_message($e)];
        }

        $key = $result['status'] === 'already_enrolled'
            ? 'admin_mentors_ok_already'
            : 'admin_mentors_ok_assigned';
        return [
            'ok' => get_string($key, 'local_launchpad', (object) [
                'name' => fullname($user),
                'track' => format_string($course->fullname),
            ]),
        ];
    }

    /**
     * Teachers on a track (editingteacher / teacher), ordered by name.
     *
     * @return list<array{id:int,name:string,initials:string}>
     */
    public static function list_track_teachers(int $courseid): array {
        global $DB;

        if ($courseid <= 0) {
            return [];
        }
        $context = \context_course::instance($courseid);
        $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                       u.middlename, u.alternatename, u.email
                  FROM {role_assignments} ra
                  JOIN {role} r ON r.id = ra.roleid
                  JOIN {user} u ON u.id = ra.userid
                 WHERE ra.contextid = :ctx
                   AND r.archetype IN ('teacher', 'editingteacher')
                   AND u.deleted = 0
              ORDER BY u.lastname ASC, u.firstname ASC";
        $users = $DB->get_records_sql($sql, ['ctx' => $context->id]);
        $out = [];
        foreach ($users as $u) {
            $out[] = [
                'id' => (int) $u->id,
                'name' => fullname($u),
                'initials' => fellowship_dashboard::initials($u),
            ];
        }
        return $out;
    }

    /**
     * One track card for the Tracks & courses grid.
     *
     * @param \stdClass $course id, fullname, shortname, idnumber, visible, summary?
     * @return array<string, mixed>
     */
    public static function track_card_context(\stdClass $course): array {
        $courseid = (int) $course->id;
        $phases = self::phase_visibility($courseid);
        $shown = 0;
        foreach ($phases as $p) {
            if (!empty($p['on'])) {
                $shown++;
            }
        }
        $foundationsready = self::foundations_ready($courseid);
        $teachers = self::teacher_names($courseid);
        $fellows = self::fellow_count($courseid);
        $capstone = self::capstone_line($course);

        return [
            'id' => $courseid,
            'name' => format_string($course->fullname),
            'idnumber' => s($course->idnumber),
            'ready' => $foundationsready,
            'ready_label' => $foundationsready
                ? get_string('admin_tracks_foundations_ready', 'local_launchpad')
                : get_string('admin_tracks_outline_only', 'local_launchpad'),
            'phases_shown_label' => get_string(
                'admin_tracks_phases_shown',
                'local_launchpad',
                (object) ['shown' => $shown, 'total' => 4]
            ),
            'capstone' => $capstone,
            'phases' => $phases,
            'has_teacher' => $teachers !== [],
            'teacher_label' => $teachers !== []
                ? implode(', ', $teachers)
                : get_string('admin_tracks_no_teacher', 'local_launchpad'),
            'fellows_label' => get_string('admin_tracks_fellows', 'local_launchpad', $fellows),
            'course_url' => (new \moodle_url('/course/view.php', ['id' => $courseid]))->out(false),
            'open_label' => get_string('admin_tracks_open_course', 'local_launchpad'),
            'courseops_url' => (new \moodle_url(persona_home::ADMIN_PATH, [
                'view' => 'courseops',
                'trackid' => $courseid,
            ]))->out(false),
            'courseops_label' => get_string('admin_tracks_course_ops', 'local_launchpad'),
            'builder_url' => (new \moodle_url(persona_home::ADMIN_PATH, [
                'view' => 'builder',
                'trackid' => $courseid,
            ]))->out(false),
            'builder_label' => get_string('admin_tracks_open_builder', 'local_launchpad'),
            'assign_teacher_url' => self::assign_teacher_url($courseid)->out(false),
            'assign_teacher_label' => get_string('admin_attention_assign_teacher', 'local_launchpad'),
        ];
    }

    /**
     * LaunchPad track courses (`lp-*`, not orientation), ordered by name.
     *
     * @return list<\stdClass> id, fullname, shortname, idnumber, visible, summary
     */
    public static function list_track_courses(): array {
        global $DB;

        $like = $DB->sql_like('idnumber', ':idn', false);
        $notorient = $DB->sql_like('idnumber', ':orient', false);
        $params = [
            'idn' => $DB->sql_like_escape(account_persona::COURSE_IDNUMBER_PREFIX) . '%',
            'orient' => $DB->sql_like_escape(self::ORIENT_IDNUMBER_PREFIX) . '%',
            'site' => SITEID,
        ];
        $sql = "SELECT id, fullname, shortname, idnumber, visible, summary, startdate
                  FROM {course}
                 WHERE id <> :site
                   AND {$like}
                   AND NOT {$notorient}
              ORDER BY fullname ASC";

        $rows = $DB->get_records_sql($sql, $params);
        return array_values($rows);
    }

    /**
     * Shared orientation courses (`lp-orient-*`), ordered by name.
     *
     * @return list<\stdClass> id, fullname, shortname, idnumber, visible, summary, startdate
     */
    public static function list_orientation_courses(): array {
        global $DB;

        $like = $DB->sql_like('idnumber', ':orient', false);
        $params = [
            'orient' => $DB->sql_like_escape(self::ORIENT_IDNUMBER_PREFIX) . '%',
            'site' => SITEID,
        ];
        $sql = "SELECT id, fullname, shortname, idnumber, visible, summary, startdate
                  FROM {course}
                 WHERE id <> :site
                   AND {$like}
              ORDER BY fullname ASC";

        return array_values($DB->get_records_sql($sql, $params));
    }

    /**
     * Valid track id for admin calendar filter (0 = all tracks).
     */
    public static function resolve_calendar_trackid(int $trackid): int {
        if ($trackid <= 0) {
            return 0;
        }
        foreach (self::list_track_courses() as $course) {
            if ((int) $course->id === $trackid) {
                return $trackid;
            }
        }
        return 0;
    }

    /**
     * Courses for the admin calendar grid (does not change Overview KPI lists).
     *
     * Optional single-track filter; optional orientation append (all orients, or
     * the filtered track's cohort orientation only).
     *
     * @return list<\stdClass>
     */
    public static function calendar_courses(int $trackid = 0, bool $includeorient = false): array {
        $tracks = self::list_track_courses();
        $resolved = self::resolve_calendar_trackid($trackid);
        $courses = [];
        if ($resolved > 0) {
            foreach ($tracks as $course) {
                if ((int) $course->id === $resolved) {
                    $courses[] = $course;
                    break;
                }
            }
        }
        if ($courses === []) {
            $courses = $tracks;
            $resolved = 0;
        }

        if (!$includeorient) {
            return $courses;
        }

        $orients = [];
        if ($resolved > 0) {
            $slug = self::cohort_slug_from_track_idnumber((string) ($courses[0]->idnumber ?? ''));
            $one = $slug !== null ? self::orientation_course_for_slug($slug) : null;
            if ($one) {
                $orients[] = $one;
            }
        } else {
            $orients = self::list_orientation_courses();
        }

        $seen = [];
        foreach ($courses as $course) {
            $seen[(int) $course->id] = true;
        }
        foreach ($orients as $orient) {
            $oid = (int) $orient->id;
            if (!isset($seen[$oid])) {
                $courses[] = $orient;
                $seen[$oid] = true;
            }
        }

        return $courses;
    }

    /**
     * KPI numbers for Overview tiles.
     *
     * Tracks ready = visible + ≥1 activity + ≥1 teacher/editingteacher.
     *
     * @param list<\stdClass> $tracks
     * @return array{tracks_ready:int,tracks_total:int,fellows:int,teachers:int,reviews_pending:int}
     */
    public static function kpi_counts(array $tracks): array {
        $ready = 0;
        foreach ($tracks as $course) {
            $courseid = (int) $course->id;
            if ((int) $course->visible === 1
                    && self::activity_count($courseid) > 0
                    && self::teacher_count($courseid) > 0) {
                $ready++;
            }
        }

        return [
            'tracks_ready' => $ready,
            'tracks_total' => count($tracks),
            'fellows' => self::count_distinct_fellows(),
            'teachers' => self::count_distinct_teachers(),
            'reviews_pending' => self::count_reviews_pending(),
        ];
    }

    /**
     * Hidden-by-default visibility panel.
     *
     * @param list<\stdClass> $tracks
     * @return array<string, mixed>
     */
    public static function visibility_context(array $tracks): array {
        $hidden = [];
        $shown = [];
        $statushidden = get_string('admin_visibility_status_hidden', 'local_launchpad');
        $statusshown = get_string('admin_visibility_status_shown', 'local_launchpad');
        $badgehidden = get_string('admin_visibility_badge_hidden', 'local_launchpad');
        $badgelive = get_string('admin_visibility_badge_live', 'local_launchpad');
        $openlabel = get_string('admin_visibility_open_track', 'local_launchpad');

        foreach ($tracks as $course) {
            $isvisible = (int) $course->visible === 1;
            $row = [
                'id' => (int) $course->id,
                'name' => format_string($course->fullname),
                'idnumber' => s($course->idnumber),
                'view_url' => (new \moodle_url(persona_home::ADMIN_PATH, [
                    'view' => 'courseops',
                    'trackid' => (int) $course->id,
                ]))->out(false),
                'is_hidden' => !$isvisible,
                'is_shown' => $isvisible,
                'status_label' => $isvisible ? $statusshown : $statushidden,
                'badge_label' => $isvisible ? $badgelive : $badgehidden,
                'open_label' => $openlabel,
            ];
            if ($isvisible) {
                $shown[] = $row;
            } else {
                $hidden[] = $row;
            }
        }

        $rows = array_merge($hidden, $shown);
        $total = count($tracks);

        return [
            'visibility_title' => get_string('admin_visibility_title', 'local_launchpad'),
            'visibility_lead' => get_string('admin_visibility_lead', 'local_launchpad'),
            'visibility_hint' => get_string('admin_visibility_hint', 'local_launchpad'),
            'visibility_card_sub' => get_string('admin_visibility_card_sub', 'local_launchpad'),
            'hidden_count' => count($hidden),
            'shown_count' => count($shown),
            'visibility_total' => $total,
            'visibility_total_label' => get_string('admin_visibility_total', 'local_launchpad', $total),
            'hidden_label' => get_string('admin_visibility_hidden', 'local_launchpad', count($hidden)),
            'shown_label' => get_string('admin_visibility_shown', 'local_launchpad', count($shown)),
            'has_hidden' => $hidden !== [],
            'has_shown' => $shown !== [],
            'hidden_courses' => $hidden,
            'shown_courses' => $shown,
            'visibility_rows' => $rows,
            'has_visibility_rows' => $rows !== [],
            'hidden_list_label' => get_string('admin_visibility_hidden_list', 'local_launchpad'),
            'shown_list_label' => get_string('admin_visibility_shown_list', 'local_launchpad'),
            'visibility_empty' => $tracks === [],
            'visibility_empty_body' => get_string('admin_visibility_empty', 'local_launchpad'),
            'visibility_footer_tip' => get_string('admin_visibility_footer_tip', 'local_launchpad'),
        ];
    }

    /**
     * Real gaps only: missing teacher, or zero activities.
     * Placeholder-only curriculum is expected and not listed here.
     *
     * @param list<\stdClass> $tracks
     * @return array<string, mixed>
     */
    public static function needs_attention(array $tracks): array {
        $items = [];
        foreach ($tracks as $course) {
            $courseid = (int) $course->id;
            $name = format_string($course->fullname);
            // Course home — turn editing on / add an activity.
            $coursehomeurl = (new \moodle_url('/course/view.php', ['id' => $courseid]))->out(false);

            $teachers = self::teacher_count($courseid);
            $activities = self::activity_count($courseid);

            if ($teachers === 0) {
                $items[] = [
                    'course_name' => $name,
                    'idnumber' => s($course->idnumber),
                    'issue' => get_string('admin_attention_no_teacher', 'local_launchpad'),
                    'hint' => get_string('admin_attention_no_teacher_hint', 'local_launchpad'),
                    'url' => self::assign_teacher_url($courseid)->out(false),
                    'cta' => get_string('admin_attention_assign_teacher', 'local_launchpad'),
                    'badge' => get_string('admin_attention_badge_no_teacher', 'local_launchpad'),
                    'is_teacher_gap' => true,
                    'is_activity_gap' => false,
                ];
            }
            if ($activities === 0) {
                $items[] = [
                    'course_name' => $name,
                    'idnumber' => s($course->idnumber),
                    'issue' => get_string('admin_attention_no_activities', 'local_launchpad'),
                    'hint' => get_string('admin_attention_no_activities_hint', 'local_launchpad'),
                    'url' => $coursehomeurl,
                    'cta' => get_string('admin_attention_open_course', 'local_launchpad'),
                    'badge' => get_string('admin_attention_badge_no_activities', 'local_launchpad'),
                    'is_teacher_gap' => false,
                    'is_activity_gap' => true,
                ];
            }
        }

        $count = count($items);
        return [
            'attention_title' => get_string('admin_attention_title', 'local_launchpad'),
            'attention_lead' => get_string('admin_attention_lead', 'local_launchpad'),
            'attention_card_sub' => get_string('admin_attention_card_sub', 'local_launchpad'),
            'has_attention' => $items !== [],
            'attention_items' => $items,
            'attention_count' => $count,
            'attention_count_label' => get_string('admin_attention_count', 'local_launchpad', $count),
            'attention_empty' => get_string('admin_attention_empty', 'local_launchpad'),
            'attention_footer_tip' => get_string('admin_attention_footer_tip', 'local_launchpad'),
        ];
    }

    /**
     * Deep link: Moodle “Assign role” for Editing teacher on this course.
     *
     * Lands on the picker where the admin selects a user — not the generic
     * Participants roster. Falls back to Teacher, then Participants.
     */
    public static function assign_teacher_url(int $courseid): \moodle_url {
        global $DB;

        if ($courseid <= 0) {
            return new \moodle_url('/user/index.php');
        }

        $context = \context_course::instance($courseid);
        foreach (['editingteacher', 'teacher'] as $shortname) {
            $roleid = (int) $DB->get_field('role', 'id', ['shortname' => $shortname]);
            if ($roleid > 0) {
                return new \moodle_url('/admin/roles/assign.php', [
                    'contextid' => $context->id,
                    'roleid' => $roleid,
                ]);
            }
        }

        return new \moodle_url('/user/index.php', ['id' => $courseid]);
    }

    /**
     * Visible (non-deleted) activity count for a course.
     */
    public static function activity_count(int $courseid): int {
        global $DB;

        if ($courseid <= 0) {
            return 0;
        }
        return (int) $DB->count_records_select(
            'course_modules',
            'course = :course AND deletioninprogress = 0',
            ['course' => $courseid]
        );
    }

    /**
     * Teachers / editing teachers on a course.
     */
    public static function teacher_count(int $courseid): int {
        global $DB;

        if ($courseid <= 0) {
            return 0;
        }
        $context = \context_course::instance($courseid);
        $sql = "SELECT COUNT(DISTINCT ra.userid)
                  FROM {role_assignments} ra
                  JOIN {role} r ON r.id = ra.roleid
                 WHERE ra.contextid = :ctx
                   AND r.archetype IN ('teacher', 'editingteacher')";
        return (int) $DB->count_records_sql($sql, ['ctx' => $context->id]);
    }

    /**
     * Distinct student-archetype users enrolled on any lp-* course.
     */
    public static function count_distinct_fellows(): int {
        global $CFG, $DB;

        require_once($CFG->libdir . '/enrollib.php');

        $like = $DB->sql_like('c.idnumber', ':idn', false);
        $sql = "SELECT COUNT(DISTINCT ue.userid)
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                  JOIN {course} c ON c.id = e.courseid
                  JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = :ctxlevel
                  JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.userid = ue.userid
                  JOIN {role} r ON r.id = ra.roleid AND r.archetype = :arch
                 WHERE ue.status = :uestatus
                   AND e.status = :estatus
                   AND {$like}";
        return (int) $DB->count_records_sql($sql, [
            'ctxlevel' => CONTEXT_COURSE,
            'arch' => 'student',
            'uestatus' => ENROL_USER_ACTIVE,
            'estatus' => ENROL_INSTANCE_ENABLED,
            'idn' => $DB->sql_like_escape(account_persona::COURSE_IDNUMBER_PREFIX) . '%',
        ]);
    }

    /**
     * Distinct teacher/editingteacher users on any lp-* course.
     */
    public static function count_distinct_teachers(): int {
        global $DB;

        $like = $DB->sql_like('c.idnumber', ':idn', false);
        $sql = "SELECT COUNT(DISTINCT ra.userid)
                  FROM {role_assignments} ra
                  JOIN {role} r ON r.id = ra.roleid
                  JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :ctxlevel
                  JOIN {course} c ON c.id = ctx.instanceid
                 WHERE r.archetype IN ('teacher', 'editingteacher')
                   AND {$like}";
        return (int) $DB->count_records_sql($sql, [
            'ctxlevel' => CONTEXT_COURSE,
            'idn' => $DB->sql_like_escape(account_persona::COURSE_IDNUMBER_PREFIX) . '%',
        ]);
    }

    /**
     * Submitted assign attempts on lp-* courses still waiting for a grade.
     */
    public static function count_reviews_pending(): int {
        global $DB;

        $like = $DB->sql_like('c.idnumber', ':idn', false);
        // Latest submitted attempt with no non-negative grade for that attempt.
        $sql = "SELECT COUNT(1)
                  FROM {assign_submission} s
                  JOIN {assign} a ON a.id = s.assignment
                  JOIN {course} c ON c.id = a.course
             LEFT JOIN {assign_grades} g
                    ON g.assignment = s.assignment
                   AND g.userid = s.userid
                   AND g.attemptnumber = s.attemptnumber
                   AND g.grade IS NOT NULL
                   AND g.grade >= 0
                 WHERE s.latest = 1
                   AND s.status = :status
                   AND g.id IS NULL
                   AND {$like}";
        return (int) $DB->count_records_sql($sql, [
            'status' => 'submitted',
            'idn' => $DB->sql_like_escape(account_persona::COURSE_IDNUMBER_PREFIX) . '%',
        ]);
    }

    /**
     * Pending LaunchPad reviews older than {@see REVIEW_SLA_SECONDS}.
     */
    public static function count_reviews_overdue(): int {
        global $DB;

        $like = $DB->sql_like('c.idnumber', ':idn', false);
        $sql = "SELECT COUNT(1)
                  FROM {assign_submission} s
                  JOIN {assign} a ON a.id = s.assignment
                  JOIN {course} c ON c.id = a.course
             LEFT JOIN {assign_grades} g
                    ON g.assignment = s.assignment
                   AND g.userid = s.userid
                   AND g.attemptnumber = s.attemptnumber
                   AND g.grade IS NOT NULL
                   AND g.grade >= 0
                 WHERE s.latest = 1
                   AND s.status = :status
                   AND g.id IS NULL
                   AND {$like}
                   AND COALESCE(NULLIF(s.timemodified, 0), s.timecreated) > 0
                   AND COALESCE(NULLIF(s.timemodified, 0), s.timecreated) <= :cutoff";
        return (int) $DB->count_records_sql($sql, [
            'status' => 'submitted',
            'idn' => $DB->sql_like_escape(account_persona::COURSE_IDNUMBER_PREFIX) . '%',
            'cutoff' => time() - self::REVIEW_SLA_SECONDS,
        ]);
    }

    /**
     * Fellowship root category (idnumber launchpad-root), or null.
     */
    public static function fellowship_root_category(): ?\stdClass {
        global $DB;

        $cat = $DB->get_record('course_categories', ['idnumber' => blueprint::ROOT_IDNUMBER]);
        return $cat ?: null;
    }

    /**
     * Phase section visibility dots (sections 1–4).
     *
     * @return list<array{on:bool,title:string}>
     */
    public static function phase_visibility(int $courseid): array {
        global $DB;

        $names = blueprint::phase_sections();
        $out = [];
        foreach ($names as $sectionnum => $title) {
            $visible = 0;
            if ($courseid > 0) {
                $visible = (int) $DB->get_field(
                    'course_sections',
                    'visible',
                    ['course' => $courseid, 'section' => $sectionnum]
                );
            }
            // Missing section row → treat as not shown.
            $out[] = [
                'on' => $visible === 1,
                'title' => $title,
            ];
        }
        return $out;
    }

    /**
     * Foundations “ready” when section 1 has a non-placeholder activity.
     */
    public static function foundations_ready(int $courseid): bool {
        global $DB;

        if ($courseid <= 0) {
            return false;
        }
        $section = $DB->get_record('course_sections', [
            'course' => $courseid,
            'section' => 1,
        ]);
        if (!$section || empty($section->sequence)) {
            return false;
        }
        $cmids = array_filter(array_map('intval', explode(',', $section->sequence)));
        if ($cmids === []) {
            return false;
        }
        [$insql, $params] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED, 'cm');
        $params['del'] = 0;
        $sql = "SELECT cm.id, m.name AS modname, cm.instance
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                 WHERE cm.id {$insql}
                   AND cm.deletioninprogress = :del";
        $cms = $DB->get_records_sql($sql, $params);
        $marker = self::PLACEHOLDER_MARKER;
        foreach ($cms as $cm) {
            $table = $cm->modname;
            if (!$DB->get_manager()->table_exists($table)) {
                continue;
            }
            $name = (string) $DB->get_field($table, 'name', ['id' => (int) $cm->instance]);
            if ($name !== '' && stripos($name, $marker) === false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Teacher / editingteacher display names on a course.
     *
     * @return list<string>
     */
    public static function teacher_names(int $courseid): array {
        $names = [];
        foreach (self::teacher_rows($courseid) as $row) {
            $names[] = (string) $row['name'];
        }
        return $names;
    }

    /**
     * Active student enrolments on one course.
     */
    public static function fellow_count(int $courseid): int {
        global $CFG, $DB;

        if ($courseid <= 0) {
            return 0;
        }
        require_once($CFG->libdir . '/enrollib.php');

        $sql = "SELECT COUNT(DISTINCT ue.userid)
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                  JOIN {context} ctx ON ctx.instanceid = e.courseid AND ctx.contextlevel = :ctxlevel
                  JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.userid = ue.userid
                  JOIN {role} r ON r.id = ra.roleid AND r.archetype = :arch
                 WHERE e.courseid = :courseid
                   AND ue.status = :uestatus
                   AND e.status = :estatus";
        return (int) $DB->count_records_sql($sql, [
            'courseid' => $courseid,
            'ctxlevel' => CONTEXT_COURSE,
            'arch' => 'student',
            'uestatus' => ENROL_USER_ACTIVE,
            'estatus' => ENROL_INSTANCE_ENABLED,
        ]);
    }

    /**
     * Short capstone line for a track card (from course summary when useful).
     */
    public static function capstone_line(\stdClass $course): string {
        $summary = trim(html_to_text((string) ($course->summary ?? ''), 0, false));
        $summary = preg_replace('/\s+/', ' ', $summary) ?? $summary;
        // Blueprint stub copy is not a real capstone blurb — use the default.
        if ($summary === '' || stripos($summary, 'placeholder') !== false) {
            return get_string('admin_tracks_capstone_default', 'local_launchpad');
        }
        if (\core_text::strlen($summary) > 120) {
            $summary = \core_text::substr($summary, 0, 117) . '…';
        }
        return get_string('admin_tracks_capstone', 'local_launchpad', $summary);
    }

    /**
     * Cohort slug from track idnumber (lp-ai-pilot → pilot).
     */
    public static function cohort_slug_from_track_idnumber(string $idnumber): ?string {
        $idnumber = strtolower(trim($idnumber));
        if (preg_match('/^lp-(?:ai|fullstack|pm|marketing|startup)-(.+)$/', $idnumber, $m)) {
            return $m[1] !== '' ? $m[1] : null;
        }
        return null;
    }

    /**
     * Most common cohort slug among track courses.
     *
     * @param list<\stdClass> $tracks
     */
    public static function primary_cohort_slug(array $tracks): ?string {
        $votes = [];
        foreach ($tracks as $course) {
            $slug = self::cohort_slug_from_track_idnumber((string) ($course->idnumber ?? ''));
            if ($slug !== null) {
                $votes[$slug] = ($votes[$slug] ?? 0) + 1;
            }
        }
        if ($votes === []) {
            return null;
        }
        arsort($votes);
        return (string) array_key_first($votes);
    }

    /**
     * Distinct cohort slug count among track courses.
     *
     * @param list<\stdClass> $tracks
     */
    public static function distinct_cohort_count(array $tracks): int {
        $seen = [];
        foreach ($tracks as $course) {
            $slug = self::cohort_slug_from_track_idnumber((string) ($course->idnumber ?? ''));
            if ($slug !== null) {
                $seen[$slug] = true;
            }
        }
        return count($seen);
    }

    /**
     * Human cohort label for tile subtitle (from orientation name or slug).
     */
    public static function cohort_display_label(string $slug, ?\stdClass $orient = null): string {
        if ($orient !== null) {
            $name = format_string($orient->fullname);
            // "LaunchPad Orientation — Cohort Pilot" → "Pilot".
            if (preg_match('/cohort\s+(.+)$/i', $name, $m)) {
                return trim($m[1]);
            }
        }
        $slug = strtolower(trim($slug));
        $slug = str_replace(['-', '_'], ' ', $slug);
        return $slug !== '' ? \core_text::strtotitle($slug) : $slug;
    }

    /**
     * Orientation course for a cohort slug (lp-orient-{slug}).
     */
    public static function orientation_course_for_slug(string $slug): ?\stdClass {
        global $DB;

        $slug = strtolower(trim($slug));
        if ($slug === '') {
            return null;
        }
        $course = $DB->get_record('course', [
            'idnumber' => self::ORIENT_IDNUMBER_PREFIX . $slug,
        ]);
        return $course ?: null;
    }

    /**
     * Earliest non-zero course startdate among tracks.
     *
     * @param list<\stdClass> $tracks
     */
    public static function earliest_track_startdate(array $tracks): int {
        $min = 0;
        foreach ($tracks as $course) {
            $ts = (int) ($course->startdate ?? 0);
            if ($ts > 0 && ($min === 0 || $ts < $min)) {
                $min = $ts;
            }
        }
        return $min;
    }

    /**
     * Whether any track has an enabled self-enrolment instance.
     *
     * @param list<\stdClass> $tracks
     */
    public static function any_self_enrolment_enabled(array $tracks): bool {
        global $CFG, $DB;

        require_once($CFG->libdir . '/enrollib.php');

        $ids = [];
        foreach ($tracks as $course) {
            $ids[] = (int) $course->id;
        }
        $ids = array_values(array_filter($ids));
        if ($ids === []) {
            return false;
        }
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'c');
        $params['estatus'] = ENROL_INSTANCE_ENABLED;
        $sql = "SELECT COUNT(1)
                  FROM {enrol} e
                 WHERE e.enrol = 'self'
                   AND e.status = :estatus
                   AND e.courseid {$insql}";
        return ((int) $DB->count_records_sql($sql, $params)) > 0;
    }

    /**
     * Recent student enrolments on track courses (newest first).
     *
     * @return list<array{fellow:string,email:string,track:string,when:string}>
     */
    public static function recent_track_enrolments(int $limit = 12): array {
        global $CFG, $DB;

        require_once($CFG->libdir . '/enrollib.php');

        $tracks = self::list_track_courses();
        $ids = array_map(static fn($c) => (int) $c->id, $tracks);
        $ids = array_values(array_filter($ids));
        if ($ids === []) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'c');
        $params += [
            'ctxlevel' => CONTEXT_COURSE,
            'arch' => 'student',
            'uestatus' => ENROL_USER_ACTIVE,
            'estatus' => ENROL_INSTANCE_ENABLED,
        ];
        $limit = max(1, min(50, $limit));
        $sql = "SELECT ue.id, ue.timecreated, u.id AS userid, u.firstname, u.lastname,
                       u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename,
                       u.email, c.fullname AS coursefullname
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                  JOIN {course} c ON c.id = e.courseid
                  JOIN {user} u ON u.id = ue.userid AND u.deleted = 0
                  JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = :ctxlevel
                  JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.userid = u.id
                  JOIN {role} r ON r.id = ra.roleid AND r.archetype = :arch
                 WHERE ue.status = :uestatus
                   AND e.status = :estatus
                   AND c.id {$insql}
              ORDER BY ue.timecreated DESC, ue.id DESC";
        $rows = $DB->get_records_sql($sql, $params, 0, $limit);
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'fellow' => fullname($row),
                'email' => s($row->email),
                'track' => format_string($row->coursefullname),
                'when' => !empty($row->timecreated)
                    ? userdate((int) $row->timecreated, get_string('strftimedatetimeshort', 'langconfig'))
                    : '—',
            ];
        }
        return $out;
    }
}
