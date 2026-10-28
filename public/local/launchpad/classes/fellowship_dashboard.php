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
 * Fellowship dashboard data (enrolments, activities, completion).
 *
 * Read-only helpers for the Fellow shell. Never invents XP, grades, or
 * unlocks — only Moodle enrolments, visibility, and completion_info.
 *
 * @package    local_launchpad
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class fellowship_dashboard {

    /** Marker for automated checks. */
    public const DATA_REGION = 'local-launchpad-fellowship';

    /**
     * LaunchPad courses where the user is an active student.
     *
     * @param int $userid
     * @return list<\stdClass> id, fullname, shortname, idnumber, summary
     */
    public static function list_courses(int $userid): array {
        global $CFG, $DB;

        if ($userid <= 0) {
            return [];
        }

        require_once($CFG->libdir . '/enrollib.php');

        $sql = "SELECT DISTINCT c.id, c.fullname, c.shortname, c.idnumber, c.summary, c.summaryformat
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                  JOIN {course} c ON c.id = e.courseid
                  JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = :ctxlevel
                  JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.userid = ue.userid
                  JOIN {role} r ON r.id = ra.roleid AND r.archetype = :arch
                 WHERE ue.userid = :userid
                   AND ue.status = :uestatus
                   AND e.status = :estatus
                   AND " . $DB->sql_like('c.idnumber', ':idn', false) . "
              ORDER BY c.fullname ASC";

        return array_values($DB->get_records_sql($sql, [
            'userid' => $userid,
            'uestatus' => ENROL_USER_ACTIVE,
            'estatus' => ENROL_INSTANCE_ENABLED,
            'ctxlevel' => CONTEXT_COURSE,
            'arch' => 'student',
            'idn' => $DB->sql_like_escape(account_persona::COURSE_IDNUMBER_PREFIX) . '%',
        ]));
    }

    /**
     * Visible course modules for a fellow, grouped by section, with completion.
     *
     * @param \stdClass $course
     * @param int $userid
     * @return array{
     *   sections: list<array{name: string, activities: list<array>}>,
     *   completion_enabled: bool,
     *   completed: int,
     *   trackable: int,
     *   percent: int|null,
     *   next: ?array
     * }
     */
    public static function course_outline(\stdClass $course, int $userid): array {
        global $CFG;

        require_once($CFG->libdir . '/completionlib.php');

        $modinfo = get_fast_modinfo($course, $userid);
        $completion = new \completion_info($course);
        $completionenabled = $completion->is_enabled();

        $sections = [];
        $completed = 0;
        $trackable = 0;
        $next = null;

        foreach ($modinfo->get_section_info_all() as $section) {
            if ($section->section <= 0 && empty($section->sequence)) {
                continue;
            }
            $cms = $modinfo->get_cms();
            $activities = [];
            $sectioncms = $modinfo->get_sections()[$section->section] ?? [];
            foreach ($sectioncms as $cmid) {
                if (!isset($cms[$cmid])) {
                    continue;
                }
                $cm = $cms[$cmid];
                if (!$cm->uservisible || $cm->deletioninprogress) {
                    continue;
                }
                // Skip labels (no dedicated learner destination).
                if ($cm->modname === 'label') {
                    continue;
                }

                $iscomplete = false;
                $tracked = false;
                if ($completionenabled && $completion->is_enabled($cm) != COMPLETION_TRACKING_NONE) {
                    $tracked = true;
                    $trackable++;
                    $data = $completion->get_data($cm, false, $userid);
                    $state = (int) $data->completionstate;
                    $iscomplete = ($state === COMPLETION_COMPLETE || $state === COMPLETION_COMPLETE_PASS);
                    if ($iscomplete) {
                        $completed++;
                    }
                }

                $url = $cm->url ? $cm->url->out(false) : (new \moodle_url('/mod/' . $cm->modname . '/view.php', [
                    'id' => $cm->id,
                ]))->out(false);

                $item = [
                    'cmid' => (int) $cm->id,
                    'name' => $cm->get_formatted_name(),
                    'modname' => $cm->modname,
                    'modname_display' => get_string('modulename', 'mod_' . $cm->modname),
                    'url' => $url,
                    'tracked' => $tracked,
                    'complete' => $iscomplete,
                    'icon_class' => self::mod_icon_class($cm->modname),
                ];
                $activities[] = $item;

                if ($next === null && (!$tracked || !$iscomplete)) {
                    $next = $item;
                }
            }

            if ($activities === [] && $section->section === 0) {
                continue;
            }

            if ($activities === []) {
                continue;
            }

            $secname = get_section_name($course, $section);
            if (trim((string) $secname) === '') {
                $secname = $section->section === 0
                    ? get_string('fellowship_section_general', 'local_launchpad')
                    : get_string('fellowship_section_numbered', 'local_launchpad', $section->section);
            }

            $sections[] = [
                'name' => $secname,
                'sectionnum' => (int) $section->section,
                'activities' => $activities,
                'has_activities' => true,
            ];
        }

        // Drop empty trailing sections with no activities.
        $sections = array_values(array_filter($sections, static function (array $s): bool {
            return !empty($s['has_activities']);
        }));

        $percent = null;
        if ($completionenabled && $trackable > 0) {
            $percent = (int) round(($completed / $trackable) * 100);
        }

        return [
            'sections' => $sections,
            'completion_enabled' => $completionenabled,
            'completed' => $completed,
            'trackable' => $trackable,
            'percent' => $percent,
            'next' => $next,
        ];
    }

    /**
     * Simple icon hint by module name (CSS class suffix).
     */
    public static function mod_icon_class(string $modname): string {
        return match ($modname) {
            'quiz' => 'quiz',
            'assign' => 'assign',
            'page' => 'page',
            'resource', 'folder', 'url' => 'resource',
            'forum' => 'forum',
            default => 'activity',
        };
    }

    /**
     * Whether the user may open the Fellowship shell.
     */
    public static function can_access(int $userid): bool {
        if ($userid <= 0) {
            return false;
        }
        if (is_siteadmin($userid)) {
            // Admins may preview; persona_home still does not force them here.
            return true;
        }
        return account_persona::is_fellowship($userid);
    }

    /**
     * Two-letter initials for the avatar chip.
     *
     * @param \stdClass $user
     */
    public static function initials(\stdClass $user): string {
        $a = self::first_letter((string) ($user->firstname ?? ''));
        $b = self::first_letter((string) ($user->lastname ?? ''));
        $out = $a . $b;
        return $out !== '' ? $out : '?';
    }

    /**
     * Uppercase first character without requiring Moodle bootstrap in smoke tests.
     */
    private static function first_letter(string $value): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (class_exists(\core_text::class, false)) {
            return \core_text::strtoupper(\core_text::substr($value, 0, 1));
        }
        if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
            return mb_strtoupper(mb_substr($value, 0, 1));
        }
        return strtoupper(substr($value, 0, 1));
    }
}
