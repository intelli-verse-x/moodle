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
 * Employer portal actions — Moodle-backed write handlers (messages, notifications).
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
$ntab = optional_param('ntab', 'all', PARAM_ALPHA);
if (!in_array($ntab, ['all', 'unread'], true)) {
    $ntab = 'all';
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

if ($action === 'mark_all_notifications_read') {
    $returnparams = [
        'view' => 'notifications',
        'ntab' => $ntab !== 'all' ? $ntab : null,
    ];
    try {
        \core_message\api::mark_all_notifications_as_read((int) $USER->id);
    } catch (\Throwable $e) {
        debugging('employee_action mark_all_notifications_read: ' . $e->getMessage(), DEBUG_DEVELOPER);
        redirect(new moodle_url('/local/launchpad/employee.php', array_filter($returnparams + ['notiferr' => 'failed'])));
    }
    redirect(new moodle_url('/local/launchpad/employee.php', array_filter($returnparams + ['notifdone' => 1])));
}

if ($action === 'update_profile') {
    $payload = \local_launchpad\employer_tools::profile_post_payload();
    $err = \local_launchpad\employer_tools::save_profile($USER, $payload);
    if ($err !== '') {
        redirect(new moodle_url('/local/launchpad/employee.php', [
            'view' => 'profile',
            'ptab' => 'edit',
            'profileerr' => $err,
        ]));
    }
    redirect(new moodle_url('/local/launchpad/employee.php', [
        'view' => 'profile',
        'ptab' => 'overview',
        'profilesaved' => 1,
    ]));
}

if ($action === 'update_preference') {
    $prefkey = optional_param('pref', '', PARAM_ALPHAEXT);
    $payload = \local_launchpad\employer_tools::preference_post_payload($prefkey);
    $err = \local_launchpad\employer_tools::save_preference($USER, $prefkey, $payload);
    if ($err !== '') {
        redirect(new moodle_url('/local/launchpad/employee.php', array_filter([
            'view' => 'profile',
            'ptab' => 'preferences',
            'pref' => $prefkey !== '' ? $prefkey : null,
            'preferr' => $err,
        ])));
    }
    redirect(new moodle_url('/local/launchpad/employee.php', array_filter([
        'view' => 'profile',
        'ptab' => 'preferences',
        'pref' => $prefkey !== '' ? $prefkey : null,
        'prefsaved' => 1,
    ])));
}

if ($action === 'mark_notification_read') {
    $notifid = required_param('notifid', PARAM_INT);
    $returnparams = [
        'view' => 'notifications',
        'ntab' => $ntab !== 'all' ? $ntab : null,
        'notifid' => $notifid > 0 ? $notifid : null,
    ];
    if ($notifid <= 0) {
        redirect(new moodle_url('/local/launchpad/employee.php', array_filter($returnparams + ['notiferr' => 'denied'])));
    }
    try {
        $notification = $DB->get_record('notifications', ['id' => $notifid], '*', MUST_EXIST);
        if ((int) $notification->useridto !== (int) $USER->id) {
            redirect(new moodle_url('/local/launchpad/employee.php', array_filter([
                'view' => 'notifications',
                'ntab' => $ntab !== 'all' ? $ntab : null,
                'notiferr' => 'denied',
            ])));
        }
        \core_message\api::mark_notification_as_read($notification);
    } catch (\Throwable $e) {
        debugging('employee_action mark_notification_read: ' . $e->getMessage(), DEBUG_DEVELOPER);
        redirect(new moodle_url('/local/launchpad/employee.php', array_filter($returnparams + ['notiferr' => 'failed'])));
    }
    redirect(new moodle_url('/local/launchpad/employee.php', array_filter($returnparams)));
}

throw new \moodle_exception('invalidaction', 'error');
