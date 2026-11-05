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

/**
 * Seed presentation-ready dummy data for Fellowship + Employer portals.
 *
 * Makes demo courses/activities visible, enables completion tracking, enrols
 * demo users, and fills completion / grades / feedback / calendar / messages /
 * badges / news so both dashboards look alive for a demo.
 *
 * Usage (from Moodle root):
 *   php public/local/launchpad/cli/seed_presentation_data.php
 *
 * @package    local_launchpad
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/enrollib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/badgeslib.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/cohort/lib.php');
require_once($CFG->dirroot . '/calendar/lib.php');
require_once($CFG->dirroot . '/message/lib.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->dirroot . '/course/lib.php');

use local_launchpad\account_persona;
use local_launchpad\account_provision;
use local_launchpad\registration;

[$options, $unrecognized] = cli_get_params(
    [
        'help' => false,
        'password' => 'DemoPass1!',
    ],
    [
        'h' => 'help',
        'p' => 'password',
    ]
);

if ($unrecognized) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognized)));
}

if (!empty($options['help'])) {
    echo <<<EOF
Seed presentation dummy data for Fellowship + Employer dashboards.

Options:
  -h, --help         Print this help
  -p, --password     Shared demo password (default: DemoPass1!)

EOF;
    exit(0);
}

$password = (string) $options['password'];
$admin = get_admin();
if (!$admin) {
    cli_error('No admin user found.');
}

// Avoid noisy mail failures on local Docker (noreply@localhost).
$CFG->noemailever = true;

\core\session\manager::set_user($admin);
$now = time();

cli_heading('ToBa Learn · presentation seed');

// ---------------------------------------------------------------------------
// 1. Demo accounts
// ---------------------------------------------------------------------------
$demodefs = [
    [
        'username' => 'fellow.demo',
        'email' => 'fellow.demo@example.com',
        'firstname' => 'Aisha',
        'lastname' => 'Fellow',
        'city' => 'Mumbai',
        'country' => 'IN',
        'portal' => registration::PORTAL_FELLOWSHIP,
        'role' => 'fellow',
    ],
    [
        'username' => 'employer.demo',
        'email' => 'employer.demo@example.com',
        'firstname' => 'Rohan',
        'lastname' => 'Employer',
        'city' => 'Bengaluru',
        'country' => 'IN',
        'portal' => 'employer',
        'role' => 'employer',
    ],
    [
        'username' => 'mentor.demo',
        'email' => 'mentor.demo@example.com',
        'firstname' => 'Priya',
        'lastname' => 'Mentor',
        'city' => 'Pune',
        'country' => 'IN',
        'portal' => registration::PORTAL_FELLOWSHIP,
        'role' => 'mentor',
    ],
];

$users = [];
foreach ($demodefs as $demo) {
    $existing = $DB->get_record('user', [
        'username' => $demo['username'],
        'mnethostid' => $CFG->mnet_localhost_id,
        'deleted' => 0,
    ]);
    if ($existing) {
        $user = $existing;
        $user->password = $password;
        $user->email = $demo['email'];
        $user->firstname = $demo['firstname'];
        $user->lastname = $demo['lastname'];
        $user->city = $demo['city'];
        $user->country = $demo['country'];
        $user->confirmed = 1;
        $user->suspended = 0;
        $user->auth = 'manual';
        user_update_user($user, true, false);
        update_internal_user_password($user, $password);
        $action = 'updated';
    } else {
        $user = (object) [
            'auth' => 'manual',
            'username' => $demo['username'],
            'password' => $password,
            'firstname' => $demo['firstname'],
            'lastname' => $demo['lastname'],
            'email' => $demo['email'],
            'emailstop' => 0,
            'city' => $demo['city'],
            'country' => $demo['country'],
            'lang' => 'en',
            'timezone' => '99',
            'confirmed' => 1,
            'mnethostid' => $CFG->mnet_localhost_id,
        ];
        $user->id = user_create_user($user, true, false);
        $action = 'created';
    }
    set_user_preference(registration::PREF_PORTAL, $demo['portal'], $user->id);

    if ($demo['role'] === 'employer') {
        $cohortid = account_provision::ensure_employee_cohort();
        if (!cohort_is_member($cohortid, (int) $user->id)) {
            cohort_add_member($cohortid, (int) $user->id);
        }
    }

    $users[$demo['role']] = $DB->get_record('user', ['id' => $user->id], '*', MUST_EXIST);
    cli_writeln(sprintf('[%s] %s (%s)', strtoupper($action), $demo['username'], $demo['role']));
}

$fellow = $users['fellow'];
$employer = $users['employer'];
$mentor = $users['mentor'];

// ---------------------------------------------------------------------------
// 2. Reveal presentation courses + enable activity completion
// ---------------------------------------------------------------------------
$presentcourses = ['lp-orient-pilot', 'lp-ai-pilot', 'lp-fullstack-pilot'];
$coursemap = [];
foreach ($presentcourses as $idnumber) {
    $course = $DB->get_record('course', ['idnumber' => $idnumber], '*', IGNORE_MISSING);
    if (!$course) {
        cli_writeln("SKIP missing course $idnumber");
        continue;
    }
    $changed = false;
    if ((int) $course->visible !== 1) {
        $DB->set_field('course', 'visible', 1, ['id' => $course->id]);
        $changed = true;
    }
    if ((int) $course->enablecompletion !== 1) {
        $DB->set_field('course', 'enablecompletion', 1, ['id' => $course->id]);
        $changed = true;
    }

    $cms = $DB->get_records('course_modules', ['course' => $course->id, 'deletioninprogress' => 0]);
    foreach ($cms as $cm) {
        $updates = [];
        if ((int) $cm->visible !== 1) {
            $updates['visible'] = 1;
        }
        if ((int) $cm->completion === 0) {
            $updates['completion'] = COMPLETION_TRACKING_MANUAL;
        }
        if ($updates) {
            $updates['id'] = $cm->id;
            $DB->update_record('course_modules', (object) $updates);
            $changed = true;
        }
    }

    // Ensure sections are visible.
    $DB->set_field('course_sections', 'visible', 1, ['course' => $course->id]);

    rebuild_course_cache((int) $course->id, true);
    $coursemap[$idnumber] = $DB->get_record('course', ['id' => $course->id], '*', MUST_EXIST);
    cli_writeln(($changed ? 'PREPPED' : 'READY') . " {$idnumber} id={$course->id}");
}

if (empty($coursemap['lp-ai-pilot'])) {
    cli_error('lp-ai-pilot course missing — run setup_structure.php --apply first.');
}

$aicourse = $coursemap['lp-ai-pilot'];
$orient = $coursemap['lp-orient-pilot'] ?? null;
$fullstack = $coursemap['lp-fullstack-pilot'] ?? null;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Ensure manual student enrolment.
 */
function lp_seed_enrol_student(stdClass $course, stdClass $user): void {
    global $DB;

    $plugin = enrol_get_plugin('manual');
    if (!$plugin) {
        cli_error('manual enrol plugin missing');
    }
    $instance = $DB->get_record('enrol', ['enrol' => 'manual', 'courseid' => $course->id], '*', IGNORE_MULTIPLE);
    if (!$instance) {
        $instanceid = $plugin->add_instance($course);
        $instance = $DB->get_record('enrol', ['id' => $instanceid], '*', MUST_EXIST);
    }
    if ((int) $instance->status !== ENROL_INSTANCE_ENABLED) {
        $DB->set_field('enrol', 'status', ENROL_INSTANCE_ENABLED, ['id' => $instance->id]);
        $instance->status = ENROL_INSTANCE_ENABLED;
    }
    $student = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
    $plugin->enrol_user($instance, $user->id, $student->id);
}

/**
 * Ensure course role (mentor).
 */
function lp_seed_assign_role(stdClass $course, stdClass $user, string $roleshortname): void {
    global $DB;

    $role = $DB->get_record('role', ['shortname' => $roleshortname], '*', MUST_EXIST);
    $context = context_course::instance($course->id);
    role_assign($role->id, $user->id, $context->id);

    // Also enrol so they appear in participants / messaging.
    $plugin = enrol_get_plugin('manual');
    $instance = $DB->get_record('enrol', ['enrol' => 'manual', 'courseid' => $course->id], '*', IGNORE_MULTIPLE);
    if ($instance && $plugin) {
        $plugin->enrol_user($instance, $user->id, $role->id);
    }
}

/**
 * Mark a course module complete for a user.
 */
function lp_seed_complete(stdClass $course, int $cmid, int $userid, int $when): void {
    global $DB;

    $completion = new completion_info($course);
    $modinfo = get_fast_modinfo($course, $userid);
    if (!isset($modinfo->cms[$cmid])) {
        return;
    }
    $cm = $modinfo->get_cm($cmid);
    if ($completion->is_enabled($cm) == COMPLETION_TRACKING_NONE) {
        return;
    }
    $data = $completion->get_data($cm, false, $userid);
    $data->completionstate = COMPLETION_COMPLETE;
    $data->timemodified = $when;
    $data->viewed = COMPLETION_VIEWED;
    $completion->internal_set_data($cm, $data, true);
}

/**
 * Create submitted assign attempt + grade + feedback comment.
 */
function lp_seed_assign_grade(
    stdClass $course,
    cm_info $cm,
    stdClass $user,
    float $grade,
    string $comment,
    int $when,
    bool $pendingonly = false
): void {
    global $DB, $CFG;

    $assign = new assign(context_module::instance($cm->id), $cm, $course);
    $assignmentid = (int) $cm->instance;

    $submission = $DB->get_record('assign_submission', [
        'assignment' => $assignmentid,
        'userid' => $user->id,
        'latest' => 1,
    ]);
    if (!$submission) {
        $submission = (object) [
            'assignment' => $assignmentid,
            'userid' => $user->id,
            'timecreated' => $when - DAYSECS,
            'timemodified' => $when - HOURSECS,
            'status' => 'submitted',
            'groupid' => 0,
            'attemptnumber' => 0,
            'latest' => 1,
        ];
        $submission->id = $DB->insert_record('assign_submission', $submission);
    } else {
        $submission->status = 'submitted';
        $submission->timemodified = $when - HOURSECS;
        $DB->update_record('assign_submission', $submission);
    }

    // On-line text body so submission looks real.
    $plugin = $assign->get_submission_plugin_by_type('onlinetext');
    if ($plugin) {
        $plugin->save($submission, (object) [
            'onlinetext_editor' => [
                'text' => '<p>Demo submission from ' . fullname($user) .
                    ' — outline, assumptions, and next steps for the presentation cohort.</p>',
                'format' => FORMAT_HTML,
                'itemid' => file_get_unused_draft_itemid(),
            ],
        ]);
    }

    if ($pendingonly) {
        return;
    }

    $agrade = $DB->get_record('assign_grades', [
        'assignment' => $assignmentid,
        'userid' => $user->id,
        'attemptnumber' => (int) $submission->attemptnumber,
    ]);
    if (!$agrade) {
        $agrade = (object) [
            'assignment' => $assignmentid,
            'userid' => $user->id,
            'timecreated' => $when,
            'timemodified' => $when,
            'grader' => get_admin()->id,
            'grade' => $grade,
            'attemptnumber' => (int) $submission->attemptnumber,
        ];
        $agrade->id = $DB->insert_record('assign_grades', $agrade);
    } else {
        $agrade->grade = $grade;
        $agrade->timemodified = $when;
        $agrade->grader = get_admin()->id;
        $DB->update_record('assign_grades', $agrade);
    }

    $fc = $DB->get_record('assignfeedback_comments', [
        'assignment' => $assignmentid,
        'grade' => $agrade->id,
    ]);
    if (!$fc) {
        $DB->insert_record('assignfeedback_comments', (object) [
            'assignment' => $assignmentid,
            'grade' => $agrade->id,
            'commenttext' => $comment,
            'commentformat' => FORMAT_HTML,
        ]);
    } else {
        $fc->commenttext = $comment;
        $fc->commentformat = FORMAT_HTML;
        $DB->update_record('assignfeedback_comments', $fc);
    }

    $gradeitem = $assign->get_grade_item();
    if ($gradeitem) {
        $max = (float) ($gradeitem->grademax ?: 100);
        grade_update(
            'mod/assign',
            $course->id,
            'mod',
            'assign',
            $assignmentid,
            0,
            (object) [
                'userid' => $user->id,
                'rawgrade' => $grade,
                'feedback' => $comment,
                'feedbackformat' => FORMAT_HTML,
                'datesubmitted' => $when - HOURSECS,
                'dategraded' => $when,
            ],
            ['itemname' => $cm->name, 'grademax' => $max]
        );
    }
}

/**
 * Seed a finished quiz attempt + gradebook score.
 */
function lp_seed_quiz_attempt(stdClass $course, cm_info $cm, stdClass $user, float $fraction, int $when): void {
    global $DB;

    $quizid = (int) $cm->instance;
    $quiz = $DB->get_record('quiz', ['id' => $quizid], '*', MUST_EXIST);
    $sumgrades = (float) ($quiz->sumgrades ?: 4);
    if ($sumgrades <= 0) {
        $sumgrades = 4;
        $DB->set_field('quiz', 'sumgrades', $sumgrades, ['id' => $quizid]);
    }
    $grade = (float) ($quiz->grade ?: 100);

    $attempt = $DB->get_record('quiz_attempts', [
        'quiz' => $quizid,
        'userid' => $user->id,
        'attempt' => 1,
    ]);
    $attemptsum = round($sumgrades * $fraction, 5);
    if (!$attempt) {
        $attempt = (object) [
            'quiz' => $quizid,
            'userid' => $user->id,
            'attempt' => 1,
            'uniqueid' => $DB->insert_record('question_usages', (object) [
                'contextid' => context_module::instance($cm->id)->id,
                'component' => 'mod_quiz',
                'preferredbehaviour' => 'deferredfeedback',
            ]),
            'layout' => '',
            'currentpage' => 0,
            'preview' => 0,
            'state' => 'finished',
            'timestart' => $when - HOURSECS,
            'timefinish' => $when - (HOURSECS / 2),
            'timemodified' => $when - (HOURSECS / 2),
            'timecheckstate' => null,
            'sumgrades' => $attemptsum,
        ];
        $attempt->id = $DB->insert_record('quiz_attempts', $attempt);
    } else {
        $attempt->state = 'finished';
        $attempt->sumgrades = $attemptsum;
        $attempt->timefinish = $when - (HOURSECS / 2);
        $attempt->timemodified = $when - (HOURSECS / 2);
        $DB->update_record('quiz_attempts', $attempt);
    }

    $qg = $DB->get_record('quiz_grades', ['quiz' => $quizid, 'userid' => $user->id]);
    $final = round($grade * $fraction, 5);
    if (!$qg) {
        $DB->insert_record('quiz_grades', (object) [
            'quiz' => $quizid,
            'userid' => $user->id,
            'grade' => $final,
            'timemodified' => $when,
        ]);
    } else {
        $qg->grade = $final;
        $qg->timemodified = $when;
        $DB->update_record('quiz_grades', $qg);
    }

    grade_update('mod/quiz', $course->id, 'mod', 'quiz', $quizid, 0, (object) [
        'userid' => $user->id,
        'rawgrade' => $final,
        'dategraded' => $when,
    ]);
}

/**
 * Create or update a personal calendar event as the given user.
 */
function lp_seed_personal_event(stdClass $user, string $name, int $timestart, int $duration, string $desc): void {
    global $USER;

    $prev = $USER;
    \core\session\manager::set_user($user);
    $existing = null;
    // Idempotent-ish: match by name + userid in next 60 days.
    global $DB;
    $existing = $DB->get_record_select(
        'event',
        "userid = :uid AND eventtype = 'user' AND name = :name AND timestart > :t",
        ['uid' => $user->id, 'name' => $name, 't' => time() - DAYSECS]
    );
    if ($existing) {
        $existing->timestart = $timestart;
        $existing->timeduration = $duration;
        $existing->description = $desc;
        $DB->update_record('event', $existing);
    } else {
        $props = (object) [
            'name' => $name,
            'description' => $desc,
            'format' => FORMAT_HTML,
            'courseid' => 0,
            'groupid' => 0,
            'userid' => $user->id,
            'modulename' => '',
            'instance' => 0,
            'eventtype' => 'user',
            'timestart' => $timestart,
            'timeduration' => $duration,
            'visible' => 1,
        ];
        calendar_event::create($props, false);
    }
    \core\session\manager::set_user($prev);
}

/**
 * Create a course calendar event.
 */
function lp_seed_course_event(stdClass $course, string $name, int $timestart, int $duration, string $desc): void {
    global $DB;

    $admin = get_admin();
    $existing = $DB->get_record_select(
        'event',
        "courseid = :cid AND eventtype = 'course' AND name = :name AND timestart > :t",
        ['cid' => $course->id, 'name' => $name, 't' => time() - DAYSECS]
    );
    if ($existing) {
        $existing->timestart = $timestart;
        $existing->timeduration = $duration;
        $existing->description = $desc;
        $DB->update_record('event', $existing);
        return;
    }
    $props = (object) [
        'name' => $name,
        'description' => $desc,
        'format' => FORMAT_HTML,
        'courseid' => $course->id,
        'groupid' => 0,
        'userid' => $admin->id,
        'modulename' => '',
        'instance' => 0,
        'eventtype' => 'course',
        'timestart' => $timestart,
        'timeduration' => $duration,
        'visible' => 1,
    ];
    calendar_event::create($props, false);
}

/**
 * Ensure a site/course badge and optionally issue it.
 */
function lp_seed_badge(array $data, ?stdClass $issueuser = null): badge {
    global $DB, $CFG, $admin, $now;

    if ((int) $data['type'] === BADGE_TYPE_SITE) {
        $existing = $DB->get_record_select(
            'badge',
            'name = :name AND type = :type AND courseid IS NULL',
            ['name' => $data['name'], 'type' => BADGE_TYPE_SITE]
        );
    } else {
        $existing = $DB->get_record('badge', [
            'name' => $data['name'],
            'type' => $data['type'],
            'courseid' => $data['courseid'],
        ]);
    }

    if ($existing) {
        $badge = new badge((int) $existing->id);
    } else {
        $id = $DB->insert_record('badge', (object) [
            'name' => $data['name'],
            'description' => $data['description'],
            'timecreated' => $now,
            'timemodified' => $now,
            'usercreated' => $admin->id,
            'usermodified' => $admin->id,
            'issuername' => $data['issuername'],
            'issuerurl' => $data['issuerurl'],
            'issuercontact' => $admin->email,
            'expiredate' => null,
            'expireperiod' => null,
            'type' => $data['type'],
            'courseid' => $data['courseid'],
            'message' => $data['message'],
            'messagesubject' => $data['messagesubject'],
            'attachment' => 1,
            'notification' => 0,
            'status' => BADGE_STATUS_ACTIVE,
            'version' => '1',
            'language' => 'en',
            'imageauthorname' => '',
            'imageauthoremail' => '',
            'imageauthorurl' => '',
            'imagecaption' => '',
        ]);
        $badge = new badge((int) $id);
        if (!$DB->record_exists('badge_criteria', ['badgeid' => $badge->id])) {
            $DB->insert_record('badge_criteria', (object) [
                'badgeid' => $badge->id,
                'criteriatype' => BADGE_CRITERIA_TYPE_OVERALL,
                'method' => BADGE_CRITERIA_AGGREGATION_ALL,
                'description' => '',
                'descriptionformat' => FORMAT_HTML,
            ]);
            $critid = $DB->insert_record('badge_criteria', (object) [
                'badgeid' => $badge->id,
                'criteriatype' => BADGE_CRITERIA_TYPE_MANUAL,
                'method' => BADGE_CRITERIA_AGGREGATION_ANY,
                'description' => '',
                'descriptionformat' => FORMAT_HTML,
            ]);
            $role = $DB->get_record('role', ['shortname' => 'editingteacher'], 'id');
            if ($role && $critid) {
                $DB->insert_record('badge_criteria_param', (object) [
                    'critid' => $critid,
                    'name' => 'role_' . $role->id,
                    'value' => $role->id,
                ]);
            }
        }
    }

    $context = $badge->get_context();
    $fs = get_file_storage();
    $files = $fs->get_area_files($context->id, 'badges', 'badgeimage', $badge->id, 'itemid', false);
    if (!$files) {
        foreach ([$CFG->dirroot . '/pix/i/badge.png', $CFG->dirroot . '/badges/pix/badge.png'] as $src) {
            if (is_readable($src)) {
                foreach (['f1.png', 'f2.png', 'f3.png'] as $filename) {
                    $fs->create_file_from_pathname([
                        'contextid' => $context->id,
                        'component' => 'badges',
                        'filearea' => 'badgeimage',
                        'itemid' => $badge->id,
                        'filepath' => '/',
                        'filename' => $filename,
                    ], $src);
                }
                break;
            }
        }
    }

    if ($issueuser && !$badge->is_issued($issueuser->id)) {
        $badge->issue($issueuser->id, true);
    }
    return $badge;
}

/**
 * Ensure news forum + a couple of discussions on a course.
 */
function lp_seed_news(stdClass $course, stdClass $author): void {
    global $DB, $CFG, $USER;

    require_once($CFG->dirroot . '/mod/forum/lib.php');

    $forum = forum_get_course_forum($course->id, 'news');
    if (!$forum) {
        cli_writeln('  WARN: could not create news forum on ' . $course->idnumber);
        return;
    }

    // Make sure the news CM is visible for the Fellowship news tab.
    $cm = get_coursemodule_from_instance('forum', $forum->id, $course->id, false, IGNORE_MISSING);
    if ($cm && (int) $cm->visible !== 1) {
        $DB->set_field('course_modules', 'visible', 1, ['id' => $cm->id]);
        rebuild_course_cache((int) $course->id, true);
    }

    $posts = [
        [
            'subject' => 'Welcome to Cohort Pilot — week 1 focus',
            'message' => '<p>Kick off Foundations this week. Complete the Foundations lesson and check before Friday office hours.</p>',
        ],
        [
            'subject' => 'Demo day dry-run scheduled',
            'message' => '<p>Mark your calendars: cohort dry-run next Wednesday. Bring your guided-project draft.</p>',
        ],
    ];

    $prev = $USER;
    \core\session\manager::set_user($author);
    foreach ($posts as $i => $p) {
        $exists = $DB->get_record('forum_discussions', [
            'forum' => $forum->id,
            'name' => $p['subject'],
        ]);
        if ($exists) {
            continue;
        }
        $discussion = (object) [
            'course' => $course->id,
            'forum' => $forum->id,
            'name' => $p['subject'],
            'message' => $p['message'],
            'messageformat' => FORMAT_HTML,
            'messagetrust' => 0,
            'mailnow' => 0,
            'groupid' => -1,
            'timestart' => 0,
            'timeend' => 0,
            'pinned' => FORUM_DISCUSSION_UNPINNED,
        ];
        forum_add_discussion($discussion, null, $p['message'], $author->id);
        // Backdate for a lived-in look.
        $created = $DB->get_record('forum_discussions', [
            'forum' => $forum->id,
            'name' => $p['subject'],
        ]);
        if ($created) {
            $ts = time() - (($i + 1) * DAYSECS);
            $DB->set_field('forum_discussions', 'timemodified', $ts, ['id' => $created->id]);
            if (!empty($created->firstpost)) {
                $DB->set_field('forum_posts', 'created', $ts, ['id' => $created->firstpost]);
                $DB->set_field('forum_posts', 'modified', $ts, ['id' => $created->firstpost]);
            }
        }
    }
    \core\session\manager::set_user($prev);
}

/**
 * Seed a short private conversation.
 */
function lp_seed_conversation(stdClass $a, stdClass $b, array $lines): void {
    $existing = \core_message\api::get_conversation_between_users([(int) $a->id, (int) $b->id]);
    if ($existing) {
        $convid = (int) $existing;
    } else {
        $conv = \core_message\api::create_conversation(
            \core_message\api::MESSAGE_CONVERSATION_TYPE_INDIVIDUAL,
            [(int) $a->id, (int) $b->id]
        );
        $convid = (int) $conv->id;
    }
    // Avoid duplicating forever on re-run: only send if conversation is empty-ish.
    $msgs = \core_message\api::get_conversation_messages((int) $a->id, $convid, 0, 20);
    $count = is_array($msgs) ? count($msgs['messages'] ?? $msgs) : 0;
    if ($count >= count($lines)) {
        return;
    }
    foreach ($lines as $line) {
        \core_message\api::send_message_to_conversation(
            (int) $line['from']->id,
            $convid,
            $line['text'],
            FORMAT_PLAIN
        );
    }
}

/**
 * Apply rich progress on one course for one learner.
 *
 * Completes Foundations pages + quiz, grades guided-project assign,
 * leaves a later assign pending for "next quest" UX.
 */
function lp_seed_course_progress(stdClass $course, stdClass $user, string $persona, int $basetime): void {
    $modinfo = get_fast_modinfo($course, $user->id);
    $cms = array_values($modinfo->get_cms());
    // Sort by section then id for stable demo shape.
    usort($cms, static function (cm_info $a, cm_info $b): int {
        $sa = (int) $a->get_section_info()->section;
        $sb = (int) $b->get_section_info()->section;
        return ($sa <=> $sb) ?: ($a->id <=> $b->id);
    });

    $pagesdone = 0;
    $quizdone = false;
    $assignsgraded = 0;
    $assignspending = 0;

    foreach ($cms as $cm) {
        if (!$cm->uservisible || $cm->modname === 'label' || $cm->modname === 'forum') {
            continue;
        }
        $section = (int) $cm->get_section_info()->section;
        $when = $basetime - ((5 - min(5, $section)) * DAYSECS) - ($cm->id * 60);

        if ($cm->modname === 'page') {
            // Complete intro + Foundations (+ one guided page for fellow richness).
            if ($section <= 1 || ($persona === 'fellow' && $section === 2 && $pagesdone < 4)) {
                lp_seed_complete($course, (int) $cm->id, (int) $user->id, $when);
                $pagesdone++;
            }
            continue;
        }

        if ($cm->modname === 'quiz' && !$quizdone && $section <= 1) {
            lp_seed_complete($course, (int) $cm->id, (int) $user->id, $when);
            $fraction = ($persona === 'fellow') ? 1.0 : 0.75;
            lp_seed_quiz_attempt($course, $cm, $user, $fraction, $when);
            $quizdone = true;
            continue;
        }

        if ($cm->modname === 'assign') {
            if ($assignsgraded < 1 && $section <= 2) {
                $score = ($persona === 'fellow') ? 88.0 : 82.0;
                $comment = $persona === 'fellow'
                    ? '<p><strong>Strong foundations.</strong> Clear problem framing — tighten the success metrics before the simulation gate.</p>'
                    : '<p><strong>Good start.</strong> Add one concrete workplace example and resubmit the evidence section.</p>';
                lp_seed_complete($course, (int) $cm->id, (int) $user->id, $when);
                lp_seed_assign_grade($course, $cm, $user, $score, $comment, $when);
                $assignsgraded++;
            } else if ($assignspending < 1) {
                // One submitted-but-ungraded item so Grades / Feedback show pending work.
                lp_seed_assign_grade($course, $cm, $user, 0, '', $when, true);
                $assignspending++;
            }
        }
    }

    cli_writeln(sprintf(
        '  progress %s @ %s: pages=%d quiz=%s assigns_graded=%d pending=%d',
        $user->username,
        $course->idnumber,
        $pagesdone,
        $quizdone ? 'yes' : 'no',
        $assignsgraded,
        $assignspending
    ));
}

// ---------------------------------------------------------------------------
// 3. Enrolments / roles
// ---------------------------------------------------------------------------
cli_heading('Enrolments');
lp_seed_enrol_student($aicourse, $fellow);
if ($orient) {
    lp_seed_enrol_student($orient, $fellow);
    lp_seed_enrol_student($orient, $employer);
}
lp_seed_enrol_student($aicourse, $employer);
if ($fullstack) {
    lp_seed_enrol_student($fullstack, $fellow);
}
lp_seed_assign_role($aicourse, $mentor, 'editingteacher');
cli_writeln('fellow → orient + ai + fullstack; employer → orient + ai; mentor → ai teacher');

// ---------------------------------------------------------------------------
// 4. Progress / grades / feedback
// ---------------------------------------------------------------------------
cli_heading('Progress + grades');
if ($orient) {
    lp_seed_course_progress($orient, $fellow, 'fellow', $now - (2 * DAYSECS));
    lp_seed_course_progress($orient, $employer, 'employer', $now - (3 * DAYSECS));
}
lp_seed_course_progress($aicourse, $fellow, 'fellow', $now);
lp_seed_course_progress($aicourse, $employer, 'employer', $now - DAYSECS);
if ($fullstack) {
    // Lighter progress on second track so multi-course switcher still shows a next quest.
    lp_seed_course_progress($fullstack, $fellow, 'fellow', $now - (4 * DAYSECS));
}

// ---------------------------------------------------------------------------
// 5. Calendar
// ---------------------------------------------------------------------------
cli_heading('Calendar');
lp_seed_course_event(
    $aicourse,
    'Foundations office hours',
    $now + (2 * DAYSECS) + (15 * HOURSECS),
    HOURSECS,
    'Drop-in clinic for Foundations check questions.'
);
lp_seed_course_event(
    $aicourse,
    'Guided project critique',
    $now + (5 * DAYSECS) + (16 * HOURSECS),
    HOURSECS + (HOURSECS / 2),
    'Live critique session with mentors.'
);
lp_seed_personal_event(
    $fellow,
    'Deep-work: Foundations notes',
    $now + DAYSECS + (10 * HOURSECS),
    2 * HOURSECS,
    'Block for finishing the Foundations lesson write-up.'
);
lp_seed_personal_event(
    $fellow,
    'Mentor 1:1 prep',
    $now + (3 * DAYSECS) + (11 * HOURSECS),
    HOURSECS,
    'Prep questions for Priya before the critique.'
);
lp_seed_personal_event(
    $employer,
    'Apply lesson on team workflow',
    $now + (2 * DAYSECS) + (9 * HOURSECS),
    HOURSECS,
    'Translate Foundations into an internal playbook draft.'
);
cli_writeln('Course + personal events seeded');

// ---------------------------------------------------------------------------
// 6. Messages
// ---------------------------------------------------------------------------
cli_heading('Messages');
lp_seed_conversation($fellow, $mentor, [
    ['from' => $mentor, 'text' => 'Hi Aisha — great Foundations check. Bring your metrics draft to Wednesday critique.'],
    ['from' => $fellow, 'text' => 'Thanks Priya! I will upload the guided-project outline tonight.'],
    ['from' => $mentor, 'text' => 'Perfect. Ping me here if the quiz feedback is unclear.'],
]);
lp_seed_conversation($employer, $fellow, [
    ['from' => $fellow, 'text' => 'Hi Rohan — welcome to the cohort. Start with Orientation, then Foundations on the AI track.'],
    ['from' => $employer, 'text' => 'Thanks! I completed Orientation and opened the first Foundations lesson.'],
    ['from' => $fellow, 'text' => 'Nice. Reply anytime — office hours are on the calendar.'],
]);
cli_writeln('Conversations ready');

// ---------------------------------------------------------------------------
// 7. Badges
// ---------------------------------------------------------------------------
cli_heading('Badges');
if (!empty($CFG->enablebadges)) {
    lp_seed_badge([
        'name' => 'Site Learner',
        'description' => 'Joined ToBa Learn and opened your first upskilling path.',
        'type' => BADGE_TYPE_SITE,
        'courseid' => null,
        'issuername' => 'ToBa Learn',
        'issuerurl' => $CFG->wwwroot,
        'message' => 'You earned Site Learner.',
        'messagesubject' => 'Badge earned',
    ], $employer);
    lp_seed_badge([
        'name' => 'First Steps',
        'description' => 'Started the assigned LaunchPad course.',
        'type' => BADGE_TYPE_COURSE,
        'courseid' => (int) $aicourse->id,
        'issuername' => format_string($aicourse->fullname),
        'issuerurl' => (new moodle_url('/course/view.php', ['id' => $aicourse->id]))->out(false),
        'message' => 'You earned First Steps.',
        'messagesubject' => 'Badge earned',
    ], $employer);
    lp_seed_badge([
        'name' => 'First Steps',
        'description' => 'Started the assigned LaunchPad course.',
        'type' => BADGE_TYPE_COURSE,
        'courseid' => (int) $aicourse->id,
        'issuername' => format_string($aicourse->fullname),
        'issuerurl' => (new moodle_url('/course/view.php', ['id' => $aicourse->id]))->out(false),
        'message' => 'You earned First Steps.',
        'messagesubject' => 'Badge earned',
    ], $fellow);
    lp_seed_badge([
        'name' => 'Foundations Cleared',
        'description' => 'Completed Foundations activities on the AI track.',
        'type' => BADGE_TYPE_COURSE,
        'courseid' => (int) $aicourse->id,
        'issuername' => format_string($aicourse->fullname),
        'issuerurl' => (new moodle_url('/course/view.php', ['id' => $aicourse->id]))->out(false),
        'message' => 'You earned Foundations Cleared.',
        'messagesubject' => 'Badge earned',
    ], $fellow);
    lp_seed_badge([
        'name' => 'Course Starter',
        'description' => 'Available after finishing the foundations path.',
        'type' => BADGE_TYPE_COURSE,
        'courseid' => (int) $aicourse->id,
        'issuername' => format_string($aicourse->fullname),
        'issuerurl' => (new moodle_url('/course/view.php', ['id' => $aicourse->id]))->out(false),
        'message' => 'You earned Course Starter.',
        'messagesubject' => 'Badge earned',
    ], null);
    cli_writeln('Badges created/issued');
} else {
    cli_writeln('Badges disabled — skipped');
}

// ---------------------------------------------------------------------------
// 8. News
// ---------------------------------------------------------------------------
cli_heading('News');
lp_seed_news($aicourse, $mentor);
// Keep news forum untracked so it does not steal completion / next-quest UX.
$newscm = $DB->get_record_sql(
    "SELECT cm.id
       FROM {course_modules} cm
       JOIN {modules} m ON m.id = cm.module AND m.name = 'forum'
       JOIN {forum} f ON f.id = cm.instance AND f.type = 'news'
      WHERE cm.course = ?",
    [$aicourse->id]
);
if ($newscm) {
    $DB->set_field('course_modules', 'completion', COMPLETION_TRACKING_NONE, ['id' => $newscm->id]);
    rebuild_course_cache((int) $aicourse->id, true);
}
cli_writeln('Announcements seeded on AI course');

purge_all_caches();

cli_heading('Demo logins');
cli_writeln('Password: ' . $password);
cli_writeln('Fellowship: fellow.demo  → ' . (new moodle_url('/local/launchpad/fellow.php'))->out(false));
cli_writeln('Employer:   employer.demo → ' . (new moodle_url('/local/launchpad/employee.php'))->out(false));
cli_writeln('Mentor:     mentor.demo   (messaging counterpart)');
cli_writeln('');
cli_writeln('Done.');
exit(0);
