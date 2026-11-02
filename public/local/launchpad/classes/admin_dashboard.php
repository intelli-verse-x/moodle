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

    /**
     * Tracks & courses page (HTML a-tracks) — card grid from live Moodle.
     *
     * No Course builder yet — card CTA opens the Moodle course.
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

        $filtered = [];
        foreach ($all as $row) {
            if ($tab === 'all' || $row['role_key'] === $tab) {
                $filtered[] = $row;
            }
        }

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
            'tabs' => $tabs,
            'col_name' => get_string('admin_people_col_name', 'local_launchpad'),
            'col_email' => get_string('admin_people_col_email', 'local_launchpad'),
            'col_role' => get_string('admin_people_col_role', 'local_launchpad'),
            'col_reach' => get_string('admin_people_col_reach', 'local_launchpad'),
            'people' => $filtered,
            'has_people' => $filtered !== [],
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
        $marker = '[placeholder';
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
