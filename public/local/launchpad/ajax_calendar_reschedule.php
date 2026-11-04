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
 * AJAX: drag-reschedule calendar event to another day.
 *
 * @package    local_launchpad
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require(__DIR__ . '/../../config.php');

require_once(__DIR__ . '/classes/calendar_service.php');
require_once(__DIR__ . '/classes/mentor_dashboard.php');

use local_launchpad\calendar_service;
use local_launchpad\fellowship_dashboard;
use local_launchpad\mentor_dashboard;

require_login();
require_sesskey();

header('Content-Type: application/json; charset=utf-8');

$userid = (int) $USER->id;
$portal = optional_param('portal', 'fellow', PARAM_ALPHA);
$allowed = ($portal === 'mentor')
    ? (mentor_dashboard::can_access($userid) || is_siteadmin())
    : (fellowship_dashboard::can_access($userid) || is_siteadmin());
if (!$allowed) {
    echo json_encode([
        'ok' => false,
        'error' => get_string('fellowship_calendar_err_permission', 'local_launchpad'),
    ]);
    exit;
}

$eventid = required_param('id', PARAM_INT);
$daykey = required_param('day_key', PARAM_TEXT);
$result = calendar_service::reschedule_to_day($userid, $eventid, (string) $daykey);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
