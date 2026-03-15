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

use classes\obf_assertion;
use classes\obf_client;
use classes\obf_badge;

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/client.php');
require_once(__DIR__ . '/classes/badge.php');
require_once($CFG->libdir . '/csvlib.class.php');

$clientid = optional_param('clientid', null, PARAM_ALPHANUM);
if (empty($clientid)) {
    $clientid = null;
}

$badgeid = optional_param('badgeid', null, PARAM_ALPHANUM);
$courseid = optional_param('courseid', null, PARAM_INT);
$action = optional_param('action', 'list', PARAM_ALPHANUM);


global $DB;

$url = new moodle_url('/local/obf/badge.php', array('action' => $action));
// Site context.
if (empty($courseid)) {
    require_login();
} else { // Course context.
    $url->param('courseid', $courseid);
    require_login($courseid);
}

$context = empty($courseid) ? context_system::instance() : context_course::instance($courseid);

$PAGE->set_context($context);

require_capability('local/obf:viewhistory', $context);

$client = obf_client::connect($clientid, $USER);

$badge = empty($badgeid) ? null : obf_badge::get_instance($badgeid);


$searchparams = array(
    'api_consumer_id' => OBF_API_CONSUMER_ID,
    'order_by' => 'desc'
);

if (!empty($courseid)) {
    $searchparams['log_entry'] = 'course_id:' . (string)$courseid;
}

$badgeid = is_null($badge) ? null : $badge->get_id();
if (!empty($badgeid)) {
    $searchparams['badge_id'] = $badgeid;
}

$courselookup = $DB->get_records_menu('course', null, '', 'id, fullname');

$history = obf_assertion::get_assertions($client, $badge, null, -1, false, $searchparams);

// CSV output filename.
$filename = 'badge_history.csv';

// CSV headers.
$headers = array(
    get_string('badgename', 'local_obf'), 
    get_string('recipients', 'local_obf'),
    get_string('issuedon', 'local_obf'), 
    get_string('expiresby', 'local_obf'),
    get_string('issuer', 'local_obf'),
    get_string('issuedfrom', 'local_obf'));

// Initialize CSV file.
$csvfile = new \csv_export_writer();
$csvfile->set_filename($filename);

// Write headers to the CSV file.
$csvfile->add_data($headers);

// Write history data to the CSV file.
foreach ($history as $assertion) {
    $users = $history->get_assertion_users($assertion);
    $logcourseid = $assertion->get_log_entry('course_id');
    $activity = $assertion->get_log_entry('activity_name');
    // Default: course_id value is not empty and not numeric.
    $issuedfrom = '';

    // Manual issuing: course_id value is null or empty string.
    if ($logcourseid === null || $logcourseid === '') {
        $issuedfrom = get_string('manualissuing', 'local_obf');
    // Course issuing: course_id value is number or numeric string.
    } else if (is_numeric($logcourseid)) {
        $issuedfrom = $courselookup[$logcourseid] ?? '';
        if (!empty($activity)) {
                $issuedfrom .= ' (' . $activity . ')';
        }
    }

    $recipients = array();
    foreach ($users as $user) {
        if (!isset($user->firstname) || !isset($user->lastname)) {
            if ($user == 'userremoved') {
                $recipients[] = '';
            } else {
                $recipients[] = $user;
            }
        } else {
            $recipients[] = $user->firstname . ' ' . $user->lastname;
        }
    }

    $expires = $assertion->get_expires();
    $expiresby = $expires ? userdate($expires, get_string('dateformatdate', 'local_obf')) : '-';
    $rowdata = array(
    $assertion->get_badge()->get_name(), // Badge name
        implode(', ', $recipients), // Recipients
        userdate($assertion->get_issuedon(), get_string('dateformatdate', 'local_obf')), // Issued on
        $expiresby, // Expires by
        $issuername = $assertion->get_issuer_name_used_in_assertion(), // Issuer
        $issuedfrom // Issued from
    );

    // Add a record to the CSV file.
    $csvfile->add_data($rowdata);
}

// Close the CSV file.
$csvfile->download_file();
