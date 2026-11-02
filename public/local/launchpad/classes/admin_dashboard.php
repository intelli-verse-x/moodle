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
        $sql = "SELECT id, fullname, shortname, idnumber, visible, summary
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
}
