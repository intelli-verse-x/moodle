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
 * Display a list of failed badge issue records.
 *
 * @package     local_obf
 * @author      Sylvain Revenu | Pimenko 2024
 * @copyright   2013-2020, Open Badge Factory Oy
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use classes\criterion\obf_criterion_item;
use classes\obf_client;
use classes\obf_issuefailedrecord;

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/obf_issuefailedrecord.php');
require_once(__DIR__ . '/lib.php');

global $DB, $PAGE, $OUTPUT;

$context = context_system::instance();

require_login();
require_capability(
    'moodle/site:config',
    $context,
);

$PAGE->set_context($context);
$PAGE->set_url('/local/obf/failrecordlist.php');
$PAGE->set_title(
    get_string(
        'failrecordlist',
        'local_obf',
    ),
);
$PAGE->set_heading(
    get_string(
        'failrecordlist',
        'local_obf',
    ),
);
$PAGE->set_pagelayout('admin');

$action = optional_param(
    'action',
    '',
    PARAM_ALPHA,
);
$id = optional_param(
    'id',
    0,
    PARAM_INT,
);

if ($action === 'delete' && confirm_sesskey() && $id > 0) {
    obf_delete_failed_record($id);
    redirect(new moodle_url('/local/obf/failrecordlist.php'));
}

$records = $DB->get_records(
    'local_obf_issuefailedrecord',
    null,
    'ID DESC',
);

$createrecord = static function($record) {
    $recordobject = new obf_issuefailedrecord($record);
    $courses = $recordobject->getinformations()['criteriondata']->get_items();

    $courseslinks = [];
    foreach ($courses as $criterioncourse) {
        $courseid = $criterioncourse->get_courseid();

        // Don't display moodle course instance.
        if ($courseid > 0) {
            $course = get_course($courseid);
            $courseslinks[] = [
                'id' => $courseid,
                'link' => (new moodle_url(
                    '/course/view.php',
                    [ 'id' => $courseid ],
                ))->out(false),
                'name' => $course->fullname
            ];
        }
    }

    $badgeinformation = $recordobject->getinformations()['badge'];
    if ($badgeinformation) {
        $name = $recordobject->getinformations()['badge']->get_name();
        $badgeinformation = true;
    } else {
        // In case badgeinformation were not retrive from server.
        $name = $recordobject->getemail()['badgeid'];
        $badgeinformation = false;
    }

    return [
        'id' => $recordobject->getid(),
        'badgename' => $name,
        'recipients' => $recordobject->getformattedrecipients(),
        'timestamp' => userdate($recordobject->gettimestamp()),
        'email' => $recordobject->getemail(),
        'status' => $recordobject->getstatus(),
        'clientid' => $recordobject->getclientid(),
        'deleteurl' => (new moodle_url(
            '/local/obf/failrecordlist.php',
            [
                'action' => 'delete',
                'id' => $recordobject->getid(),
                'sesskey' => sesskey()
            ],
        ))->out(false),
        'courseslinks' => $courseslinks,
        'badgeinformation' => $badgeinformation,
    ];
};

$failedrecords = array_values(
    array_map(
        $createrecord,
        $records,
    ),
);

// Check if all client connections are avaible.
// In case OBF server is still down we can't get badge information.
// So we will display different information to users like badgeID.
$connectionfailed = true;
$result = null;
$clientavaible = $DB->get_records(
    'local_obf_oauth2',
    null,
    'client_name',
);
if (empty($clientavaible)) {
    // Fallback for legacy connect.
    $client = obf_client::get_instance();
    $result = $client->test_connection();
} else {
    foreach ($clientavaible as $client) {
        $client = obf_client::connect($client->client_id);
        $result = $client->test_connection();
    }
}
if ($result === -1) {
    $connectionfailed = false;
}

$data = [
    'records' => $failedrecords,
    'connectionfailed' => $connectionfailed
];

echo $OUTPUT->header();

echo $OUTPUT->render_from_template(
    'local_obf/issuefailedrecord',
    $data,
);

echo $OUTPUT->footer();