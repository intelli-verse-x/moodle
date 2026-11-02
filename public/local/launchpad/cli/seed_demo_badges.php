<?php
// Seed site + course badges for Employer portal demos (Moodle core badge records).
define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/badgeslib.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->libdir . '/clilib.php');

\core\session\manager::write_close();

if (empty($CFG->enablebadges)) {
    cli_error('Badges are disabled (enablebadges).');
}

$admin = get_admin();
if (!$admin) {
    cli_error('No admin user found.');
}

$now = time();
$user = $DB->get_record('user', ['username' => 'employer.demo', 'deleted' => 0], '*', MUST_EXIST);
$courses = enrol_get_users_courses($user->id, true);
$course = reset($courses);
if (!$course) {
    cli_error('employer.demo has no enrolled courses.');
}

/**
 * Create or update a Moodle badge and optionally issue it.
 *
 * @param array $data
 * @param bool $issue
 * @return badge
 */
function local_launchpad_seed_badge(array $data, bool $issue = false): badge {
    global $DB, $CFG, $admin, $now, $user;

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
        $record = (object) [
            'name' => $data['name'],
            'description' => $data['description'],
            'timecreated' => $now,
            'timemodified' => $now,
            'usercreated' => $admin->id,
            'usermodified' => $admin->id,
            'issuername' => $data['issuername'],
            'issuerurl' => $data['issuerurl'],
            'issuercontact' => $data['issuercontact'],
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
        ];
        $id = $DB->insert_record('badge', $record, true);
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
        $candidates = [
            $CFG->dirroot . '/pix/i/badge.png',
            $CFG->dirroot . '/badges/pix/badge.png',
            $CFG->dirroot . '/pix/f/badge.png',
        ];
        $src = null;
        foreach ($candidates as $path) {
            if (is_readable($path)) {
                $src = $path;
                break;
            }
        }
        if ($src) {
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
        }
    }

    if ($issue && !$badge->is_issued($user->id)) {
        $badge->issue($user->id, true);
        mtrace('Issued: ' . $badge->name . ' → employer.demo');
    } else {
        mtrace(($existing ? 'Exists' : 'Created') . ': ' . $badge->name .
            ' (type=' . $badge->type . ', id=' . $badge->id . ')');
    }

    return $badge;
}

$site = local_launchpad_seed_badge([
    'name' => 'Site Learner',
    'description' => 'Site badge available to all learners on this Moodle.',
    'type' => BADGE_TYPE_SITE,
    'courseid' => null,
    'issuername' => 'ToBa Learn',
    'issuerurl' => $CFG->wwwroot,
    'issuercontact' => $admin->email,
    'message' => 'Congratulations! You earned the Site Learner badge.',
    'messagesubject' => 'You earned a badge!',
], false);

$coursebadge = local_launchpad_seed_badge([
    'name' => 'Course Starter',
    'description' => 'Custom course badge for completing the foundations path.',
    'type' => BADGE_TYPE_COURSE,
    'courseid' => (int) $course->id,
    'issuername' => format_string($course->fullname),
    'issuerurl' => (new moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
    'issuercontact' => $admin->email,
    'message' => 'Congratulations! You earned the Course Starter badge.',
    'messagesubject' => 'You earned a course badge!',
], false);

$earned = local_launchpad_seed_badge([
    'name' => 'First Steps',
    'description' => 'Awarded when you begin your assigned upskilling course.',
    'type' => BADGE_TYPE_COURSE,
    'courseid' => (int) $course->id,
    'issuername' => format_string($course->fullname),
    'issuerurl' => (new moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
    'issuercontact' => $admin->email,
    'message' => 'Congratulations! You earned the First Steps badge.',
    'messagesubject' => 'You earned a course badge!',
], true);

mtrace('Done. Site=' . $site->id . ' Course=' . $coursebadge->id . ' Earned=' . $earned->id);
purge_all_caches();
