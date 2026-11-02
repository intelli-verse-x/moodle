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
        // Site admins use admin.php — do not linger on the Mentor shell.
        return account_persona::is_mentor($userid);
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

                    // Prefer grade for latest attempt; if latest is only reopened/draft, use prior mark.
                    // Fresh submitted attempt with no grade stays waiting (do not inherit old marks).
                    $hasgrade = false;
                    $gradepct = null;
                    $gradestr = '';
                    $gradedat = 0;
                    $attemptnumber = $latest ? (int) $latest->attemptnumber : -1;
                    $agrade = false;
                    if ($attemptnumber >= 0) {
                        $agrade = $DB->get_record('assign_grades', [
                            'assignment' => $instanceid,
                            'userid' => $studentid,
                            'attemptnumber' => $attemptnumber,
                        ]);
                        if (!($agrade && $agrade->grade !== null && (float) $agrade->grade >= 0)) {
                            $agrade = false;
                        }
                    }
                    if (!$agrade && $latest && in_array($substatus, ['reopened', 'draft', 'new'], true)) {
                        $prior = $DB->get_records_select(
                            'assign_grades',
                            'assignment = :assignment AND userid = :userid AND grade >= 0 AND attemptnumber < :attempt',
                            [
                                'assignment' => $instanceid,
                                'userid' => $studentid,
                                'attempt' => max(0, $attemptnumber),
                            ],
                            'attemptnumber DESC, timemodified DESC',
                            '*',
                            0,
                            1
                        );
                        $agrade = $prior ? reset($prior) : false;
                    }
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

                    $gradeurl = self::review_url((int) $cm->id, $studentid);

                    $items[] = [
                        'fellow_id' => $studentid,
                        'fellow_name' => fullname($student),
                        'assign_name' => $assignname,
                        'course_id' => $courseid,
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
     * Fellows enrolled on mentor courses (students who can submit assigns).
     *
     * Activity counts come from Moodle completion when enabled; review state from assigns.
     *
     * @param list<\stdClass> $courses
     * @return array{items: list<array>, has_items: bool, fellow_count: int}
     */
    public static function list_fellows(int $userid, array $courses): array {
        global $CFG;

        require_once($CFG->libdir . '/enrollib.php');

        $queue = self::list_review_queue($userid, $courses, 'all');
        $byfellow = [];
        foreach ($queue['items'] ?? [] as $row) {
            $fid = (int) ($row['fellow_id'] ?? 0);
            $cid = (int) ($row['course_id'] ?? 0);
            if ($fid <= 0 || $cid <= 0) {
                continue;
            }
            $fkey = $fid . ':' . $cid;
            if (!isset($byfellow[$fkey])) {
                $byfellow[$fkey] = [
                    'waiting' => 0,
                    'released' => 0,
                    'grade_display' => '',
                    'first_waiting_url' => '',
                ];
            }
            if (!empty($row['is_waiting'])) {
                $byfellow[$fkey]['waiting']++;
                if ($byfellow[$fkey]['first_waiting_url'] === '') {
                    $byfellow[$fkey]['first_waiting_url'] = (string) ($row['url'] ?? '');
                }
            }
            if (!empty($row['is_released'])) {
                $byfellow[$fkey]['released']++;
                if ($byfellow[$fkey]['grade_display'] === '' && !empty($row['has_grade'])) {
                    $byfellow[$fkey]['grade_display'] = (string) ($row['grade_display'] ?? '');
                }
            }
        }

        $items = [];
        $seen = [];
        foreach ($courses as $course) {
            $courseid = (int) $course->id;
            $context = \context_course::instance($courseid);
            $students = get_enrolled_users(
                $context,
                'mod/assign:submit',
                0,
                'u.id,u.firstname,u.lastname',
                'u.lastname ASC, u.firstname ASC'
            );
            foreach ($students as $student) {
                $studentid = (int) $student->id;
                $key = $studentid . ':' . $courseid;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $outline = fellowship_dashboard::course_outline($course, $studentid);
                $completed = (int) ($outline['completed'] ?? 0);
                $trackable = (int) ($outline['trackable'] ?? 0);
                $percent = $outline['percent'] ?? null;
                $hasprogress = $percent !== null;

                $q = $byfellow[$studentid . ':' . $courseid] ?? [
                    'waiting' => 0,
                    'released' => 0,
                    'grade_display' => '',
                    'first_waiting_url' => '',
                ];

                if ($q['waiting'] > 0) {
                    $status = 'pending';
                    $statuslabel = get_string('mentor_status_waiting', 'local_launchpad');
                    $chip = '';
                } else if ($q['released'] > 0) {
                    $status = 'reviewed';
                    $statuslabel = get_string('mentor_fellow_status_reviewed', 'local_launchpad');
                    $chip = 'ok';
                } else if ($hasprogress && $completed > 0) {
                    $status = 'active';
                    $statuslabel = get_string('mentor_fellow_status_active', 'local_launchpad');
                    $chip = 'line';
                } else {
                    $status = 'not_started';
                    $statuslabel = get_string('mentor_fellow_status_new', 'local_launchpad');
                    $chip = 'line';
                }

                $progresslabel = $hasprogress
                    ? get_string('mentor_fellow_progress', 'local_launchpad', [
                        'done' => $completed,
                        'total' => $trackable,
                    ])
                    : get_string('mentor_fellow_progress_untracked', 'local_launchpad');

                $items[] = [
                    'fellow_id' => $studentid,
                    'fellow_name' => fullname($student),
                    'initials' => fellowship_dashboard::initials($student),
                    'course_id' => $courseid,
                    'course_name' => format_string($course->fullname),
                    'course_idnumber' => s($course->idnumber),
                    'completed' => $completed,
                    'trackable' => $trackable,
                    'percent' => $hasprogress ? (int) $percent : 0,
                    'has_percent' => $hasprogress,
                    'progress_label' => $progresslabel,
                    'status' => $status,
                    'status_label' => $statuslabel,
                    'chip' => $chip,
                    'waiting_count' => (int) $q['waiting'],
                    'has_waiting' => $q['waiting'] > 0,
                    'grade_display' => $q['grade_display'] !== '' ? $q['grade_display'] : '—',
                    'detail_url' => self::fellow_detail_url($studentid, $courseid),
                    'profile_url' => (new \moodle_url('/user/view.php', [
                        'id' => $studentid,
                        'course' => $courseid,
                    ]))->out(false),
                    'can_message' => messaging::can_message($userid, $studentid),
                    'message_url' => messaging::messages_url($studentid, 'mentor'),
                    'review_url' => $q['first_waiting_url'] !== ''
                        ? $q['first_waiting_url']
                        : (new \moodle_url(persona_home::MENTOR_PATH, ['view' => 'queue']))->out(false),
                ];
            }
        }

        $count = count($items);

        return [
            'items' => $items,
            'has_items' => $items !== [],
            'fellow_count' => $count,
            'fellow_count_label' => get_string('mentor_fellows_count', 'local_launchpad', $count),
        ];
    }

    /**
     * LaunchPad review URL (ToBa chrome; grades via Moodle assign).
     */
    public static function review_url(int $cmid, int $fellowid): string {
        return (new \moodle_url(persona_home::MENTOR_PATH, [
            'view' => 'review',
            'id' => $cmid,
            'userid' => $fellowid,
        ]))->out(false);
    }

    /**
     * LaunchPad per-fellow coaching URL for one mentor track course.
     */
    public static function fellow_detail_url(int $fellowid, int $courseid): string {
        return (new \moodle_url(persona_home::MENTOR_PATH, [
            'view' => 'fellow',
            'userid' => $fellowid,
            'course' => $courseid,
        ]))->out(false);
    }

    /**
     * One fellow on one mentor course: progress + assign rows (fail closed).
     *
     * @param list<\stdClass> $courses Mentor course list already scoped for this mentor
     * @return array|null Null when mentor/course/fellow access is invalid
     */
    public static function get_fellow_detail(int $mentorid, int $fellowid, int $courseid, array $courses): ?array {
        global $CFG;

        require_once($CFG->libdir . '/enrollib.php');

        if ($mentorid <= 0 || $fellowid <= 0 || $courseid <= 0 || !self::can_access($mentorid)) {
            return null;
        }

        $course = null;
        foreach ($courses as $c) {
            if ((int) $c->id === $courseid) {
                $course = $c;
                break;
            }
        }
        if (!$course) {
            return null;
        }

        $context = \context_course::instance($courseid);
        $students = get_enrolled_users(
            $context,
            'mod/assign:submit',
            0,
            'u.id,u.firstname,u.lastname',
            'u.lastname ASC, u.firstname ASC'
        );
        if (!isset($students[$fellowid])) {
            return null;
        }
        $student = $students[$fellowid];

        $outline = fellowship_dashboard::course_outline($course, $fellowid);
        $completed = (int) ($outline['completed'] ?? 0);
        $trackable = (int) ($outline['trackable'] ?? 0);
        $percent = $outline['percent'] ?? null;
        $hasprogress = $percent !== null;

        $queue = self::list_review_queue($mentorid, [$course], 'all');
        $assigns = [];
        $waiting = 0;
        $released = 0;
        $gradeDisplay = '';
        $firstWaitingUrl = '';
        foreach ($queue['items'] ?? [] as $row) {
            if ((int) ($row['fellow_id'] ?? 0) !== $fellowid) {
                continue;
            }
            if ((int) ($row['course_id'] ?? 0) !== $courseid) {
                continue;
            }
            $assigns[] = $row;
            if (!empty($row['is_waiting'])) {
                $waiting++;
                if ($firstWaitingUrl === '') {
                    $firstWaitingUrl = (string) ($row['url'] ?? '');
                }
            }
            if (!empty($row['is_released'])) {
                $released++;
                if ($gradeDisplay === '' && !empty($row['has_grade'])) {
                    $gradeDisplay = (string) ($row['grade_display'] ?? '');
                }
            }
        }

        if ($waiting > 0) {
            $status = 'pending';
            $statuslabel = get_string('mentor_status_waiting', 'local_launchpad');
            $chip = '';
        } else if ($released > 0) {
            $status = 'reviewed';
            $statuslabel = get_string('mentor_fellow_status_reviewed', 'local_launchpad');
            $chip = 'ok';
        } else if ($hasprogress && $completed > 0) {
            $status = 'active';
            $statuslabel = get_string('mentor_fellow_status_active', 'local_launchpad');
            $chip = 'line';
        } else {
            $status = 'not_started';
            $statuslabel = get_string('mentor_fellow_status_new', 'local_launchpad');
            $chip = 'line';
        }

        $progresslabel = $hasprogress
            ? get_string('mentor_fellow_progress', 'local_launchpad', [
                'done' => $completed,
                'total' => $trackable,
            ])
            : get_string('mentor_fellow_progress_untracked', 'local_launchpad');

        return [
            'fellow_id' => $fellowid,
            'fellow_name' => fullname($student),
            'initials' => fellowship_dashboard::initials($student),
            'course_id' => $courseid,
            'course_name' => format_string($course->fullname),
            'course_idnumber' => s($course->idnumber),
            'completed' => $completed,
            'trackable' => $trackable,
            'percent' => $hasprogress ? (int) $percent : 0,
            'has_percent' => $hasprogress,
            'progress_label' => $progresslabel,
            'status' => $status,
            'status_label' => $statuslabel,
            'chip' => $chip,
            'waiting_count' => $waiting,
            'has_waiting' => $waiting > 0,
            'grade_display' => $gradeDisplay !== '' ? $gradeDisplay : '—',
            'review_url' => $firstWaitingUrl !== ''
                ? $firstWaitingUrl
                : (new \moodle_url(persona_home::MENTOR_PATH, ['view' => 'queue']))->out(false),
            'profile_url' => (new \moodle_url('/user/view.php', [
                'id' => $fellowid,
                'course' => $courseid,
            ]))->out(false),
            'can_message' => messaging::can_message($mentorid, $fellowid),
            'message_url' => messaging::messages_url($fellowid, 'mentor'),
            'assigns' => $assigns,
            'has_assigns' => $assigns !== [],
            'fellows_url' => (new \moodle_url(persona_home::MENTOR_PATH, ['view' => 'fellows']))->out(false),
        ];
    }

    /**
     * Load one fellow × assign for the mentor review page.
     *
     * @return array Mustache context
     */
    public static function get_review_context(int $mentorid, int $cmid, int $fellowid): array {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'assign');
        self::require_mentor_can_grade($mentorid, $course, $cm);

        $context = \context_module::instance($cm->id);
        $assign = new \assign($context, $cm, $course);
        $instance = $assign->get_instance();
        $fellow = $DB->get_record('user', ['id' => $fellowid, 'deleted' => 0], '*', MUST_EXIST);

        $submission = $assign->get_user_submission($fellowid, false);
        $substatus = $submission ? (string) $submission->status : '';
        $attempt = $submission ? ((int) $submission->attemptnumber + 1) : 0;
        $submittedat = 0;
        if ($submission && ($substatus === 'submitted' || $substatus === 'reopened')) {
            $submittedat = (int) ($submission->timemodified ?: $submission->timecreated);
        }

        $onlinetext = '';
        $textplugin = $assign->get_submission_plugin_by_type('onlinetext');
        if ($textplugin && $textplugin->is_enabled() && $submission) {
            $raw = $textplugin->get_editor_text('onlinetext', (int) $submission->id);
            if (is_string($raw) && trim($raw) !== '') {
                $onlinetext = format_text($raw, FORMAT_HTML, ['context' => $context]);
            }
        }

        $files = [];
        $fileplugin = $assign->get_submission_plugin_by_type('file');
        if ($fileplugin && $fileplugin->is_enabled() && $submission) {
            foreach ($fileplugin->get_files($submission, $fellow) as $storedfile) {
                /** @var \stored_file $storedfile */
                $files[] = [
                    'filename' => $storedfile->get_filename(),
                    'filesize' => display_size($storedfile->get_filesize()),
                    'ext' => strtoupper(pathinfo($storedfile->get_filename(), PATHINFO_EXTENSION) ?: 'FILE'),
                    'url' => \moodle_url::make_pluginfile_url(
                        $storedfile->get_contextid(),
                        $storedfile->get_component(),
                        $storedfile->get_filearea(),
                        $storedfile->get_itemid(),
                        $storedfile->get_filepath(),
                        $storedfile->get_filename()
                    )->out(false),
                ];
            }
        }

        $grade = $assign->get_user_grade($fellowid, false);
        $grademax = (float) $instance->grade;
        $gradepoints = '';
        $gradepct = '';
        $comment = '';
        $hasgrade = false;
        if ($grade && $grade->grade !== null && (float) $grade->grade >= 0) {
            $hasgrade = true;
            $gradepoints = format_float((float) $grade->grade, 2);
            if ($grademax > 0) {
                $gradepct = format_float((((float) $grade->grade) / $grademax) * 100, 0);
            }
            $fc = $DB->get_record('assignfeedback_comments', [
                'assignment' => (int) $instance->id,
                'grade' => (int) $grade->id,
            ]);
            if ($fc) {
                $comment = (string) $fc->commenttext;
            }
        }

        $cansubmitreview = ($substatus === 'submitted' || $substatus === 'reopened' || $hasgrade);
        $moodlegrader = (new \moodle_url('/mod/assign/view.php', [
            'id' => $cm->id,
            'action' => 'grader',
            'userid' => $fellowid,
        ]))->out(false);

        $queueurl = (new \moodle_url(persona_home::MENTOR_PATH, ['view' => 'queue']))->out(false);

        return [
            'cmid' => (int) $cm->id,
            'fellow_id' => $fellowid,
            'fellow_name' => fullname($fellow),
            'assign_name' => $cm->get_formatted_name(),
            'course_name' => format_string($course->fullname),
            'attempt' => $attempt,
            'has_attempt' => $attempt > 0,
            'submitted_at' => $submittedat
                ? userdate($submittedat, get_string('strftimedatetimeshort', 'langconfig'))
                : '',
            'substatus' => $substatus,
            'can_submit_review' => $cansubmitreview,
            'has_onlinetext' => $onlinetext !== '',
            'onlinetext_html' => $onlinetext,
            'files' => $files,
            'has_files' => $files !== [],
            'grade_max' => $grademax > 0 ? format_float($grademax, 0) : '100',
            'grade_points' => $gradepoints,
            'grade_pct' => $gradepct,
            'has_grade' => $hasgrade,
            'comment' => $comment,
            'pass_bar' => 70,
            'queue_url' => $queueurl,
            'moodle_grader_url' => $moodlegrader,
            'form_action' => self::review_url((int) $cm->id, $fellowid),
            'sesskey' => sesskey(),
            'is_released' => $hasgrade && $substatus !== 'reopened',
            'status_chip' => $hasgrade
                ? ($substatus === 'reopened'
                    ? get_string('mentor_status_changes', 'local_launchpad')
                    : get_string('mentor_status_released', 'local_launchpad'))
                : get_string('mentor_status_waiting', 'local_launchpad'),
            'status_chip_class' => $hasgrade
                ? ($substatus === 'reopened' ? 'warn' : 'ok')
                : '',
            'outcome_met' => true,
            'outcome_changes' => false,
        ];
    }

    /**
     * Save grade + change note into Moodle assign (native save_grade).
     *
     * @param string $outcome met|changes
     * @return string redirect message key suffix
     */
    public static function save_review(
        int $mentorid,
        int $cmid,
        int $fellowid,
        string $gradepctraw,
        string $comment,
        string $outcome
    ): string {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'assign');
        self::require_mentor_can_grade($mentorid, $course, $cm);

        $context = \context_module::instance($cm->id);
        $assign = new \assign($context, $cm, $course);
        $instance = $assign->get_instance();
        $grademax = (float) $instance->grade;
        if ($grademax <= 0) {
            $grademax = 100.0;
        }

        $pct = unformat_float($gradepctraw);
        if ($pct === false || $pct === null) {
            throw new \moodle_exception('mentor_review_grade_invalid', 'local_launchpad');
        }
        $pct = (float) $pct;
        if ($pct < 0 || $pct > 100) {
            throw new \moodle_exception('mentor_review_grade_invalid', 'local_launchpad');
        }

        $comment = trim($comment);
        if ($comment === '') {
            throw new \moodle_exception('mentor_review_comment_required', 'local_launchpad');
        }

        if ($outcome !== 'met' && $outcome !== 'changes') {
            $outcome = 'met';
        }
        // Pass bar 70: Met below 70 is invalid — coerce to Changes (HTML canRel rule).
        if ($outcome === 'met' && $pct < 70) {
            $outcome = 'changes';
        }

        // Change notes require assignfeedback_comments. Curriculum placeholders often ship with
        // that plugin disabled — enable before save_grade or Moodle silently drops the text.
        $commentsplugin = $assign->get_feedback_plugin_by_type('comments');
        if ($commentsplugin && !$commentsplugin->is_enabled()) {
            $commentsplugin->set_config('enabled', '1');
            // Reload so plugin enabledcache is fresh for save_grade.
            $assign = new \assign($context, $cm, $course);
        }

        // Changes → reopen. Placeholders often ship as untilpass + maxattempts=1 (blocks reopen).
        if ($outcome === 'changes') {
            $reopen = (string) ($instance->attemptreopenmethod ?? '');
            $maxattempts = (int) ($instance->maxattempts ?? 1);
            $needfix = ($reopen === '' || $reopen === 'none' || $reopen === 'untilpass' || $maxattempts === 1);
            if ($needfix) {
                $DB->set_field('assign', 'attemptreopenmethod', 'manual', ['id' => (int) $instance->id]);
                if ($maxattempts === 1) {
                    $DB->set_field('assign', 'maxattempts', -1, ['id' => (int) $instance->id]); // unlimited
                }
                $assign = new \assign($context, $cm, $course);
                $instance = $assign->get_instance();
            }
        }

        $submission = $assign->get_user_submission($fellowid, false);
        $attemptnumber = $submission ? (int) $submission->attemptnumber : -1;

        $data = new \stdClass();
        $data->attemptnumber = $attemptnumber;
        $data->grade = ($pct / 100.0) * $grademax;
        $data->assignfeedbackcomments_editor = [
            'text' => $comment,
            'format' => FORMAT_MOODLE,
        ];
        $data->sendstudentnotifications = 0;
        // Allow another attempt when requesting changes (Moodle manual reopen).
        $data->addattempt = ($outcome === 'changes') ? 1 : 0;

        $assign->save_grade($fellowid, $data);

        return $outcome === 'changes' ? 'changes' : 'released';
    }

    /**
     * Mentor must teach this lp-* course and hold grade capability.
     */
    private static function require_mentor_can_grade(int $mentorid, \stdClass $course, \cm_info $cm): void {
        if (!self::can_access($mentorid)) {
            throw new \moodle_exception('mentor_access_denied', 'local_launchpad');
        }
        $idnumber = (string) ($course->idnumber ?? '');
        if ($idnumber === '' || stripos($idnumber, account_persona::COURSE_IDNUMBER_PREFIX) !== 0) {
            throw new \moodle_exception('mentor_review_course_denied', 'local_launchpad');
        }
        $allowed = false;
        foreach (persona_home::list_mentor_courses($mentorid) as $c) {
            if ((int) $c->id === (int) $course->id) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed && !is_siteadmin($mentorid)) {
            throw new \moodle_exception('mentor_review_course_denied', 'local_launchpad');
        }
        $context = \context_module::instance($cm->id);
        require_capability('mod/assign:grade', $context);
    }

    /** Max waiting rows on mentor home Queue card (full list lives in sidebar Queue). */
    public const HOME_QUEUE_PREVIEW = 6;

    /**
     * Programme Foundations rubric weights (HTML “Rubric at a glance” — not per-assign Moodle rubrics).
     *
     * @return list<array{label:string,weight:string}>
     */
    public static function rubric_at_a_glance(): array {
        return [
            [
                'label' => get_string('mentor_rubric_evidence', 'local_launchpad'),
                'weight' => '30',
            ],
            [
                'label' => get_string('mentor_rubric_acceptance', 'local_launchpad'),
                'weight' => '25',
            ],
            [
                'label' => get_string('mentor_rubric_prioritisation', 'local_launchpad'),
                'weight' => '25',
            ],
            [
                'label' => get_string('mentor_rubric_communication', 'local_launchpad'),
                'weight' => '20',
            ],
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
        $preview = array_slice($waiting, 0, self::HOME_QUEUE_PREVIEW);

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
            'has_waiting' => $waitingcount > 0,
            'has_waiting_preview' => $preview !== [],
            'waiting_preview' => $preview,
            'courses' => $coursecards,
            'has_courses' => $coursecards !== [],
            'queue_url' => (new \moodle_url(persona_home::MENTOR_PATH, ['view' => 'queue']))->out(false),
            'rubric_rows' => self::rubric_at_a_glance(),
            'coach_tip' => get_string('mentor_home_coach_tip', 'local_launchpad'),
        ];
    }
}
