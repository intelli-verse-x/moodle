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
 * Mentor dashboard helpers — review queue from Moodle assigns (no invented grades).
 *
 * @package    local_launchpad
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class mentor_dashboard {

    /** Marker for mentor shell templates / CSS. */
    public const DATA_REGION = 'local-launchpad-mentor';

    /**
     * Whether this user may open the mentor shell.
     */
    public static function can_access(int $userid): bool {
        if ($userid <= 0) {
            return false;
        }
        return account_persona::is_mentor($userid) || is_siteadmin($userid);
    }

    /**
     * Review-queue rows across mentor courses (one row = fellow × assign).
     *
     * Statuses align with Fellowship Feedback: pending | reviewed | changes | not_submitted.
     *
     * @param list<\stdClass> $courses
     * @param string $filter waiting|released|open|all
     * @return array{
     *   items: list<array>,
     *   has_items: bool,
     *   waiting_count: int,
     *   released_count: int,
     *   open_count: int,
     *   all_count: int,
     *   filter: string,
     *   filters: list<array>
     * }
     */
    public static function list_review_queue(int $userid, array $courses, string $filter = 'waiting'): array {
        global $CFG, $DB;

        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->libdir . '/enrollib.php');

        $allowed = ['waiting', 'released', 'open', 'all'];
        if (!in_array($filter, $allowed, true)) {
            $filter = 'waiting';
        }

        $items = [];
        foreach ($courses as $course) {
            $courseid = (int) $course->id;
            $context = \context_course::instance($courseid);
            // Fellows who can submit assigns on this track.
            $students = get_enrolled_users($context, 'mod/assign:submit', 0, 'u.id,u.firstname,u.lastname', 'u.lastname ASC, u.firstname ASC');
            if ($students === []) {
                continue;
            }

            $modinfo = get_fast_modinfo($course, $userid);
            foreach ($modinfo->get_cms() as $cm) {
                if ($cm->modname !== 'assign' || !$cm->uservisible || $cm->deletioninprogress) {
                    continue;
                }
                $instanceid = (int) $cm->instance;
                $assignname = $cm->get_formatted_name();
                $assignmax = (float) ($DB->get_field('assign', 'grade', ['id' => $instanceid]) ?: 0);

                foreach ($students as $student) {
                    $studentid = (int) $student->id;
                    $latest = $DB->get_record('assign_submission', [
                        'assignment' => $instanceid,
                        'userid' => $studentid,
                        'latest' => 1,
                    ]);
                    $substatus = $latest ? (string) $latest->status : '';
                    $attempt = $latest ? ((int) $latest->attemptnumber + 1) : 0;
                    $submittedat = 0;
                    if ($latest && ($substatus === 'submitted' || $substatus === 'reopened')) {
                        $submittedat = (int) ($latest->timemodified ?: $latest->timecreated);
                    }

                    $hasgrade = false;
                    $gradepct = null;
                    $gradestr = '';
                    $gradedat = 0;
                    $agrades = $DB->get_records_select(
                        'assign_grades',
                        'assignment = :assignment AND userid = :userid AND grade >= 0',
                        ['assignment' => $instanceid, 'userid' => $studentid],
                        'attemptnumber DESC, timemodified DESC',
                        '*',
                        0,
                        1
                    );
                    $agrade = $agrades ? reset($agrades) : false;
                    if ($agrade && $agrade->grade !== null && (float) $agrade->grade >= 0) {
                        $hasgrade = true;
                        $gradedat = (int) ($agrade->timemodified ?: 0);
                        if ($assignmax > 0) {
                            $gradepct = (((float) $agrade->grade) / $assignmax) * 100;
                            $gradestr = format_float($gradepct, 0) . '%';
                        } else {
                            $gradestr = format_float((float) $agrade->grade, 2);
                        }
                    }

                    // Classify (same rules as fellow Feedback).
                    if ($hasgrade && ($substatus === 'reopened' || $substatus === 'draft' || $substatus === 'new')) {
                        $status = 'changes';
                        $chip = 'warn';
                    } else if ($hasgrade) {
                        $status = 'reviewed';
                        $chip = ($gradepct !== null && $gradepct < 70) ? 'warn' : 'ok';
                    } else if ($substatus === 'submitted') {
                        $status = 'pending';
                        $chip = '';
                    } else {
                        $status = 'not_submitted';
                        $chip = 'line';
                    }

                    $statuslabel = match ($status) {
                        'pending' => get_string('mentor_status_waiting', 'local_launchpad'),
                        'reviewed' => get_string('mentor_status_released', 'local_launchpad'),
                        'changes' => get_string('mentor_status_changes', 'local_launchpad'),
                        default => get_string('mentor_status_open', 'local_launchpad'),
                    };

                    $gradeurl = (new \moodle_url('/mod/assign/view.php', [
                        'id' => $cm->id,
                        'action' => 'grader',
                        'userid' => $studentid,
                    ]))->out(false);

                    $items[] = [
                        'fellow_id' => $studentid,
                        'fellow_name' => fullname($student),
                        'assign_name' => $assignname,
                        'course_name' => format_string($course->fullname),
                        'course_idnumber' => s($course->idnumber),
                        'status' => $status,
                        'status_label' => $statuslabel,
                        'chip' => $chip,
                        'attempt' => $attempt,
                        'has_attempt' => $attempt > 0,
                        'submitted_at' => $submittedat
                            ? userdate($submittedat, get_string('strftimedatetimeshort', 'langconfig'))
                            : '',
                        'submitted_ts' => $submittedat,
                        'grade_display' => $gradestr,
                        'has_grade' => $gradestr !== '',
                        'graded_at' => $gradedat
                            ? userdate($gradedat, get_string('strftimedatetimeshort', 'langconfig'))
                            : '',
                        'url' => $gradeurl,
                        'is_waiting' => $status === 'pending',
                        'is_released' => $status === 'reviewed' || $status === 'changes',
                        'is_open' => $status === 'not_submitted',
                    ];
                }
            }
        }

        $waiting = array_values(array_filter($items, static fn(array $r): bool => $r['is_waiting']));
        $released = array_values(array_filter($items, static fn(array $r): bool => $r['is_released']));
        $open = array_values(array_filter($items, static fn(array $r): bool => $r['is_open']));

        $filtered = match ($filter) {
            'waiting' => $waiting,
            'released' => $released,
            'open' => $open,
            default => $items,
        };

        usort($filtered, static function (array $a, array $b): int {
            // Waiting: newest submit first; else by fellow name.
            if (($a['submitted_ts'] ?? 0) || ($b['submitted_ts'] ?? 0)) {
                $cmp = ((int) ($b['submitted_ts'] ?? 0)) <=> ((int) ($a['submitted_ts'] ?? 0));
                if ($cmp !== 0) {
                    return $cmp;
                }
            }
            return strcmp((string) $a['fellow_name'], (string) $b['fellow_name']);
        });

        $base = new \moodle_url(persona_home::MENTOR_PATH, ['view' => 'queue']);
        $filters = [
            [
                'key' => 'waiting',
                'label' => get_string('mentor_filter_waiting', 'local_launchpad'),
                'count' => count($waiting),
                'url' => (new \moodle_url($base, ['filter' => 'waiting']))->out(false),
                'current' => $filter === 'waiting',
            ],
            [
                'key' => 'released',
                'label' => get_string('mentor_filter_released', 'local_launchpad'),
                'count' => count($released),
                'url' => (new \moodle_url($base, ['filter' => 'released']))->out(false),
                'current' => $filter === 'released',
            ],
            [
                'key' => 'open',
                'label' => get_string('mentor_filter_open', 'local_launchpad'),
                'count' => count($open),
                'url' => (new \moodle_url($base, ['filter' => 'open']))->out(false),
                'current' => $filter === 'open',
            ],
            [
                'key' => 'all',
                'label' => get_string('mentor_filter_all', 'local_launchpad'),
                'count' => count($items),
                'url' => (new \moodle_url($base, ['filter' => 'all']))->out(false),
                'current' => $filter === 'all',
            ],
        ];

        return [
            'items' => $filtered,
            'has_items' => $filtered !== [],
            'waiting_count' => count($waiting),
            'released_count' => count($released),
            'open_count' => count($open),
            'all_count' => count($items),
            'filter' => $filter,
            'filters' => $filters,
        ];
    }

    /**
     * Dashboard summary for mentor home.
     *
     * @param list<\stdClass> $courses
     * @return array
     */
    public static function home_summary(int $userid, array $courses): array {
        $queue = self::list_review_queue($userid, $courses, 'all');
        $waiting = array_values(array_filter(
            $queue['items'] ?? [],
            static fn(array $r): bool => !empty($r['is_waiting'])
        ));
        usort($waiting, static fn(array $a, array $b): int =>
            ((int) ($b['submitted_ts'] ?? 0)) <=> ((int) ($a['submitted_ts'] ?? 0)));
        $preview = array_slice($waiting, 0, 5);

        $coursecards = [];
        foreach ($courses as $c) {
            $coursecards[] = [
                'fullname' => format_string($c->fullname),
                'idnumber' => s($c->idnumber),
                'moodle_url' => (new \moodle_url('/course/view.php', ['id' => $c->id]))->out(false),
            ];
        }

        $waitingcount = (int) ($queue['waiting_count'] ?? 0);

        return [
            'waiting_count' => $waitingcount,
            'waiting_label' => get_string('mentor_waiting_count', 'local_launchpad', $waitingcount),
            'released_count' => (int) ($queue['released_count'] ?? 0),
            'open_count' => (int) ($queue['open_count'] ?? 0),
            'has_waiting' => $preview !== [],
            'waiting_preview' => $preview,
            'courses' => $coursecards,
            'has_courses' => $coursecards !== [],
            'queue_url' => (new \moodle_url(persona_home::MENTOR_PATH, ['view' => 'queue']))->out(false),
        ];
    }
}
