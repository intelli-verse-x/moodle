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
 * AJAX: calendar month panel HTML (fellow or mentor).
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
use local_launchpad\persona_home;

require_login();
require_sesskey();

$userid = (int) $USER->id;
$portal = optional_param('portal', 'fellow', PARAM_ALPHA);
if (!in_array($portal, ['fellow', 'mentor', 'admin'], true)) {
    $portal = 'fellow';
}

$time = required_param('time', PARAM_INT);
$filter = optional_param('filter', 'all', PARAM_ALPHA);
$caltrack = 0;
$calincludeorient = false;
$adminui = [];

if ($portal === 'admin') {
    if (!admin_dashboard::can_access($userid) && !is_siteadmin()) {
        throw new \moodle_exception('nopermissions', 'error', '', get_string('fellowship_nav_calendar', 'local_launchpad'));
    }
    $caltrack = admin_dashboard::resolve_calendar_trackid(optional_param('track', 0, PARAM_INT));
    $calincludeorient = (bool) optional_param('include_orient', 0, PARAM_BOOL);
    $courses = admin_dashboard::calendar_courses($caltrack, $calincludeorient);
    $basepath = persona_home::ADMIN_PATH;
    $ctxfn = 'admin_context';
    $alltracks = [];
    foreach (admin_dashboard::list_track_courses() as $trackcourse) {
        $alltracks[] = [
            'id' => (int) $trackcourse->id,
            'name' => format_string($trackcourse->fullname),
        ];
    }
    $adminui = [
        'trackid' => $caltrack,
        'include_orient' => $calincludeorient,
        'preferred_courseid' => $caltrack,
        'show_track_filter' => true,
        'all_tracks' => $alltracks,
    ];
} else if ($portal === 'mentor') {
    if (!mentor_dashboard::can_access($userid) && !is_siteadmin()) {
        throw new \moodle_exception('nopermissions', 'error', '', get_string('fellowship_nav_calendar', 'local_launchpad'));
    }
    $courses = persona_home::list_mentor_courses($userid);
    $basepath = persona_home::MENTOR_PATH;
    $ctxfn = 'mentor_context';
} else {
    if (!fellowship_dashboard::can_access($userid) && !is_siteadmin()) {
        throw new \moodle_exception('nopermissions', 'error', '', get_string('fellowship_nav_calendar', 'local_launchpad'));
    }
    $courses = fellowship_dashboard::list_courses($userid);
    $basepath = persona_home::FELLOW_PATH;
    $ctxfn = 'fellow_context';
}

$resolved = calendar_service::resolve_month($time > 0 ? $time : null, null, null);
if ($portal === 'admin') {
    $ctx = calendar_service::admin_context(
        $userid,
        $courses,
        (int) $resolved['year'],
        (int) $resolved['month'],
        'month',
        $filter,
        null,
        $adminui
    );
} else {
    $ctx = calendar_service::{$ctxfn}(
        $userid,
        $courses,
        (int) $resolved['year'],
        (int) $resolved['month'],
        'month',
        $filter,
        null
    );
}

$html = $OUTPUT->render_from_template('local_launchpad/fellowship_calendar_month', $ctx);
$pageparams = array_filter([
    'view' => 'calendar',
    'cal' => 'month',
    'time' => (int) $resolved['time'],
    'filter' => ($filter === 'course' || $filter === 'personal') ? $filter : null,
    'track' => ($portal === 'admin' && $caltrack > 0) ? $caltrack : null,
    'include_orient' => ($portal === 'admin' && $calincludeorient) ? 1 : null,
]);
$pageurl = (new moodle_url($basepath, $pageparams))->out(false);

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'html' => $html,
    'pageurl' => $pageurl,
    'time' => (int) $resolved['time'],
    'month_label' => (string) ($ctx['month_label'] ?? ''),
], JSON_UNESCAPED_UNICODE);
