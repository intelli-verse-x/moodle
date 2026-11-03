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
     * @param string $mtab all|starred|private|group
     */
    public static function messages_url(
        ?int $touserid = null,
        string $portal = 'fellow',
        string $mtab = 'all',
        int $convid = 0
    ): string {
        $path = ($portal === 'mentor') ? persona_home::MENTOR_PATH : persona_home::FELLOW_PATH;
        $params = ['view' => 'messages'];
        if ($mtab !== 'all' && in_array($mtab, ['starred', 'private', 'group'], true)) {
            $params['mtab'] = $mtab;
        }
        if ($touserid !== null && $touserid > 0) {
            $params['id'] = $touserid;
        } else if ($convid > 0) {
            $params['convid'] = $convid;
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
        $sm = get_string_manager();
        if ($unread > 0) {
            if ($sm->string_exists('fellowship_messages_unread', 'local_launchpad')) {
                return get_string('fellowship_messages_unread', 'local_launchpad', $unread);
            }
            return 'Messages, ' . $unread . ' unread';
        }
        if ($sm->string_exists('fellowship_nav_messages', 'local_launchpad')) {
            return get_string('fellowship_nav_messages', 'local_launchpad');
        }
        if ($sm->string_exists('fellowship_messages', 'local_launchpad')) {
            return get_string('fellowship_messages', 'local_launchpad');
        }
        return 'Messages';
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
     * Page context: inbox list and/or open thread with another user / conversation.
     *
     * @param string $portal fellow|mentor
     * @param string $mtab all|starred|private|group
     * @return array Mustache context
     */
    public static function page_context(
        int $userid,
        string $portal = 'fellow',
        ?int $otherid = null,
        string $mtab = 'all',
        int $convid = 0
    ): array {
        global $CFG;

        if ($portal !== 'mentor') {
            $portal = 'fellow';
        }
        if (!in_array($mtab, ['all', 'starred', 'private', 'group'], true)) {
            $mtab = 'all';
        }

        $inboxurl = self::messages_url(null, $portal, $mtab);
        $messagingon = !empty($CFG->messaging);
        $strings = self::page_strings();
        $path = ($portal === 'mentor') ? persona_home::MENTOR_PATH : persona_home::FELLOW_PATH;
        $taburls = [];
        foreach (['all', 'starred', 'private', 'group'] as $key) {
            $params = ['view' => 'messages'];
            if ($key !== 'all') {
                $params['mtab'] = $key;
            }
            if ($otherid !== null && $otherid > 0) {
                $params['id'] = $otherid;
            } else if ($convid > 0) {
                $params['convid'] = $convid;
            }
            $taburls[$key] = (new \moodle_url($path, $params))->out(false);
        }

        $ctx = [
            'messaging_enabled' => $messagingon,
            'inbox_url' => $inboxurl,
            'moodle_messages_url' => self::moodle_messages_url($otherid),
            'has_thread' => false,
            'can_send' => false,
            'can_delete_messages' => false,
            'conversations' => [],
            'has_conversations' => false,
            'conversation_count' => 0,
            'unread_conversations' => 0,
            'has_unread_conversations' => false,
            'messages' => [],
            'has_messages' => false,
            'form_action' => $inboxurl,
            'ajax_url' => (new \moodle_url('/local/launchpad/ajax_messaging.php'))->out(false),
            'sesskey' => sesskey(),
            'portal' => $portal,
            'other_id' => $otherid ?: 0,
            'conversation_id' => 0,
            'mtab' => $mtab,
            'mtab_all' => $mtab === 'all',
            'mtab_starred' => $mtab === 'starred',
            'mtab_private' => $mtab === 'private',
            'mtab_group' => $mtab === 'group',
            'tab_all_url' => $taburls['all'],
            'tab_starred_url' => $taburls['starred'],
            'tab_private_url' => $taburls['private'],
            'tab_group_url' => $taburls['group'],
            'is_mentor' => $portal === 'mentor',
            'thread_type_label' => $strings['private'],
            'strings' => $strings,
        ];

        if (!$messagingon) {
            return $ctx;
        }

        $selectedother = ($otherid !== null && $otherid > 0) ? $otherid : 0;
        $selectedconvid = max(0, $convid);
        $allconversations = self::list_conversations($userid, $portal, $mtab, $selectedother, $selectedconvid);
        $filtered = array_values(array_filter($allconversations, static function (array $row) use ($mtab): bool {
            if ($mtab === 'starred') {
                return !empty($row['isfavourite']);
            }
            if ($mtab === 'private') {
                return !empty($row['is_private']) || !empty($row['is_self']);
            }
            if ($mtab === 'group') {
                return !empty($row['is_group']);
            }
            return true;
        }));

        $unreadconversations = 0;
        try {
            $user = \core_user::get_user($userid);
            if ($user) {
                $unreadconversations = (int) \core_message\api::count_unread_conversations($user);
            }
        } catch (\Throwable $e) {
            foreach ($allconversations as $row) {
                if (!empty($row['has_unread'])) {
                    $unreadconversations++;
                }
            }
        }

        $ctx['conversations'] = $filtered;
        $ctx['has_conversations'] = $filtered !== [];
        $ctx['conversation_count'] = count($allconversations);
        $ctx['unread_conversations'] = $unreadconversations;
        $ctx['has_unread_conversations'] = $unreadconversations > 0;
        $ctx['has_filter_empty'] = $allconversations !== [] && $filtered === [];

        if ($selectedother <= 0 && $selectedconvid <= 0) {
            return $ctx;
        }

        // Prefer 1:1 by other user; otherwise open a Moodle conversation id (group / self).
        if ($selectedother > 0) {
            if (!self::can_message($userid, $selectedother)) {
                $ctx['thread_denied'] = true;
                $ctx['other_name'] = $strings['unavailable_title'];
                return $ctx;
            }

            $other = \core_user::get_user($selectedother, '*', IGNORE_MISSING);
            if (!$other || !empty($other->deleted)) {
                $ctx['thread_denied'] = true;
                $ctx['other_name'] = $strings['unavailable_title'];
                return $ctx;
            }

            $selectedconvid = self::ensure_conversation($userid, $selectedother);
            if ($selectedconvid <= 0) {
                $ctx['thread_denied'] = true;
                $ctx['other_name'] = fullname($other);
                return $ctx;
            }

            return self::hydrate_thread_context(
                $ctx,
                $userid,
                $portal,
                $mtab,
                $selectedconvid,
                $selectedother,
                $other,
                true,
                $strings['private']
            );
        }

        // Group / personal conversation by convid.
        $matched = null;
        foreach ($allconversations as $row) {
            if ((int) ($row['id'] ?? 0) === $selectedconvid) {
                $matched = $row;
                break;
            }
        }
        if ($matched === null) {
            $ctx['thread_denied'] = true;
            $ctx['other_name'] = $strings['unavailable_title'];
            return $ctx;
        }

        // Individual rows should redirect-style open via other user when possible.
        if (!empty($matched['is_private']) && !empty($matched['other_id'])) {
            $other = \core_user::get_user((int) $matched['other_id'], '*', IGNORE_MISSING);
            if ($other && empty($other->deleted) && self::can_message($userid, (int) $matched['other_id'])) {
                return self::hydrate_thread_context(
                    $ctx,
                    $userid,
                    $portal,
                    $mtab,
                    $selectedconvid,
                    (int) $matched['other_id'],
                    $other,
                    true,
                    (string) ($matched['type_label'] ?? $strings['private'])
                );
            }
        }

        return self::hydrate_thread_context(
            $ctx,
            $userid,
            $portal,
            $mtab,
            $selectedconvid,
            0,
            null,
            false,
            (string) ($matched['type_label'] ?? $strings['thread_heading']),
            (string) ($matched['other_name'] ?? $matched['name'] ?? $strings['thread_heading']),
            (string) ($matched['other_initials'] ?? $matched['initials'] ?? '?'),
            (string) ($matched['imageurl'] ?? ''),
            !empty($matched['has_image'])
        );
    }

    /**
     * Fill thread panel fields for an open conversation.
     *
     * @param array<string,mixed> $ctx
     * @return array<string,mixed>
     */
    private static function hydrate_thread_context(
        array $ctx,
        int $userid,
        string $portal,
        string $mtab,
        int $convid,
        int $otherid,
        ?\stdClass $other,
        bool $cansendprivate,
        string $typelabel,
        string $displayname = '',
        string $initials = '',
        string $imageurl = '',
        bool $hasimage = false
    ): array {
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
        if ($other !== null) {
            $displayname = fullname($other);
            $initials = fellowship_dashboard::initials($other);
            $imageurl = self::member_image_url($other);
            $hasimage = $imageurl !== '';
        }
        $youlabel = get_string_manager()->string_exists('employer_messages_you', 'local_launchpad')
            ? get_string('employer_messages_you', 'local_launchpad')
            : 'You';
        $messages = [];
        foreach ($raw['messages'] ?? [] as $msg) {
            $fromid = (int) ($msg->useridfrom ?? 0);
            $mid = (int) ($msg->id ?? 0);
            $ismine = $fromid === $userid;
            $fromlabel = $youlabel;
            if (!$ismine) {
                $fromlabel = $displayname;
                if (!empty($msg->useridfrom)) {
                    $fromuser = \core_user::get_user($fromid, 'id,firstname,lastname', IGNORE_MISSING);
                    if ($fromuser) {
                        $fromlabel = fullname($fromuser);
                    }
                }
            }
            $messages[] = [
                'id' => $mid,
                'text' => (string) ($msg->text ?? ''),
                'is_mine' => $ismine,
                'from_label' => $fromlabel,
                'can_delete' => $candelete && $mid > 0
                    && \core_message\api::can_delete_message($userid, $mid),
                'when' => !empty($msg->timecreated)
                    ? userdate((int) $msg->timecreated, get_string('strftimedatetimeshort', 'langconfig'))
                    : '',
            ];
        }

        $threadurl = $otherid > 0
            ? self::messages_url($otherid, $portal, $mtab)
            : self::messages_url(null, $portal, $mtab, $convid);
        $cansend = false;
        if ($cansendprivate && $otherid > 0) {
            try {
                $cansend = \core_message\api::can_send_message_to_conversation($userid, $convid);
            } catch (\Throwable $e) {
                $cansend = false;
            }
        }

        $ctx['has_thread'] = true;
        $ctx['thread_denied'] = false;
        $ctx['conversation_id'] = $convid;
        $ctx['other_id'] = $otherid;
        $ctx['other_name'] = $displayname;
        $ctx['other_initials'] = $initials !== '' ? $initials : '?';
        $ctx['other_imageurl'] = $imageurl;
        $ctx['other_has_image'] = $hasimage;
        $ctx['thread_type_label'] = $typelabel;
        $ctx['messages'] = $messages;
        $ctx['has_messages'] = $messages !== [];
        $ctx['can_send'] = $cansend;
        $ctx['can_delete_messages'] = $candelete && $cansend;
        $ctx['form_action'] = $threadurl;
        $ctx['ajax_url'] = (new \moodle_url('/local/launchpad/ajax_messaging.php'))->out(false);
        $ctx['portal'] = $portal === 'mentor' ? 'mentor' : 'fellow';
        $ctx['moodle_messages_url'] = $otherid > 0
            ? self::moodle_messages_url($otherid)
            : (new \moodle_url('/message/index.php', ['convid' => $convid]))->out(false);
        $ctx['inbox_url'] = self::messages_url(null, $portal, $mtab);

        return $ctx;
    }

    /**
     * Mustache string bag for the Fellowship / Mentor messages page.
     *
     * @return array<string,string>
     */
    private static function page_strings(): array {
        $str = static function (string $id, string $fallback) {
            if (get_string_manager()->string_exists($id, 'local_launchpad')) {
                $out = get_string($id, 'local_launchpad');
                if (is_string($out) && $out !== '' && strpos($out, '[[') === false) {
                    return $out;
                }
            }
            return $fallback;
        };

        return [
            'inbox_heading' => $str('fellowship_messages_inbox', 'Conversations'),
            'intro' => $str(
                'fellowship_messages_intro',
                'Read and reply here — conversations sync with Moodle messaging.'
            ),
            'open_moodle' => $str('fellowship_messages_open_moodle', 'Open Moodle messenger'),
            'open_thread' => $str('employer_messages_open_thread', 'Open in Moodle'),
            'total_label' => $str('employer_messages_total_label', 'Conversations'),
            'unread_label' => $str('employer_messages_unread_label', 'Unread'),
            'tab_all' => $str('employer_messages_tab_all', 'All'),
            'tab_starred' => $str('employer_messages_tab_starred', 'Starred'),
            'tab_private' => $str('employer_messages_tab_private', 'Private'),
            'tab_group' => $str('employer_messages_tab_group', 'Group'),
            'thread_heading' => $str('fellowship_messages_thread', 'Conversation'),
            'private' => $str('fellowship_messages_private', 'Private chat'),
            'back' => $str('fellowship_messages_back', 'All messages'),
            'pick_title' => $str('fellowship_messages_pick_title', 'Choose a conversation'),
            'pick_body' => $str(
                'fellowship_messages_pick_body',
                'Pick someone from the list, or open Message from a mentor on My track.'
            ),
            'thread_empty' => $str('fellowship_messages_thread_empty', 'No messages yet. Say hello below.'),
            'inbox_empty_title' => $str('employer_messages_empty_title', 'No conversations yet'),
            'inbox_empty_body' => $str(
                'fellowship_messages_inbox_empty',
                'No conversations yet. Message someone from their profile card to start.'
            ),
            'empty_filter_title' => $str('employer_messages_empty_filter_title', 'Nothing in this view'),
            'empty_filter_body' => $str(
                'employer_messages_empty_filter_body',
                'Try another tab to see more conversations.'
            ),
            'cannot_reply' => $str(
                'employer_messages_cannot_reply',
                'You cannot reply here. Open Moodle Messages or check messaging permissions.'
            ),
            'compose' => $str('fellowship_messages_compose', 'Message'),
            'compose_placeholder' => $str('fellowship_messages_compose_placeholder', 'Write a message…'),
            'send' => $str('fellowship_messages_send', 'Send'),
            'emoji' => $str('fellowship_messages_emoji', 'Emoji'),
            'permission' => $str('fellowship_messages_err_permission', 'You cannot message this person.'),
            'unavailable_title' => $str('fellowship_messages_unavailable', 'Cannot message this person'),
            'unavailable_body' => $str(
                'fellowship_messages_unavailable_body',
                'You do not have permission to message them, or messaging is unavailable.'
            ),
            'disabled_title' => $str('fellowship_messages_disabled_title', 'Messaging is off'),
            'disabled_body' => $str(
                'fellowship_messages_disabled_body',
                'Site messaging is disabled in Moodle. Ask an admin to enable it.'
            ),
            'delete' => $str('fellowship_messages_delete', 'Delete'),
            'delete_cancel' => $str('fellowship_messages_delete_cancel', 'Cancel'),
            'delete_confirm_title' => $str('fellowship_messages_delete_confirm_title', 'Delete messages'),
            'delete_confirm' => $str(
                'fellowship_messages_delete_confirm',
                'Are you sure you would like to delete the selected messages? This will not delete them for other conversation participants.'
            ),
            'select_hint' => $str('fellowship_messages_select_hint', 'Tap a message to select and delete'),
        ];
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
            $youlabel = get_string_manager()->string_exists('employer_messages_you', 'local_launchpad')
                ? get_string('employer_messages_you', 'local_launchpad')
                : 'You';
            return [
                'ok' => true,
                'error' => null,
                'message' => [
                    'id' => $mid,
                    'text' => (string) ($sent->text ?? $clean),
                    'is_mine' => true,
                    'from_label' => $youlabel,
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
     * @return list<array<string,mixed>>
     */
    private static function list_conversations(
        int $userid,
        string $portal,
        string $mtab = 'all',
        int $selectedother = 0,
        int $selectedconvid = 0
    ): array {
        try {
            // Favourites + private (+ self) + groups — same overview as Employer.
            $rows = \core_message\api::get_conversations($userid, 0, self::INBOX_LIMIT, null, null, true);
        } catch (\Throwable $e) {
            return [];
        }

        $str = static function (string $id, string $fallback): string {
            if (get_string_manager()->string_exists($id, 'local_launchpad')) {
                $out = get_string($id, 'local_launchpad');
                if (is_string($out) && $out !== '' && strpos($out, '[[') === false) {
                    return $out;
                }
            }
            return $fallback;
        };
        $youprefix = $str('employer_messages_you_prefix', 'You:');

        $out = [];
        foreach ($rows as $conv) {
            $convid = (int) ($conv->id ?? 0);
            if ($convid <= 0) {
                continue;
            }

            $type = (int) ($conv->type ?? \core_message\api::MESSAGE_CONVERSATION_TYPE_INDIVIDUAL);
            $isgroup = $type === \core_message\api::MESSAGE_CONVERSATION_TYPE_GROUP;
            $isself = $type === \core_message\api::MESSAGE_CONVERSATION_TYPE_SELF;
            $isprivate = $type === \core_message\api::MESSAGE_CONVERSATION_TYPE_INDIVIDUAL;

            $name = trim(html_to_text((string) ($conv->name ?? ''), 0, false));
            $imageurl = trim((string) ($conv->imageurl ?? ''));
            $otherid = 0;
            $other = null;

            if ($isself) {
                if ($name === '') {
                    $name = get_string('selfconversation', 'core_message');
                }
            } else if ($isprivate && !empty($conv->members) && is_iterable($conv->members)) {
                foreach ($conv->members as $member) {
                    $mid = (int) ($member->id ?? $member->userid ?? 0);
                    if ($mid <= 0 || $mid === $userid) {
                        continue;
                    }
                    $other = $member;
                    $otherid = $mid;
                    if ($name === '') {
                        $name = trim(html_to_text((string) ($member->fullname ?? ''), 0, false));
                    }
                    if ($imageurl === '' && !empty($member->profileimageurl)) {
                        $imageurl = (string) $member->profileimageurl;
                    }
                    break;
                }
                if ($otherid <= 0) {
                    continue;
                }
            }

            if ($name === '') {
                $name = $str('employer_messages_untitled', 'Conversation');
            }

            $preview = '';
            $previewprefix = '';
            $when = '';
            if (!empty($conv->messages) && is_array($conv->messages)) {
                $msg = reset($conv->messages);
                if ($msg) {
                    $preview = trim(html_to_text((string) ($msg->text ?? ''), 0, false));
                    if (\core_text::strlen($preview) > 90) {
                        $preview = \core_text::substr($preview, 0, 87) . '…';
                    }
                    $from = (int) ($msg->useridfrom ?? 0);
                    if ($from === $userid) {
                        $previewprefix = $youprefix;
                    } else if (!$isprivate) {
                        foreach ((array) ($conv->members ?? []) as $member) {
                            if ((int) ($member->id ?? 0) === $from) {
                                $sender = trim(html_to_text((string) ($member->fullname ?? ''), 0, false));
                                if ($sender !== '') {
                                    $parts = preg_split('/\s+/', $sender);
                                    $previewprefix = ($parts[0] ?? $sender) . ':';
                                }
                                break;
                            }
                        }
                    }
                    $created = (int) ($msg->timecreated ?? 0);
                    if ($created > 0) {
                        $when = userdate($created, get_string('strftimerecent', 'langconfig'));
                    }
                }
            } else if ($isself) {
                $preview = $str(
                    'employer_messages_self_empty',
                    'Your personal space in Moodle — open it to add a note.'
                );
            }

            if ($isgroup) {
                $typelabel = $str('employer_messages_type_group', 'Group');
            } else if ($isself) {
                $typelabel = $str('employer_messages_type_self', 'Personal');
            } else {
                $typelabel = $str('employer_messages_type_private', 'Private');
            }

            $otheruser = $otherid > 0 ? \core_user::get_user($otherid, '*', IGNORE_MISSING) : null;
            if ($imageurl === '' && $otheruser) {
                $imageurl = self::member_image_url($otheruser);
            }
            $initials = $otheruser
                ? fellowship_dashboard::initials($otheruser)
                : self::conversation_initials($name);

            $url = $otherid > 0
                ? self::messages_url($otherid, $portal, $mtab)
                : self::messages_url(null, $portal, $mtab, $convid);

            $unread = (int) ($conv->unreadcount ?? 0);
            $out[] = [
                'id' => $convid,
                'other_id' => $otherid,
                'name' => $name,
                'other_name' => $name,
                'other_initials' => $initials,
                'initials' => $initials,
                'imageurl' => $imageurl,
                'has_image' => $imageurl !== '',
                'preview' => $preview,
                'has_preview' => $preview !== '',
                'preview_prefix' => $previewprefix,
                'has_preview_prefix' => $previewprefix !== '',
                'when' => $when,
                'has_when' => $when !== '',
                'type_label' => $typelabel,
                'isfavourite' => !empty($conv->isfavourite),
                'is_group' => $isgroup,
                'is_self' => $isself,
                'is_private' => $isprivate,
                'unread' => $unread,
                'has_unread' => $unread > 0,
                'is_unread' => empty($conv->isread),
                'is_selected' => ($selectedother > 0 && $otherid === $selectedother)
                    || ($selectedconvid > 0 && $convid === $selectedconvid),
                'url' => $url,
            ];
        }
        return $out;
    }

    /**
     * Two-letter initials fallback when no avatar URL.
     */
    private static function conversation_initials(string $name): string {
        $name = trim($name);
        if ($name === '') {
            return '?';
        }
        $parts = preg_split('/\s+/', $name) ?: [];
        $a = \core_text::substr($parts[0] ?? $name, 0, 1);
        $b = \core_text::substr($parts[1] ?? '', 0, 1);
        $out = \core_text::strtoupper($a . $b);
        return $out !== '' ? $out : '?';
    }

    /**
     * Profile image URL for a conversation member, if available.
     */
    private static function member_image_url(\stdClass $user): string {
        global $PAGE;

        try {
            $pic = new \user_picture($user);
            $pic->size = 1;
            return $pic->get_url($PAGE)->out(false);
        } catch (\Throwable $e) {
            return '';
        }
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
