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

    /** Canonical Moodle course-group idnumber for one LaunchPad track chat. */
    public const TRACK_CHAT_IDNUMBER = 'lp-trackchat';

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
     *
     * @param string $portal fellow|mentor|any … fellow portal also requires a LaunchPad
     *        classmate or mentor relationship (not site-wide strangers).
     */
    public static function can_message(int $fromid, int $toid, string $portal = 'any'): bool {
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
            if (!\core_message\api::can_send_message($toid, $fromid)) {
                return false;
            }
        } catch (\Throwable $e) {
            return false;
        }

        if ($portal === 'fellow') {
            return self::is_launchpad_message_peer($fromid, $toid);
        }
        return true;
    }

    /**
     * Existing 1:1 conversation id between two users (0 if none).
     */
    public static function existing_conversation_id(int $userid, int $otherid): int {
        if ($userid <= 0 || $otherid <= 0 || $userid === $otherid) {
            return 0;
        }
        if (!class_exists('\core_message\api')) {
            return 0;
        }
        try {
            $existing = \core_message\api::get_conversation_between_users([$userid, $otherid]);
            return $existing ? (int) $existing : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * May open a thread: can send, or already have a conversation (read-only OK).
     *
     * Lets mentors read admin Review-oversight pings even when Moodle blocks reply.
     */
    public static function can_view_thread_with(int $viewerid, int $otherid, string $portal = 'any'): bool {
        if (self::can_message($viewerid, $otherid, $portal)) {
            return true;
        }
        return self::existing_conversation_id($viewerid, $otherid) > 0;
    }

    /**
     * Fellow may message: mentors on a shared lp-* course, or classmates (student) on a shared lp-* course.
     */
    public static function is_launchpad_message_peer(int $fromid, int $toid): bool {
        if ($fromid <= 0 || $toid <= 0 || $fromid === $toid) {
            return false;
        }
        // Mentors of the fellow's LaunchPad courses.
        if (account_persona::is_mentor($toid)) {
            foreach (self::shared_launchpad_courseids($fromid, $toid) as $courseid) {
                if (account_persona::is_mentor($toid, $courseid)
                        && account_persona::is_fellowship($fromid, $courseid)) {
                    return true;
                }
            }
        }
        // Classmates: both students on at least one shared LaunchPad *track* (not orientation).
        foreach (self::shared_launchpad_courseids($fromid, $toid) as $courseid) {
            $course = get_course($courseid);
            $idn = (string) ($course->idnumber ?? '');
            if (!admin_dashboard::is_track_idnumber($idn)) {
                continue;
            }
            if (account_persona::is_fellowship($fromid, $courseid)
                    && account_persona::is_fellowship($toid, $courseid)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Shared LaunchPad (lp-*) course ids between two users.
     *
     * @return list<int>
     */
    public static function shared_launchpad_courseids(int $userid, int $otherid): array {
        global $CFG;

        require_once($CFG->libdir . '/enrollib.php');

        $shared = enrol_get_shared_courses($userid, $otherid, true);
        if (empty($shared)) {
            return [];
        }
        $ids = [];
        foreach ($shared as $course) {
            $idnumber = (string) ($course->idnumber ?? '');
            if ($idnumber !== '' && str_starts_with($idnumber, account_persona::COURSE_IDNUMBER_PREFIX)) {
                $ids[] = (int) $course->id;
            }
        }
        return $ids;
    }

    /**
     * Classmates (student role) on the viewer's LaunchPad courses … for Messages picker.
     *
     * @return list<array{id:int,name:string,initials:string,course_label:string,url:string,can_message:bool}>
     */
    public static function list_classmates(int $userid, string $portal = 'fellow'): array {
        global $CFG;

        require_once($CFG->libdir . '/enrollib.php');

        if ($userid <= 0 || empty($CFG->messaging)) {
            return [];
        }

        $courses = fellowship_dashboard::list_courses($userid);
        if ($courses === []) {
            return [];
        }

        $byid = [];
        foreach ($courses as $course) {
            // Orientation (lp-orient-*) is shared onboarding … skip smoke/test classmates there.
            $idnumber = (string) ($course->idnumber ?? '');
            if ($idnumber !== '' && str_starts_with($idnumber, admin_dashboard::ORIENT_IDNUMBER_PREFIX)) {
                continue;
            }
            $context = \context_course::instance((int) $course->id);
            $students = get_enrolled_users(
                $context,
                'mod/assign:submit',
                0,
                'u.id,u.firstname,u.lastname,u.firstnamephonetic,u.lastnamephonetic,u.middlename,u.alternatename',
                'u.lastname ASC, u.firstname ASC'
            );
            $courselabel = format_string($course->fullname);
            foreach ($students as $student) {
                $sid = (int) $student->id;
                if ($sid === $userid) {
                    continue;
                }
                // Mentors sometimes hold submit; skip teacher archetypes.
                if (account_persona::is_mentor($sid, (int) $course->id)) {
                    continue;
                }
                if (!account_persona::is_fellowship($sid, (int) $course->id)) {
                    continue;
                }
                if (!isset($byid[$sid])) {
                    $byid[$sid] = [
                        'id' => $sid,
                        'name' => fullname($student),
                        'initials' => fellowship_dashboard::initials($student),
                        'course_label' => $courselabel,
                        'url' => self::messages_url($sid, $portal),
                        'can_message' => self::can_message($userid, $sid, $portal),
                        'role_label' => get_string('fellowship_messages_role_classmate', 'local_launchpad'),
                    ];
                }
            }
        }

        $out = array_values($byid);
        usort($out, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return $out;
    }

    /**
     * Mentors on the viewer's LaunchPad courses … Messages picker (alongside classmates).
     *
     * @return list<array>
     */
    public static function list_course_mentors_for_messages(int $userid, string $portal = 'fellow'): array {
        if ($userid <= 0) {
            return [];
        }
        $byid = [];
        foreach (fellowship_dashboard::list_courses($userid) as $course) {
            $idnumber = (string) ($course->idnumber ?? '');
            if ($idnumber !== '' && str_starts_with($idnumber, admin_dashboard::ORIENT_IDNUMBER_PREFIX)) {
                continue;
            }
            foreach (fellowship_dashboard::course_mentors($course, $userid) as $mentor) {
                $mid = (int) $mentor['id'];
                if (isset($byid[$mid])) {
                    continue;
                }
                $byid[$mid] = [
                    'id' => $mid,
                    'name' => (string) $mentor['name'],
                    'initials' => fellowship_dashboard::initials(
                        (object) ['firstname' => $mentor['name'], 'lastname' => '']
                    ),
                    'course_label' => format_string($course->fullname),
                    'url' => self::messages_url($mid, $portal),
                    'can_message' => !empty($mentor['can_message'])
                        || self::can_message($userid, $mid, $portal),
                    'role_label' => get_string('fellowship_messages_role_mentor', 'local_launchpad'),
                ];
                // Prefer real initials from user record.
                $u = \core_user::get_user($mid, 'id,firstname,lastname', IGNORE_MISSING);
                if ($u) {
                    $byid[$mid]['initials'] = fellowship_dashboard::initials($u);
                    $byid[$mid]['name'] = fullname($u);
                }
            }
        }
        $out = array_values($byid);
        usort($out, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return $out;
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
     * Moodle notification preference page (advanced matrix escape).
     */
    public static function notification_preferences_url(): string {
        return (new \moodle_url('/message/notificationpreferences.php'))->out(false);
    }

    /**
     * Portal script path for fellow|mentor|admin|employer.
     */
    public static function portal_path(string $portal): string {
        return match ($portal) {
            'mentor' => persona_home::MENTOR_PATH,
            'admin' => persona_home::ADMIN_PATH,
            'employer' => persona_home::EMPLOYEE_PATH,
            default => persona_home::FELLOW_PATH,
        };
    }

    /**
     * LaunchPad notifications inbox URL.
     *
     * @param array<string,scalar|null> $params
     */
    public static function notifications_page_url(string $portal = 'fellow', array $params = []): string {
        $params = array_merge(['view' => 'notifications'], $params);
        return (new \moodle_url(self::portal_path($portal), array_filter(
            $params,
            static fn($v) => $v !== null && $v !== '' && $v !== false
        )))->out(false);
    }

    /**
     * LaunchPad notification preferences URL.
     */
    public static function notification_prefs_page_url(string $portal = 'fellow'): string {
        return (new \moodle_url(self::portal_path($portal), ['view' => 'notificationprefs']))->out(false);
    }

    /**
     * Shell topbar context for LaunchPad notification dropdown (+ Moodle compare link).
     *
     * @return array<string,mixed>
     */
    public static function notification_dropdown_context(int $userid, string $portal = 'fellow'): array {
        $unread = self::notification_unread_count($userid);
        $seeall = self::notifications_page_url($portal);
        return [
            'notif_userid' => $userid,
            'notif_portal' => $portal,
            'notif_ajax_url' => (new \moodle_url('/local/launchpad/ajax_notifications.php'))->out(false),
            'notif_sesskey' => sesskey(),
            'notif_prefs_url' => self::notification_prefs_page_url($portal),
            'notif_see_all_url' => $seeall,
            'notifications_url' => $seeall,
            'notifications_label' => self::notifications_label($unread),
            'unread_notifications' => $unread,
            'has_unread_notifications' => $unread > 0,
            'notif_limit' => 20,
        ];
    }

    /**
     * Popup notifications page (Moodle message_popup API), export-shaped for the custom dropdown.
     *
     * @return array{ok:bool,notifications:list<array>,unreadcount:int,error?:string}
     */
    public static function list_popup_notifications(
        int $userid,
        int $limit = 20,
        int $offset = 0,
        string $portal = 'fellow'
    ): array {
        global $PAGE;

        if ($userid <= 0 || !class_exists('\message_popup\api')) {
            return ['ok' => false, 'notifications' => [], 'unreadcount' => 0, 'error' => 'unavailable'];
        }

        $limit = max(1, min(50, $limit));
        $offset = max(0, $offset);
        if (!in_array($portal, ['fellow', 'mentor', 'admin', 'employer'], true)) {
            $portal = 'fellow';
        }

        try {
            $PAGE->set_context(\context_system::instance());
            $renderer = $PAGE->get_renderer('core_message');
            $records = \message_popup\api::get_popup_notifications($userid, 'DESC', $limit, $offset);
            $items = [];
            foreach ($records as $notification) {
                $output = new \message_popup\output\popup_notification($notification);
                $ctx = $output->export_for_template($renderer);
                $id = (int) ($ctx->id ?? 0);
                $contexturl = trim((string) ($ctx->contexturl ?? ''));
                $detail = self::notifications_page_url($portal, ['notifid' => $id]);
                $items[] = [
                    'id' => $id,
                    'subject' => (string) ($ctx->subject ?? ''),
                    'shortenedsubject' => (string) ($ctx->shortenedsubject ?? $ctx->subject ?? ''),
                    'timecreatedpretty' => (string) ($ctx->timecreatedpretty ?? ''),
                    'timecreated' => (int) ($ctx->timecreated ?? 0),
                    'read' => !empty($ctx->read),
                    'unread' => empty($ctx->read),
                    'contexturl' => $contexturl,
                    'has_contexturl' => $contexturl !== '',
                    'openurl' => $detail,
                    'viewmoreurl' => $detail,
                    'iconurl' => (string) ($ctx->iconurl ?? ''),
                ];
            }
            return [
                'ok' => true,
                'notifications' => $items,
                'unreadcount' => self::notification_unread_count($userid),
            ];
        } catch (\Throwable $e) {
            debugging('list_popup_notifications: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return [
                'ok' => false,
                'notifications' => [],
                'unreadcount' => self::notification_unread_count($userid),
                'error' => 'loadfailed',
            ];
        }
    }

    /**
     * Mark every unread popup notification as read (core_message API).
     *
     * @return array{ok:bool,unreadcount:int,error?:string}
     */
    public static function mark_all_popup_notifications_read(int $userid, int $timecreatedto = 0): array {
        if ($userid <= 0 || !class_exists('\core_message\api')) {
            return ['ok' => false, 'unreadcount' => 0, 'error' => 'unavailable'];
        }
        try {
            if ($timecreatedto <= 0) {
                $timecreatedto = time();
            }
            // Signature: touserid, fromuserid=null|0 (all senders), timecreatedto.
            \core_message\api::mark_all_notifications_as_read($userid, 0, $timecreatedto);
            return ['ok' => true, 'unreadcount' => self::notification_unread_count($userid)];
        } catch (\Throwable $e) {
            debugging('mark_all_popup_notifications_read: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return [
                'ok' => false,
                'unreadcount' => self::notification_unread_count($userid),
                'error' => 'markfailed',
            ];
        }
    }

    /**
     * AJAX: load one notification for the inbox right pane (marks read like Moodle).
     *
     * @return array{ok:bool,selected?:array<string,mixed>,unreadcount:int,error?:string}
     */
    public static function notification_detail_for_inbox(
        int $userid,
        int $notifid,
        string $portal = 'fellow',
        string $ntab = 'all'
    ): array {
        global $DB;

        if (!in_array($portal, ['fellow', 'mentor', 'admin', 'employer'], true)) {
            $portal = 'fellow';
        }
        $ntab = in_array($ntab, ['all', 'unread'], true) ? $ntab : 'all';
        $notifid = max(0, $notifid);
        $unread = self::notification_unread_count($userid);

        if ($userid <= 0 || $notifid <= 0) {
            return ['ok' => false, 'unreadcount' => $unread, 'error' => 'invalid'];
        }

        try {
            $record = self::find_popup_notification_record($userid, $notifid, []);
            if ($record === null) {
                $record = $DB->get_record('notifications', ['id' => $notifid, 'useridto' => $userid]);
            }
            if ($record === null) {
                return ['ok' => false, 'unreadcount' => $unread, 'error' => 'notfound'];
            }

            $wasunread = empty($record->timeread);
            if ($wasunread && class_exists('\core_message\api')) {
                try {
                    \core_message\api::mark_notification_as_read($record);
                    $record->timeread = time();
                    if ($unread > 0) {
                        $unread--;
                    }
                } catch (\Throwable $e) {
                    debugging('notification_detail_for_inbox mark: ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }

            return [
                'ok' => true,
                'selected' => self::format_inbox_detail($record, $portal, $ntab),
                'unreadcount' => $unread,
                'was_unread' => $wasunread,
            ];
        } catch (\Throwable $e) {
            debugging('notification_detail_for_inbox: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return [
                'ok' => false,
                'unreadcount' => self::notification_unread_count($userid),
                'error' => 'loadfailed',
            ];
        }
    }

    /**
     * Full-page notifications inbox + detail (LaunchPad chrome for every portal).
     *
     * @return array<string,mixed>
     */
    public static function notifications_inbox_page(
        int $userid,
        string $portal = 'fellow',
        string $ntab = 'all',
        int $notifid = 0,
        bool $notifdone = false,
        string $notiferr = ''
    ): array {
        global $DB;

        if (!in_array($portal, ['fellow', 'mentor', 'admin', 'employer'], true)) {
            $portal = 'fellow';
        }
        $ntab = in_array($ntab, ['all', 'unread'], true) ? $ntab : 'all';
        $notifid = max(0, $notifid);
        $script = self::portal_path($portal);
        $prefsurl = self::notification_prefs_page_url($portal);
        $actionurl = (new \moodle_url($script))->out(false);

        $taburls = [
            'all' => self::notifications_page_url($portal, array_filter([
                'ntab' => null,
                'notifid' => $notifid > 0 ? $notifid : null,
            ])),
            'unread' => self::notifications_page_url($portal, array_filter([
                'ntab' => 'unread',
                'notifid' => $notifid > 0 ? $notifid : null,
            ])),
        ];

        $flash = null;
        if ($notifdone) {
            $flash = ['ok' => true, 'text' => get_string('fellowship_notif_marked_all', 'local_launchpad')];
        } else if ($notiferr !== '') {
            $flash = [
                'ok' => false,
                'text' => get_string('fellowship_notif_action_failed', 'local_launchpad'),
            ];
        }

        $strings = [
            'notifications_total_label' => get_string('fellowship_notifications', 'local_launchpad'),
            'notifications_intro' => get_string('fellowship_notif_page_intro', 'local_launchpad'),
            'notifications_inbox_lead' => get_string('fellowship_notif_page_lead', 'local_launchpad'),
            'notifications_mark_all' => get_string('fellowship_notif_mark_all', 'local_launchpad'),
            'notifications_preferences' => get_string('fellowship_notif_preferences', 'local_launchpad'),
            'notifications_inbox_heading' => get_string('fellowship_notif_inbox', 'local_launchpad'),
            'notifications_unread_label' => get_string('fellowship_notif_unread_label', 'local_launchpad'),
            'notifications_tab_all' => get_string('fellowship_notif_tab_all', 'local_launchpad'),
            'notifications_tab_unread' => get_string('fellowship_notif_tab_unread', 'local_launchpad'),
            'notifications_empty_title' => get_string('fellowship_notif_empty', 'local_launchpad'),
            'notifications_empty_body' => get_string('fellowship_notif_empty_body', 'local_launchpad'),
            'notifications_empty_filter_title' => get_string('fellowship_notif_empty_filter_title', 'local_launchpad'),
            'notifications_empty_filter_body' => get_string('fellowship_notif_empty_filter_body', 'local_launchpad'),
            'notifications_detail_heading' => get_string('fellowship_notif_detail', 'local_launchpad'),
            'notifications_detail_empty_title' => get_string('fellowship_notif_detail_empty_title', 'local_launchpad'),
            'notifications_detail_empty_body' => get_string('fellowship_notif_detail_empty_body', 'local_launchpad'),
            'notifications_back' => get_string('fellowship_notif_back', 'local_launchpad'),
            'notifications_open_related' => get_string('fellowship_notif_open_related', 'local_launchpad'),
            'notifications_unread_chip' => get_string('fellowship_notif_tab_unread', 'local_launchpad'),
        ];

        $empty = [
            'strings' => $strings,
            'page_items' => [],
            'has_items' => false,
            'notification_count' => 0,
            'unread_count' => 0,
            'has_unread' => false,
            'prefs_url' => $prefsurl,
            'ntab_all' => $ntab === 'all',
            'ntab_unread' => $ntab === 'unread',
            'tab_all_url' => $taburls['all'],
            'tab_unread_url' => $taburls['unread'],
            'has_selected' => false,
            'selected' => null,
            'can_mark_all' => false,
            'action_url' => $actionurl,
            'ajax_url' => (new \moodle_url('/local/launchpad/ajax_notifications.php'))->out(false),
            'sesskey' => sesskey(),
            'portal' => $portal,
            'ntab' => $ntab,
            'has_flash' => $flash !== null,
            'flash' => $flash,
        ];

        if ($userid <= 0 || !class_exists('\message_popup\api')) {
            return $empty;
        }

        try {
            $unreadcount = self::notification_unread_count($userid);
            $records = \message_popup\api::get_popup_notifications($userid, 'DESC', 80, 0);

            if ($notifid > 0) {
                $selectedrecord = self::find_popup_notification_record($userid, $notifid, $records);
                if ($selectedrecord !== null && empty($selectedrecord->timeread)) {
                    try {
                        \core_message\api::mark_notification_as_read($selectedrecord);
                        $selectedrecord->timeread = time();
                        if ($unreadcount > 0) {
                            $unreadcount--;
                        }
                        foreach ($records as $idx => $rec) {
                            if ((int) ($rec->id ?? 0) === $notifid) {
                                $records[$idx]->timeread = $selectedrecord->timeread;
                                break;
                            }
                        }
                    } catch (\Throwable $e) {
                        debugging('notifications_inbox mark read: ' . $e->getMessage(), DEBUG_DEVELOPER);
                    }
                }
            }

            $allitems = [];
            $selected = null;
            foreach ($records as $n) {
                $row = self::format_inbox_row($n, $portal, $ntab, $notifid);
                if ($row === null) {
                    continue;
                }
                $allitems[] = $row;
                if (!empty($row['is_selected'])) {
                    $selected = self::format_inbox_detail($n, $portal, $ntab);
                }
            }

            if ($selected === null && $notifid > 0) {
                $extra = self::find_popup_notification_record($userid, $notifid, $records);
                if ($extra === null) {
                    $extra = $DB->get_record('notifications', ['id' => $notifid, 'useridto' => $userid]);
                }
                if ($extra !== null) {
                    $selected = self::format_inbox_detail($extra, $portal, $ntab);
                    $extrarow = self::format_inbox_row($extra, $portal, $ntab, $notifid);
                    if ($extrarow !== null) {
                        array_unshift($allitems, $extrarow);
                    }
                }
            }

            $items = [];
            foreach ($allitems as $row) {
                if ($ntab === 'unread' && empty($row['unread'])) {
                    continue;
                }
                $items[] = $row;
            }

            return array_merge($empty, [
                'page_items' => $items,
                'has_items' => $items !== [],
                'notification_count' => count($records),
                'unread_count' => $unreadcount,
                'has_unread' => $unreadcount > 0,
                'can_mark_all' => $unreadcount > 0,
                'has_selected' => $selected !== null,
                'selected' => $selected,
            ]);
        } catch (\Throwable $e) {
            debugging('notifications_inbox_page: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return $empty;
        }
    }

    /**
     * Channels exposed in the LaunchPad notification preferences matrix.
     *
     * @return list<string>
     */
    public static function notification_preference_channels(): array {
        return ['popup', 'email'];
    }

    /**
     * Read notification-preferences POST payload (matrix + disable-all).
     *
     * @return array{disable_all:bool,matrix:array<string,array{popup:bool,email:bool}>}
     */
    public static function notification_preferences_request_payload(): array {
        $providerlist = optional_param_array('providers', [], PARAM_ALPHANUMEXT);
        $rawmatrix = $_POST['matrix'] ?? [];
        if (!is_array($rawmatrix)) {
            $rawmatrix = [];
        }

        $matrix = [];
        foreach ($providerlist as $prefbase) {
            $prefbase = clean_param((string) $prefbase, PARAM_ALPHANUMEXT);
            if ($prefbase === '') {
                continue;
            }
            $channels = $rawmatrix[$prefbase] ?? [];
            if (!is_array($channels)) {
                $channels = [];
            }
            $matrix[$prefbase] = [
                'popup' => !empty($channels['popup']),
                'email' => !empty($channels['email']),
            ];
        }

        return [
            'disable_all' => (bool) optional_param('disable_all', 0, PARAM_BOOL),
            'matrix' => $matrix,
        ];
    }

    /**
     * Notification preferences — full Web/Email matrix (Moodle storage, LaunchPad chrome).
     *
     * No Advanced Moodle escape: fellow/mentor/admin/employer manage prefs here.
     *
     * @return array<string,mixed>
     */
    public static function notification_preferences_page(
        int $userid,
        string $portal = 'fellow',
        bool $saved = false,
        string $err = ''
    ): array {
        global $CFG;

        $portal = in_array($portal, ['fellow', 'mentor', 'admin', 'employer'], true) ? $portal : 'fellow';
        $back = self::notifications_page_url($portal);

        $flash = null;
        if ($saved) {
            $flash = ['ok' => true, 'text' => get_string('fellowship_notif_prefs_saved', 'local_launchpad')];
        } else if ($err !== '') {
            $flash = [
                'ok' => false,
                'text' => get_string('fellowship_notif_prefs_failed', 'local_launchpad'),
            ];
        }

        require_once($CFG->dirroot . '/message/lib.php');

        $user = \core_user::get_user($userid, '*', MUST_EXIST);
        $allprocessors = get_message_processors();
        $channelnames = self::notification_preference_channels();
        $channels = [];
        foreach ($channelnames as $name) {
            if (empty($allprocessors[$name])) {
                continue;
            }
            $processor = $allprocessors[$name];
            if (empty($processor->enabled) || empty($processor->configured)) {
                continue;
            }
            $channels[$name] = $processor;
        }

        $providers = message_get_providers_for_user($userid);
        $preferences = \core_message\api::get_all_message_preferences($allprocessors, $providers, $user);
        $defaults = get_message_output_default_preferences();
        $disableall = !empty($user->emailstop);

        $columnheaders = [];
        foreach ($channels as $name => $processor) {
            $columnheaders[] = [
                'name' => $name,
                'label' => $name === 'popup'
                    ? get_string('fellowship_notif_pref_web', 'local_launchpad')
                    : get_string('fellowship_notif_pref_email_short', 'local_launchpad'),
            ];
        }

        $colspan = count($columnheaders) + 1;
        $groups = self::build_notification_preference_groups(
            $providers,
            $channels,
            $preferences,
            $defaults,
            $colspan
        );

        return [
            'action_url' => (new \moodle_url(self::portal_path($portal)))->out(false),
            'sesskey' => sesskey(),
            'back_url' => $back,
            'has_flash' => $flash !== null,
            'flash' => $flash,
            'disable_all_checked' => $disableall,
            'has_groups' => $groups !== [],
            'groups' => $groups,
            'column_headers' => $columnheaders,
            'column_count' => $colspan,
            'title' => get_string('fellowship_notif_preferences', 'local_launchpad'),
            'help' => get_string('fellowship_notif_prefs_help', 'local_launchpad'),
            'disable_all_label' => get_string('disableall', 'message'),
            'disable_all_help' => get_string('fellowship_notif_prefs_disable_help', 'local_launchpad'),
            'provider_col_label' => get_string('fellowship_notif_prefs_provider_col', 'local_launchpad'),
            'empty_label' => get_string('fellowship_notif_prefs_empty', 'local_launchpad'),
            'save_label' => get_string('fellowship_notif_prefs_save', 'local_launchpad'),
            'back_label' => get_string('fellowship_notif_back_inbox', 'local_launchpad'),
        ];
    }

    /**
     * Group providers for the LaunchPad prefs matrix (activities → system → other).
     *
     * @param list<\stdClass> $providers
     * @param array<string,\stdClass> $channels popup/email processors only
     * @param \stdClass $preferences
     * @param \stdClass $defaults
     * @return list<array<string,mixed>>
     */
    private static function build_notification_preference_groups(
        array $providers,
        array $channels,
        \stdClass $preferences,
        \stdClass $defaults,
        int $colspan
    ): array {
        $activitycomponents = [];
        $othercomponents = [];
        foreach ($providers as $provider) {
            $component = (string) ($provider->component ?? '');
            if ($component === '' || $component === 'moodle') {
                continue;
            }
            if (str_starts_with($component, 'mod_')) {
                $activitycomponents[] = $component;
            } else {
                $othercomponents[] = $component;
            }
        }
        $activitycomponents = array_values(array_unique($activitycomponents));
        $othercomponents = array_values(array_unique($othercomponents));
        sort($activitycomponents);
        sort($othercomponents);
        $componentorder = array_merge($activitycomponents, ['moodle'], $othercomponents);

        $grouped = [];
        foreach ($componentorder as $component) {
            $rows = [];
            foreach ($providers as $provider) {
                if ((string) ($provider->component ?? '') !== $component) {
                    continue;
                }
                // Match Moodle notification preferences page: IM belongs to message prefs.
                if ($component === 'moodle' && (string) ($provider->name ?? '') === 'instantmessage') {
                    continue;
                }
                $prefbase = $component . '_' . $provider->name;
                if (!empty($defaults->{$prefbase . '_disable'})) {
                    continue;
                }

                $channelcells = [];
                foreach ($channels as $procname => $processor) {
                    $lockedkey = $procname . '_provider_' . $prefbase . '_locked';
                    $locked = !empty($defaults->{$lockedkey});
                    $enabled = self::is_notification_channel_enabled(
                        $prefbase,
                        $procname,
                        $locked,
                        $preferences,
                        $defaults
                    );
                    $lockedlabel = '';
                    if ($locked) {
                        $lockedlabel = $enabled
                            ? get_string('forcedmessage', 'message')
                            : get_string('disallowed', 'message');
                    }
                    $channelcells[] = [
                        'name' => $procname,
                        'prefbase' => $prefbase,
                        'checked' => $enabled,
                        'locked' => $locked,
                        'locked_on' => $locked && $enabled,
                        'locked_off' => $locked && !$enabled,
                        'locked_label' => $lockedlabel,
                        'editable' => !$locked,
                    ];
                }

                try {
                    $displayname = get_string('messageprovider:' . $provider->name, $component);
                } catch (\Throwable $e) {
                    $displayname = $prefbase;
                }

                $rows[] = [
                    'displayname' => $displayname,
                    'prefbase' => $prefbase,
                    'channels' => $channelcells,
                ];
            }

            if ($rows === []) {
                continue;
            }

            try {
                $groupname = ($component === 'moodle')
                    ? get_string('coresystem')
                    : get_string('pluginname', $component);
            } catch (\Throwable $e) {
                $groupname = $component;
            }

            $grouped[] = [
                'displayname' => $groupname,
                'component' => $component,
                'providers' => $rows,
                'provider_count' => count($rows),
                'column_count' => $colspan,
            ];
        }

        return $grouped;
    }

    /**
     * Whether a processor channel is enabled for a provider (user pref or site default).
     */
    private static function is_notification_channel_enabled(
        string $prefbase,
        string $processorname,
        bool $locked,
        \stdClass $preferences,
        \stdClass $defaults
    ): bool {
        $prefkey = $prefbase . '_enabled';
        if (!$locked && isset($preferences->{$prefkey}) && is_array($preferences->{$prefkey})) {
            return !empty($preferences->{$prefkey}[$processorname]);
        }

        $defaultkey = 'message_provider_' . $prefbase . '_enabled';
        if (!isset($defaults->{$defaultkey}) || $defaults->{$defaultkey} === '' || $defaults->{$defaultkey} === 'none') {
            return false;
        }
        return in_array($processorname, explode(',', (string) $defaults->{$defaultkey}), true);
    }

    /**
     * Save LaunchPad notification preference matrix into Moodle user prefs.
     *
     * @param array<string,mixed> $raw disable_all + matrix[prefbase][popup|email]
     */
    public static function save_notification_preferences(int $userid, array $raw): string {
        global $CFG, $DB, $USER;

        if ($userid <= 0) {
            return 'denied';
        }
        require_once($CFG->dirroot . '/message/lib.php');
        require_once($CFG->dirroot . '/user/lib.php');

        $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
        if (!\core_message_can_edit_message_profile($user)) {
            return 'denied';
        }

        $disableall = !empty($raw['disable_all']);
        if ((int) $user->emailstop !== ($disableall ? 1 : 0)) {
            $update = (object) [
                'id' => $userid,
                'emailstop' => $disableall ? 1 : 0,
            ];
            user_update_user($update, false, false);
            if ((int) $USER->id === $userid) {
                $USER->emailstop = $disableall ? 1 : 0;
            }
        }

        $allprocessors = get_message_processors();
        $channelnames = self::notification_preference_channels();
        $available = [];
        foreach ($channelnames as $name) {
            if (!empty($allprocessors[$name]) && !empty($allprocessors[$name]->enabled)
                    && !empty($allprocessors[$name]->configured)) {
                $available[$name] = true;
            }
        }

        $providers = message_get_providers_for_user($userid);
        $defaults = get_message_output_default_preferences();
        $matrix = is_array($raw['matrix'] ?? null) ? $raw['matrix'] : [];

        $allowedbases = [];
        foreach ($providers as $provider) {
            $component = (string) ($provider->component ?? '');
            $name = (string) ($provider->name ?? '');
            if ($component === '' || $name === '') {
                continue;
            }
            if ($component === 'moodle' && $name === 'instantmessage') {
                continue;
            }
            $prefbase = $component . '_' . $name;
            if (!empty($defaults->{$prefbase . '_disable'})) {
                continue;
            }
            $allowedbases[$prefbase] = true;
        }

        foreach ($matrix as $prefbase => $channels) {
            $prefbase = clean_param((string) $prefbase, PARAM_ALPHANUMEXT);
            if ($prefbase === '' || empty($allowedbases[$prefbase])) {
                continue;
            }
            if (!is_array($channels)) {
                $channels = [];
            }

            $defaultkey = 'message_provider_' . $prefbase . '_enabled';
            $defaultparts = [];
            if (!empty($defaults->{$defaultkey}) && $defaults->{$defaultkey} !== 'none') {
                $defaultparts = array_filter(array_map('trim', explode(',', (string) $defaults->{$defaultkey})));
            }

            $userpref = get_user_preferences('message_provider_' . $prefbase . '_enabled', null, $userid);
            if ($userpref === null || $userpref === '') {
                $baseline = $defaultparts;
            } else if ($userpref === 'none') {
                $baseline = [];
            } else {
                $baseline = array_filter(array_map('trim', explode(',', (string) $userpref)));
            }

            $new = [];
            foreach ($baseline as $proc) {
                if ($proc !== '' && !isset($available[$proc])) {
                    // Preserve processors outside the LaunchPad Web/Email matrix (sms, etc.).
                    $new[] = $proc;
                }
            }

            foreach ($channelnames as $procname) {
                if (empty($available[$procname])) {
                    continue;
                }
                $locked = !empty($defaults->{$procname . '_provider_' . $prefbase . '_locked'});
                if ($locked) {
                    if (in_array($procname, $defaultparts, true)) {
                        $new[] = $procname;
                    }
                    continue;
                }
                if (!empty($channels[$procname])) {
                    $new[] = $procname;
                }
            }

            $new = array_values(array_unique($new));
            set_user_preference(
                'message_provider_' . $prefbase . '_enabled',
                $new === [] ? 'none' : implode(',', $new),
                $userid
            );
        }

        return '';
    }

    /**
     * @param list<\stdClass> $records
     */
    private static function find_popup_notification_record(int $userid, int $notifid, array $records): ?\stdClass {
        global $DB;

        if ($notifid <= 0 || $userid <= 0) {
            return null;
        }
        foreach ($records as $n) {
            if ((int) ($n->id ?? 0) === $notifid) {
                return $n;
            }
        }
        try {
            $sql = "SELECT n.*
                      FROM {notifications} n
                     WHERE n.id = ?
                       AND n.useridto = ?
                       AND n.id IN (SELECT notificationid FROM {message_popup_notifications})";
            $rec = $DB->get_record_sql($sql, [$notifid, $userid]);
            return $rec ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function format_inbox_row(\stdClass $n, string $portal, string $ntab, int $selectedid): ?array {
        $id = (int) ($n->id ?? 0);
        if ($id <= 0) {
            return null;
        }
        $subject = trim(strip_tags((string) ($n->subject ?? '')));
        if ($subject === '') {
            $subject = trim(strip_tags((string) ($n->smallmessage ?? '')));
        }
        if ($subject === '') {
            $subject = get_string('fellowship_notifications', 'local_launchpad');
        }
        $preview = trim(strip_tags((string) ($n->smallmessage ?? '')));
        if ($preview === '' || $preview === $subject) {
            $preview = trim(strip_tags((string) ($n->fullmessage ?? '')));
        }
        if (\core_text::strlen($preview) > 120) {
            $preview = \core_text::substr($preview, 0, 117) . '…';
        }
        $isunread = empty($n->timeread);
        $when = !empty($n->timecreated)
            ? userdate((int) $n->timecreated, get_string('strftimedatetimeshort', 'langconfig'))
            : '';

        return [
            'id' => $id,
            'name' => $subject,
            'preview' => $preview,
            'has_preview' => $preview !== '',
            'when' => $when,
            'has_when' => $when !== '',
            'type_label' => '',
            'has_type' => false,
            'unread' => $isunread,
            'unread_label' => $isunread ? get_string('fellowship_notif_tab_unread', 'local_launchpad') : '',
            'is_selected' => $id === $selectedid,
            'url' => self::notifications_page_url($portal, array_filter([
                'ntab' => $ntab !== 'all' ? $ntab : null,
                'notifid' => $id,
            ])),
        ];
    }

    /**
     * Map a Moodle course/mod URL into the current LaunchPad portal when possible.
     * Leaves external / unknown URLs unchanged.
     */
    public static function portalize_moodle_url(string $url, string $portal = 'fellow'): string {
        global $CFG;

        $raw = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5);
        if ($raw === '' || preg_match('#^(javascript|data|mailto):#i', $raw)) {
            return $url;
        }
        if (!in_array($portal, ['fellow', 'mentor', 'admin', 'employer'], true)) {
            $portal = 'fellow';
        }

        $parts = parse_url($raw);
        if ($parts === false) {
            return $url;
        }

        $path = (string) ($parts['path'] ?? '');
        $rootpath = rtrim((string) (parse_url($CFG->wwwroot, PHP_URL_PATH) ?: ''), '/');
        if ($rootpath !== '' && str_starts_with($path, $rootpath)) {
            $path = substr($path, strlen($rootpath));
            if ($path === '' || $path[0] !== '/') {
                $path = '/' . ltrim($path, '/');
            }
        }

        $lookslocal = (bool) preg_match('#^/(mod/|course/|user/|local/launchpad/)#', $path);
        $www = parse_url($CFG->wwwroot);
        if (!empty($parts['host']) && !empty($www['host'])
                && strcasecmp((string) $parts['host'], (string) $www['host']) !== 0
                && !$lookslocal) {
            // Foreign host and not a Moodle path we recognize.
            return $url;
        }

        // Already in LaunchPad chrome.
        if (str_contains($path, '/local/launchpad/')) {
            return $raw;
        }

        $query = [];
        if (!empty($parts['query'])) {
            parse_str(html_entity_decode((string) $parts['query'], ENT_QUOTES | ENT_HTML5), $query);
        }

        if (preg_match('#^/mod/[^/]+/view\.php$#', $path) && !empty($query['id'])) {
            return self::portal_activity_url($portal, (int) $query['id']);
        }
        if (preg_match('#^/mod/[^/]+/index\.php$#', $path) && !empty($query['id'])) {
            return self::portal_course_url($portal, (int) $query['id']);
        }
        if (preg_match('#^/course/view\.php$#', $path) && !empty($query['id'])) {
            return self::portal_course_url($portal, (int) $query['id']);
        }
        // Enrolment / welcome CTAs often point at the course user profile page.
        if (preg_match('#^/user/(view|profile)\.php$#', $path) && !empty($query['course'])) {
            return self::portal_course_url($portal, (int) $query['course']);
        }
        if (preg_match('#^/course/(recent|resources|index)\.php$#', $path) && !empty($query['id'])) {
            return self::portal_course_url($portal, (int) $query['id']);
        }

        return $raw;
    }

    /**
     * Activity (cm) URL inside a portal shell.
     * Deleted / missing CMs fall back to the course/track page (never Moodle course chrome).
     */
    public static function portal_activity_url(string $portal, int $cmid): string {
        $cmid = max(0, $cmid);
        if ($cmid <= 0) {
            return (new \moodle_url(self::portal_path($portal)))->out(false);
        }

        $cm = get_coursemodule_from_id('', $cmid, 0, false, IGNORE_MISSING);
        if (!$cm || !empty($cm->deletioninprogress)) {
            $courseid = $cm ? (int) $cm->course : 0;
            return $courseid > 0
                ? self::portal_course_url($portal, $courseid)
                : (new \moodle_url(self::portal_path($portal)))->out(false);
        }

        if ($portal === 'fellow') {
            return (new \moodle_url(persona_home::FELLOW_PATH, [
                'view' => 'activity',
                'cmid' => $cmid,
            ]))->out(false);
        }
        if ($portal === 'admin') {
            return (new \moodle_url(persona_home::ADMIN_PATH, array_filter([
                'view' => 'activity',
                'id' => (int) $cm->course > 0 ? (int) $cm->course : null,
                'cmid' => $cmid,
            ])))->out(false);
        }
        if ($portal === 'mentor') {
            // Mentors review submissions from the queue (need fellow id for a specific review).
            return (new \moodle_url(persona_home::MENTOR_PATH, ['view' => 'queue']))->out(false);
        }
        // Employer: open the parent course in the employee shell.
        return self::portal_course_url('employer', (int) $cm->course);
    }

    /**
     * Course / track URL inside a portal shell.
     */
    public static function portal_course_url(string $portal, int $courseid): string {
        $courseid = max(0, $courseid);
        if ($courseid <= 0) {
            return (new \moodle_url(self::portal_path($portal)))->out(false);
        }
        return match ($portal) {
            'fellow' => (new \moodle_url(persona_home::FELLOW_PATH, [
                'view' => 'track',
                'id' => $courseid,
            ]))->out(false),
            'employer' => (new \moodle_url(persona_home::EMPLOYEE_PATH, [
                'view' => 'course',
                'id' => $courseid,
            ]))->out(false),
            'admin' => (new \moodle_url(persona_home::ADMIN_PATH, [
                'view' => 'manage',
                'id' => $courseid,
            ]))->out(false),
            'mentor' => (new \moodle_url(persona_home::MENTOR_PATH, [
                'view' => 'fellows',
                'course' => $courseid,
            ]))->out(false),
            default => (new \moodle_url(persona_home::FELLOW_PATH, [
                'view' => 'track',
                'id' => $courseid,
            ]))->out(false),
        };
    }

    /**
     * Rewrite Moodle hrefs inside notification HTML to LaunchPad portal URLs.
     */
    public static function rewrite_notification_html_links(string $html, string $portal = 'fellow'): string {
        if ($html === '' || !str_contains($html, 'href=')) {
            return $html;
        }
        return (string) preg_replace_callback(
            '/\bhref\s*=\s*(["\'])([^"\']*)\1/i',
            static function (array $m) use ($portal): string {
                $rewritten = self::portalize_moodle_url($m[2], $portal);
                return 'href=' . $m[1] . htmlspecialchars($rewritten, ENT_QUOTES | ENT_HTML5) . $m[1];
            },
            $html
        );
    }

    /**
     * Normalize URLs for equality (path + sorted query; ignore host/amp/sesskey).
     */
    public static function notification_urls_equivalent(string $a, string $b): bool {
        $na = self::normalize_notification_url($a);
        $nb = self::normalize_notification_url($b);
        return $na !== '' && $na === $nb;
    }

    /**
     * @return string path?query
     */
    private static function normalize_notification_url(string $url): string {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5);
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        if ($parts === false) {
            return '';
        }
        $path = (string) ($parts['path'] ?? '');
        $query = [];
        if (!empty($parts['query'])) {
            parse_str((string) $parts['query'], $query);
        }
        unset($query['sesskey'], $query['notify'], $query['lang']);
        ksort($query);
        return strtolower($path) . ($query === [] ? '' : '?' . http_build_query($query));
    }

    /**
     * Drop Moodle breadcrumb chrome and duplicate activity links (CTA already covers them).
     */
    public static function clean_notification_html(string $html, string $portal, string $contexturl = ''): string {
        $html = trim($html);
        if ($html === '') {
            return $html;
        }

        // Moodle assign/quiz emails lead with: Course -> Module index -> Activity [<hr>].
        $html = (string) preg_replace(
            '#<p\b[^>]*>\s*(?:<a\b[^>]*>[\s\S]*?</a>\s*(?:->|-&gt;|→|»|&gt;)\s*){1,}<a\b[^>]*>[\s\S]*?</a>\s*</p>\s*(?:<hr\b[^>]*/?>\s*)?#i',
            '',
            $html,
            1
        );

        $html = self::rewrite_notification_html_links($html, $portal);

        if ($contexturl !== '') {
            $html = (string) preg_replace_callback(
                '#<a\b([^>]*)\bhref\s*=\s*(["\'])([^"\']*)\2([^>]*)>([\s\S]*?)</a>#i',
                static function (array $m) use ($contexturl, $portal): string {
                    $href = self::portalize_moodle_url(html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5), $portal);
                    if (self::notification_urls_equivalent($href, $contexturl)) {
                        // Same destination as the primary CTA — keep wording, drop the link.
                        return $m[5];
                    }
                    return '<a' . $m[1] . 'href=' . $m[2]
                        . htmlspecialchars($href, ENT_QUOTES | ENT_HTML5)
                        . $m[2] . $m[4] . '>' . $m[5] . '</a>';
                },
                $html
            );
        }

        // Trailing / leading decorative hrs left by Moodle templates.
        $html = (string) preg_replace('#^(?:\s*<hr\b[^>]*/?>\s*)+#i', '', $html);
        $html = (string) preg_replace('#(?:\s*<hr\b[^>]*/?>\s*)+$#i', '', $html);

        return trim($html);
    }

    /**
     * @return array<string,mixed>
     */
    private static function format_inbox_detail(\stdClass $n, string $portal, string $ntab): array {
        $id = (int) ($n->id ?? 0);
        $subject = trim(strip_tags((string) ($n->subject ?? '')));
        if ($subject === '') {
            $subject = trim(strip_tags((string) ($n->smallmessage ?? '')));
        }
        if ($subject === '') {
            $subject = get_string('fellowship_notifications', 'local_launchpad');
        }
        $contexturl = self::portalize_moodle_url(trim((string) ($n->contexturl ?? '')), $portal);
        $contextname = trim(strip_tags((string) ($n->contexturlname ?? '')));
        if ($contextname === '') {
            $contextname = get_string('fellowship_notif_open_related', 'local_launchpad');
        }

        $bodyhtml = '';
        $html = trim((string) ($n->fullmessagehtml ?? ''));
        if ($html !== '') {
            $bodyhtml = format_text($html, FORMAT_HTML, ['filter' => false, 'para' => false]);
        } else {
            $bodyhtml = format_text(
                (string) ($n->fullmessage ?? ''),
                (int) ($n->fullmessageformat ?? FORMAT_MOODLE),
                ['filter' => false]
            );
        }
        $bodyhtml = self::clean_notification_html($bodyhtml, $portal, $contexturl);
        if (trim(strip_tags($bodyhtml)) === '') {
            $small = trim(strip_tags((string) ($n->smallmessage ?? '')));
            $bodyhtml = s($small !== '' ? $small : get_string('fellowship_notif_no_body', 'local_launchpad'));
        }

        $when = !empty($n->timecreated)
            ? userdate((int) $n->timecreated, get_string('strftimedatetimeshort', 'langconfig'))
            : '';

        return [
            'id' => $id,
            'name' => $subject,
            'body_html' => $bodyhtml,
            'when' => $when,
            'has_when' => $when !== '',
            'type_label' => '',
            'has_type' => false,
            'unread' => empty($n->timeread),
            'has_context' => $contexturl !== '',
            'context_url' => $contexturl,
            'context_label' => $contextname,
            'back_url' => self::notifications_page_url($portal, array_filter([
                'ntab' => $ntab !== 'all' ? $ntab : null,
            ])),
        ];
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
            'isfavourite' => false,
            'starred_limit' => self::STARRED_LIMIT,
            'starred_count' => 0,
            'conversations' => [],
            'has_conversations' => false,
            'conversation_count' => 0,
            'unread_conversations' => 0,
            'has_unread_conversations' => false,
            'classmates' => [],
            'has_classmates' => false,
            'mentors' => [],
            'has_mentors' => false,
            'has_people' => false,
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
            'is_fellow_portal' => $portal === 'fellow',
            'thread_type_label' => $strings['private'],
            'strings' => $strings,
        ];

        if (!$messagingon) {
            return $ctx;
        }

        $selectedother = ($otherid !== null && $otherid > 0) ? $otherid : 0;
        $selectedconvid = max(0, $convid);
        // Fetch full inbox once, then filter like Moodle's All / Starred / Private / Group tabs.
        $allconversations = self::list_conversations($userid, $portal, 'all', $selectedother, $selectedconvid);
        $filtered = self::filter_conversations_by_tab($allconversations, $mtab);

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
        // Group tab: honest empty copy when track chat is off / disabled / pending sync.
        if ($mtab === 'group' && $filtered === []) {
            $groupempty = self::group_tab_empty_copy($userid, $portal);
            $ctx['strings']['empty_filter_title'] = $groupempty['title'];
            $ctx['strings']['empty_filter_body'] = $groupempty['body'];
            $ctx['strings']['inbox_empty_title'] = $groupempty['title'];
            $ctx['strings']['inbox_empty_body'] = $groupempty['body'];
        }
        // People picker only on All — otherwise classmates look like chats under every filter.
        $ctx['show_people_picker'] = $portal === 'fellow' && $mtab === 'all';

        if (!empty($ctx['show_people_picker'])) {
            $ctx['classmates'] = self::list_classmates($userid, $portal);
            $ctx['has_classmates'] = $ctx['classmates'] !== [];
            $ctx['mentors'] = self::list_course_mentors_for_messages($userid, $portal);
            $ctx['has_mentors'] = $ctx['mentors'] !== [];
            $ctx['has_people'] = $ctx['has_classmates'] || $ctx['has_mentors'];
        }

        if ($selectedother <= 0 && $selectedconvid <= 0) {
            return $ctx;
        }

        // Prefer 1:1 by other user; otherwise open a Moodle conversation id (group / self).
        if ($selectedother > 0) {
            $canmsg = self::can_message($userid, $selectedother, $portal);
            $existingconvid = self::existing_conversation_id($userid, $selectedother);
            // Deny only when they cannot send AND there is no prior conversation to read.
            if (!$canmsg && $existingconvid <= 0) {
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

            $selectedconvid = $existingconvid > 0
                ? $existingconvid
                : self::ensure_conversation($userid, $selectedother);
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
                $canmsg,
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

        // Individual rows: open via other user when possible (send or read-only).
        if (!empty($matched['is_private']) && !empty($matched['other_id'])) {
            $oid = (int) $matched['other_id'];
            $other = \core_user::get_user($oid, '*', IGNORE_MISSING);
            if ($other && empty($other->deleted) && self::can_view_thread_with($userid, $oid, $portal)) {
                return self::hydrate_thread_context(
                    $ctx,
                    $userid,
                    $portal,
                    $mtab,
                    $selectedconvid,
                    $oid,
                    $other,
                    self::can_message($userid, $oid, $portal),
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
        } else if (($portal === 'mentor' || $portal === 'fellow') && $convid > 0 && $otherid <= 0) {
            // Personal space (self) / group: Moodle allows members to post; LaunchPad was blocking other_id=0.
            try {
                $cansend = \core_message\api::can_send_message_to_conversation($userid, $convid);
            } catch (\Throwable $e) {
                $cansend = false;
            }
        }

        $isfavourite = self::is_conversation_starred($userid, $convid);

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
        $ctx['isfavourite'] = $isfavourite;
        $ctx['starred_limit'] = self::STARRED_LIMIT;
        $ctx['starred_count'] = self::count_starred_conversations($userid);
        $ctx['form_action'] = $threadurl;
        $ctx['ajax_url'] = (new \moodle_url('/local/launchpad/ajax_messaging.php'))->out(false);
        $ctx['portal'] = $portal === 'mentor' ? 'mentor' : 'fellow';
        $ctx['moodle_messages_url'] = $otherid > 0
            ? self::moodle_messages_url($otherid)
            : (new \moodle_url('/message/index.php', ['convid' => $convid]))->out(false);
        $ctx['inbox_url'] = self::messages_url(null, $portal, $mtab);

        return $ctx;
    }

    /** Soft cap so Starred stays useful (Moodle itself has no cap). */
    public const STARRED_LIMIT = 10;

    /**
     * How many conversations this user has starred.
     */
    public static function count_starred_conversations(int $userid): int {
        if ($userid <= 0) {
            return 0;
        }
        try {
            $service = \core_favourites\service_factory::get_service_for_user_context(
                \context_user::instance($userid)
            );
            $favs = $service->find_favourites_by_type('core_message', 'message_conversations');
            return is_array($favs) ? count($favs) : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Whether this conversation is in the user's starred / favourites list.
     */
    public static function is_conversation_starred(int $userid, int $convid): bool {
        if ($userid <= 0 || $convid <= 0) {
            return false;
        }
        try {
            $conv = \core_message\api::get_conversation($userid, $convid, false, false, 0, 0, 0);
            return !empty($conv->isfavourite);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Star or unstar a conversation the user belongs to.
     *
     * @return array{ok:bool,error:?string,starred?:bool,starred_count?:int,starred_limit?:int}
     */
    public static function set_conversation_starred(int $userid, int $convid, bool $star): array {
        $limit = self::STARRED_LIMIT;
        if ($userid <= 0 || $convid <= 0) {
            return [
                'ok' => false,
                'error' => get_string('fellowship_messages_err_star', 'local_launchpad'),
            ];
        }
        try {
            if (!\core_message\api::is_user_in_conversation($userid, $convid)) {
                return [
                    'ok' => false,
                    'error' => get_string('fellowship_messages_err_permission', 'local_launchpad'),
                ];
            }
            $already = self::is_conversation_starred($userid, $convid);
            if ($star) {
                if (!$already) {
                    $count = self::count_starred_conversations($userid);
                    if ($count >= $limit) {
                        return [
                            'ok' => false,
                            'error' => get_string('fellowship_messages_err_star_limit', 'local_launchpad', $limit),
                            'starred' => false,
                            'starred_count' => $count,
                            'starred_limit' => $limit,
                        ];
                    }
                    \core_message\api::set_favourite_conversation($convid, $userid);
                }
            } else if ($already) {
                \core_message\api::unset_favourite_conversation($convid, $userid);
            }
            $starred = self::is_conversation_starred($userid, $convid);
            return [
                'ok' => true,
                'error' => null,
                'starred' => $starred,
                'starred_count' => self::count_starred_conversations($userid),
                'starred_limit' => $limit,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => get_string('fellowship_messages_err_star', 'local_launchpad'),
            ];
        }
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
                'Private messages with mentors and peers. This page is LaunchPad\'s view of those conversations.'
            ),
            'open_moodle' => $str('fellowship_messages_open_moodle', 'Open Moodle messenger'),
            'open_thread' => $str('employer_messages_open_thread', 'Open in Moodle'),
            'total_label' => $str('employer_messages_total_label', 'Conversations'),
            'unread_label' => $str('employer_messages_unread_label', 'Unread'),
            'tab_all' => $str('employer_messages_tab_all', 'All'),
            'tab_starred' => $str('employer_messages_tab_starred', 'Starred'),
            'tab_private' => $str('employer_messages_tab_private', 'Private'),
            'tab_group' => $str('employer_messages_tab_group', 'Group'),
            'star' => $str('fellowship_messages_star', 'Star'),
            'unstar' => $str('fellowship_messages_unstar', 'Unstar'),
            'star_title' => $str('fellowship_messages_star_title', 'Star this conversation'),
            'unstar_title' => $str('fellowship_messages_unstar_title', 'Remove from starred'),
            'star_limit_hint' => get_string_manager()->string_exists(
                'fellowship_messages_star_limit_hint',
                'local_launchpad'
            )
                ? get_string('fellowship_messages_star_limit_hint', 'local_launchpad', self::STARRED_LIMIT)
                : ('You can star up to ' . self::STARRED_LIMIT . ' conversations.'),
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
                'You cannot reply here. Open Messages or check messaging permissions.'
            ),
            'readonly' => $str(
                'fellowship_messages_readonly',
                'You can read this conversation, but you cannot reply from LaunchPad.'
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
                'Site messaging is disabled. Ask an admin to enable it.'
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
     * Post into an existing conversation by id (personal space / group).
     * Fellow + mentor portals — used when there is no other user id (self/group).
     *
     * @return array{ok:bool,error:?string,message?:array}
     */
    public static function send_to_conversation(int $fromid, int $convid, string $text, string $portal = 'fellow'): array {
        $text = trim($text);
        if ($text === '') {
            return [
                'ok' => false,
                'error' => get_string('fellowship_messages_err_empty', 'local_launchpad'),
            ];
        }
        if ($portal !== 'mentor' && $portal !== 'fellow') {
            $portal = 'fellow';
        }
        if ($fromid <= 0 || $convid <= 0) {
            return [
                'ok' => false,
                'error' => get_string('fellowship_messages_err_permission', 'local_launchpad'),
            ];
        }
        $allowed = is_siteadmin($fromid)
            || ($portal === 'mentor' && account_persona::is_mentor($fromid))
            || ($portal === 'fellow' && account_persona::is_fellowship($fromid));
        if (!$allowed) {
            return [
                'ok' => false,
                'error' => get_string('fellowship_messages_err_permission', 'local_launchpad'),
            ];
        }

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
     * Send a private message; creates the individual conversation if needed.
     *
     * @return array{ok:bool,error:?string,message?:array}
     */
    public static function send(int $fromid, int $toid, string $text, string $portal = 'any'): array {
        $text = trim($text);
        if ($text === '') {
            return [
                'ok' => false,
                'error' => get_string('fellowship_messages_err_empty', 'local_launchpad'),
            ];
        }
        if ($portal !== 'mentor' && $portal !== 'fellow') {
            $portal = 'any';
        }
        if (!self::can_message($fromid, $toid, $portal)) {
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

            // E16: fellow → mentor also gets an email ping (soft-fail).
            if ($portal === 'fellow') {
                self::maybe_email_mentor_ping($fromid, $toid, $clean);
            }

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
     * Whether a fellow→mentor LaunchPad email ping should fire (not peer chat).
     */
    public static function should_email_mentor_ping(int $fromid, int $toid): bool {
        if ($fromid <= 0 || $toid <= 0 || $fromid === $toid) {
            return false;
        }
        if (!account_persona::is_fellowship($fromid) || !account_persona::is_mentor($toid)) {
            return false;
        }
        foreach (self::shared_launchpad_courseids($fromid, $toid) as $courseid) {
            if (account_persona::is_fellowship($fromid, $courseid)
                    && account_persona::is_mentor($toid, $courseid)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Admin → teacher review nudge (Moodle message + email). Fail closed on caps.
     *
     * @return array{ok:bool,error:?string}
     */
    public static function send_admin_review_nudge(
        int $adminid,
        int $teacherid,
        string $messagetext,
        string $emailsubject,
        string $emailbody
    ): array {
        global $CFG;

        $messagetext = trim(clean_param($messagetext, PARAM_TEXT));
        $emailsubject = trim(clean_param($emailsubject, PARAM_TEXT));
        $emailbody = trim(clean_param($emailbody, PARAM_TEXT));
        if ($messagetext === '' || $emailsubject === '' || $emailbody === '') {
            return [
                'ok' => false,
                'error' => get_string('fellowship_messages_err_empty', 'local_launchpad'),
            ];
        }
        if (!self::can_message($adminid, $teacherid, 'any')) {
            return [
                'ok' => false,
                'error' => get_string('fellowship_messages_err_permission', 'local_launchpad'),
            ];
        }

        $convid = self::ensure_conversation($adminid, $teacherid);
        if ($convid <= 0) {
            return [
                'ok' => false,
                'error' => get_string('fellowship_messages_err_save', 'local_launchpad'),
            ];
        }

        try {
            if (!\core_message\api::can_send_message_to_conversation($adminid, $convid)) {
                return [
                    'ok' => false,
                    'error' => get_string('fellowship_messages_err_permission', 'local_launchpad'),
                ];
            }
            \core_message\api::send_message_to_conversation($adminid, $convid, $messagetext, FORMAT_PLAIN);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => get_string('fellowship_messages_err_save', 'local_launchpad'),
            ];
        }

        // Soft-fail email — message already delivered in-app.
        try {
            require_once($CFG->libdir . '/moodlelib.php');
            $to = \core_user::get_user($teacherid, '*', IGNORE_MISSING);
            if ($to && trim((string) ($to->email ?? '')) !== '') {
                $html = nl2br(s($emailbody));
                email_to_user(
                    $to,
                    \core_user::get_noreply_user(),
                    $emailsubject,
                    $emailbody,
                    $html
                );
            }
        } catch (\Throwable $e) {
            // Ignore mail failures.
        }

        return ['ok' => true, 'error' => null];
    }

    /**
     * Email the mentor about a new LaunchPad message. Soft-fail always.
     *
     * @return bool True when Moodle accepted the mail attempt (incl. noemailever divert).
     */
    public static function maybe_email_mentor_ping(int $fromid, int $toid, string $preview): bool {
        global $CFG;

        if (!self::should_email_mentor_ping($fromid, $toid)) {
            return false;
        }

        try {
            require_once($CFG->libdir . '/moodlelib.php');
            $from = \core_user::get_user($fromid, '*', IGNORE_MISSING);
            $to = \core_user::get_user($toid, '*', IGNORE_MISSING);
            if (!$from || !$to || trim((string) ($to->email ?? '')) === '') {
                return false;
            }

            $preview = trim($preview);
            if (\core_text::strlen($preview) > 160) {
                $preview = \core_text::substr($preview, 0, 157) . '…';
            }
            $link = self::messages_url($fromid, 'mentor');
            $subject = get_string('mentor_email_ping_subject', 'local_launchpad', fullname($from));
            $body = get_string('mentor_email_ping_body', 'local_launchpad', (object) [
                'fromname' => fullname($from),
                'preview' => $preview,
                'link' => $link,
            ]);
            $html = nl2br(s($body));

            // Direct email so prefs cannot swallow the mam-required ping.
            return (bool) email_to_user(
                $to,
                \core_user::get_noreply_user(),
                $subject,
                $body,
                $html
            );
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Filter inbox rows for All / Starred / Private / Group (Moodle-aligned).
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private static function filter_conversations_by_tab(array $rows, string $mtab): array {
        if ($mtab === 'all' || $mtab === '') {
            return array_values($rows);
        }
        return array_values(array_filter($rows, static function (array $row) use ($mtab): bool {
            if ($mtab === 'starred') {
                return !empty($row['isfavourite']);
            }
            if ($mtab === 'private') {
                // Moodle private view = 1:1 + personal space.
                return !empty($row['is_private']) || !empty($row['is_self']);
            }
            if ($mtab === 'group') {
                return !empty($row['is_group']);
            }
            return true;
        }));
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
            // Always load the overview set; tab filtering happens in filter_conversations_by_tab().
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
                    'Your personal space — add a note here.'
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
            $rolelabel = '';
            if ($portal === 'fellow' && $otherid > 0) {
                if (account_persona::is_mentor($otherid)) {
                    $rolelabel = get_string('fellowship_messages_role_mentor', 'local_launchpad');
                } else if (self::is_launchpad_message_peer($userid, $otherid)) {
                    $rolelabel = get_string('fellowship_messages_role_classmate', 'local_launchpad');
                }
            }
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
                'role_label' => $rolelabel,
                'has_role_label' => $rolelabel !== '',
            ];
        }

        // Moodle sometimes omits empty/cleared self chats from get_conversations — keep Personal space visible.
        $hasself = false;
        foreach ($out as $row) {
            if (!empty($row['is_self'])) {
                $hasself = true;
                break;
            }
        }
        if (!$hasself) {
            try {
                $self = \core_message\api::get_self_conversation($userid);
                $selfid = (int) ($self->id ?? 0);
                if ($selfid > 0) {
                    $selfname = get_string('selfconversation', 'core_message');
                    $selfpreview = $str(
                        'employer_messages_self_empty',
                        'Your personal space — add a note here.'
                    );
                    array_unshift($out, [
                        'id' => $selfid,
                        'other_id' => 0,
                        'name' => $selfname,
                        'other_name' => $selfname,
                        'other_initials' => self::conversation_initials($selfname),
                        'initials' => self::conversation_initials($selfname),
                        'imageurl' => '',
                        'has_image' => false,
                        'preview' => $selfpreview,
                        'has_preview' => $selfpreview !== '',
                        'preview_prefix' => '',
                        'has_preview_prefix' => false,
                        'when' => '',
                        'has_when' => false,
                        'type_label' => $str('employer_messages_type_self', 'Personal'),
                        'isfavourite' => true,
                        'is_group' => false,
                        'is_self' => true,
                        'is_private' => false,
                        'unread' => 0,
                        'has_unread' => false,
                        'is_unread' => false,
                        'is_selected' => $selectedconvid > 0 && $selfid === $selectedconvid,
                        'url' => self::messages_url(null, $portal, $mtab, $selfid),
                        'role_label' => '',
                        'has_role_label' => false,
                    ]);
                }
            } catch (\Throwable $e) {
                // Non-fatal — inbox still works without Personal space.
            }
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

    /**
     * Find or create the canonical track chat Moodle course group (idnumber lp-trackchat).
     */
    public static function ensure_track_chat_group(int $courseid): ?\stdClass {
        global $CFG, $DB;

        if ($courseid <= 0) {
            return null;
        }
        require_once($CFG->dirroot . '/group/lib.php');

        $existing = groups_get_group_by_idnumber($courseid, self::TRACK_CHAT_IDNUMBER);
        if ($existing) {
            // Track chat must be ALL-visible so admin/CLI sync and groups_get_members work.
            // MEMBERS visibility hides roster from non-members and breaks enable/sync.
            if ((int) ($existing->visibility ?? -1) !== GROUPS_VISIBILITY_ALL) {
                $existing->visibility = GROUPS_VISIBILITY_ALL;
                try {
                    groups_update_group($existing);
                } catch (\Throwable $e) {
                    // Soft-fail — still return the group for callers.
                }
                $existing = groups_get_group_by_idnumber($courseid, self::TRACK_CHAT_IDNUMBER) ?: $existing;
            }
            return $existing;
        }

        $course = $DB->get_record('course', ['id' => $courseid], '*', IGNORE_MISSING);
        if (!$course) {
            return null;
        }

        $data = new \stdClass();
        $data->courseid = $courseid;
        $data->name = get_string_manager()->string_exists('admin_track_chat_group_name', 'local_launchpad')
            ? get_string('admin_track_chat_group_name', 'local_launchpad')
            : 'Track chat';
        $data->idnumber = self::TRACK_CHAT_IDNUMBER;
        $data->description = get_string_manager()->string_exists('admin_track_chat_group_desc', 'local_launchpad')
            ? get_string('admin_track_chat_group_desc', 'local_launchpad')
            : 'LaunchPad track group chat for fellows and mentors on this course.';
        $data->descriptionformat = FORMAT_HTML;
        // ALL: roster visible for sync/messaging; MEMBERS breaks groups_is_member for admins.
        $data->visibility = GROUPS_VISIBILITY_ALL;
        $data->participation = 1;

        try {
            $gid = (int) groups_create_group($data);
        } catch (\Throwable $e) {
            // Race: another request created it.
            $existing = groups_get_group_by_idnumber($courseid, self::TRACK_CHAT_IDNUMBER);
            return $existing ?: null;
        }
        if ($gid <= 0) {
            return null;
        }
        return groups_get_group($gid, '*', IGNORE_MISSING) ?: null;
    }

    /**
     * Empty-state title/body for Messages → Group when the filtered list is empty.
     *
     * Distinguishes track chat never enabled vs disabled vs enabled-but-missing.
     *
     * @return array{title:string,body:string}
     */
    public static function group_tab_empty_copy(int $userid, string $portal = 'fellow'): array {
        $title = get_string_manager()->string_exists('fellowship_messages_group_empty_title', 'local_launchpad')
            ? get_string('fellowship_messages_group_empty_title', 'local_launchpad')
            : 'No group chats';

        // Include hidden tracks — fellowship list_courses hides visible=0, but messaging still applies.
        $courseids = self::user_track_courseids_for_messages($userid, $portal);

        $sawon = false;
        $sawdisabled = false;
        $sawoff = false;
        $sawsiteoff = false;
        foreach ($courseids as $courseid) {
            $status = self::get_track_chat_status($courseid);
            switch ((string) ($status['status_key'] ?? 'off')) {
                case 'on':
                    $sawon = true;
                    break;
                case 'disabled':
                    $sawdisabled = true;
                    break;
                case 'site_off':
                    $sawsiteoff = true;
                    break;
                default:
                    $sawoff = true;
                    break;
            }
        }

        if ($sawon) {
            $body = get_string_manager()->string_exists('fellowship_messages_group_empty_pending', 'local_launchpad')
                ? get_string('fellowship_messages_group_empty_pending', 'local_launchpad')
                : 'Track group chat is enabled, but it is not in your inbox yet. Refresh, or ask a programme admin to check membership.';
        } else if ($sawdisabled) {
            $body = get_string_manager()->string_exists('fellowship_messages_group_empty_disabled', 'local_launchpad')
                ? get_string('fellowship_messages_group_empty_disabled', 'local_launchpad')
                : 'Track group chat is turned off right now. Ask a programme admin to enable it again — history returns with it.';
        } else if ($sawsiteoff && !$sawoff) {
            $body = get_string_manager()->string_exists('fellowship_messages_disabled_body', 'local_launchpad')
                ? get_string('fellowship_messages_disabled_body', 'local_launchpad')
                : 'Site messaging is disabled. Ask an admin to enable it.';
        } else {
            $body = get_string_manager()->string_exists('fellowship_messages_group_empty_off', 'local_launchpad')
                ? get_string('fellowship_messages_group_empty_off', 'local_launchpad')
                : 'No track group chat yet. When a programme admin enables it for your course, it appears here.';
        }

        return ['title' => $title, 'body' => $body];
    }

    /**
     * LaunchPad track course ids for a user (fellow student / mentor teacher), including hidden courses.
     * Skips orientation. Used for Group-tab empty copy.
     *
     * @return list<int>
     */
    public static function user_track_courseids_for_messages(int $userid, string $portal = 'fellow'): array {
        global $CFG, $DB;

        if ($userid <= 0) {
            return [];
        }

        if ($portal === 'mentor') {
            $ids = [];
            foreach (persona_home::list_mentor_courses($userid) as $course) {
                if (fellowship_dashboard::is_orientation_course($course)) {
                    continue;
                }
                $ids[] = (int) $course->id;
            }
            return $ids;
        }

        require_once($CFG->libdir . '/enrollib.php');
        $sql = "SELECT DISTINCT c.id, c.idnumber
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                  JOIN {course} c ON c.id = e.courseid
                  JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = :ctxlevel
                  JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.userid = ue.userid
                  JOIN {role} r ON r.id = ra.roleid AND r.archetype = :arch
                 WHERE ue.userid = :userid
                   AND ue.status = :uestatus
                   AND e.status = :estatus
                   AND " . $DB->sql_like('c.idnumber', ':idn', false);
        $rows = $DB->get_records_sql($sql, [
            'userid' => $userid,
            'uestatus' => ENROL_USER_ACTIVE,
            'estatus' => ENROL_INSTANCE_ENABLED,
            'ctxlevel' => CONTEXT_COURSE,
            'arch' => 'student',
            'idn' => $DB->sql_like_escape(account_persona::COURSE_IDNUMBER_PREFIX) . '%',
        ]);
        $ids = [];
        foreach ($rows as $row) {
            if (account_provision::is_orientation_idnumber((string) ($row->idnumber ?? ''))) {
                continue;
            }
            $ids[] = (int) $row->id;
        }
        return $ids;
    }

    /**
     * Soft-sync membership when the track chat group already exists (enrol/assign hooks).
     * Does not create the group — admin Enable owns creation.
     */
    public static function maybe_sync_track_chat_members(int $courseid): void {
        global $CFG, $DB;

        if ($courseid <= 0) {
            return;
        }
        try {
            $idnumber = (string) ($DB->get_field('course', 'idnumber', ['id' => $courseid]) ?: '');
            if ($idnumber !== '' && account_provision::is_orientation_idnumber($idnumber)) {
                return;
            }
            require_once($CFG->dirroot . '/group/lib.php');
            if (!groups_get_group_by_idnumber($courseid, self::TRACK_CHAT_IDNUMBER)) {
                return;
            }
            self::sync_track_chat_members($courseid);
        } catch (\Throwable $e) {
            // Soft-fail — roster ops must not break if messaging/group sync fails.
        }
    }

    /**
     * Set course-group members to LaunchPad fellows + mentors on this track.
     *
     * @return int Active member count after sync
     */
    public static function sync_track_chat_members(int $courseid): int {
        global $CFG;

        if ($courseid <= 0) {
            return 0;
        }
        require_once($CFG->dirroot . '/group/lib.php');

        $group = self::ensure_track_chat_group($courseid);
        if (!$group) {
            return 0;
        }
        $groupid = (int) $group->id;

        $wanted = [];
        foreach (admin_dashboard::list_track_fellows($courseid) as $row) {
            $uid = (int) ($row['id'] ?? 0);
            if ($uid > 0) {
                $wanted[$uid] = true;
            }
        }
        foreach (admin_dashboard::list_track_roster_mentors($courseid) as $row) {
            $uid = (int) ($row['id'] ?? 0);
            if ($uid > 0) {
                $wanted[$uid] = true;
            }
        }

        $current = [];
        foreach (groups_get_members($groupid, 'u.id') as $u) {
            $current[(int) $u->id] = true;
        }

        foreach (array_keys($wanted) as $uid) {
            if (empty($current[$uid])) {
                // Soft-fail per user (e.g. not enrolled yet).
                try {
                    groups_add_member($groupid, $uid);
                } catch (\Throwable $e) {
                    // Continue.
                }
            }
        }
        foreach (array_keys($current) as $uid) {
            if (empty($wanted[$uid])) {
                try {
                    groups_remove_member($groupid, $uid);
                } catch (\Throwable $e) {
                    // Continue.
                }
            }
        }

        return count(groups_get_members($groupid, 'u.id'));
    }

    /**
     * Status bag for Admin Course ops Participants card.
     *
     * @return array{
     *   messaging_site_on:bool,
     *   has_group:bool,
     *   group_id:int,
     *   conversation_id:int,
     *   enabled:bool,
     *   member_count:int,
     *   status_key:string
     * }
     */
    public static function get_track_chat_status(int $courseid): array {
        global $CFG;

        $out = [
            'messaging_site_on' => !empty($CFG->messaging),
            'has_group' => false,
            'group_id' => 0,
            'conversation_id' => 0,
            'enabled' => false,
            'member_count' => 0,
            'status_key' => 'off',
        ];
        if ($courseid <= 0) {
            return $out;
        }
        if (empty($CFG->messaging)) {
            $out['status_key'] = 'site_off';
            return $out;
        }

        require_once($CFG->dirroot . '/group/lib.php');
        $group = groups_get_group_by_idnumber($courseid, self::TRACK_CHAT_IDNUMBER);
        if (!$group) {
            $out['status_key'] = 'off';
            return $out;
        }
        $out['has_group'] = true;
        $out['group_id'] = (int) $group->id;
        $out['member_count'] = count(groups_get_members((int) $group->id, 'u.id'));

        $context = \context_course::instance($courseid);
        $conv = \core_message\api::get_conversation_by_area(
            'core_group',
            'groups',
            (int) $group->id,
            $context->id
        );
        if ($conv) {
            $out['conversation_id'] = (int) $conv->id;
            $out['enabled'] = !empty($conv->enabled);
            $out['status_key'] = $out['enabled'] ? 'on' : 'disabled';
        } else {
            $out['status_key'] = 'off';
        }
        return $out;
    }

    /**
     * Admin-owned: enable or disable the track group conversation.
     *
     * @return array{ok?:string,error?:string}
     */
    public static function set_track_chat_enabled(int $courseid, bool $on): array {
        global $CFG;

        if ($courseid <= 0) {
            return ['error' => get_string('admin_courseops_err_track', 'local_launchpad')];
        }
        if (empty($CFG->messaging)) {
            return ['error' => get_string('admin_track_chat_err_messaging_off', 'local_launchpad')];
        }

        require_once($CFG->dirroot . '/group/lib.php');

        $group = self::ensure_track_chat_group($courseid);
        if (!$group) {
            return ['error' => get_string('admin_track_chat_err_group', 'local_launchpad')];
        }

        $membercount = self::sync_track_chat_members($courseid);
        $context = \context_course::instance($courseid);
        $convid = 0;
        $conv = \core_message\api::get_conversation_by_area(
            'core_group',
            'groups',
            (int) $group->id,
            $context->id
        );

        try {
            if ($on) {
                if (!$conv) {
                    $memberids = array_map(
                        static fn($u) => (int) $u->id,
                        groups_get_members((int) $group->id, 'u.id')
                    );
                    $created = \core_message\api::create_conversation(
                        \core_message\api::MESSAGE_CONVERSATION_TYPE_GROUP,
                        $memberids,
                        (string) $group->name,
                        \core_message\api::MESSAGE_CONVERSATION_ENABLED,
                        'core_group',
                        'groups',
                        (int) $group->id,
                        $context->id
                    );
                    $convid = (int) ($created->id ?? 0);
                } else {
                    $convid = (int) $conv->id;
                    if (empty($conv->enabled)) {
                        \core_message\api::enable_conversation($convid);
                    }
                    \core_message\api::update_conversation_name($convid, (string) $group->name);
                    // Ensure members match group after re-enable.
                    $memberids = array_map(
                        static fn($u) => (int) $u->id,
                        groups_get_members((int) $group->id, 'u.id')
                    );
                    if ($memberids !== []) {
                        \core_message\api::add_members_to_conversation($memberids, $convid);
                    }
                }
                if ($convid <= 0) {
                    return ['error' => get_string('admin_track_chat_err_enable', 'local_launchpad')];
                }
                return [
                    'ok' => get_string('admin_track_chat_enabled_ok', 'local_launchpad', $membercount),
                ];
            }

            // Disable.
            if ($conv && !empty($conv->enabled)) {
                \core_message\api::disable_conversation((int) $conv->id);
            }
            return ['ok' => get_string('admin_track_chat_disabled_ok', 'local_launchpad')];
        } catch (\Throwable $e) {
            return ['error' => get_string('admin_track_chat_err_enable', 'local_launchpad')];
        }
    }
}
