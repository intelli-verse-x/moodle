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
 * AJAX: fellow calendar month panel HTML (prev/next without full page reload).
 *
 * @package    local_launchpad
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require(__DIR__ . '/../../config.php');

use local_launchpad\calendar_service;
use local_launchpad\fellowship_dashboard;
use local_launchpad\persona_home;

require_login();
require_sesskey();

$userid = (int) $USER->id;
if (!fellowship_dashboard::can_access($userid) && !is_siteadmin()) {
    throw new \moodle_exception('nopermissions', 'error', '', get_string('fellowship_nav_calendar', 'local_launchpad'));
}

$time = required_param('time', PARAM_INT);
$resolved = calendar_service::resolve_month($time > 0 ? $time : null, null, null);
$courses = fellowship_dashboard::list_courses($userid);
$ctx = calendar_service::fellow_context(
    $userid,
    $courses,
    (int) $resolved['year'],
    (int) $resolved['month'],
    'month'
);

$html = $OUTPUT->render_from_template('local_launchpad/fellowship_calendar_month', $ctx);
$pageurl = (new moodle_url(persona_home::FELLOW_PATH, [
    'view' => 'calendar',
    'cal' => 'month',
    'time' => (int) $resolved['time'],
]))->out(false);

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'html' => $html,
    'pageurl' => $pageurl,
    'time' => (int) $resolved['time'],
    'month_label' => (string) ($ctx['month_label'] ?? ''),
], JSON_UNESCAPED_UNICODE);
