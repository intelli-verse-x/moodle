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
 * Admin announcements — post to course news forums across LaunchPad tracks.
 *
 * @package    local_launchpad
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_launchpad;

defined('MOODLE_INTERNAL') || die();

/**
 * Compose + list LaunchPad announcements via Moodle news forums.
 */
final class announcement_service {

    /** Suggested tag chips for the compose form. */
    public const SUGGESTED_TAGS = ['Programme', 'Assessment', 'Schedule'];

    /** Visible rows before "Show more". */
    public const LIST_PAGE_SIZE = 8;

    /** Max rows kept in the admin list DOM (further history → Moodle Open). */
    public const LIST_FETCH_LIMIT = 40;

    /** Fellow sidebar: last time Announcements was opened (user preference name). */
    public const PREF_NEWS_SEEN = 'local_launchpad_news_seen';

    /** When no preference yet, ignore news older than this (avoid ancient backlog badges). */
    public const UNREAD_LOOKBACK = 14 * DAYSECS;

    /** Max pinned news discussions per track (news forum). */
    public const PIN_LIMIT = 3;

    /**
     * Mark LaunchPad news as read for the fellow (clears sidebar badge).
     */
    public static function mark_seen(int $userid): void {
        if ($userid <= 0) {
            return;
        }
        set_user_preference(self::PREF_NEWS_SEEN, (string) time(), $userid);
    }

    /**
     * Count news-forum discussions newer than the fellow's seen watermark.
     *
     * @param list<\stdClass> $courses Enrolled track courses
     */
    public static function unread_count_for_courses(int $userid, array $courses): int {
        global $DB;

        if ($userid <= 0 || $courses === []) {
            return 0;
        }

        $courseids = [];
        foreach ($courses as $course) {
            $id = (int) ($course->id ?? 0);
            if ($id > 0) {
                $courseids[] = $id;
            }
        }
        if ($courseids === []) {
            return 0;
        }

        $seen = self::seen_watermark($userid);
        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'cid');
        $params['seen'] = $seen;
        $params['uid'] = $userid;

        $sql = "SELECT COUNT(DISTINCT d.id)
                  FROM {forum} f
                  JOIN {forum_discussions} d ON d.forum = f.id
                  JOIN {forum_posts} p ON p.discussion = d.id AND p.parent = 0
                 WHERE f.type = 'news'
                   AND f.course {$insql}
                   AND GREATEST(p.created, p.modified) > :seen
                   AND p.userid <> :uid";

        return (int) $DB->count_records_sql($sql, $params);
    }

    /**
     * Unix time: posts created after this count as unread for the sidebar badge.
     */
    public static function seen_watermark(int $userid): int {
        $raw = get_user_preferences(self::PREF_NEWS_SEEN, null, $userid);
        if ($raw !== null && $raw !== false && $raw !== '') {
            return max(0, (int) $raw);
        }

        $user = \core_user::get_user($userid, 'id,firstaccess,timecreated', IGNORE_MISSING);
        $baseline = 0;
        if ($user) {
            $baseline = (int) ($user->firstaccess ?: 0);
            if ($baseline <= 0) {
                $baseline = (int) ($user->timecreated ?: 0);
            }
        }
        $lookback = time() - self::UNREAD_LOOKBACK;
        return max($baseline, $lookback);
    }

    /**
     * Admin Announcements page context.
     *
     * @param \stdClass $user
     * @param array{ok?:string,error?:string} $flash
     * @return array<string,mixed>
     */
    public static function admin_context(\stdClass $user, array $flash = []): array {
        $tracks = admin_dashboard::list_track_courses();
        $trackoptions = [];
        foreach ($tracks as $course) {
            $trackoptions[] = [
                'id' => (int) $course->id,
                'label' => format_string($course->fullname),
            ];
        }

        $tagoptions = [];
        foreach (self::SUGGESTED_TAGS as $tag) {
            $tagoptions[] = [
                'name' => $tag,
                'value' => $tag,
            ];
        }

        $rawitems = self::list_for_admin($tracks, self::LIST_FETCH_LIMIT);
        $items = [];
        foreach ($rawitems as $i => $item) {
            $item['paged_out'] = $i >= self::LIST_PAGE_SIZE;
            $items[] = $item;
        }
        $morecount = max(0, count($items) - self::LIST_PAGE_SIZE);
        $formaction = (new \moodle_url(persona_home::ADMIN_PATH, ['view' => 'announcements']))->out(false);

        return array_merge([
            'eyebrow' => get_string('admin_announcements_eyebrow', 'local_launchpad'),
            'heading' => get_string('admin_nav_announcements', 'local_launchpad'),
            'lead' => get_string('admin_announcements_lead', 'local_launchpad'),
            'form_title' => get_string('admin_announcements_compose_title', 'local_launchpad'),
            'list_title' => get_string('admin_announcements_list_title', 'local_launchpad'),
            'subject_label' => get_string('admin_announcements_subject', 'local_launchpad'),
            'message_label' => get_string('admin_announcements_message', 'local_launchpad'),
            'tags_label' => get_string('admin_announcements_tags', 'local_launchpad'),
            'tracks_label' => get_string('admin_announcements_tracks', 'local_launchpad'),
            'all_tracks_label' => get_string('admin_announcements_all_tracks', 'local_launchpad'),
            'custom_tag_label' => get_string('admin_announcements_custom_tag', 'local_launchpad'),
            'custom_tag_placeholder' => get_string('admin_announcements_custom_tag_ph', 'local_launchpad'),
            'pin_label' => get_string('admin_announcements_pin', 'local_launchpad'),
            'pin_help' => get_string('admin_announcements_pin_help', 'local_launchpad'),
            'submit_label' => get_string('admin_announcements_submit', 'local_launchpad'),
            'save_label' => get_string('admin_announcements_save', 'local_launchpad'),
            'cancel_edit_label' => get_string('admin_announcements_cancel_edit', 'local_launchpad'),
            'editing_label' => get_string('admin_announcements_editing', 'local_launchpad'),
            'advanced_label' => get_string('admin_announcements_advanced', 'local_launchpad'),
            'edit_label' => get_string('admin_announcements_edit', 'local_launchpad'),
            'delete_label' => get_string('admin_announcements_delete', 'local_launchpad'),
            'delete_confirm' => get_string('admin_announcements_delete_confirm', 'local_launchpad'),
            'show_more_label' => get_string('admin_announcements_show_more', 'local_launchpad', $morecount),
            'col_subject' => get_string('admin_announcements_col_subject', 'local_launchpad'),
            'col_track' => get_string('admin_announcements_col_track', 'local_launchpad'),
            'col_when' => get_string('admin_announcements_col_when', 'local_launchpad'),
            'form_action' => $formaction,
            'sesskey' => sesskey(),
            'has_tracks' => $trackoptions !== [],
            'track_options' => $trackoptions,
            'tag_options' => $tagoptions,
            'items' => $items,
            'has_items' => $items !== [],
            'list_page_size' => self::LIST_PAGE_SIZE,
            'list_has_more' => $morecount > 0,
            'list_more_count' => $morecount,
            'flash_ok' => !empty($flash['ok']),
            'flash_ok_body' => (string) ($flash['ok'] ?? ''),
            'flash_err' => !empty($flash['error']),
            'flash_err_body' => (string) ($flash['error'] ?? ''),
        ], admin_dashboard::empty_state(
            'admin_announcements_empty_body',
            'admin_announcements_empty_title',
            null,
            null,
            'AN'
        ));
    }

    /**
     * Post the same announcement to one or more LaunchPad tracks.
     *
     * @param \stdClass $user
     * @param string $subject
     * @param string $message
     * @param list<int> $trackids Empty + $alltracks → all tracks
     * @param bool $alltracks
     * @param list<string> $tagnames
     * @return array{ok?:string,error?:string}
     */
    public static function post(
        \stdClass $user,
        string $subject,
        string $message,
        array $trackids,
        bool $alltracks,
        array $tagnames,
        bool $pinned = false
    ): array {
        global $CFG;

        require_once($CFG->dirroot . '/mod/forum/lib.php');

        $subject = trim($subject);
        $message = trim($message);
        if ($subject === '') {
            return ['error' => get_string('admin_announcements_err_subject', 'local_launchpad')];
        }
        if ($message === '') {
            return ['error' => get_string('admin_announcements_err_message', 'local_launchpad')];
        }

        $targets = self::resolve_tracks($trackids, $alltracks);
        if ($targets === []) {
            return ['error' => get_string('admin_announcements_err_tracks', 'local_launchpad')];
        }

        $tags = self::normalize_tags($tagnames);
        $ok = 0;
        $failures = [];

        foreach ($targets as $course) {
            $cid = (int) $course->id;
            $label = format_string($course->fullname);
            try {
                $forum = forum_get_course_forum($cid, 'news');
                if (!$forum) {
                    $failures[] = $label . ': ' . get_string('admin_announcements_err_forum', 'local_launchpad');
                    continue;
                }
                $cm = get_coursemodule_from_instance('forum', (int) $forum->id, $cid, false, MUST_EXIST);
                $modcontext = \context_module::instance((int) $cm->id);
                if (!has_capability('mod/forum:addnews', $modcontext)) {
                    $failures[] = $label . ': ' . get_string('admin_announcements_err_cap', 'local_launchpad');
                    continue;
                }
                $pinvalue = FORUM_DISCUSSION_UNPINNED;
                if ($pinned) {
                    if (!has_capability('mod/forum:pindiscussions', $modcontext, $user)) {
                        $failures[] = $label . ': ' . get_string('admin_announcements_err_cap_pin', 'local_launchpad');
                        continue;
                    }
                    if (self::count_pinned_in_forum((int) $forum->id) >= self::PIN_LIMIT) {
                        $failures[] = $label . ': ' . get_string(
                            'admin_announcements_err_pin_limit',
                            'local_launchpad',
                            self::PIN_LIMIT
                        );
                        continue;
                    }
                    $pinvalue = FORUM_DISCUSSION_PINNED;
                }

                $discussion = new \stdClass();
                $discussion->course = $cid;
                $discussion->forum = (int) $forum->id;
                $discussion->name = $subject;
                $discussion->message = clean_text($message, FORMAT_HTML);
                $discussion->messageformat = FORMAT_HTML;
                $discussion->messagetrust = trusttext_trusted($modcontext) ? 1 : 0;
                $discussion->mailnow = 0;
                $discussion->timestart = 0;
                $discussion->timeend = 0;
                $discussion->pinned = $pinvalue;
                $discussion->groupid = -1;
                if ($tags !== []) {
                    $discussion->tags = $tags;
                }

                $discussionid = forum_add_discussion($discussion, null, null, (int) $user->id);
                if (!$discussionid) {
                    $failures[] = $label . ': ' . get_string('admin_announcements_err_save', 'local_launchpad');
                    continue;
                }
                $ok++;
            } catch (\Throwable $e) {
                $failures[] = $label . ': ' . admin_dashboard::flash_error_message($e);
            }
        }

        $total = count($targets);
        if ($ok === 0) {
            $detail = $failures !== [] ? ' ' . implode('; ', $failures) : '';
            return ['error' => get_string('admin_announcements_err_none', 'local_launchpad') . $detail];
        }

        $okmsg = get_string('admin_announcements_ok', 'local_launchpad', (object) [
            'ok' => $ok,
            'total' => $total,
        ]);
        if ($failures !== []) {
            return [
                'ok' => $okmsg . ' ' . get_string('admin_announcements_partial', 'local_launchpad')
                    . ' ' . implode('; ', $failures),
            ];
        }
        return ['ok' => $okmsg];
    }

    /**
     * Update a news discussion (subject / message / tags). Fail closed on caps.
     *
     * @param list<string> $tagnames
     * @return array{ok?:string,error?:string}
     */
    public static function update(
        \stdClass $user,
        int $discussionid,
        string $subject,
        string $message,
        array $tagnames,
        bool $pinned = false
    ): array {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/forum/lib.php');

        $subject = trim($subject);
        $message = trim($message);
        if ($subject === '') {
            return ['error' => get_string('admin_announcements_err_subject', 'local_launchpad')];
        }
        if ($message === '') {
            return ['error' => get_string('admin_announcements_err_message', 'local_launchpad')];
        }

        $bundle = self::load_news_discussion($discussionid);
        if ($bundle === null) {
            return ['error' => get_string('admin_announcements_err_missing', 'local_launchpad')];
        }
        if (!self::user_can_edit($user, $bundle['modcontext'], $bundle['post'])) {
            return ['error' => get_string('admin_announcements_err_cap_edit', 'local_launchpad')];
        }

        $currentpinned = (int) ($bundle['discussion']->pinned ?? FORUM_DISCUSSION_UNPINNED) === FORUM_DISCUSSION_PINNED;
        $pinvalue = $currentpinned ? FORUM_DISCUSSION_PINNED : FORUM_DISCUSSION_UNPINNED;
        if ($pinned !== $currentpinned) {
            if (!has_capability('mod/forum:pindiscussions', $bundle['modcontext'], $user)) {
                return ['error' => get_string('admin_announcements_err_cap_pin', 'local_launchpad')];
            }
            if ($pinned && self::count_pinned_in_forum(
                    (int) $bundle['forum']->id,
                    (int) $bundle['discussion']->id
            ) >= self::PIN_LIMIT) {
                return ['error' => get_string(
                    'admin_announcements_err_pin_limit',
                    'local_launchpad',
                    self::PIN_LIMIT
                )];
            }
            $pinvalue = $pinned ? FORUM_DISCUSSION_PINNED : FORUM_DISCUSSION_UNPINNED;
        }

        $clean = clean_text($message, FORMAT_HTML);
        $now = time();
        $postupdate = (object) [
            'id' => (int) $bundle['post']->id,
            'subject' => $subject,
            'message' => $clean,
            'messageformat' => FORMAT_HTML,
            'messagetrust' => trusttext_trusted($bundle['modcontext']) ? 1 : 0,
            'modified' => $now,
        ];
        $DB->update_record('forum_posts', $postupdate);
        $DB->update_record('forum_discussions', (object) [
            'id' => (int) $bundle['discussion']->id,
            'name' => $subject,
            'timemodified' => $now,
            'usermodified' => (int) $user->id,
            'pinned' => $pinvalue,
        ]);

        $tags = self::normalize_tags($tagnames);
        \core_tag_tag::set_item_tags(
            'mod_forum',
            'forum_posts',
            (int) $bundle['post']->id,
            $bundle['modcontext'],
            $tags
        );

        return ['ok' => get_string('admin_announcements_updated', 'local_launchpad')];
    }

    /**
     * Delete a news discussion. Fail closed on caps.
     *
     * @return array{ok?:string,error?:string}
     */
    public static function delete(\stdClass $user, int $discussionid): array {
        global $CFG;

        require_once($CFG->dirroot . '/mod/forum/lib.php');

        $bundle = self::load_news_discussion($discussionid);
        if ($bundle === null) {
            return ['error' => get_string('admin_announcements_err_missing', 'local_launchpad')];
        }
        if (!self::user_can_delete($user, $bundle['modcontext'], $bundle['post'])) {
            return ['error' => get_string('admin_announcements_err_cap_delete', 'local_launchpad')];
        }

        try {
            $ok = forum_delete_discussion(
                $bundle['discussion'],
                false,
                $bundle['course'],
                $bundle['cm'],
                $bundle['forum']
            );
            if (!$ok) {
                return ['error' => get_string('admin_announcements_err_delete', 'local_launchpad')];
            }
            return ['ok' => get_string('admin_announcements_deleted', 'local_launchpad')];
        } catch (\Throwable $e) {
            return ['error' => admin_dashboard::flash_error_message($e)];
        }
    }

    /**
     * Recent news discussions across tracks for the admin list.
     *
     * @param list<\stdClass> $tracks
     * @return list<array<string,mixed>>
     */
    public static function list_for_admin(array $tracks, int $limit = 30): array {
        global $DB, $USER;

        $items = [];
        foreach ($tracks as $course) {
            $cid = (int) $course->id;
            $forums = $DB->get_records('forum', ['course' => $cid, 'type' => 'news'], 'id ASC', 'id', 0, 1);
            $forum = $forums ? reset($forums) : false;
            if (!$forum) {
                continue;
            }
            $cm = get_coursemodule_from_instance('forum', (int) $forum->id, $cid, false, IGNORE_MISSING);
            if (!$cm) {
                continue;
            }
            $modcontext = \context_module::instance((int) $cm->id);
            if (!has_capability('mod/forum:viewdiscussion', $modcontext)) {
                continue;
            }

            $discussions = $DB->get_records(
                'forum_discussions',
                ['forum' => (int) $forum->id],
                'timemodified DESC',
                '*',
                0,
                15
            );
            foreach ($discussions as $discussion) {
                $post = $DB->get_record('forum_posts', [
                    'discussion' => (int) $discussion->id,
                    'parent' => 0,
                ]);
                if (!$post) {
                    continue;
                }
                $tagrows = self::tag_chips_for_post((int) $post->id);
                $tagnames = array_map(static fn(array $t): string => (string) $t['name'], $tagrows);
                $messageplain = trim(html_to_text(format_text(
                    $post->message,
                    (int) $post->messageformat,
                    ['context' => $modcontext, 'para' => false, 'filter' => false]
                ), 0, false));
                $canedit = self::user_can_edit($USER, $modcontext, $post);
                $candelete = self::user_can_delete($USER, $modcontext, $post);
                $ispinned = (int) ($discussion->pinned ?? 0) === 1;
                $items[] = [
                    'id' => (int) $discussion->id,
                    'subject' => format_string($discussion->name),
                    'subject_raw' => (string) $discussion->name,
                    'message_plain' => $messageplain,
                    'excerpt' => shorten_text(format_text(
                        $post->message,
                        (int) $post->messageformat,
                        ['context' => $modcontext, 'para' => false]
                    ), 160),
                    'when' => userdate((int) $discussion->timemodified, get_string('strftimedatetimeshort', 'langconfig')),
                    'timemodified' => (int) $discussion->timemodified,
                    'course_name' => format_string($course->fullname),
                    'url' => (new \moodle_url('/mod/forum/discuss.php', ['d' => $discussion->id]))->out(false),
                    'tags' => $tagrows,
                    'has_tags' => $tagrows !== [],
                    'tags_csv' => implode(',', $tagnames),
                    'is_pinned' => $ispinned,
                    'pinned_badge' => $ispinned
                        ? get_string('admin_announcements_pinned_badge', 'local_launchpad')
                        : '',
                    'can_edit' => $canedit,
                    'can_delete' => $candelete,
                    'has_actions' => $canedit || $candelete,
                ];
            }
        }

        usort($items, [self::class, 'sort_announcement_items']);
        return array_slice($items, 0, $limit);
    }

    /**
     * Pinned first, then newest timemodified.
     *
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     */
    public static function sort_announcement_items(array $a, array $b): int {
        $pa = !empty($a['is_pinned']) ? 1 : 0;
        $pb = !empty($b['is_pinned']) ? 1 : 0;
        if ($pa !== $pb) {
            return $pb <=> $pa;
        }
        return ((int) ($b['timemodified'] ?? 0)) <=> ((int) ($a['timemodified'] ?? 0));
    }

    /**
     * How many pinned discussions already exist in a news forum.
     *
     * @param int $exclude discussion id to ignore (when re-pinning / already pinned edit)
     */
    private static function count_pinned_in_forum(int $forumid, int $exclude = 0): int {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/forum/lib.php');

        if ($forumid <= 0) {
            return 0;
        }
        $params = [
            'forum' => $forumid,
            'pinned' => FORUM_DISCUSSION_PINNED,
        ];
        $sql = "SELECT COUNT(1)
                  FROM {forum_discussions}
                 WHERE forum = :forum
                   AND pinned = :pinned";
        if ($exclude > 0) {
            $sql .= " AND id <> :exclude";
            $params['exclude'] = $exclude;
        }
        return (int) $DB->count_records_sql($sql, $params);
    }

    /**
     * Load a LaunchPad-track news discussion bundle, or null if not allowed/found.
     *
     * @return null|array{
     *   discussion:\stdClass,
     *   post:\stdClass,
     *   forum:\stdClass,
     *   course:\stdClass,
     *   cm:\stdClass,
     *   modcontext:\context_module
     * }
     */
    private static function load_news_discussion(int $discussionid): ?array {
        global $DB;

        if ($discussionid <= 0) {
            return null;
        }
        $discussion = $DB->get_record('forum_discussions', ['id' => $discussionid]);
        if (!$discussion) {
            return null;
        }
        $forum = $DB->get_record('forum', ['id' => (int) $discussion->forum]);
        if (!$forum || (string) $forum->type !== 'news') {
            return null;
        }
        $course = $DB->get_record('course', ['id' => (int) $discussion->course]);
        if (!$course || !admin_dashboard::is_track_idnumber((string) ($course->idnumber ?? ''))) {
            return null;
        }
        $cm = get_coursemodule_from_instance('forum', (int) $forum->id, (int) $course->id, false, IGNORE_MISSING);
        if (!$cm) {
            return null;
        }
        $post = $DB->get_record('forum_posts', [
            'discussion' => (int) $discussion->id,
            'parent' => 0,
        ]);
        if (!$post) {
            return null;
        }

        return [
            'discussion' => $discussion,
            'post' => $post,
            'forum' => $forum,
            'course' => $course,
            'cm' => $cm,
            'modcontext' => \context_module::instance((int) $cm->id),
        ];
    }

    private static function user_can_edit(\stdClass $user, \context $modcontext, \stdClass $post): bool {
        if (has_capability('mod/forum:editanypost', $modcontext, $user)) {
            return true;
        }
        // Own announcement + can post news (typical teacher/admin path).
        return (int) ($post->userid ?? 0) === (int) $user->id
            && has_capability('mod/forum:addnews', $modcontext, $user);
    }

    private static function user_can_delete(\stdClass $user, \context $modcontext, \stdClass $post): bool {
        if (has_capability('mod/forum:deleteanypost', $modcontext, $user)) {
            return true;
        }
        return (int) ($post->userid ?? 0) === (int) $user->id
            && has_capability('mod/forum:deleteownpost', $modcontext, $user);
    }

    /**
     * Tag chip rows for a forum post (shared by admin + fellow lists).
     *
     * @return list<array{name:string}>
     */
    public static function tag_chips_for_post(int $postid): array {
        if ($postid <= 0) {
            return [];
        }
        $tags = \core_tag_tag::get_item_tags('mod_forum', 'forum_posts', $postid);
        $out = [];
        foreach ($tags as $tag) {
            $name = trim((string) ($tag->get_display_name() ?? ''));
            if ($name === '') {
                continue;
            }
            $out[] = ['name' => $name];
        }
        return $out;
    }

    /**
     * @param list<int> $trackids
     * @return list<\stdClass>
     */
    private static function resolve_tracks(array $trackids, bool $alltracks): array {
        $all = admin_dashboard::list_track_courses();
        if ($alltracks || $trackids === []) {
            // Empty selection with no all_tracks flag = fail closed (caller checks).
            return $alltracks ? $all : [];
        }
        $wanted = [];
        foreach ($trackids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $wanted[$id] = true;
            }
        }
        $out = [];
        foreach ($all as $course) {
            if (isset($wanted[(int) $course->id])) {
                $out[] = $course;
            }
        }
        return $out;
    }

    /**
     * @param list<string> $tagnames
     * @return list<string>
     */
    private static function normalize_tags(array $tagnames): array {
        $out = [];
        $seen = [];
        foreach ($tagnames as $raw) {
            $name = trim((string) $raw);
            if ($name === '') {
                continue;
            }
            // Keep short; Moodle tags are free-text but avoid abuse.
            if (\core_text::strlen($name) > 50) {
                $name = \core_text::substr($name, 0, 50);
            }
            $key = \core_text::strtolower($name);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $name;
        }
        return $out;
    }
}
