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
     * Combined unread for LaunchPad's single top-bar control.
     */
    public static function unread_count(int $userid): int {
        global $CFG;

        if ($userid <= 0) {
            return 0;
        }

        $count = 0;
        if (class_exists('\message_popup\api')) {
            try {
                $count += (int) \message_popup\api::count_unread_popup_notifications($userid);
            } catch (\Throwable $e) {
                // Continue.
            }
        }

        if (!empty($CFG->messaging) && class_exists('\core_message\api')) {
            try {
                $user = \core_user::get_user($userid);
                if ($user) {
                    $count += (int) \core_message\api::count_unread_conversations($user);
                    $count += (int) \core_message\api::get_received_contact_requests_count($userid);
                }
            } catch (\Throwable $e) {
                // Fail soft.
            }
        }

        return max(0, $count);
    }

    /**
     * Aria / title label for the Messages top-bar control.
     */
    public static function messages_label(int $unread): string {
        if ($unread > 0) {
            return get_string('fellowship_messages_unread', 'local_launchpad', $unread);
        }
        return get_string('fellowship_messages', 'local_launchpad');
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
            'conversations' => [],
            'has_conversations' => false,
            'messages' => [],
            'has_messages' => false,
            'form_action' => $inboxurl,
            'sesskey' => sesskey(),
            'portal' => $portal,
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
        $ctx['moodle_messages_url'] = self::moodle_messages_url($otherid);

        return $ctx;
    }

    /**
     * Soft-delete messages for this user only (Moodle MESSAGE_ACTION_DELETED).
     *
     * @param list<int> $messageids
     * @return array{ok:bool,error:?string,deleted:int}
     */
    public static function delete_messages(int $userid, array $messageids): array {
        if ($userid <= 0) {
            return [
                'ok' => false,
                'error' => get_string('fellowship_messages_err_permission', 'local_launchpad'),
                'deleted' => 0,
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
            ];
        }

        if (!self::user_can_delete_own_messages($userid)) {
            return [
                'ok' => false,
                'error' => get_string('fellowship_messages_err_delete', 'local_launchpad'),
                'deleted' => 0,
            ];
        }

        $deleted = 0;
        foreach ($ids as $messageid) {
            try {
                if (!\core_message\api::can_delete_message($userid, $messageid)) {
                    continue;
                }
                if (\core_message\api::delete_message($userid, $messageid)) {
                    $deleted++;
                }
            } catch (\Throwable $e) {
                // Skip failed ids; continue batch.
            }
        }

        if ($deleted === 0) {
            return [
                'ok' => false,
                'error' => get_string('fellowship_messages_err_delete', 'local_launchpad'),
                'deleted' => 0,
            ];
        }

        return ['ok' => true, 'error' => null, 'deleted' => $deleted];
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
     * @return array{ok:bool,error:?string}
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
            \core_message\api::send_message_to_conversation($fromid, $convid, $clean, FORMAT_PLAIN);
            return ['ok' => true, 'error' => null];
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
