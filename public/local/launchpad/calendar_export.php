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
 * Download LaunchPad-visible calendar events as iCal.
 *
 * @package    local_launchpad
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_once(__DIR__ . '/classes/calendar_service.php');
require_once(__DIR__ . '/classes/mentor_dashboard.php');

use local_launchpad\calendar_service;
use local_launchpad\fellowship_dashboard;
use local_launchpad\mentor_dashboard;
use local_launchpad\persona_home;

require_login();
require_sesskey();

$userid = (int) $USER->id;
$portal = optional_param('portal', 'fellow', PARAM_ALPHA);

if ($portal === 'mentor') {
    if (!mentor_dashboard::can_access($userid) && !is_siteadmin()) {
        throw new \moodle_exception('nopermissions', 'error', '', get_string('fellowship_nav_calendar', 'local_launchpad'));
    }
    $courses = persona_home::list_mentor_courses($userid);
} else {
    if (!fellowship_dashboard::can_access($userid) && !is_siteadmin()) {
        throw new \moodle_exception('nopermissions', 'error', '', get_string('fellowship_nav_calendar', 'local_launchpad'));
    }
    $courses = fellowship_dashboard::list_courses($userid);
}

$ical = calendar_service::build_ical($userid, $courses);
$filename = 'launchpad-calendar-' . userdate(time(), '%Y%m%d') . '.ics';

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, must-revalidate');
echo $ical;
exit;
