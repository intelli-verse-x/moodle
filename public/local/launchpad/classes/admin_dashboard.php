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

        return [
            'firstname' => $firstname,
            'welcome_back' => get_string('admin_welcome_back', 'local_launchpad', $firstname),
            'eyebrow' => get_string('admin_overview_eyebrow', 'local_launchpad'),
            'heading' => get_string('admin_overview_heading', 'local_launchpad'),
            'lead' => get_string('admin_overview_lead', 'local_launchpad'),
            'moodle_admin_url' => (new \moodle_url('/admin/search.php'))->out(false),
            'moodle_admin_label' => get_string('admin_open_moodle_admin', 'local_launchpad'),
            'kpi_tracks_ready' => $kpis['tracks_ready'],
            'kpi_tracks_total' => $kpis['tracks_total'],
            'kpi_fellows' => $kpis['fellows'],
            'kpi_teachers' => $kpis['teachers'],
            'kpi_reviews_pending' => $kpis['reviews_pending'],
            'kpi_tracks_label' => get_string('admin_kpi_tracks_ready', 'local_launchpad'),
            'kpi_fellows_label' => get_string('admin_kpi_fellows', 'local_launchpad'),
            'kpi_teachers_label' => get_string('admin_kpi_teachers', 'local_launchpad'),
            'kpi_reviews_label' => get_string('admin_kpi_reviews', 'local_launchpad'),
            'kpi_tracks_meta' => get_string(
                'admin_kpi_tracks_meta',
                'local_launchpad',
                (object) ['ready' => $kpis['tracks_ready'], 'total' => $kpis['tracks_total']]
            ),
        ] + $visibility + $attention;
    }

    /** Substring that marks unfinished structure-CLI placeholder activities. */
    public const PLACEHOLDER_MARKER = '[placeholder';

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

        return [
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
            'empty_body' => get_string('admin_tracks_empty', 'local_launchpad'),
        ];
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
            return [
                'eyebrow' => get_string('admin_builder_eyebrow', 'local_launchpad'),
                'heading' => get_string('admin_builder_heading', 'local_launchpad'),
                'lead' => get_string('admin_builder_lead', 'local_launchpad'),
                'has_tracks' => false,
                'empty_body' => get_string('admin_builder_empty', 'local_launchpad'),
                'flash_ok' => !empty($flash['ok']),
                'flash_ok_body' => (string) ($flash['ok'] ?? ''),
                'flash_err' => !empty($flash['error']),
                'flash_err_body' => (string) ($flash['error'] ?? ''),
            ];
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
            return [
                'has_course' => false,
                'empty_body' => get_string('admin_builder_empty', 'local_launchpad'),
                'builder_url' => (new \moodle_url(persona_home::ADMIN_PATH, ['view' => 'builder']))->out(false),
                'builder_label' => get_string('admin_nav_builder', 'local_launchpad'),
                'flash_ok' => !empty($flash['ok']),
                'flash_ok_body' => (string) ($flash['ok'] ?? ''),
                'flash_err' => !empty($flash['error']),
                'flash_err_body' => (string) ($flash['error'] ?? ''),
            ];
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
            return ['error' => $e->getMessage()];
        }
        if (!self::is_track_idnumber((string) $course->idnumber)) {
            return ['error' => get_string('admin_mentors_err_not_track', 'local_launchpad')];
        }

        $context = \context_course::instance($trackcourseid);
        try {
            require_capability('moodle/course:manageactivities', $context);
        } catch (\required_capability_exception $e) {
            return ['error' => $e->getMessage()];
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
            return ['error' => $e->getMessage()];
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
            return ['error' => $e->getMessage()];
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
                return ['error' => $e2->getMessage()];
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

        if ($trackid <= 0 || $cmid <= 0) {
            return [
                'has_activity' => false,
                'empty_body' => get_string('admin_builder_err_params', 'local_launchpad'),
                'manage_url' => $manageurl,
                'back_label' => get_string('admin_activity_back', 'local_launchpad'),
                'flash_ok' => !empty($flash['ok']),
                'flash_ok_body' => (string) ($flash['ok'] ?? ''),
                'flash_err' => !empty($flash['error']),
                'flash_err_body' => (string) ($flash['error'] ?? ''),
            ];
        }

        try {
            $course = account_provision::require_launchpad_course($trackid);
        } catch (account_provision_exception $e) {
            return [
                'has_activity' => false,
                'empty_body' => $e->getMessage(),
                'manage_url' => $manageurl,
                'back_label' => get_string('admin_activity_back', 'local_launchpad'),
                'flash_ok' => false,
                'flash_ok_body' => '',
                'flash_err' => true,
                'flash_err_body' => $e->getMessage(),
            ];
        }

        $cm = get_coursemodule_from_id('', $cmid, $trackid, false, IGNORE_MISSING);
        if (!$cm) {
            return [
                'has_activity' => false,
                'empty_body' => get_string('admin_builder_err_cm', 'local_launchpad'),
                'manage_url' => $manageurl,
                'back_label' => get_string('admin_activity_back', 'local_launchpad'),
                'flash_ok' => !empty($flash['ok']),
                'flash_ok_body' => (string) ($flash['ok'] ?? ''),
                'flash_err' => !empty($flash['error']),
                'flash_err_body' => (string) ($flash['error'] ?? ''),
            ];
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
                'empty_body' => get_string('admin_manage_err_mod', 'local_launchpad'),
                'manage_url' => $manageurl,
                'back_label' => get_string('admin_activity_back', 'local_launchpad'),
                'flash_ok' => false,
                'flash_ok_body' => '',
                'flash_err' => true,
                'flash_err_body' => get_string('admin_manage_err_mod', 'local_launchpad'),
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
            'file_hint' => get_string('admin_activity_file_hint', 'local_launchpad'),
            'quiz_hint' => get_string('admin_activity_quiz_hint', 'local_launchpad'),
            'assign_hint' => get_string('admin_activity_assign_hint', 'local_launchpad'),
            'save_label' => get_string('admin_activity_save', 'local_launchpad'),
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
     * Save slim activity settings (name / intro / page content / grade).
     * Uses direct instance updates so we skip Moodle editor draft plumbing.
     *
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
        ?bool $trackcompletion = null
    ): array {
        global $CFG, $DB;

        unset($actor);
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->libdir . '/completionlib.php');

        if ($trackcourseid <= 0 || $cmid <= 0) {
            return ['error' => get_string('admin_builder_err_params', 'local_launchpad')];
        }
        try {
            $course = account_provision::require_launchpad_course($trackcourseid);
        } catch (account_provision_exception $e) {
            return ['error' => $e->getMessage()];
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
            return ['error' => $e->getMessage()];
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
            rebuild_course_cache($trackcourseid, true);
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }

        return [
            'ok' => get_string('admin_activity_ok_saved', 'local_launchpad', format_string($name)),
        ];
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
            $out[] = [
                'cmid' => $cmid,
                'modname' => $modname,
                'kind' => self::builder_mod_kind($modname),
                'title' => format_string($instancename !== '' ? $instancename : $modname),
                'state' => $state,
                'state_label' => get_string('admin_builder_state_' . $state, 'local_launchpad'),
                'state_chip' => $statechip,
                'is_draft' => $isdraft,
                'cm_visible' => $cmvisible,
                'cm_hidden' => !$cmvisible,
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
            return ['error' => $e->getMessage()];
        }
        if (!self::is_track_idnumber((string) $course->idnumber)) {
            return ['error' => get_string('admin_mentors_err_not_track', 'local_launchpad')];
        }

        $context = \context_course::instance($trackcourseid);
        try {
            require_capability('moodle/course:manageactivities', $context);
        } catch (\required_capability_exception $e) {
            return ['error' => $e->getMessage()];
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
            return ['error' => $e->getMessage()];
        }
        if (!self::is_track_idnumber((string) $course->idnumber)) {
            return ['error' => get_string('admin_mentors_err_not_track', 'local_launchpad')];
        }

        $context = \context_course::instance($trackcourseid);
        try {
            require_capability('moodle/course:update', $context);
        } catch (\required_capability_exception $e) {
            return ['error' => $e->getMessage()];
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
    public static function cohorts_context(\stdClass $user, array $flash = []): array {
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
            return ['error' => $e->getMessage()];
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
            return ['error' => $e->getMessage()];
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

        return [
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
            'empty_body' => get_string('admin_people_empty', 'local_launchpad'),
            'footnote' => get_string('admin_people_footnote', 'local_launchpad'),
        ];
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
    public static function reviews_context(\stdClass $user): array {
        $pending = self::list_pending_reviews(50);
        $pendingtotal = self::count_reviews_pending();
        $released = self::count_reviews_released();
        $tracks = self::list_track_courses();
        $bytrackpending = self::pending_counts_by_course($tracks);
        $other = 0;
        foreach ($tracks as $course) {
            if (empty($bytrackpending[(int) $course->id])) {
                $other++;
            }
        }

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
                'label' => get_string('admin_reviews_tile_released', 'local_launchpad'),
                'value' => (string) $released,
                'sub' => get_string('admin_reviews_tile_released_sub', 'local_launchpad'),
                'icon_svg' => $icon('<path d="M4 12l5 5L20 6"/>'),
            ],
            [
                'label' => get_string('admin_reviews_tile_other', 'local_launchpad'),
                'value' => (string) $other,
                'sub' => get_string('admin_reviews_tile_other_sub', 'local_launchpad'),
                'icon_svg' => $icon(
                    '<path d="M12 3l8 4v5c0 5-3.5 8-8 9-4.5-1-8-4-8-9V7z"/>'
                    . '<path d="M8 12h8M12 8v8"/>'
                ),
            ],
        ];

        return [
            'eyebrow' => get_string('admin_reviews_eyebrow', 'local_launchpad'),
            'heading' => get_string('admin_reviews_heading', 'local_launchpad'),
            'lead' => get_string('admin_reviews_lead', 'local_launchpad'),
            'tiles' => $tiles,
            'col_fellow' => get_string('admin_reviews_col_fellow', 'local_launchpad'),
            'col_track' => get_string('admin_reviews_col_track', 'local_launchpad'),
            'col_submitted' => get_string('admin_reviews_col_submitted', 'local_launchpad'),
            'col_teacher' => get_string('admin_reviews_col_teacher', 'local_launchpad'),
            'col_state' => get_string('admin_reviews_col_state', 'local_launchpad'),
            'pending' => $pending,
            'has_pending' => $pending !== [],
            'empty_body' => get_string('admin_reviews_empty', 'local_launchpad'),
            'open_label' => get_string('admin_reviews_open', 'local_launchpad'),
        ];
    }

    /**
     * Pending assign submissions on LaunchPad courses (oldest first).
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
        $limit = max(1, min(100, $limit));

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

        $rows = $DB->get_records_sql($sql, $params, 0, $limit);
        $teachercache = [];
        $out = [];
        foreach ($rows as $row) {
            $courseid = (int) $row->courseid;
            if (!isset($teachercache[$courseid])) {
                $names = self::teacher_names($courseid);
                $teachercache[$courseid] = $names !== []
                    ? implode(', ', $names)
                    : get_string('admin_tracks_no_teacher', 'local_launchpad');
            }
            $submitted = (int) ($row->timemodified ?: $row->timecreated);
            $out[] = [
                'courseid' => $courseid,
                'fellow' => fullname($row),
                'email' => s($row->email),
                'track' => format_string($row->coursefullname),
                'assign' => format_string($row->assignname),
                'submitted' => $submitted > 0
                    ? userdate($submitted, get_string('strftimedatetimeshort', 'langconfig'))
                    : '—',
                'teacher' => $teachercache[$courseid],
                'state' => get_string('admin_reviews_state_pending', 'local_launchpad'),
                'grader_url' => (new \moodle_url('/mod/assign/view.php', [
                    'id' => (int) $row->cmid,
                    'action' => 'grader',
                    'userid' => (int) $row->userid,
                ]))->out(false),
            ];
        }
        return $out;
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
    public static function mentors_context(\stdClass $user, array $flash = []): array {
        $tracks = self::list_track_courses();
        $cards = [];
        foreach ($tracks as $course) {
            $cards[] = self::mentor_track_card_context($course);
        }

        return [
            'eyebrow' => get_string('admin_mentors_eyebrow', 'local_launchpad'),
            'heading' => get_string('admin_mentors_heading', 'local_launchpad'),
            'lead' => get_string('admin_mentors_lead', 'local_launchpad'),
            'has_tracks' => $cards !== [],
            'tracks' => $cards,
            'empty_body' => get_string('admin_mentors_empty', 'local_launchpad'),
            'flash_ok' => !empty($flash['ok']),
            'flash_ok_body' => (string) ($flash['ok'] ?? ''),
            'flash_err' => !empty($flash['error']),
            'flash_err_body' => (string) ($flash['error'] ?? ''),
            'form_action' => (new \moodle_url(persona_home::ADMIN_PATH, ['view' => 'mentors']))->out(false),
            'sesskey' => sesskey(),
        ];
    }

    /**
     * One Mentors & capacity track card.
     *
     * @param \stdClass $course
     * @return array<string, mixed>
     */
    public static function mentor_track_card_context(\stdClass $course): array {
        $courseid = (int) $course->id;
        $teachers = self::list_track_teachers($courseid);
        $fellows = self::fellow_count($courseid);
        $hasteacher = $teachers !== [];
        $primary = $hasteacher ? $teachers[0] : null;

        return [
            'id' => $courseid,
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
            return ['error' => $e->getMessage()];
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
            return ['error' => $e->getMessage()];
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
        foreach ($tracks as $course) {
            $row = [
                'id' => (int) $course->id,
                'name' => format_string($course->fullname),
                'idnumber' => s($course->idnumber),
                'edit_url' => (new \moodle_url('/course/edit.php', ['id' => (int) $course->id]))->out(false),
                'view_url' => (new \moodle_url('/course/view.php', ['id' => (int) $course->id]))->out(false),
            ];
            if ((int) $course->visible === 1) {
                $shown[] = $row;
            } else {
                $hidden[] = $row;
            }
        }

        return [
            'visibility_title' => get_string('admin_visibility_title', 'local_launchpad'),
            'visibility_lead' => get_string('admin_visibility_lead', 'local_launchpad'),
            'visibility_hint' => get_string('admin_visibility_hint', 'local_launchpad'),
            'hidden_count' => count($hidden),
            'shown_count' => count($shown),
            'hidden_label' => get_string('admin_visibility_hidden', 'local_launchpad', count($hidden)),
            'shown_label' => get_string('admin_visibility_shown', 'local_launchpad', count($shown)),
            'has_hidden' => $hidden !== [],
            'has_shown' => $shown !== [],
            'hidden_courses' => $hidden,
            'shown_courses' => $shown,
            'hidden_list_label' => get_string('admin_visibility_hidden_list', 'local_launchpad'),
            'shown_list_label' => get_string('admin_visibility_shown_list', 'local_launchpad'),
            'settings_label' => get_string('admin_visibility_course_settings', 'local_launchpad'),
            'visibility_empty' => $tracks === [],
            'visibility_empty_body' => get_string('admin_visibility_empty', 'local_launchpad'),
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
                ];
            }
        }

        return [
            'attention_title' => get_string('admin_attention_title', 'local_launchpad'),
            'attention_lead' => get_string('admin_attention_lead', 'local_launchpad'),
            'has_attention' => $items !== [],
            'attention_items' => $items,
            'attention_empty' => get_string('admin_attention_empty', 'local_launchpad'),
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
              ORDER BY u.lastname ASC, u.firstname ASC";
        $users = $DB->get_records_sql($sql, ['ctx' => $context->id]);
        $names = [];
        foreach ($users as $u) {
            $names[] = fullname($u);
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
