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

namespace local_launchpad;

/**
 * Moodle-backed messaging for LaunchPad shells (custom UI, Moodle storage).
 *
 * @package    local_launchpad
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class messaging {

    /** Max messages loaded in a thread view. */
    public const THREAD_LIMIT = 80;

    /** Max conversations on the inbox list. */
    public const INBOX_LIMIT = 40;

    /**
     * LaunchPad messages URL (fellow or mentor shell).
     *
     * @param string $portal fellow|mentor
     */
    public static function messages_url(?int $touserid = null, string $portal = 'fellow'): string {
        $path = ($portal === 'mentor') ? persona_home::MENTOR_PATH : persona_home::FELLOW_PATH;
        $params = ['view' => 'messages'];
        if ($touserid !== null && $touserid > 0) {
            $params['id'] = $touserid;
        }
        return (new \moodle_url($path, $params))->out(false);
    }

    /**
     * Escape hatch to Moodle's native messenger (optional secondary link).
     */
    public static function moodle_messages_url(?int $touserid = null): string {
        $params = [];
        if ($touserid !== null && $touserid > 0) {
            $params['id'] = $touserid;
        }
        return (new \moodle_url('/message/index.php', $params))->out(false);
    }

    /**
     * Moodle's default notifications list (popup processor "See all").
     */
    public static function moodle_notifications_url(): string {
        return (new \moodle_url('/message/output/popup/notifications.php'))->out(false);
    }

    /**
     * Whether $fromid may open a private message to $toid.
     */
    public static function can_message(int $fromid, int $toid): bool {
        global $CFG;

        if ($fromid <= 0 || $toid <= 0 || $fromid === $toid) {
            return false;
        }
        if (empty($CFG->messaging)) {
            return false;
        }
        if (!class_exists('\core_message\api')) {
            return false;
        }
        try {
            return \core_message\api::can_send_message($toid, $fromid);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Unread Moodle popup notifications (top-bar bell).
     */
    public static function notification_unread_count(int $userid): int {
        if ($userid <= 0 || !class_exists('\message_popup\api')) {
            return 0;
        }
        try {
            return max(0, (int) \message_popup\api::count_unread_popup_notifications($userid));
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Unread private conversations (sidebar Messages badge).
     */
    public static function conversation_unread_count(int $userid): int {
        global $CFG;

        if ($userid <= 0 || empty($CFG->messaging) || !class_exists('\core_message\api')) {
            return 0;
        }
        try {
            $user = \core_user::get_user($userid);
            if (!$user) {
                return 0;
            }
            return max(0, (int) \core_message\api::count_unread_conversations($user));
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * @deprecated Prefer notification_unread_count(); kept for older callers.
     */
    public static function unread_count(int $userid): int {
        return self::notification_unread_count($userid);
    }

    /**
     * Aria / title label for the Messages control.
     */
    public static function messages_label(int $unread): string {
        if ($unread > 0) {
            return get_string('fellowship_messages_unread', 'local_launchpad', $unread);
        }
        return get_string('fellowship_messages', 'local_launchpad');
    }

    /**
     * Aria / title label for the notifications bell.
     */
    public static function notifications_label(int $unread): string {
        if ($unread > 0) {
            return get_string('fellowship_notifications_unread', 'local_launchpad', $unread);
        }
        return get_string('fellowship_notifications', 'local_launchpad');
    }

    /**
     * Page context: inbox list and/or open thread with another user.
     *
     * @param string $portal fellow|mentor
     * @return array Mustache context
     */
    public static function page_context(int $userid, string $portal = 'fellow', ?int $otherid = null): array {
        global $CFG;

        if ($portal !== 'mentor') {
            $portal = 'fellow';
        }

        $inboxurl = self::messages_url(null, $portal);
        $messagingon = !empty($CFG->messaging);

        $ctx = [
            'messaging_enabled' => $messagingon,
            'inbox_url' => $inboxurl,
            'moodle_messages_url' => self::moodle_messages_url($otherid),
            'has_thread' => false,
            'can_send' => false,
            'can_delete_messages' => false,
            'conversations' => [],
            'has_conversations' => false,
            'messages' => [],
            'has_messages' => false,
            'form_action' => $inboxurl,
            'ajax_url' => (new \moodle_url('/local/launchpad/ajax_messaging.php'))->out(false),
            'sesskey' => sesskey(),
            'portal' => $portal,
            'other_id' => $otherid ?: 0,
            'is_mentor' => $portal === 'mentor',
        ];

        if (!$messagingon) {
            return $ctx;
        }

        $ctx['conversations'] = self::list_conversations($userid, $portal);
        $ctx['has_conversations'] = $ctx['conversations'] !== [];

        if ($otherid === null || $otherid <= 0) {
            return $ctx;
        }

        if (!self::can_message($userid, $otherid)) {
            $ctx['thread_denied'] = true;
            $ctx['other_name'] = get_string('fellowship_messages_unavailable', 'local_launchpad');
            return $ctx;
        }

        $other = \core_user::get_user($otherid, '*', IGNORE_MISSING);
        if (!$other || !empty($other->deleted)) {
            $ctx['thread_denied'] = true;
            $ctx['other_name'] = get_string('fellowship_messages_unavailable', 'local_launchpad');
            return $ctx;
        }

        $convid = self::ensure_conversation($userid, $otherid);
        if ($convid <= 0) {
            $ctx['thread_denied'] = true;
            $ctx['other_name'] = fullname($other);
            return $ctx;
        }

        try {
            \core_message\api::mark_all_messages_as_read($userid, $convid);
        } catch (\Throwable $e) {
            // Non-fatal.
        }

        $raw = \core_message\api::get_conversation_messages(
            $userid,
            $convid,
            0,
            self::THREAD_LIMIT,
            'timecreated ASC'
        );
        $candelete = self::user_can_delete_own_messages($userid);
        $messages = [];
        foreach ($raw['messages'] ?? [] as $msg) {
            $fromid = (int) ($msg->useridfrom ?? 0);
            $mid = (int) ($msg->id ?? 0);
            $messages[] = [
                'id' => $mid,
                'text' => (string) ($msg->text ?? ''),
                'is_mine' => $fromid === $userid,
                'can_delete' => $candelete && $mid > 0
                    && \core_message\api::can_delete_message($userid, $mid),
                'when' => !empty($msg->timecreated)
                    ? userdate((int) $msg->timecreated, get_string('strftimedatetimeshort', 'langconfig'))
                    : '',
            ];
        }

        $threadurl = self::messages_url($otherid, $portal);
        $ctx['has_thread'] = true;
        $ctx['thread_denied'] = false;
        $ctx['conversation_id'] = $convid;
        $ctx['other_id'] = $otherid;
        $ctx['other_name'] = fullname($other);
        $ctx['other_initials'] = fellowship_dashboard::initials($other);
        $ctx['messages'] = $messages;
        $ctx['has_messages'] = $messages !== [];
        $ctx['can_send'] = \core_message\api::can_send_message_to_conversation($userid, $convid);
        $ctx['can_delete_messages'] = $candelete;
        $ctx['form_action'] = $threadurl;
        $ctx['ajax_url'] = (new \moodle_url('/local/launchpad/ajax_messaging.php'))->out(false);
        $ctx['portal'] = $portal === 'mentor' ? 'mentor' : 'fellow';
        $ctx['moodle_messages_url'] = self::moodle_messages_url($otherid);

        return $ctx;
    }

    /**
     * Soft-delete messages for this user only (Moodle MESSAGE_ACTION_DELETED).
     *
     * @param list<int> $messageids
     * @return array{ok:bool,error:?string,deleted:int,deleted_ids:list<int>}
     */
    public static function delete_messages(int $userid, array $messageids): array {
        if ($userid <= 0) {
            return [
                'ok' => false,
                'error' => get_string('fellowship_messages_err_permission', 'local_launchpad'),
                'deleted' => 0,
                'deleted_ids' => [],
            ];
        }

        $ids = [];
        foreach ($messageids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if ($ids === []) {
            return [
                'ok' => false,
                'error' => get_string('fellowship_messages_err_delete_none', 'local_launchpad'),
                'deleted' => 0,
                'deleted_ids' => [],
            ];
        }

        if (!self::user_can_delete_own_messages($userid)) {
            return [
                'ok' => false,
                'error' => get_string('fellowship_messages_err_delete', 'local_launchpad'),
                'deleted' => 0,
                'deleted_ids' => [],
            ];
        }

        $deletedids = [];
        foreach ($ids as $messageid) {
            try {
                if (!\core_message\api::can_delete_message($userid, $messageid)) {
                    continue;
                }
                if (\core_message\api::delete_message($userid, $messageid)) {
                    $deletedids[] = $messageid;
                }
            } catch (\Throwable $e) {
                // Skip failed ids; continue batch.
            }
        }

        if ($deletedids === []) {
            return [
                'ok' => false,
                'error' => get_string('fellowship_messages_err_delete', 'local_launchpad'),
                'deleted' => 0,
                'deleted_ids' => [],
            ];
        }

        return [
            'ok' => true,
            'error' => null,
            'deleted' => count($deletedids),
            'deleted_ids' => $deletedids,
        ];
    }

    /**
     * Whether the user may delete messages from their own view.
     */
    public static function user_can_delete_own_messages(int $userid): bool {
        global $USER;

        if ($userid <= 0 || (int) $USER->id !== $userid) {
            return false;
        }
        return has_capability('moodle/site:deleteownmessage', \context_system::instance())
            || has_capability('moodle/site:deleteanymessage', \context_system::instance());
    }

    /**
     * Send a private message; creates the individual conversation if needed.
     *
     * @return array{ok:bool,error:?string,message?:array}
     */
    public static function send(int $fromid, int $toid, string $text): array {
        $text = trim($text);
        if ($text === '') {
            return [
                'ok' => false,
                'error' => get_string('fellowship_messages_err_empty', 'local_launchpad'),
            ];
        }
        if (!self::can_message($fromid, $toid)) {
            return [
                'ok' => false,
                'error' => get_string('fellowship_messages_err_permission', 'local_launchpad'),
            ];
        }

        $convid = self::ensure_conversation($fromid, $toid);
        if ($convid <= 0) {
            return [
                'ok' => false,
                'error' => get_string('fellowship_messages_err_save', 'local_launchpad'),
            ];
        }

        // Plain text — avoid HTML injection in custom UI; Moodle still stores safely.
        $clean = clean_param($text, PARAM_TEXT);
        if (trim($clean) === '') {
            return [
                'ok' => false,
                'error' => get_string('fellowship_messages_err_empty', 'local_launchpad'),
            ];
        }

        try {
            if (!\core_message\api::can_send_message_to_conversation($fromid, $convid)) {
                return [
                    'ok' => false,
                    'error' => get_string('fellowship_messages_err_permission', 'local_launchpad'),
                ];
            }
            $sent = \core_message\api::send_message_to_conversation($fromid, $convid, $clean, FORMAT_PLAIN);
            $mid = (int) ($sent->id ?? 0);
            $when = !empty($sent->timecreated)
                ? userdate((int) $sent->timecreated, get_string('strftimedatetimeshort', 'langconfig'))
                : '';
            return [
                'ok' => true,
                'error' => null,
                'message' => [
                    'id' => $mid,
                    'text' => (string) ($sent->text ?? $clean),
                    'is_mine' => true,
                    'can_delete' => $mid > 0 && self::user_can_delete_own_messages($fromid)
                        && \core_message\api::can_delete_message($fromid, $mid),
                    'when' => $when,
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => get_string('fellowship_messages_err_save', 'local_launchpad'),
            ];
        }
    }

    /**
     * @return list<array>
     */
    private static function list_conversations(int $userid, string $portal): array {
        try {
            $rows = \core_message\api::get_conversations(
                $userid,
                0,
                self::INBOX_LIMIT,
                \core_message\api::MESSAGE_CONVERSATION_TYPE_INDIVIDUAL
            );
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($rows as $conv) {
            $members = array_values((array) ($conv->members ?? []));
            $other = null;
            foreach ($members as $member) {
                $mid = (int) ($member->id ?? $member->userid ?? 0);
                if ($mid > 0 && $mid !== $userid) {
                    $other = $member;
                    break;
                }
            }
            if (!$other) {
                continue;
            }
            $otherid = (int) ($other->id ?? $other->userid ?? 0);
            $preview = '';
            if (!empty($conv->messages[0])) {
                $preview = trim(html_to_text((string) ($conv->messages[0]->text ?? ''), 0, false));
                if (\core_text::strlen($preview) > 90) {
                    $preview = \core_text::substr($preview, 0, 87) . '…';
                }
            }
            $unread = (int) ($conv->unreadcount ?? 0);
            $otheruser = \core_user::get_user($otherid, 'id,firstname,lastname', IGNORE_MISSING);
            $out[] = [
                'other_id' => $otherid,
                'other_name' => (string) ($other->fullname ?? ($otheruser ? fullname($otheruser) : '')),
                'other_initials' => $otheruser
                    ? fellowship_dashboard::initials($otheruser)
                    : '?',
                'preview' => $preview,
                'unread' => $unread,
                'has_unread' => $unread > 0,
                'is_unread' => empty($conv->isread),
                'url' => self::messages_url($otherid, $portal),
            ];
        }
        return $out;
    }

    /**
     * Get or create a 1:1 conversation between two users.
     */
    private static function ensure_conversation(int $userid, int $otherid): int {
        $existing = \core_message\api::get_conversation_between_users([$userid, $otherid]);
        if ($existing) {
            return (int) $existing;
        }
        try {
            $created = \core_message\api::create_conversation(
                \core_message\api::MESSAGE_CONVERSATION_TYPE_INDIVIDUAL,
                [$userid, $otherid]
            );
            return (int) ($created->id ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
