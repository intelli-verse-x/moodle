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
 * AJAX: create / update / delete personal or course calendar events.
 *
 * @package    local_launchpad
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require(__DIR__ . '/../../config.php');

require_once(__DIR__ . '/classes/calendar_service.php');
require_once(__DIR__ . '/classes/mentor_dashboard.php');
require_once(__DIR__ . '/classes/admin_dashboard.php');

use local_launchpad\admin_dashboard;
use local_launchpad\calendar_service;
use local_launchpad\fellowship_dashboard;
use local_launchpad\mentor_dashboard;

require_login();
require_sesskey();

header('Content-Type: application/json; charset=utf-8');

$userid = (int) $USER->id;
$portal = optional_param('portal', 'fellow', PARAM_ALPHA);
if ($portal === 'admin') {
    $allowed = admin_dashboard::can_access($userid) || is_siteadmin();
} else if ($portal === 'mentor') {
    $allowed = mentor_dashboard::can_access($userid) || is_siteadmin();
} else {
    $allowed = fellowship_dashboard::can_access($userid) || is_siteadmin();
}
if (!$allowed) {
    echo json_encode([
        'ok' => false,
        'error' => get_string('fellowship_calendar_err_permission', 'local_launchpad'),
    ]);
    exit;
}

$action = required_param('action', PARAM_ALPHA);
$result = ['ok' => false, 'error' => get_string('fellowship_calendar_err_save', 'local_launchpad'), 'id' => null];

if ($action === 'delete') {
    $eventid = required_param('id', PARAM_INT);
    $result = calendar_service::delete_event($userid, $eventid);
} else if ($action === 'create' || $action === 'update') {
    $scope = optional_param('scope', 'personal', PARAM_ALPHA);
    if ($scope !== 'course') {
        $scope = 'personal';
    }
    $name = required_param('name', PARAM_TEXT);
    $date = required_param('date', PARAM_TEXT);
    $time = required_param('time', PARAM_TEXT);
    $durationmode = optional_param('duration_mode', 'none', PARAM_ALPHA);
    $durationmin = optional_param('duration', 0, PARAM_INT);
    $enddate = optional_param('end_date', '', PARAM_TEXT);
    $endtime = optional_param('end_time', '', PARAM_TEXT);
    $description = optional_param('description', '', PARAM_RAW);
    $location = optional_param('location', '', PARAM_TEXT);
    $courseid = optional_param('courseid', 0, PARAM_INT);
    $repeats = optional_param('repeats', 1, PARAM_INT);
    $timestart = calendar_service::parse_user_datetime((string) $date, (string) $time);
    if ($timestart === null) {
        $result = [
            'ok' => false,
            'error' => get_string('fellowship_calendar_err_when', 'local_launchpad'),
            'id' => null,
        ];
    } else {
        $dur = calendar_service::timeduration_from_form(
            (string) $durationmode,
            $timestart,
            $durationmin,
            (string) $enddate,
            (string) $endtime
        );
        if (!$dur['ok']) {
            $result = ['ok' => false, 'error' => $dur['error'], 'id' => null];
        } else {
            $description = trim(clean_param($description, PARAM_RAW));
            // Allow basic HTML from textarea (escaped by clean_text on store via Moodle).
            $description = clean_text($description, FORMAT_HTML);
            $location = trim(clean_param((string) $location, PARAM_TEXT));
            $timeduration = (int) $dur['timeduration'];
            if ($scope === 'course') {
                if ($action === 'create') {
                    $result = calendar_service::create_course(
                        $userid,
                        $courseid,
                        (string) $name,
                        $timestart,
                        $timeduration,
                        $description,
                        $location,
                        $repeats
                    );
                } else {
                    $eventid = required_param('id', PARAM_INT);
                    $result = calendar_service::update_course(
                        $userid,
                        $eventid,
                        $courseid,
                        (string) $name,
                        $timestart,
                        $timeduration,
                        $description,
                        $location
                    );
                }
            } else if ($action === 'create') {
                $result = calendar_service::create_personal(
                    $userid,
                    (string) $name,
                    $timestart,
                    $timeduration,
                    $description,
                    $location,
                    $repeats
                );
            } else {
                $eventid = required_param('id', PARAM_INT);
                $result = calendar_service::update_personal(
                    $userid,
                    $eventid,
                    (string) $name,
                    $timestart,
                    $timeduration,
                    $description,
                    $location
                );
            }
        }
    }
} else {
    $result = [
        'ok' => false,
        'error' => get_string('fellowship_calendar_err_save', 'local_launchpad'),
        'id' => null,
    ];
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
