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
 * AJAX: send / delete LaunchPad messages (Moodle-backed).
 *
 * @package    local_launchpad
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require(__DIR__ . '/../../config.php');

use local_launchpad\fellowship_dashboard;
use local_launchpad\mentor_dashboard;
use local_launchpad\messaging;

require_login();
require_sesskey();

header('Content-Type: application/json; charset=utf-8');

$userid = (int) $USER->id;
$portal = optional_param('portal', 'fellow', PARAM_ALPHA);
if ($portal === 'mentor') {
    $allowed = mentor_dashboard::can_access($userid) || is_siteadmin();
} else {
    $allowed = fellowship_dashboard::can_access($userid) || is_siteadmin();
}
if (!$allowed) {
    echo json_encode([
        'ok' => false,
        'error' => get_string('fellowship_messages_err_permission', 'local_launchpad'),
    ]);
    exit;
}

$action = required_param('action', PARAM_ALPHA);
$toid = required_param('id', PARAM_INT);

if ($action === 'send') {
    $body = required_param('message', PARAM_RAW_TRIMMED);
    echo json_encode(messaging::send($userid, $toid, $body));
    exit;
}

if ($action === 'delete') {
    $messageids = optional_param_array('messageids', [], PARAM_INT);
    echo json_encode(messaging::delete_messages($userid, $messageids));
    exit;
}

echo json_encode([
    'ok' => false,
    'error' => get_string('fellowship_messages_err_save', 'local_launchpad'),
]);
