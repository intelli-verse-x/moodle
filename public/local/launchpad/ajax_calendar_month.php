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

use local_launchpad\calendar_service;
use local_launchpad\fellowship_dashboard;
use local_launchpad\mentor_dashboard;
use local_launchpad\persona_home;

require_login();
require_sesskey();

$userid = (int) $USER->id;
$portal = optional_param('portal', 'fellow', PARAM_ALPHA);
if ($portal !== 'mentor') {
    $portal = 'fellow';
}

if ($portal === 'mentor') {
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

$time = required_param('time', PARAM_INT);
$filter = optional_param('filter', 'all', PARAM_ALPHA);
$resolved = calendar_service::resolve_month($time > 0 ? $time : null, null, null);
$ctx = calendar_service::{$ctxfn}(
    $userid,
    $courses,
    (int) $resolved['year'],
    (int) $resolved['month'],
    'month',
    $filter,
    null
);

$html = $OUTPUT->render_from_template('local_launchpad/fellowship_calendar_month', $ctx);
$pageparams = [
    'view' => 'calendar',
    'cal' => 'month',
    'time' => (int) $resolved['time'],
];
if ($filter === 'course' || $filter === 'personal') {
    $pageparams['filter'] = $filter;
}
$pageurl = (new moodle_url($basepath, $pageparams))->out(false);

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'html' => $html,
    'pageurl' => $pageurl,
    'time' => (int) $resolved['time'],
    'month_label' => (string) ($ctx['month_label'] ?? ''),
], JSON_UNESCAPED_UNICODE);
