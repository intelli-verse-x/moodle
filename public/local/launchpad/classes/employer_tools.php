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

defined('MOODLE_INTERNAL') || die();

/**
 * Employer “My tools” pages — Fellowship pattern:
 * custom UI reads Moodle data; actions deep-link into Moodle core UIs.
 *
 * @package    local_launchpad
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class employer_tools {

    /**
     * Grades — overview of enrolled courses, or detailed Moodle grade report for one course.
     *
     * Overview: employee.php?view=grades
     * Detail:   employee.php?view=grades&id={courseid}
     *
     * @param int $userid
     * @param list<array<string,mixed>> $courses
     * @param array<string,string> $urls
     * @param int $courseid 0 = overview; >0 = course detail (must be enrolled)
     * @return array<string,mixed>
     */
    /**
     * Grades — Moodle overview + full user report for one course (custom UI).
     *
     * Overview: employee.php?view=grades
     * Detail:   employee.php?view=grades&id={courseid}
     * Filter:   &gtab=all|graded|pending
     *
     * @param int $userid
     * @param list<array<string,mixed>> $courses
     * @param array<string,string> $urls
     * @param int $courseid
     * @param string $gtab
     * @return array<string,mixed>
     */
    public static function grades_page(
        int $userid,
        array $courses,
        array $urls,
        int $courseid = 0,
        string $gtab = 'all'
    ): array {
        global $CFG;

        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->libdir . '/grade/grade_item.php');
        require_once($CFG->libdir . '/grade/grade_grade.php');

        $allowedtabs = ['all', 'graded', 'pending'];
        $gtab = in_array($gtab, $allowedtabs, true) ? $gtab : 'all';

        $overviewurl = (string) ($urls['grades']
            ?? (new \moodle_url('/local/launchpad/employee.php', ['view' => 'grades']))->out(false));
        $moodleoverview = (string) ($urls['moodle_grades']
            ?? (new \moodle_url('/grade/report/overview/index.php'))->out(false));

        $taburls = [];
        foreach ($allowedtabs as $key) {
            $taburls[$key] = (new \moodle_url('/local/launchpad/employee.php', [
                'view' => 'grades',
                'gtab' => $key,
            ]))->out(false);
        }

        $items = [];
        $gradedcount = 0;
        $pctsum = 0.0;
        $pctn = 0;
        foreach ($courses as $course) {
            $cid = (int) ($course['id'] ?? 0);
            if ($cid <= 0) {
                continue;
            }
            $summary = self::course_grade_summary($cid, $userid);
            $moodlecoursegrades = (string) ($course['gradesurl'] ?? '');
            if ($moodlecoursegrades === '') {
                $moodlecoursegrades = (new \moodle_url('/grade/report/user/index.php', ['id' => $cid]))->out(false);
            }
            if (!empty($summary['has_grade'])) {
                $gradedcount++;
            }
            if (!empty($summary['pct_num'])) {
                $pctsum += (float) $summary['pct_num'];
                $pctn++;
            }
            $hasgrade = !empty($summary['has_grade']);
            $items[] = [
                'id' => $cid,
                'name' => (string) ($course['name'] ?? ''),
                'shortname' => (string) ($course['shortname'] ?? ''),
                'meta' => $summary['meta'],
                'detail' => $hasgrade
                    ? trim($summary['grade_display'] . (!empty($summary['percentage']) ? ' · ' . $summary['percentage'] : ''))
                    : self::str('employer_grades_tile_pending_detail', null, 'Open report when grades are posted'),
                'grade_display' => $summary['grade_display'],
                'percentage' => $summary['percentage'],
                'pct_num' => (int) ($summary['pct_num'] ?? 0),
                'has_grade' => $hasgrade,
                'has_percentage' => $summary['has_percentage'],
                'is_pending' => !$hasgrade,
                'css' => $hasgrade ? 'on' : '',
                'status' => (string) ($course['status'] ?? ''),
                'status_label' => $hasgrade
                    ? self::str('employer_grades_status_graded', null, 'Graded')
                    : self::str('employer_grades_status_pending', null, 'Awaiting grades'),
                'status_pill' => $hasgrade
                    ? self::str('employer_grades_status_graded', null, 'Graded')
                    : self::str('employer_grades_status_locked', null, 'Awaiting'),
                'url' => (new \moodle_url('/local/launchpad/employee.php', [
                    'view' => 'grades',
                    'id' => $cid,
                ]))->out(false),
                'moodle_url' => $moodlecoursegrades,
            ];
        }

        $filtered = array_values(array_filter($items, static function (array $row) use ($gtab): bool {
            if ($gtab === 'graded') {
                return !empty($row['has_grade']);
            }
            if ($gtab === 'pending') {
                return !empty($row['is_pending']);
            }
            return true;
        }));

        $avgpct = $pctn > 0 ? (int) round($pctsum / $pctn) : 0;
        $coursecount = count($items);
        $pendingcount = max(0, $coursecount - $gradedcount);
        // Journey progress = share of courses with a Moodle course total (badge-style hero).
        $gradespct = $coursecount > 0 ? (int) round(($gradedcount / $coursecount) * 100) : 0;

        $recent = array_values(array_filter($items, static fn(array $r): bool => !empty($r['has_grade'])));
        $recent = array_slice($recent, 0, 5);
        $next = array_values(array_filter($items, static fn(array $r): bool => !empty($r['is_pending'])));
        $next = array_slice($next, 0, 5);

        $detail = [];
        $hasdetail = false;
        if ($courseid > 0) {
            $coursename = '';
            $enrolled = false;
            $moodledetailurl = (new \moodle_url('/grade/report/user/index.php', ['id' => $courseid]))->out(false);
            foreach ($courses as $course) {
                if ((int) ($course['id'] ?? 0) === $courseid) {
                    $enrolled = true;
                    $coursename = (string) ($course['name'] ?? '');
                    if (!empty($course['gradesurl'])) {
                        $moodledetailurl = (string) $course['gradesurl'];
                    }
                    break;
                }
            }
            if ($enrolled) {
                $detail = self::course_grade_detail($courseid, $userid, $coursename, $overviewurl, $moodledetailurl);
                $hasdetail = true;
            } else {
                $detail = [
                    'course_id' => $courseid,
                    'course_name' => self::str('employer_grades_unknown_course', null, 'Course'),
                    'back_url' => $overviewurl,
                    'moodle_url' => $moodledetailurl,
                    'denied' => true,
                    'denied_message' => self::str(
                        'employer_grades_not_enrolled',
                        null,
                        'You are not enrolled in this course.'
                    ),
                    'rows' => [],
                    'has_rows' => false,
                    'has_course_total' => false,
                    'has_course_percentage' => false,
                    'course_total' => '—',
                    'course_percentage' => '',
                    'show_weight' => false,
                    'show_contribution' => false,
                    'show_letter' => false,
                    'show_feedback' => true,
                    'graded_items' => 0,
                    'total_items' => 0,
                ];
                $hasdetail = true;
            }
        }

        return [
            'page_items' => $filtered,
            'has_items' => $filtered !== [],
            'course_count' => $coursecount,
            'graded_count' => $gradedcount,
            'pending_count' => $pendingcount,
            'grades_pct' => $gradespct,
            'avg_percentage' => $avgpct,
            'has_avg_percentage' => $pctn > 0,
            'recent_items' => $recent,
            'has_recent' => $recent !== [],
            'next_items' => $next,
            'has_next' => $next !== [],
            'has_side_panels' => $items !== [],
            'has_grade_detail' => $hasdetail,
            'grade_detail' => $detail,
            'moodle_url' => $hasdetail
                ? (string) ($detail['moodle_url'] ?? $moodleoverview)
                : $moodleoverview,
            'gtab_all' => $gtab === 'all',
            'gtab_graded' => $gtab === 'graded',
            'gtab_pending' => $gtab === 'pending',
            'tab_all_url' => $taburls['all'],
            'tab_graded_url' => $taburls['graded'],
            'tab_pending_url' => $taburls['pending'],
            'page_intro' => $hasdetail
                ? self::str(
                    'employer_grades_detail_intro',
                    null,
                    'Full Moodle user grade report for this course — weights, ranges, feedback, and contribution.'
                )
                : self::str(
                    'employer_grades_intro',
                    null,
                    'Moodle grade overview across your courses. Open a course for the full user report.'
                ),
        ];
    }

    /**
     * Badges — Moodle site + course catalogue with All / Earned tabs.
     *
     * @param int $userid
     * @param array<string,string> $urls
     * @param list<array<string,mixed>> $courses Enrolled courses (for course badges)
     * @param string $tab all|earned
     * @return array<string,mixed>
     */
    public static function badges_page(int $userid, array $urls, array $courses = [], string $tab = 'all'): array {
        global $CFG;

        $tab = ($tab === 'earned') ? 'earned' : 'all';
        $moodleurl = (string) ($urls['moodle_badges']
            ?? (new \moodle_url('/badges/mybadges.php'))->out(false));
        $taballurl = (new \moodle_url('/local/launchpad/employee.php', [
            'view' => 'badges',
            'tab' => 'all',
        ]))->out(false);
        $tabearnedurl = (new \moodle_url('/local/launchpad/employee.php', [
            'view' => 'badges',
            'tab' => 'earned',
        ]))->out(false);

        $empty = [
            'page_items' => [],
            'has_items' => false,
            'earned_items' => [],
            'has_earned_items' => false,
            'badges_enabled' => !empty($CFG->enablebadges),
            'badges_earned' => 0,
            'badges_total' => 0,
            'badges_pct' => 0,
            'has_recent' => false,
            'recent_items' => [],
            'next_items' => [],
            'has_next' => false,
            'has_side_panels' => false,
            'badges_json' => '[]',
            'moodle_url' => $moodleurl,
            'tab_all' => $tab === 'all',
            'tab_earned' => $tab === 'earned',
            'tab_all_url' => $taballurl,
            'tab_earned_url' => $tabearnedurl,
        ];

        if ($userid <= 0 || empty($CFG->enablebadges)) {
            return $empty;
        }

        try {
            require_once($CFG->libdir . '/badgeslib.php');

            // Prefer enrolments from Moodle when the shell course list is empty / incomplete.
            $courseids = [];
            foreach ($courses as $course) {
                $cid = (int) ($course['id'] ?? 0);
                if ($cid > 0) {
                    $courseids[$cid] = $cid;
                }
            }
            if ($courseids === []) {
                foreach (enrol_get_users_courses($userid, true) as $course) {
                    $courseids[(int) $course->id] = (int) $course->id;
                }
            }

            $catalogue = [];
            // Site badges (active) available in Moodle.
            // perpage 0 = no SQL LIMIT (full Moodle catalogue for this scope).
            foreach (badges_get_badges(BADGE_TYPE_SITE, 0, 'name', 'ASC', 0, 0, $userid) as $badge) {
                $catalogue[(int) $badge->id] = $badge;
            }
            // Custom / course badges from enrolled courses.
            if (!empty($CFG->badges_allowcoursebadges)) {
                foreach ($courseids as $cid) {
                    foreach (badges_get_badges(BADGE_TYPE_COURSE, $cid, 'name', 'ASC', 0, 0, $userid) as $badge) {
                        $catalogue[(int) $badge->id] = $badge;
                    }
                }
            }

            // Always include issued badges (awarded to this user).
            $issued = badges_get_user_badges($userid, 0, 0, 0);
            foreach ($issued as $badge) {
                $bid = (int) $badge->id;
                if (!isset($catalogue[$bid])) {
                    $catalogue[$bid] = $badge;
                } else {
                    $catalogue[$bid]->dateissued = $badge->dateissued ?? ($catalogue[$bid]->dateissued ?? null);
                    $catalogue[$bid]->uniquehash = $badge->uniquehash ?? ($catalogue[$bid]->uniquehash ?? '');
                }
            }

            $items = [];
            $recent = [];
            $earnedcount = 0;
            foreach ($catalogue as $badge) {
                $bid = (int) ($badge->id ?? 0);
                if ($bid <= 0) {
                    continue;
                }
                $type = (int) ($badge->type ?? BADGE_TYPE_SITE);
                $courseid = (int) ($badge->courseid ?? 0);
                try {
                    $context = ($type === BADGE_TYPE_SITE || $courseid <= 0)
                        ? \context_system::instance()
                        : \context_course::instance($courseid);
                } catch (\Throwable $e) {
                    $context = \context_system::instance();
                }
                $image = \moodle_url::make_pluginfile_url(
                    $context->id,
                    'badges',
                    'badgeimage',
                    $bid,
                    '/',
                    'f1'
                )->out(false);

                $earned = !empty($badge->dateissued) || !empty($badge->uniquehash);
                $hash = (string) ($badge->uniquehash ?? '');
                $description = self::plain_text(format_string((string) ($badge->description ?? '')));
                if ($description === '') {
                    $description = self::str(
                        'employer_badge_how_default',
                        null,
                        'Complete the Moodle criteria for this badge.'
                    );
                }

                if ($earned) {
                    $earnedcount++;
                    $detail = !empty($badge->dateissued)
                        ? self::str(
                            'employer_badge_earned_on',
                            userdate((int) $badge->dateissued, get_string('strftimedate', 'langconfig')),
                            'Earned {$a}'
                        )
                        : self::str('employer_badge_earned', null, 'Earned');
                    // Moodle issued-badge page.
                    $url = $hash !== ''
                        ? (new \moodle_url('/badges/badge.php', ['hash' => $hash]))->out(false)
                        : $moodleurl;
                } else {
                    $detail = $description;
                    // Moodle badge overview (criteria / details) for available badges.
                    $url = (new \moodle_url('/badges/overview.php', ['id' => $bid]))->out(false);
                }

                $statuspill = $earned
                    ? self::str('employer_badge_status_earned', null, 'Earned')
                    : self::str('employer_badge_status_locked', null, 'Locked');
                $scopelabel = ($type === BADGE_TYPE_COURSE)
                    ? self::str('employer_badge_scope_course', null, 'Course badge')
                    : self::str('employer_badge_scope_site', null, 'Site badge');

                $row = [
                    'id' => $bid,
                    'name' => self::plain_text(format_string((string) ($badge->name ?? ''))),
                    'detail' => $detail,
                    'description' => $description,
                    'imageurl' => $image,
                    'url' => $url,
                    'moodle_url' => $url,
                    'earned' => $earned,
                    'css' => $earned ? 'on' : '',
                    'status_pill' => $statuspill,
                    'scope_label' => $scopelabel,
                    'dateissued' => (int) ($badge->dateissued ?? 0),
                    'date_label' => !empty($badge->dateissued)
                        ? userdate((int) $badge->dateissued, get_string('strftimedate', 'langconfig'))
                        : '',
                    'has_date' => !empty($badge->dateissued),
                    'has_link' => true,
                ];
                $items[] = $row;
                if ($earned) {
                    $recent[] = $row;
                }
            }

            // All tab: name A–Z. Earned tab / recent: newest first.
            usort($items, static fn(array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']));
            usort($recent, static fn(array $a, array $b): int => ($b['dateissued'] <=> $a['dateissued']));
            $recent = array_slice($recent, 0, 6);
            $next = array_values(array_filter($items, static fn(array $row): bool => empty($row['earned'])));
            $next = array_slice($next, 0, 6);

            $total = count($items);
            $pct = $total > 0 ? (int) round(($earnedcount / $total) * 100) : 0;
            $earneditems = array_values(array_filter($items, static fn(array $row): bool => !empty($row['earned'])));

            $popupdata = [];
            foreach ($items as $row) {
                $popupdata[(string) $row['id']] = [
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'description' => $row['description'],
                    'detail' => $row['detail'],
                    'imageurl' => $row['imageurl'],
                    'moodle_url' => $row['moodle_url'],
                    'earned' => !empty($row['earned']),
                    'status_pill' => $row['status_pill'],
                    'scope_label' => $row['scope_label'],
                    'date_label' => $row['date_label'],
                ];
            }

            return array_merge($empty, [
                'page_items' => $items,
                'has_items' => $items !== [],
                'earned_items' => $earneditems,
                'has_earned_items' => $earneditems !== [],
                'badges_enabled' => true,
                'badges_earned' => $earnedcount,
                'badges_total' => $total,
                'badges_pct' => $pct,
                'has_recent' => $recent !== [],
                'recent_items' => $recent,
                'next_items' => $next,
                'has_next' => $next !== [],
                'has_side_panels' => $items !== [],
                'badges_json' => json_encode(
                    $popupdata,
                    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
                ) ?: '[]',
            ]);
        } catch (\Throwable $e) {
            debugging('employer_tools badges: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return $empty;
        }
    }

    /**
     * Calendar — upcoming Moodle events; rows open Moodle day view.
     *
     * @param int $userid
     * @param list<array<string,mixed>> $courses
     * @return array<string,mixed>
     */
    public static function calendar_page(int $userid, array $courses): array {
        global $CFG;

        $moodleurl = (new \moodle_url('/calendar/view.php', ['view' => 'month']))->out(false);
        $empty = array_merge([
            'page_items' => [],
            'has_items' => false,
            'moodle_url' => $moodleurl,
            'page_intro' => self::str(
                'employer_calendar_intro',
                null,
                'Upcoming events from your Moodle calendar. Open Moodle for the full month view.'
            ),
        ], self::crumbs([
            ['label' => self::str('employer_nav_calendar', null, 'Calendar'), 'url' => ''],
        ]));

        if ($userid <= 0) {
            return $empty;
        }

        try {
            require_once($CFG->dirroot . '/calendar/lib.php');

            $courseids = [];
            $coursenames = [];
            foreach ($courses as $c) {
                $cid = (int) ($c['id'] ?? 0);
                if ($cid <= 0) {
                    continue;
                }
                $courseids[] = $cid;
                $coursenames[$cid] = (string) ($c['name'] ?? '');
            }

            $tstart = time() - DAYSECS;
            $tend = time() + (90 * DAYSECS);
            $raw = calendar_get_legacy_events(
                $tstart,
                $tend,
                $userid,
                true,
                $courseids === [] ? true : $courseids,
                true,
                true
            );

            $items = [];
            foreach ($raw as $event) {
                $courseid = (int) ($event->courseid ?? 0);
                $items[] = [
                    'id' => (int) $event->id,
                    'name' => self::plain_text(format_string($event->name)),
                    'when' => userdate((int) $event->timestart, get_string('strftimedatetimeshort', 'langconfig')),
                    'timestart' => (int) $event->timestart,
                    'course_name' => $courseid > 0
                        ? ($coursenames[$courseid] ?? '')
                        : self::str('employer_calendar_personal', null, 'Personal'),
                    'url' => (new \moodle_url('/calendar/view.php', [
                        'view' => 'day',
                        'time' => (int) $event->timestart,
                    ]))->out(false),
                ];
            }
            usort($items, static fn(array $a, array $b): int => ($a['timestart'] <=> $b['timestart']));

            return array_merge($empty, [
                'page_items' => $items,
                'has_items' => $items !== [],
            ]);
        } catch (\Throwable $e) {
            debugging('employer_tools calendar: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return $empty;
        }
    }

    /**
     * Messages — Moodle conversation overview (favourites / private / group) + detail panel.
     *
     * List: employee.php?view=messages&mtab=all|starred|private|group
     * Detail: &convid={id} loads recent messages; reply stays in Moodle.
     *
     * @param int $userid
     * @param array<string,string> $urls
     * @param string $mtab all|starred|private|group
     * @param int $convid Selected conversation id (0 = none)
     * @param bool $msgsent
     * @param string $msgerr
     * @return array<string,mixed>
     */
    public static function messages_page(
        int $userid,
        array $urls,
        string $mtab = 'all',
        int $convid = 0,
        bool $msgsent = false,
        string $msgerr = ''
    ): array {
        $allowedtabs = ['all', 'starred', 'private', 'group'];
        $mtab = in_array($mtab, $allowedtabs, true) ? $mtab : 'all';

        $moodleurl = (string) ($urls['moodle_messages']
            ?? (new \moodle_url('/message/index.php'))->out(false));

        $taburls = [];
        foreach ($allowedtabs as $key) {
            $params = ['view' => 'messages', 'mtab' => $key];
            if ($convid > 0) {
                $params['convid'] = $convid;
            }
            $taburls[$key] = (new \moodle_url('/local/launchpad/employee.php', $params))->out(false);
        }

        $flash = self::messages_flash($msgsent, $msgerr);

        $empty = [
            'page_items' => [],
            'has_items' => false,
            'conversation_count' => 0,
            'unread_conversations' => 0,
            'has_unread_conversations' => false,
            'moodle_url' => $moodleurl,
            'mtab_all' => $mtab === 'all',
            'mtab_starred' => $mtab === 'starred',
            'mtab_private' => $mtab === 'private',
            'mtab_group' => $mtab === 'group',
            'tab_all_url' => $taburls['all'],
            'tab_starred_url' => $taburls['starred'],
            'tab_private_url' => $taburls['private'],
            'tab_group_url' => $taburls['group'],
            'has_selected' => false,
            'selected' => null,
            'has_flash' => $flash !== null,
            'flash' => $flash,
        ];

        if ($userid <= 0) {
            return $empty;
        }

        try {
            // Opening a thread marks it read before listing — Moodle drawer behaviour.
            if ($convid > 0) {
                try {
                    \core_message\api::mark_all_messages_as_read($userid, $convid);
                } catch (\Throwable $e) {
                    debugging('employer_tools messages mark read: ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }

            // Moodle overview loads favourites + private (+ self) + groups.
            $conversations = \core_message\api::get_conversations($userid, 0, 50, null, null, true);

            $unread = 0;
            try {
                $user = \core_user::get_user($userid);
                if ($user) {
                    $unread = (int) \core_message\api::count_unread_conversations($user);
                }
            } catch (\Throwable $e) {
                debugging('employer_tools messages unread: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }

            $items = [];
            foreach ($conversations as $conv) {
                $row = self::format_conversation_row($userid, $conv, $mtab, $convid);
                if ($row === null) {
                    continue;
                }
                $items[] = $row;
            }

            // Keep Moodle ordering (recent activity first); filter tabs client-side via PHP.
            $filtered = array_values(array_filter($items, static function (array $row) use ($mtab): bool {
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

            $selected = null;
            if ($convid > 0) {
                foreach ($items as $row) {
                    if ((int) $row['id'] === $convid) {
                        $selected = self::conversation_detail($userid, $row, $moodleurl, $mtab);
                        break;
                    }
                }
                // If selected not in list (edge), still try Moodle fetch.
                if ($selected === null) {
                    $selected = self::conversation_detail($userid, [
                        'id' => $convid,
                        'name' => self::str('employer_messages_untitled', null, 'Conversation'),
                        'subname' => '',
                        'imageurl' => '',
                        'has_image' => false,
                        'type_label' => '',
                        'moodle_url' => (new \moodle_url('/message/index.php', ['convid' => $convid]))->out(false),
                    ], $moodleurl, $mtab);
                }
            }

            return array_merge($empty, [
                'page_items' => $filtered,
                'has_items' => $filtered !== [],
                'conversation_count' => count($items),
                'unread_conversations' => $unread,
                'has_unread_conversations' => $unread > 0,
                'has_selected' => $selected !== null,
                'selected' => $selected,
            ]);
        } catch (\Throwable $e) {
            debugging('employer_tools messages: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return $empty;
        }
    }

    /**
     * Map a Moodle conversation object into a list-row for the Employer overview.
     *
     * @param int $userid
     * @param \stdClass $conv
     * @param string $mtab
     * @param int $selectedid
     * @return array<string,mixed>|null
     */
    private static function format_conversation_row(
        int $userid,
        \stdClass $conv,
        string $mtab,
        int $selectedid
    ): ?array {
        $convid = (int) ($conv->id ?? 0);
        if ($convid <= 0) {
            return null;
        }

        $type = (int) ($conv->type ?? \core_message\api::MESSAGE_CONVERSATION_TYPE_INDIVIDUAL);
        $isgroup = $type === \core_message\api::MESSAGE_CONVERSATION_TYPE_GROUP;
        $isself = $type === \core_message\api::MESSAGE_CONVERSATION_TYPE_SELF;
        $isprivate = $type === \core_message\api::MESSAGE_CONVERSATION_TYPE_INDIVIDUAL;

        $name = trim(self::plain_text((string) ($conv->name ?? '')));
        $imageurl = trim((string) ($conv->imageurl ?? ''));
        $otheruserid = 0;

        if ($isself) {
            if ($name === '') {
                $name = get_string('selfconversation', 'core_message');
            }
        } else if ($isprivate && !empty($conv->members) && is_iterable($conv->members)) {
            foreach ($conv->members as $member) {
                $mid = (int) ($member->id ?? 0);
                if ($mid === $userid || $mid <= 0) {
                    continue;
                }
                $otheruserid = $mid;
                if ($name === '') {
                    $name = trim(self::plain_text((string) ($member->fullname ?? '')));
                }
                if ($imageurl === '' && !empty($member->profileimageurl)) {
                    $imageurl = (string) $member->profileimageurl;
                }
                break;
            }
        }

        if ($name === '') {
            $name = self::str('employer_messages_untitled', null, 'Conversation');
        }

        // Moodle subname is usually a course shortname for group chats; keep that only.
        $subname = trim(self::plain_text((string) ($conv->subname ?? '')));
        if ($isself && $subname === '') {
            $subname = self::str('employer_messages_self_sub', null, 'Notes to yourself');
        }

        $preview = '';
        $previewprefix = '';
        $when = '';
        $whensort = 0;
        if (!empty($conv->messages) && is_array($conv->messages)) {
            $msg = reset($conv->messages);
            if ($msg) {
                $preview = self::plain_text(strip_tags((string) ($msg->text ?? '')));
                if (\core_text::strlen($preview) > 110) {
                    $preview = \core_text::substr($preview, 0, 107) . '…';
                }
                $from = (int) ($msg->useridfrom ?? 0);
                if ($from === $userid) {
                    $previewprefix = self::str('employer_messages_you_prefix', null, 'You:');
                } else if (!$isprivate) {
                    // Group / multi: show sender first name when available.
                    foreach ((array) ($conv->members ?? []) as $member) {
                        if ((int) ($member->id ?? 0) === $from) {
                            $sender = trim(self::plain_text((string) ($member->fullname ?? '')));
                            if ($sender !== '') {
                                $parts = preg_split('/\s+/', $sender);
                                $previewprefix = ($parts[0] ?? $sender) . ':';
                            }
                            break;
                        }
                    }
                }
                $whensort = (int) ($msg->timecreated ?? 0);
                if ($whensort > 0) {
                    $when = self::message_when_label($whensort);
                }
            }
        } else if ($isself) {
            $preview = self::str(
                'employer_messages_self_empty',
                null,
                'Your personal space in Moodle — open it to add a note.'
            );
        }

        $convunread = (int) ($conv->unreadcount ?? 0);
        $portalurl = (new \moodle_url('/local/launchpad/employee.php', [
            'view' => 'messages',
            'mtab' => $mtab,
            'convid' => $convid,
        ]))->out(false);
        $moodleconvurl = (new \moodle_url('/message/index.php', ['convid' => $convid]))->out(false);

        if ($isgroup) {
            $typelabel = self::str('employer_messages_type_group', null, 'Group');
        } else if ($isself) {
            $typelabel = self::str('employer_messages_type_self', null, 'Personal');
        } else {
            $typelabel = self::str('employer_messages_type_private', null, 'Private');
        }

        return [
            'id' => $convid,
            'name' => $name,
            'subname' => $subname,
            'has_subname' => $subname !== '',
            'preview' => $preview,
            'has_preview' => $preview !== '',
            'preview_prefix' => $previewprefix,
            'has_preview_prefix' => $previewprefix !== '',
            'when' => $when,
            'has_when' => $when !== '',
            'unread' => $convunread,
            'has_unread' => $convunread > 0,
            'isfavourite' => !empty($conv->isfavourite),
            'ismuted' => !empty($conv->ismuted),
            'is_group' => $isgroup,
            'is_self' => $isself,
            'is_private' => $isprivate,
            'type_label' => $typelabel,
            'imageurl' => $imageurl,
            'has_image' => $imageurl !== '',
            'initials' => self::conversation_initials($name),
            'is_selected' => $selectedid > 0 && $selectedid === $convid,
            'url' => $portalurl,
            'moodle_url' => $moodleconvurl,
            'other_userid' => $otheruserid,
        ];
    }

    /**
     * Load a short Moodle thread overview for the detail panel.
     *
     * @param int $userid
     * @param array<string,mixed> $row
     * @param string $moodleurl
     * @return array<string,mixed>
     */
    private static function conversation_detail(int $userid, array $row, string $moodleurl, string $mtab = 'all'): array {
        $convid = (int) ($row['id'] ?? 0);
        $messages = [];
        $canreply = false;

        try {
            $canreply = $convid > 0 && \core_message\api::can_send_message_to_conversation($userid, $convid);
        } catch (\Throwable $e) {
            debugging('employer_tools messages can_reply: ' . $e->getMessage(), DEBUG_DEVELOPER);
            $canreply = false;
        }

        try {
            $full = \core_message\api::get_conversation(
                $userid,
                $convid,
                false,
                false,
                0,
                0,
                40,
                0,
                true
            );
            if ($full && !empty($full->messages) && is_array($full->messages)) {
                // API returns newest first; show oldest→newest in the panel.
                $slice = array_reverse(array_slice($full->messages, 0, 40));
                foreach ($slice as $msg) {
                    $from = (int) ($msg->useridfrom ?? 0);
                    $text = self::plain_text(strip_tags((string) ($msg->text ?? '')));
                    if ($text === '') {
                        continue;
                    }
                    $messages[] = [
                        'text' => $text,
                        'when' => !empty($msg->timecreated)
                            ? self::message_when_label((int) $msg->timecreated)
                            : '',
                        'is_mine' => $from === $userid,
                        'from_label' => $from === $userid
                            ? self::str('employer_messages_you', null, 'You')
                            : (string) ($row['name'] ?? ''),
                    ];
                }
            }
            if ($full && empty($row['name'])) {
                $row['name'] = self::plain_text((string) ($full->name ?? $row['name']));
            }
            if ($full && isset($full->cansendmessagetoconversation)) {
                $canreply = !empty($full->cansendmessagetoconversation);
            }
        } catch (\Throwable $e) {
            debugging('employer_tools messages detail: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        return [
            'id' => $convid,
            'name' => (string) ($row['name'] ?? ''),
            'subname' => (string) ($row['subname'] ?? ''),
            'has_subname' => !empty($row['subname']),
            'type_label' => (string) ($row['type_label'] ?? ''),
            'imageurl' => (string) ($row['imageurl'] ?? ''),
            'has_image' => !empty($row['has_image']),
            'initials' => (string) ($row['initials'] ?? self::conversation_initials((string) ($row['name'] ?? 'C'))),
            'messages' => $messages,
            'has_messages' => $messages !== [],
            'can_reply' => $canreply,
            'cannot_reply' => !$canreply,
            'action_url' => (new \moodle_url('/local/launchpad/employee_action.php'))->out(false),
            'sesskey' => sesskey(),
            'mtab' => $mtab,
            'moodle_url' => (string) ($row['moodle_url']
                ?? (new \moodle_url('/message/index.php', ['convid' => $convid]))->out(false)),
            'open_all_url' => $moodleurl,
        ];
    }

    /**
     * Flash banner after send_message redirect.
     *
     * @param bool $msgsent
     * @param string $msgerr
     * @return array<string,mixed>|null
     */
    private static function messages_flash(bool $msgsent, string $msgerr): ?array {
        if ($msgsent) {
            return [
                'ok' => true,
                'text' => self::str('employer_messages_send_ok', null, 'Message sent.'),
            ];
        }
        if ($msgerr === '') {
            return null;
        }
        $map = [
            'empty' => self::str('employer_messages_send_empty', null, 'Type a message before sending.'),
            'invalid' => self::str('employer_messages_send_invalid', null, 'That conversation could not be opened.'),
            'toolong' => self::str('employer_messages_send_toolong', null, 'That message is too long.'),
            'denied' => self::str(
                'employer_messages_send_denied',
                null,
                'You cannot reply to this conversation. Check Moodle messaging permissions.'
            ),
            'failed' => self::str(
                'employer_messages_send_failed',
                null,
                'Moodle could not deliver the message. Try again or open Messages in Moodle.'
            ),
        ];
        return [
            'ok' => false,
            'text' => $map[$msgerr] ?? $map['failed'],
        ];
    }

    /**
     * Moodle-style short timestamp for conversation lists.
     *
     * @param int $timestamp
     * @return string
     */
    private static function message_when_label(int $timestamp): string {
        if ($timestamp <= 0) {
            return '';
        }
        $starttoday = usergetmidnight(time());
        if ($timestamp >= $starttoday) {
            return userdate($timestamp, get_string('strftimetime24', 'langconfig'));
        }
        return userdate($timestamp, get_string('strftimedatefullshort', 'langconfig'));
    }

    /**
     * Two-letter initials fallback when no avatar URL.
     *
     * @param string $name
     * @return string
     */
    private static function conversation_initials(string $name): string {
        $name = trim($name);
        if ($name === '') {
            return 'M';
        }
        $parts = preg_split('/\s+/', $name) ?: [];
        $a = \core_text::strtoupper(\core_text::substr($parts[0] ?? $name, 0, 1));
        $b = isset($parts[1])
            ? \core_text::strtoupper(\core_text::substr($parts[1], 0, 1))
            : \core_text::strtoupper(\core_text::substr($parts[0] ?? $name, 1, 1));
        return $a . $b;
    }

    /**
     * Notifications — Moodle popup notifications; manage in Moodle.
     *
     * @param int $userid
     * @param array<string,string> $urls
     * @return array<string,mixed>
     */
    public static function notifications_page(int $userid, array $urls): array {
        $moodleurl = (string) ($urls['moodle_notifications']
            ?? (new \moodle_url('/message/output/popup/notifications.php'))->out(false));
        $empty = array_merge([
            'page_items' => [],
            'has_items' => false,
            'moodle_url' => $moodleurl,
            'page_intro' => self::str(
                'employer_notifications_intro',
                null,
                'Recent Moodle notifications. Open Moodle to manage or mark them as read.'
            ),
        ], self::crumbs([
            ['label' => self::str('employer_nav_notifications', null, 'Notifications'), 'url' => ''],
        ]));

        if ($userid <= 0) {
            return $empty;
        }

        try {
            $records = \message_popup\api::get_popup_notifications($userid, 'DESC', 25, 0);
            $items = [];
            foreach ($records as $n) {
                $subject = trim(self::plain_text((string) ($n->subject ?? '')));
                if ($subject === '') {
                    $subject = self::plain_text(strip_tags((string) ($n->smallmessage ?? '')));
                }
                if ($subject === '') {
                    $subject = self::str('employer_notifications_untitled', null, 'Notification');
                }
                $contexturl = trim((string) ($n->contexturl ?? ''));
                $url = $contexturl !== '' ? $contexturl : $moodleurl;
                $isunread = empty($n->timeread);
                $items[] = [
                    'id' => (int) ($n->id ?? 0),
                    'name' => $subject,
                    'when' => !empty($n->timecreated)
                        ? userdate((int) $n->timecreated, get_string('strftimedatetimeshort', 'langconfig'))
                        : '',
                    'unread' => $isunread,
                    'unread_label' => $isunread
                        ? self::str('employer_notifications_unread_chip', null, 'Unread')
                        : '',
                    'css' => $isunread ? 'is-unread' : '',
                    'url' => $url,
                ];
            }
            return array_merge($empty, [
                'page_items' => $items,
                'has_items' => $items !== [],
            ]);
        } catch (\Throwable $e) {
            debugging('employer_tools notifications: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return $empty;
        }
    }

    /**
     * @param int $courseid
     * @param int $userid
     * @return array{
     *   meta:string,
     *   grade_display:string,
     *   percentage:string,
     *   pct_num:int,
     *   has_grade:bool,
     *   has_percentage:bool
     * }
     */
    private static function course_grade_summary(int $courseid, int $userid): array {
        $empty = [
            'meta' => self::str('employer_grades_meta_na', null, 'No course total yet'),
            'grade_display' => '—',
            'percentage' => '',
            'pct_num' => 0,
            'has_grade' => false,
            'has_percentage' => false,
        ];
        try {
            $course = get_course($courseid);
            if (empty($course->showgrades)) {
                return array_merge($empty, [
                    'meta' => self::str('employer_grades_disabled_course', null, 'Grades hidden for this course'),
                ]);
            }
            $courseitem = \grade_item::fetch_course_item($courseid);
            if (!$courseitem) {
                return $empty;
            }
            if ($courseitem->is_hidden() && !has_capability(
                'moodle/grade:viewhidden',
                \context_course::instance($courseid)
            )) {
                return array_merge($empty, [
                    'meta' => self::str('employer_grades_hidden', null, 'Hidden by course'),
                ]);
            }
            $grade = new \grade_grade(['itemid' => $courseitem->id, 'userid' => $userid]);
            $grade->grade_item = $courseitem;
            $final = $grade->finalgrade;
            if ($final === null) {
                return $empty;
            }
            $display = grade_format_gradevalue($final, $courseitem);
            $percentage = '';
            $pctnum = 0;
            $haspercentage = false;
            if ($courseitem->grademax > $courseitem->grademin) {
                $fraction = ((float) $final - (float) $courseitem->grademin)
                    / ((float) $courseitem->grademax - (float) $courseitem->grademin);
                $pctnum = (int) round($fraction * 100);
                $percentage = (string) $pctnum . '%';
                $haspercentage = true;
            }
            return [
                'meta' => self::str('employer_grades_course_total', null, 'Course total'),
                'grade_display' => $display,
                'percentage' => $percentage,
                'pct_num' => $pctnum,
                'has_grade' => true,
                'has_percentage' => $haspercentage,
            ];
        } catch (\Throwable $e) {
            debugging('employer_tools grade summary: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return $empty;
        }
    }

    /**
     * Full Moodle user grade report for one enrolled course (custom UI).
     *
     * Uses gradereport_user so weights, ranges, letters, feedback, and contribution
     * match Moodle’s native User report.
     *
     * @param int $courseid
     * @param int $userid
     * @param string $coursename
     * @param string $backurl
     * @param string $moodleurl
     * @return array<string,mixed>
     */
    private static function course_grade_detail(
        int $courseid,
        int $userid,
        string $coursename,
        string $backurl,
        string $moodleurl
    ): array {
        global $CFG;

        require_once($CFG->dirroot . '/grade/lib.php');
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->dirroot . '/grade/report/lib.php');
        require_once($CFG->dirroot . '/grade/report/user/lib.php');

        $context = \context_course::instance($courseid);
        $canview = has_capability('moodle/grade:view', $context)
            || has_capability('moodle/grade:viewall', $context)
            || $userid === (int) ($GLOBALS['USER']->id ?? 0);

        $denied = [
            'course_id' => $courseid,
            'course_name' => $coursename,
            'back_url' => $backurl,
            'moodle_url' => $moodleurl,
            'course_total' => '—',
            'course_percentage' => '',
            'has_course_total' => false,
            'has_course_percentage' => false,
            'course_pct_num' => 0,
            'rows' => [],
            'has_rows' => false,
            'show_weight' => false,
            'show_contribution' => false,
            'show_letter' => false,
            'show_feedback' => true,
            'graded_items' => 0,
            'total_items' => 0,
            'denied' => true,
            'denied_message' => self::str(
                'employer_grades_denied',
                null,
                'You do not have permission to view grades in this course.'
            ),
        ];

        if (!$canview) {
            return $denied;
        }

        try {
            // Full-course regrade only — userid-scoped regrade requires $updated_item.
            grade_regrade_final_grades($courseid);

            $gpr = new \grade_plugin_return([
                'type' => 'report',
                'plugin' => 'user',
                'courseid' => $courseid,
                'userid' => $userid,
            ]);
            $report = new \gradereport_user\report\user($courseid, $gpr, $context, $userid);
            $report->fill_table();

            $showweight = !empty($report->showweight);
            $showcontribution = !empty($report->showcontributiontocoursetotal);
            $showletter = !empty($report->showlettergrade);
            $showfeedback = !empty($report->showfeedback);

            // Enrich leaf rows with module label / activity URL from gradeitemsdata.
            $metaById = [];
            foreach ($report->gradeitemsdata as $item) {
                $gid = (int) ($item['id'] ?? 0);
                if ($gid <= 0) {
                    continue;
                }
                $modlabel = '';
                $activityurl = '';
                if (!empty($item['itemmodule'])) {
                    $modlabel = get_string('pluginname', 'mod_' . $item['itemmodule']);
                    if (!empty($item['cmid'])) {
                        $activityurl = (new \moodle_url('/mod/' . $item['itemmodule'] . '/view.php', [
                            'id' => (int) $item['cmid'],
                        ]))->out(false);
                    }
                }
                $metaById[$gid] = [
                    'itemtype' => (string) ($item['itemtype'] ?? ''),
                    'modlabel' => $modlabel,
                    'activity_url' => $activityurl,
                    'has_raw' => array_key_exists('graderaw', $item) && $item['graderaw'] !== null,
                    'gradeformatted' => trim(self::plain_text(strip_tags((string) ($item['gradeformatted'] ?? '')))),
                    'percentageformatted' => trim(self::plain_text(strip_tags(
                        (string) ($item['percentageformatted'] ?? '')
                    ))),
                ];
            }

            $rows = [];
            $coursetotal = '—';
            $coursepct = '';
            $coursepctnum = 0;
            $hascoursetotal = false;
            $hascoursepct = false;
            $gradeditems = 0;
            $leafitems = 0;

            foreach ($report->tabledata as $row) {
                if (!empty($row['spacer']) || empty($row['itemname']['content'])) {
                    continue;
                }
                $rowid = (string) ($row['itemname']['id'] ?? '');
                $iscategory = str_starts_with($rowid, 'cat_');
                $itemid = 0;
                if (preg_match('/^row_(\d+)_/', $rowid, $m)) {
                    $itemid = (int) $m[1];
                } else if (preg_match('/^cat_(\d+)_/', $rowid, $m)) {
                    $itemid = (int) $m[1];
                }

                $name = self::plain_text((string) $row['itemname']['content']);
                // Moodle concatenates icons + “Aggregation” labels — normalize course total.
                if (stripos($name, 'Course total') !== false) {
                    $name = self::str('employer_grades_course_total', null, 'Course total');
                }
                if ($name === '') {
                    continue;
                }

                $meta = $metaById[$itemid] ?? null;
                $itemtype = (string) ($meta['itemtype'] ?? ($iscategory ? 'category' : ''));
                $iscourse = $itemtype === 'course'
                    || (!$iscategory && isset($row['grade']) && stripos($name, 'Course total') !== false)
                    || ($itemid > 0 && isset($metaById[$itemid]) && ($metaById[$itemid]['itemtype'] ?? '') === 'course');
                if (!$iscourse && $itemid > 0 && isset($metaById[$itemid])) {
                    $iscourse = ($metaById[$itemid]['itemtype'] ?? '') === 'course';
                }
                // Category header rows (no grade cell) vs category/course totals.
                $hasgradecell = isset($row['grade']);
                if ($iscategory && !$hasgradecell) {
                    $rows[] = [
                        'id' => $itemid,
                        'name' => $name,
                        'modlabel' => '',
                        'has_modlabel' => false,
                        'activity_url' => '',
                        'has_activity_url' => false,
                        'weight' => '—',
                        'grade' => '—',
                        'range' => '—',
                        'has_range' => false,
                        'percentage' => '—',
                        'has_percentage' => false,
                        'letter' => '—',
                        'has_letter' => false,
                        'feedback' => '',
                        'has_feedback' => false,
                        'contribution' => '—',
                        'has_contribution' => false,
                        'is_course' => false,
                        'is_category' => true,
                        'is_graded' => false,
                        'css' => 'is-category',
                        'show_weight' => $showweight,
                        'show_contribution' => $showcontribution,
                        'show_letter' => $showletter,
                        'show_feedback' => $showfeedback,
                    ];
                    continue;
                }

                $cell = static function (array $row, string $key): string {
                    if (empty($row[$key]['content'])) {
                        return '';
                    }
                    // Prefer the visible grade value; drop Moodle action menus / icons.
                    $html = (string) $row[$key]['content'];
                    $html = preg_replace('/<div[^>]*action-menu[\s\S]*?<\/div>/i', '', $html) ?? $html;
                    $html = preg_replace('/<[^>]+>/', ' ', $html) ?? $html;
                    $raw = html_entity_decode($html, ENT_QUOTES | ENT_HTML5);
                    $raw = preg_replace('/\s+/', ' ', $raw) ?? $raw;
                    $raw = trim($raw);
                    // Common Moodle chrome left after stripping tags.
                    $raw = preg_replace('/\bActions\b.*$/iu', '', $raw) ?? $raw;
                    $raw = preg_replace('/\bGrade analysis\b.*$/iu', '', $raw) ?? $raw;
                    return trim($raw);
                };

                // Prefer gradeitemsdata formatted values when present (cleaner than table HTML).
                $grade = '';
                $percentage = '';
                if (!empty($meta['gradeformatted'])) {
                    $grade = (string) $meta['gradeformatted'];
                }
                if ($grade === '') {
                    $grade = $cell($row, 'grade');
                }
                if (!empty($meta['percentageformatted'])) {
                    $percentage = (string) $meta['percentageformatted'];
                }
                if ($percentage === '') {
                    $percentage = $cell($row, 'percentage');
                }

                $weight = $cell($row, 'weight');
                $range = $cell($row, 'range');
                $letter = $cell($row, 'lettergrade');
                $feedback = $cell($row, 'feedback');
                $contribution = $cell($row, 'contributiontocoursetotal');

                $blankmarks = ['', '-', '–', '—', '&nbsp;', "\xc2\xa0"];
                if (in_array($grade, $blankmarks, true)) {
                    $grade = self::str('employer_grades_not_graded', null, 'Not graded');
                }
                $hasraw = !empty($meta['has_raw']);

                $modlabel = (string) ($meta['modlabel'] ?? '');
                $activityurl = (string) ($meta['activity_url'] ?? '');
                // Prefer module label from grade item; strip duplicated type prefix from name.
                if ($modlabel !== '' && str_starts_with($name, $modlabel)) {
                    $name = trim(substr($name, strlen($modlabel)));
                }

                if (!$iscourse && !$iscategory) {
                    $leafitems++;
                    if ($hasraw) {
                        $gradeditems++;
                    }
                }

                if ($iscourse && $hasraw) {
                    $coursetotal = $grade;
                    $hascoursetotal = true;
                    if ($percentage !== '' && $percentage !== '-' && $percentage !== '–') {
                        $coursepct = $percentage;
                        $hascoursepct = true;
                        if (preg_match('/(\d+(?:\.\d+)?)/', $percentage, $pm)) {
                            $coursepctnum = (int) round((float) $pm[1]);
                        }
                    }
                }

                $rows[] = [
                    'id' => $itemid,
                    'name' => $name,
                    'modlabel' => $modlabel,
                    'has_modlabel' => $modlabel !== '',
                    'activity_url' => $activityurl,
                    'has_activity_url' => $activityurl !== '',
                    'weight' => ($weight !== '' && $weight !== '-' && $weight !== '–') ? $weight : '—',
                    'grade' => $grade,
                    'range' => ($range !== '' && $range !== '-' && $range !== '–') ? $range : '—',
                    'has_range' => $range !== '' && $range !== '-' && $range !== '–',
                    'percentage' => ($percentage !== '' && $percentage !== '-' && $percentage !== '–')
                        ? $percentage : '—',
                    'has_percentage' => $percentage !== '' && $percentage !== '-' && $percentage !== '–',
                    'letter' => ($letter !== '' && $letter !== '-' && $letter !== '–') ? $letter : '—',
                    'has_letter' => $letter !== '' && $letter !== '-' && $letter !== '–',
                    'feedback' => $feedback,
                    'has_feedback' => $feedback !== '' && $feedback !== "\xc2\xa0",
                    'contribution' => ($contribution !== '' && $contribution !== '-' && $contribution !== '–')
                        ? $contribution : '—',
                    'has_contribution' => $contribution !== '' && $contribution !== '-' && $contribution !== '–',
                    'is_course' => $iscourse,
                    'is_category' => $iscategory && !$iscourse,
                    'is_graded' => $hasraw,
                    'css' => $iscourse
                        ? 'is-course-total'
                        : (($iscategory && !$iscourse)
                            ? 'is-category'
                            : ($hasraw ? 'is-graded' : 'is-pending')),
                    'show_weight' => $showweight,
                    'show_contribution' => $showcontribution,
                    'show_letter' => $showletter,
                    'show_feedback' => $showfeedback,
                ];
            }

            // Fallback: if tabledata yielded nothing useful, use gradeitemsdata.
            if ($rows === [] && !empty($report->gradeitemsdata)) {
                foreach ($report->gradeitemsdata as $item) {
                    $itemid = (int) ($item['id'] ?? 0);
                    $itemtype = (string) ($item['itemtype'] ?? '');
                    $iscourse = $itemtype === 'course';
                    $iscategory = $itemtype === 'category';
                    $name = trim(self::plain_text((string) ($item['itemname'] ?? '')));
                    if ($name === '' && $itemid > 0) {
                        try {
                            $gi = \grade_item::fetch(['id' => $itemid]);
                            if ($gi) {
                                $name = self::plain_text((string) $gi->get_name());
                            }
                        } catch (\Throwable $e) {
                            // Keep fallback.
                        }
                    }
                    if ($name === '') {
                        $name = $iscourse
                            ? self::str('employer_grades_course_total', null, 'Course total')
                            : self::str('employer_grades_item', null, 'Grade item');
                    }
                    $meta = $metaById[$itemid] ?? [];
                    $grade = (string) ($meta['gradeformatted'] ?? '');
                    if ($grade === '' || $grade === '-') {
                        $grade = self::str('employer_grades_not_graded', null, 'Not graded');
                    }
                    $hasraw = !empty($meta['has_raw']);
                    $percentage = (string) ($meta['percentageformatted'] ?? '');
                    if ($iscourse && $hasraw) {
                        $coursetotal = $grade;
                        $hascoursetotal = true;
                    }
                    if (!$iscourse && !$iscategory) {
                        $leafitems++;
                        if ($hasraw) {
                            $gradeditems++;
                        }
                    }
                    $rows[] = [
                        'id' => $itemid,
                        'name' => $name,
                        'modlabel' => (string) ($meta['modlabel'] ?? ''),
                        'has_modlabel' => !empty($meta['modlabel']),
                        'activity_url' => (string) ($meta['activity_url'] ?? ''),
                        'has_activity_url' => !empty($meta['activity_url']),
                        'weight' => '—',
                        'grade' => $grade,
                        'range' => '—',
                        'has_range' => false,
                        'percentage' => ($percentage !== '' && $percentage !== '-') ? $percentage : '—',
                        'has_percentage' => $percentage !== '' && $percentage !== '-',
                        'letter' => '—',
                        'has_letter' => false,
                        'feedback' => '',
                        'has_feedback' => false,
                        'contribution' => '—',
                        'has_contribution' => false,
                        'is_course' => $iscourse,
                        'is_category' => $iscategory,
                        'is_graded' => $hasraw,
                        'css' => $iscourse ? 'is-course-total' : ($iscategory ? 'is-category' : 'is-pending'),
                        'show_weight' => $showweight,
                        'show_contribution' => $showcontribution,
                        'show_letter' => $showletter,
                        'show_feedback' => $showfeedback,
                    ];
                }
            }

            return [
                'course_id' => $courseid,
                'course_name' => $coursename,
                'back_url' => $backurl,
                'moodle_url' => $moodleurl,
                'course_total' => $coursetotal,
                'course_percentage' => $coursepct,
                'course_pct_num' => $coursepctnum,
                'has_course_total' => $hascoursetotal,
                'has_course_percentage' => $hascoursepct,
                'rows' => $rows,
                'has_rows' => $rows !== [],
                'show_weight' => $showweight,
                'show_contribution' => $showcontribution,
                'show_letter' => $showletter,
                'show_feedback' => $showfeedback,
                'graded_items' => $gradeditems,
                'total_items' => $leafitems,
                'denied' => false,
                'denied_message' => '',
            ];
        } catch (\Throwable $e) {
            debugging('employer_tools grade detail: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return array_merge($denied, [
                'denied' => true,
                'denied_message' => self::str(
                    'employer_grades_load_failed',
                    null,
                    'Could not load the Moodle grade report for this course.'
                ),
            ]);
        }
    }

    /**
     * @param list<array{label:string,url?:string}|null> $parts
     * @return array{has_crumbs:bool,crumbs:list<array{label:string,url:string,has_url:bool}>}
     */
    private static function crumbs(array $parts): array {
        $crumbs = [];
        $crumbs[] = [
            'label' => self::str('employer_nav_home', null, 'Home'),
            'url' => (new \moodle_url('/local/launchpad/employee.php'))->out(false),
            'has_url' => true,
        ];
        foreach ($parts as $part) {
            if ($part === null) {
                continue;
            }
            $url = (string) ($part['url'] ?? '');
            $crumbs[] = [
                'label' => (string) $part['label'],
                'url' => $url,
                'has_url' => $url !== '',
            ];
        }
        return [
            'has_crumbs' => count($crumbs) > 1,
            'crumbs' => $crumbs,
        ];
    }

    private static function plain_text(string $value): string {
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(strip_tags($decoded));
    }

    /**
     * @param string $identifier
     * @param string|object|array|null $a
     * @param string $fallback
     */
    private static function str(string $identifier, $a = null, string $fallback = ''): string {
        if (get_string_manager()->string_exists($identifier, 'local_launchpad')) {
            $out = get_string($identifier, 'local_launchpad', $a);
            if (is_string($out) && $out !== '' && strpos($out, '[[') === false) {
                return $out;
            }
        }
        if ($fallback !== '' && $a !== null && is_scalar($a) && strpos($fallback, '{$a}') !== false) {
            return str_replace('{$a}', (string) $a, $fallback);
        }
        return $fallback !== '' ? $fallback : (is_scalar($a) ? (string) $a : '');
    }
}
