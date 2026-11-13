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
 * Local-only: wipe LaunchPad seed/demo progress and/or lp-* courses for a clean journey.
 *
 * Default is dry-run. Pass --apply to write (fail-closed local target only).
 *
 * @package    local_launchpad
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/user/lib.php');

use local_launchpad\account_persona;
use local_launchpad\apply_target;
use local_launchpad\registration;

list($options, $unrecognized) = cli_get_params([
    'help' => false,
    'apply' => false,
    'mode' => 'structure',
    'delete-demo-users' => false,
], [
    'h' => 'help',
]);

if ($unrecognized) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognized)));
}

if (!empty($options['help'])) {
    $help = <<<EOF
Wipe LaunchPad seeded/demo data so you can walk admin → enrol → fellow → mentor from scratch.

Modes:
  --mode=structure   Hide + delete every course with idnumber lp-* (default).
                     Keeps LaunchPad categories. Admin recreates tracks in UI
                     (or re-run setup_structure.php --apply for empty shelves).
  --mode=progress    Keep courses; clear completions, quiz attempts, assign
                     submissions/grades on lp-* for non-siteadmin users; unenrol
                     students from lp-* courses; clear LaunchPad user prefs.
  --mode=all         progress cleanup extras + structure delete.

Options:
  -h, --help              Show this help
      --apply             Write (default dry-run). Local target only.
      --delete-demo-users Also delete fellow.demo / mentor.demo / employer.demo
                          (structure/all only; after courses gone).

Examples:
  php public/local/launchpad/cli/clean_seed_data.php
  php public/local/launchpad/cli/clean_seed_data.php --mode=structure --apply
  php public/local/launchpad/cli/clean_seed_data.php --mode=all --apply --delete-demo-users

EOF;
    cli_writeln($help);
    exit(0);
}

if (during_initial_install()) {
    cli_error('Moodle is not installed.');
}

$mode = strtolower(trim((string) $options['mode']));
if (!in_array($mode, ['structure', 'progress', 'all'], true)) {
    cli_error('Invalid --mode. Use structure, progress, or all.');
}

$apply = !empty($options['apply']);
$deletedemousers = !empty($options['delete-demo-users']);

if ($apply) {
    $dbname = '';
    if (!empty($CFG->dbname)) {
        $dbname = (string) $CFG->dbname;
    }
    if (!apply_target::is_verified_local((string) $CFG->wwwroot, $dbname)) {
        cli_error('Refusing --apply: site is not a verified local target.');
    }
}

$admin = get_admin();
if (!$admin) {
    cli_error('No admin user found.');
}
\core\session\manager::set_user($admin);

cli_heading('LaunchPad clean seed data · ' . ($apply ? 'APPLY' : 'DRY-RUN') . ' · mode=' . $mode);

$prefix = account_persona::COURSE_IDNUMBER_PREFIX;
$courses = $DB->get_records_sql(
    "SELECT * FROM {course}
      WHERE " . $DB->sql_like('idnumber', ':p') . "
      ORDER BY idnumber ASC",
    ['p' => $DB->sql_like_escape($prefix) . '%']
);

cli_writeln('lp-* courses found: ' . count($courses));
foreach ($courses as $c) {
    cli_writeln(sprintf(
        '  #%d visible=%d %s (%s)',
        (int) $c->id,
        (int) $c->visible,
        (string) $c->idnumber,
        (string) $c->shortname
    ));
}

/**
 * Before wiping courses, stamp toba_portal so fellow/mentor shells still open empty.
 *
 * @param array<int,stdClass> $courses
 * @return list<int> Affected user ids (for notification wipe)
 */
function lp_clean_stamp_portal_prefs(array $courses, bool $apply): array {
    $fellows = [];
    $mentors = [];
    foreach ($courses as $course) {
        $context = context_course::instance((int) $course->id);
        foreach (get_enrolled_users($context, '', 0, 'u.id', null, 0, 0, true) as $user) {
            $uid = (int) $user->id;
            if (is_siteadmin($uid)) {
                continue;
            }
            if (account_persona::is_mentor($uid, (int) $course->id)) {
                $mentors[$uid] = true;
            }
            if (account_persona::is_fellowship($uid, (int) $course->id)) {
                $fellows[$uid] = true;
            }
        }
    }
    $affected = [];
    // Mentors win if both (teacher + student oddity).
    foreach (array_keys($mentors) as $uid) {
        unset($fellows[$uid]);
        $affected[$uid] = true;
        cli_writeln('  stamp toba_portal=mentor user#' . $uid);
        if ($apply) {
            registration::remember_login_portal($uid, registration::PORTAL_MENTOR);
        }
    }
    foreach (array_keys($fellows) as $uid) {
        $affected[$uid] = true;
        cli_writeln('  stamp toba_portal=fellowship user#' . $uid);
        if ($apply) {
            registration::remember_login_portal($uid, registration::PORTAL_FELLOWSHIP);
        }
    }
    return array_map('intval', array_keys($affected));
}

/**
 * Delete Moodle notifications (and popup rows) for users — leftover after course wipe.
 *
 * @param list<int> $userids
 */
function lp_clean_clear_notifications(array $userids, bool $apply): int {
    global $DB;

    $userids = array_values(array_unique(array_filter(array_map('intval', $userids))));
    if ($userids === []) {
        return 0;
    }
    list($insql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
    $notifs = $DB->get_records_select('notifications', "useridto $insql", $params, '', 'id');
    $n = count($notifs);
    if ($n === 0) {
        return 0;
    }
    if (!$apply) {
        return $n;
    }
    $ids = array_map('intval', array_keys($notifs));
    list($idinsql, $idparams) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
    $DB->delete_records_select('message_popup_notifications', "notificationid $idinsql", $idparams);
    $DB->delete_records_select('notifications', "id $idinsql", $idparams);
    return $n;
}

$stampeduserids = [];
if (($mode === 'structure' || $mode === 'all') && $courses) {
    cli_writeln('');
    cli_writeln('=== Stamp portal prefs before course wipe ===');
    $stampeduserids = lp_clean_stamp_portal_prefs($courses, $apply);
}

/**
 * Clear LaunchPad-related user preferences (sticky course, portfolio case, portal ok to keep).
 */
function lp_clean_clear_prefs(bool $apply): int {
    global $DB;
    $like = $DB->sql_like('name', ':p');
    $params = ['p' => $DB->sql_like_escape('local_launchpad_') . '%'];
    // Keep portal preference so persona routing still works for leftover users.
    $rs = $DB->get_recordset_select('user_preferences', $like, $params, 'id ASC', 'id, userid, name');
    $n = 0;
    foreach ($rs as $pref) {
        $name = (string) $pref->name;
        if ($name === registration::PREF_PORTAL) {
            continue;
        }
        $n++;
        if ($apply) {
            $DB->delete_records('user_preferences', ['id' => (int) $pref->id]);
        }
    }
    $rs->close();
    return $n;
}

/**
 * Reset learning records on one course for every non-admin user.
 */
function lp_clean_course_progress(stdClass $course, bool $apply): array {
    global $DB, $CFG;

    $stats = [
        'completions' => 0,
        'quiz_attempts' => 0,
        'assign_grades' => 0,
        'assign_subs' => 0,
        'unenrol_student' => 0,
    ];

    $modinfo = get_fast_modinfo($course);
    $context = context_course::instance((int) $course->id);
    $users = get_enrolled_users($context, '', 0, 'u.id', null, 0, 0, true);

    foreach ($users as $user) {
        if (is_siteadmin((int) $user->id)) {
            continue;
        }

        // Activity completion.
        $completion = new completion_info($course);
        if ($completion->is_enabled()) {
            foreach ($modinfo->get_cms() as $cm) {
                if (!$apply) {
                    $stats['completions']++;
                    continue;
                }
                try {
                    $completion->update_state($cm, COMPLETION_UNKNOWN, (int) $user->id, true);
                    $DB->delete_records('course_modules_completion', [
                        'coursemoduleid' => (int) $cm->id,
                        'userid' => (int) $user->id,
                    ]);
                    $stats['completions']++;
                } catch (Throwable $e) {
                    cli_writeln('  ! completion clear failed cm=' . $cm->id . ' u=' . $user->id . ': ' . $e->getMessage());
                }
            }
            if ($apply) {
                $DB->delete_records('course_completions', [
                    'course' => (int) $course->id,
                    'userid' => (int) $user->id,
                ]);
            }
        }

        foreach ($modinfo->get_cms() as $cm) {
            if ($cm->modname === 'quiz') {
                $attempts = $DB->get_records('quiz_attempts', [
                    'quiz' => (int) $cm->instance,
                    'userid' => (int) $user->id,
                ], '', 'id');
                $stats['quiz_attempts'] += count($attempts);
                if ($apply) {
                    foreach ($attempts as $att) {
                        try {
                            $attemptobj = quiz_attempt::create((int) $att->id);
                            $attemptobj->delete_attempt();
                        } catch (Throwable $e) {
                            $DB->delete_records('quiz_attempts', ['id' => (int) $att->id]);
                        }
                    }
                    grade_update('mod/quiz', (int) $course->id, 'mod', 'quiz', (int) $cm->instance, 0, (object) [
                        'userid' => (int) $user->id,
                        'rawgrade' => null,
                    ]);
                }
            }
            if ($cm->modname === 'assign') {
                $assign = new assign(context_module::instance((int) $cm->id), $cm, $course);
                $submission = $assign->get_user_submission((int) $user->id, false);
                if ($submission) {
                    $stats['assign_subs']++;
                }
                $grade = $assign->get_user_grade((int) $user->id, false);
                if ($grade) {
                    $stats['assign_grades']++;
                }
                if ($apply) {
                    if ($submission) {
                        $fs = get_file_storage();
                        $fs->delete_area_files(
                            context_module::instance((int) $cm->id)->id,
                            'assignsubmission_file',
                            'submission_files',
                            (int) $submission->id
                        );
                        $fs->delete_area_files(
                            context_module::instance((int) $cm->id)->id,
                            'assignsubmission_onlinetext',
                            'submissions_onlinetext',
                            (int) $submission->id
                        );
                        $DB->delete_records('assignsubmission_file', ['submission' => (int) $submission->id]);
                        $DB->delete_records('assignsubmission_onlinetext', ['submission' => (int) $submission->id]);
                        $DB->delete_records('assign_submission', [
                            'assignment' => (int) $cm->instance,
                            'userid' => (int) $user->id,
                        ]);
                    }
                    if ($grade) {
                        $DB->delete_records('assignfeedback_comments', ['grade' => (int) $grade->id]);
                        $DB->delete_records('assign_grades', [
                            'assignment' => (int) $cm->instance,
                            'userid' => (int) $user->id,
                        ]);
                    }
                    grade_update('mod/assign', (int) $course->id, 'mod', 'assign', (int) $cm->instance, 0, (object) [
                        'userid' => (int) $user->id,
                        'rawgrade' => null,
                    ]);
                }
            }
        }

        // Unenrol student role enrolments only (keep teachers for mentor path unless structure wipe).
        $enrols = $DB->get_records('user_enrolments', ['userid' => (int) $user->id]);
        foreach ($enrols as $ue) {
            $enrol = $DB->get_record('enrol', ['id' => (int) $ue->enrolid]);
            if (!$enrol || (int) $enrol->courseid !== (int) $course->id) {
                continue;
            }
            $roles = get_user_roles($context, (int) $user->id, false);
            $isstudent = false;
            foreach ($roles as $role) {
                if ($role->shortname === 'student') {
                    $isstudent = true;
                    break;
                }
            }
            if (!$isstudent) {
                continue;
            }
            $stats['unenrol_student']++;
            if ($apply) {
                $plugin = enrol_get_plugin($enrol->enrol);
                if ($plugin) {
                    $plugin->unenrol_user($enrol, (int) $user->id);
                }
            }
        }
    }

    return $stats;
}

if ($mode === 'progress' || $mode === 'all') {
    cli_writeln('');
    cli_writeln('=== Progress reset on lp-* ===');
    $totals = [
        'completions' => 0,
        'quiz_attempts' => 0,
        'assign_grades' => 0,
        'assign_subs' => 0,
        'unenrol_student' => 0,
    ];
    foreach ($courses as $course) {
        cli_writeln('Course ' . $course->idnumber . ' …');
        $s = lp_clean_course_progress($course, $apply);
        foreach ($s as $k => $v) {
            $totals[$k] += $v;
        }
        cli_writeln(sprintf(
            '  completions~%d quiz_attempts=%d assign_subs=%d assign_grades=%d unenrol_student=%d',
            $s['completions'],
            $s['quiz_attempts'],
            $s['assign_subs'],
            $s['assign_grades'],
            $s['unenrol_student']
        ));
    }
    $prefs = lp_clean_clear_prefs($apply);
    cli_writeln('LaunchPad user prefs cleared (except toba_portal): ' . $prefs);
    cli_writeln(sprintf(
        'TOTALS completions~%d quiz=%d assign_subs=%d assign_grades=%d unenrol=%d prefs=%d',
        $totals['completions'],
        $totals['quiz_attempts'],
        $totals['assign_subs'],
        $totals['assign_grades'],
        $totals['unenrol_student'],
        $prefs
    ));
}

if ($mode === 'structure' || $mode === 'all') {
    cli_writeln('');
    cli_writeln('=== Delete lp-* courses ===');
    foreach ($courses as $course) {
        $label = $course->idnumber . ' / ' . $course->shortname;
        if (!$apply) {
            cli_writeln('  [dry] would hide+delete #' . $course->id . ' ' . $label);
            continue;
        }
        try {
            if ((int) $course->visible === 1) {
                course_change_visibility((int) $course->id, false);
                cli_writeln('  hidden #' . $course->id . ' ' . $label);
            }
            // Re-fetch after visibility change.
            $fresh = get_course((int) $course->id);
            $ok = delete_course($fresh, false);
            if ($ok) {
                cli_writeln('  deleted #' . $course->id . ' ' . $label);
            } else {
                cli_writeln('  ! delete_course returned false for #' . $course->id);
            }
        } catch (Throwable $e) {
            cli_writeln('  ! failed #' . $course->id . ': ' . $e->getMessage());
        }
    }

    // Also include anyone already tagged fellowship/mentor (post-wipe repair / empty course list).
    $tagged = $DB->get_records_select(
        'user_preferences',
        'name = :n AND (' . $DB->sql_compare_text('value') . ' = :f OR ' . $DB->sql_compare_text('value') . ' = :m)',
        [
            'n' => registration::PREF_PORTAL,
            'f' => registration::PORTAL_FELLOWSHIP,
            'm' => registration::PORTAL_MENTOR,
        ],
        '',
        'userid'
    );
    foreach ($tagged as $row) {
        $stampeduserids[] = (int) $row->userid;
    }
    cli_writeln('');
    cli_writeln('=== Clear Moodle notifications for affected users ===');
    $nnotif = lp_clean_clear_notifications($stampeduserids, $apply);
    cli_writeln(($apply ? 'deleted' : 'would delete') . " notification rows: $nnotif");
}

if ($deletedemousers && ($mode === 'structure' || $mode === 'all')) {
    cli_writeln('');
    cli_writeln('=== Delete presentation demo users ===');
    $demousernames = ['fellow.demo', 'mentor.demo', 'employer.demo'];
    foreach ($demousernames as $username) {
        $user = $DB->get_record('user', [
            'username' => $username,
            'mnethostid' => $CFG->mnet_localhost_id,
            'deleted' => 0,
        ]);
        if (!$user) {
            cli_writeln('  skip missing ' . $username);
            continue;
        }
        if (is_siteadmin((int) $user->id)) {
            cli_writeln('  refuse delete siteadmin ' . $username);
            continue;
        }
        if (!$apply) {
            cli_writeln('  [dry] would delete user #' . $user->id . ' ' . $username);
            continue;
        }
        user_delete_user($user);
        cli_writeln('  deleted user ' . $username);
    }
}

if ($apply) {
    purge_all_caches();
    cli_writeln('');
    cli_writeln('Caches purged.');
}

cli_writeln('');
cli_writeln($apply
    ? 'Done. Open Admin → Tracks: empty (or leftover cats). Create a track, enrol a fellow, walk the path.'
    : 'Dry-run only. Re-run with --apply when ready.');
exit(0);
