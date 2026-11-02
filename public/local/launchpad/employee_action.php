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
 * Employer portal actions — Moodle-backed write handlers (messages, etc.).
 *
 * @package    local_launchpad
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_launchpad\account_persona;

require_login();
require_sesskey();

if (!account_persona::is_employee((int) $USER->id) && !is_siteadmin()) {
    throw new \moodle_exception('employee_access_denied', 'local_launchpad');
}

$action = required_param('action', PARAM_ALPHAEXT);
$mtab = optional_param('mtab', 'all', PARAM_ALPHA);
if (!in_array($mtab, ['all', 'starred', 'private', 'group'], true)) {
    $mtab = 'all';
}

if ($action === 'send_message') {
    $convid = required_param('convid', PARAM_INT);
    $raw = optional_param('message', '', PARAM_RAW);
    $message = trim(clean_param($raw, PARAM_TEXT));

    $returnparams = [
        'view' => 'messages',
        'convid' => $convid > 0 ? $convid : null,
        'mtab' => $mtab !== 'all' ? $mtab : null,
    ];

    if ($convid <= 0) {
        redirect(new moodle_url('/local/launchpad/employee.php', array_filter($returnparams + ['msgerr' => 'invalid'])));
    }
    if ($message === '') {
        redirect(new moodle_url('/local/launchpad/employee.php', array_filter($returnparams + ['msgerr' => 'empty'])));
    }
    if (\core_text::strlen($message) > 65535) {
        redirect(new moodle_url('/local/launchpad/employee.php', array_filter($returnparams + ['msgerr' => 'toolong'])));
    }

    try {
        if (!\core_message\api::can_send_message_to_conversation((int) $USER->id, $convid)) {
            redirect(new moodle_url('/local/launchpad/employee.php', array_filter($returnparams + ['msgerr' => 'denied'])));
        }
        \core_message\api::send_message_to_conversation((int) $USER->id, $convid, $message, FORMAT_PLAIN);
        // Match Moodle drawer: viewing/sending clears unread for this conversation.
        \core_message\api::mark_all_messages_as_read((int) $USER->id, $convid);
    } catch (\Throwable $e) {
        debugging('employee_action send_message: ' . $e->getMessage(), DEBUG_DEVELOPER);
        redirect(new moodle_url('/local/launchpad/employee.php', array_filter($returnparams + ['msgerr' => 'failed'])));
    }

    redirect(new moodle_url('/local/launchpad/employee.php', array_filter($returnparams + ['msgsent' => 1])));
}

throw new \moodle_exception('invalidaction', 'error');
