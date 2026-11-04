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
     * Notifications — Moodle popup notifications in Employer shell.
     *
     * Overview: employee.php?view=notifications
     * Filter:   &ntab=all|unread
     * Detail:   &notifid={id} (marks read via core_message API)
     *
     * @param int $userid
     * @param array<string,string> $urls
     * @param string $ntab
     * @param int $notifid
     * @param bool $notifdone flash: mark-all succeeded
     * @param string $notiferr flash: action error code
     * @return array<string,mixed>
     */
    public static function notifications_page(
        int $userid,
        array $urls,
        string $ntab = 'all',
        int $notifid = 0,
        bool $notifdone = false,
        string $notiferr = ''
    ): array {
        $ntab = in_array($ntab, ['all', 'unread'], true) ? $ntab : 'all';
        $notifid = max(0, $notifid);

        $moodleurl = (string) ($urls['moodle_notifications']
            ?? (new \moodle_url('/message/output/popup/notifications.php'))->out(false));
        $prefsurl = (string) ($urls['moodle_notification_prefs']
            ?? (new \moodle_url('/message/notificationpreferences.php'))->out(false));

        $taburls = [];
        foreach (['all', 'unread'] as $key) {
            $params = ['view' => 'notifications', 'ntab' => $key];
            if ($notifid > 0) {
                $params['notifid'] = $notifid;
            }
            $taburls[$key] = (new \moodle_url('/local/launchpad/employee.php', $params))->out(false);
        }

        $flash = self::notifications_flash($notifdone, $notiferr);
        $actionurl = (new \moodle_url('/local/launchpad/employee_action.php'))->out(false);

        $empty = array_merge([
            'page_items' => [],
            'has_items' => false,
            'notification_count' => 0,
            'unread_count' => 0,
            'has_unread' => false,
            'moodle_url' => $moodleurl,
            'prefs_url' => $prefsurl,
            'ntab_all' => $ntab === 'all',
            'ntab_unread' => $ntab === 'unread',
            'tab_all_url' => $taburls['all'],
            'tab_unread_url' => $taburls['unread'],
            'has_selected' => false,
            'selected' => null,
            'can_mark_all' => false,
            'action_url' => $actionurl,
            'sesskey' => sesskey(),
            'ntab' => $ntab,
            'has_flash' => $flash !== null,
            'flash' => $flash,
            'page_intro' => self::str(
                'employer_notifications_intro',
                null,
                'Course and site alerts from Moodle. Open one to read it, or mark all as read.'
            ),
        ], self::crumbs([
            ['label' => self::str('employer_nav_notifications', null, 'Notifications'), 'url' => ''],
        ]));

        if ($userid <= 0) {
            return $empty;
        }

        try {
            $unreadcount = (int) \message_popup\api::count_unread_popup_notifications($userid);
            $records = \message_popup\api::get_popup_notifications($userid, 'DESC', 50, 0);

            // Opening a notification marks it read — Moodle popup behaviour.
            if ($notifid > 0) {
                $selectedrecord = self::find_popup_notification($userid, $notifid, $records);
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
                        debugging('employer_tools notifications mark read: ' . $e->getMessage(), DEBUG_DEVELOPER);
                    }
                }
            }

            $allitems = [];
            $selected = null;
            foreach ($records as $n) {
                $row = self::format_notification_row($n, $ntab, $notifid, $moodleurl);
                if ($row === null) {
                    continue;
                }
                $allitems[] = $row;
                if (!empty($row['is_selected'])) {
                    $selected = self::format_notification_detail($n, $moodleurl, $ntab);
                }
            }

            // Selected id may fall outside the first page — still show detail.
            if ($selected === null && $notifid > 0) {
                $extra = self::find_popup_notification($userid, $notifid, $records);
                if ($extra !== null) {
                    $selected = self::format_notification_detail($extra, $moodleurl, $ntab);
                    // Ensure it appears in the list when filtering allows.
                    $extrarow = self::format_notification_row($extra, $ntab, $notifid, $moodleurl);
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

            $total = count($records);

            return array_merge($empty, [
                'page_items' => $items,
                'has_items' => $items !== [],
                'notification_count' => $total,
                'unread_count' => $unreadcount,
                'has_unread' => $unreadcount > 0,
                'can_mark_all' => $unreadcount > 0,
                'has_selected' => $selected !== null,
                'selected' => $selected,
            ]);
        } catch (\Throwable $e) {
            debugging('employer_tools notifications: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return $empty;
        }
    }

    /**
     * Load a popup notification owned by the user (list hit or DB).
     *
     * @param int $userid
     * @param int $notifid
     * @param list<\stdClass> $records
     * @return \stdClass|null
     */
    private static function find_popup_notification(int $userid, int $notifid, array $records): ?\stdClass {
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
            $sql = "SELECT n.id, n.useridfrom, n.useridto,
                           n.subject, n.fullmessage, n.fullmessageformat,
                           n.fullmessagehtml, n.smallmessage, n.contexturl,
                           n.contexturlname, n.timecreated, n.component,
                           n.eventtype, n.timeread, n.customdata
                      FROM {notifications} n
                     WHERE n.id = ?
                       AND n.useridto = ?
                       AND n.id IN (SELECT notificationid FROM {message_popup_notifications})";
            $rec = $DB->get_record_sql($sql, [$notifid, $userid]);
            return $rec ?: null;
        } catch (\Throwable $e) {
            debugging('employer_tools find notification: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
    }

    /**
     * Inbox row for one Moodle popup notification.
     *
     * @param \stdClass $n
     * @param string $ntab
     * @param int $selectedid
     * @param string $moodleurl
     * @return array<string,mixed>|null
     */
    private static function format_notification_row(
        \stdClass $n,
        string $ntab,
        int $selectedid,
        string $moodleurl
    ): ?array {
        $id = (int) ($n->id ?? 0);
        if ($id <= 0) {
            return null;
        }

        $subject = self::notification_subject($n);
        $preview = self::plain_text((string) ($n->smallmessage ?? ''));
        if ($preview === '' || $preview === $subject) {
            $preview = self::plain_text(strip_tags((string) ($n->fullmessage ?? '')));
        }
        if (\core_text::strlen($preview) > 120) {
            $preview = \core_text::substr($preview, 0, 117) . '…';
        }

        $isunread = empty($n->timeread);
        $when = !empty($n->timecreated)
            ? self::message_when_label((int) $n->timecreated)
            : '';
        $type = self::notification_type_label($n);

        $listurl = (new \moodle_url('/local/launchpad/employee.php', array_filter([
            'view' => 'notifications',
            'ntab' => $ntab !== 'all' ? $ntab : null,
            'notifid' => $id,
        ])))->out(false);

        return [
            'id' => $id,
            'name' => $subject,
            'preview' => $preview,
            'has_preview' => $preview !== '',
            'when' => $when,
            'has_when' => $when !== '',
            'type_label' => $type,
            'has_type' => $type !== '',
            'unread' => $isunread,
            'unread_label' => $isunread
                ? self::str('employer_notifications_unread_chip', null, 'Unread')
                : '',
            'is_selected' => $id === $selectedid,
            'css' => trim(($isunread ? 'is-unread' : '') . ($id === $selectedid ? ' is-selected' : '')),
            'url' => $listurl,
            'moodle_url' => $moodleurl,
        ];
    }

    /**
     * Detail pane for one Moodle popup notification.
     *
     * @param \stdClass $n
     * @param string $moodleurl
     * @param string $ntab
     * @return array<string,mixed>
     */
    private static function format_notification_detail(\stdClass $n, string $moodleurl, string $ntab): array {
        $id = (int) ($n->id ?? 0);
        $subject = self::notification_subject($n);
        $contexturl = trim((string) ($n->contexturl ?? ''));
        $contextname = trim(self::plain_text((string) ($n->contexturlname ?? '')));
        if ($contextname === '') {
            $contextname = self::str('employer_notifications_open_related', null, 'Open related');
        }

        $bodyhtml = '';
        $html = trim((string) ($n->fullmessagehtml ?? ''));
        if ($html !== '') {
            $bodyhtml = format_text($html, FORMAT_HTML, ['filter' => false, 'para' => false]);
        } else {
            $format = (int) ($n->fullmessageformat ?? FORMAT_MOODLE);
            $bodyhtml = format_text((string) ($n->fullmessage ?? ''), $format, ['filter' => false]);
        }
        if (trim(strip_tags($bodyhtml)) === '') {
            $small = self::plain_text((string) ($n->smallmessage ?? ''));
            $bodyhtml = $small !== '' ? s($small) : s(self::str(
                'employer_notifications_no_body',
                null,
                'No additional detail for this notification.'
            ));
        }

        $when = !empty($n->timecreated)
            ? userdate((int) $n->timecreated, get_string('strftimedatetimeshort', 'langconfig'))
            : '';
        $type = self::notification_type_label($n);
        $isunread = empty($n->timeread);

        return [
            'id' => $id,
            'name' => $subject,
            'body_html' => $bodyhtml,
            'when' => $when,
            'has_when' => $when !== '',
            'type_label' => $type,
            'has_type' => $type !== '',
            'unread' => $isunread,
            'has_context' => $contexturl !== '',
            'context_url' => $contexturl,
            'context_label' => $contextname,
            'moodle_url' => (new \moodle_url('/message/output/popup/notifications.php', [
                'notificationid' => $id,
            ]))->out(false),
            'ntab' => $ntab,
            'back_url' => (new \moodle_url('/local/launchpad/employee.php', array_filter([
                'view' => 'notifications',
                'ntab' => $ntab !== 'all' ? $ntab : null,
            ])))->out(false),
        ];
    }

    /**
     * @param \stdClass $n
     * @return string
     */
    private static function notification_subject(\stdClass $n): string {
        $subject = trim(self::plain_text((string) ($n->subject ?? '')));
        if ($subject === '') {
            $subject = self::plain_text(strip_tags((string) ($n->smallmessage ?? '')));
        }
        if ($subject === '') {
            $subject = self::str('employer_notifications_untitled', null, 'Notification');
        }
        return $subject;
    }

    /**
     * Human label from Moodle component / eventtype when available.
     *
     * @param \stdClass $n
     * @return string
     */
    private static function notification_type_label(\stdClass $n): string {
        $component = trim((string) ($n->component ?? ''));
        if ($component === '') {
            return self::str('employer_notifications_type_site', null, 'Site');
        }
        try {
            if (get_string_manager()->string_exists('pluginname', $component)) {
                $name = get_string('pluginname', $component);
                if (is_string($name) && $name !== '' && strpos($name, '[[') === false) {
                    return $name;
                }
            }
        } catch (\Throwable $e) {
            // Fall through to component short name.
        }
        $short = preg_replace('/^(mod_|local_|block_|tool_)/', '', $component);
        return $short !== '' ? ucwords(str_replace('_', ' ', (string) $short)) : $component;
    }

    /**
     * @param bool $done
     * @param string $err
     * @return array{ok:bool,text:string}|null
     */
    private static function notifications_flash(bool $done, string $err): ?array {
        if ($done) {
            return [
                'ok' => true,
                'text' => self::str(
                    'employer_notifications_mark_all_ok',
                    null,
                    'All notifications marked as read.'
                ),
            ];
        }
        if ($err === '') {
            return null;
        }
        $map = [
            'denied' => self::str(
                'employer_notifications_action_denied',
                null,
                'That notification could not be updated.'
            ),
            'failed' => self::str(
                'employer_notifications_action_failed',
                null,
                'Moodle could not update notifications. Try again.'
            ),
        ];
        return [
            'ok' => false,
            'text' => $map[$err] ?? $map['failed'],
        ];
    }

    /**
     * Mustache string bag for employer_profile (shells merge under `strings`).
     *
     * @return array<string,string>
     */
    public static function profile_strings(): array {
        return [
            'profile_intro' => self::str(
                'employer_profile_intro',
                null,
                'Your Moodle account details, editable profile fields, and preference links.'
            ),
            'profile_tabs_aria' => self::str('employer_profile_tabs_aria', null, 'Profile sections'),
            'profile_tab_overview' => self::str('employer_profile_tab_overview', null, 'Overview'),
            'profile_tab_edit' => self::str('employer_profile_tab_edit', null, 'Edit'),
            'profile_tab_preferences' => self::str('employer_profile_tab_preferences', null, 'Preferences'),
            'profile_edit_cta' => self::str('employer_profile_edit_cta', null, 'Edit profile'),
            'profile_edit_moodle' => self::str('employer_profile_edit_moodle', null, 'Full editor in Moodle'),
            'profile_edit_external' => self::str('employer_profile_edit_external', null, 'Edit in identity provider'),
            'profile_open_moodle' => self::str('employer_profile_open_moodle', null, 'Open public profile'),
            'profile_empty_title' => self::str('employer_profile_empty_title', null, 'No profile details yet'),
            'profile_empty_body' => self::str(
                'employer_profile_empty_body',
                null,
                'Add your city, contact details, or bio so mentors and peers can find you.'
            ),
            'profile_edit_heading' => self::str('employer_profile_edit_heading', null, 'Edit profile'),
            'profile_edit_help' => self::str(
                'employer_profile_edit_help',
                null,
                'Changes save to your Moodle user account. Locked fields are controlled by your login method.'
            ),
            'profile_edit_note' => self::str(
                'employer_profile_edit_note',
                null,
                'Picture upload and advanced custom fields open in Moodle’s full profile editor.'
            ),
            'profile_custom_heading' => self::str('employer_profile_custom_heading', null, 'Additional fields'),
            'profile_save' => self::str('employer_profile_save', null, 'Save profile'),
            'profile_cancel' => self::str('employer_profile_cancel', null, 'Cancel'),
            'profile_edit_unavailable_title' => self::str(
                'employer_profile_edit_unavailable_title',
                null,
                'Edit this profile in Moodle'
            ),
            'profile_edit_unavailable_body' => self::str(
                'employer_profile_edit_unavailable_body',
                null,
                'Your account settings require Moodle’s profile editor (or an external identity provider).'
            ),
            'profile_prefs_lead' => self::str(
                'employer_profile_prefs_lead',
                null,
                'Account, communication, and system preferences — edited here when possible, or opened in Moodle for heavy tools.'
            ),
            'native_tools_note' => self::str(
                'employer_native_tools_note',
                null,
                'Full grades, messaging, calendar editing, and notification settings stay in Moodle.'
            ),
            'pref_back' => self::str('employer_pref_back', null, 'Back to preferences'),
            'pref_save' => self::str('employer_pref_save', null, 'Save preferences'),
            'pref_cancel' => self::str('employer_pref_cancel', null, 'Cancel'),
            'pref_open_moodle' => self::str('employer_pref_open_moodle', null, 'Open in Moodle'),
            'pref_open_portal_edit' => self::str('employer_pref_open_portal_edit', null, 'Edit in LaunchPad'),
            'pref_advanced_moodle' => self::str('employer_pref_advanced_moodle', null, 'Advanced in Moodle'),
        ];
    }

    /**
     * Collect profile POST fields for save_profile().
     *
     * @return array<string,mixed>
     */
    public static function profile_post_payload(): array {
        $fieldmap = [
            'firstname' => PARAM_TEXT,
            'lastname' => PARAM_TEXT,
            'email' => PARAM_RAW,
            'city' => PARAM_TEXT,
            'country' => PARAM_ALPHANUMEXT,
            'phone1' => PARAM_TEXT,
            'phone2' => PARAM_TEXT,
            'institution' => PARAM_TEXT,
            'department' => PARAM_TEXT,
            'idnumber' => PARAM_TEXT,
            'address' => PARAM_TEXT,
            'description' => PARAM_RAW,
            'timezone' => PARAM_TEXT,
            'lang' => PARAM_LANG,
            'interests' => PARAM_TEXT,
        ];
        $payload = [];
        foreach ($fieldmap as $key => $type) {
            if (array_key_exists($key, $_POST)) {
                $payload[$key] = optional_param($key, '', $type);
            }
        }
        foreach ($_POST as $key => $val) {
            if (is_string($key) && strpos($key, 'profile_field_') === 0) {
                $payload[$key] = clean_param($val, PARAM_TEXT);
            }
        }
        return $payload;
    }

    /**
     * Profile — Moodle user record + preferences hub (Employer / Fellowship shells).
     *
     * @param \stdClass $user
     * @param array<string,string> $urls
     * @param string $ptab overview|edit|preferences
     * @param bool $profilesaved
     * @param string $profileerr
     * @param string $portalpath shell script path for tab URLs
     * @param string $actionpath POST handler path
     * @param string $pref preference subpage key
     * @param bool $prefsaved
     * @param string $preferr
     * @return array<string,mixed>
     */
    public static function profile_page(
        \stdClass $user,
        array $urls,
        string $ptab = 'overview',
        bool $profilesaved = false,
        string $profileerr = '',
        string $portalpath = '/local/launchpad/employee.php',
        string $actionpath = '/local/launchpad/employee_action.php',
        string $pref = '',
        bool $prefsaved = false,
        string $preferr = ''
    ): array {
        global $CFG, $PAGE;

        require_once($CFG->dirroot . '/user/lib.php');
        require_once($CFG->dirroot . '/user/profile/lib.php');
        require_once($CFG->dirroot . '/user/editlib.php');

        $ptab = in_array($ptab, ['overview', 'edit', 'preferences'], true) ? $ptab : 'overview';
        $userid = (int) ($user->id ?? 0);
        if ($portalpath === '') {
            $portalpath = '/local/launchpad/employee.php';
        }
        if ($actionpath === '') {
            $actionpath = '/local/launchpad/employee_action.php';
        }
        if ($pref === 'hub') {
            $pref = '';
        }
        $allowedprefs = self::preference_whitelist();
        if ($pref !== '' && !in_array($pref, $allowedprefs, true)) {
            $pref = '';
        }
        if ($ptab !== 'preferences') {
            $pref = '';
        }

        // Always load a fresh DB record so Overview/Edit reflect Moodle saves immediately.
        if ($userid > 0) {
            try {
                $fresh = \core_user::get_user($userid, '*', MUST_EXIST);
                profile_load_data($fresh);
                $user = $fresh;
            } catch (\Throwable $e) {
                debugging('employer_tools profile_page reload: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        $moodleprofile = (string) ($urls['moodle_profile']
            ?? (new \moodle_url('/user/profile.php', ['id' => $userid]))->out(false));
        $moodleedit = (string) ($urls['moodle_profile_edit']
            ?? (new \moodle_url('/user/edit.php', ['id' => $userid, 'returnto' => 'profile']))->out(false));
        $moodleprefs = (string) ($urls['preferences']
            ?? (new \moodle_url('/user/preferences.php'))->out(false));

        $taburls = [];
        foreach (['overview', 'edit', 'preferences'] as $key) {
            $taburls[$key] = (new \moodle_url($portalpath, [
                'view' => 'profile',
                'ptab' => $key,
            ]))->out(false);
        }

        $flash = self::profile_flash($profilesaved, $profileerr);
        $fullname = fullname($user);
        $email = trim((string) ($user->email ?? ''));
        $canedit = false;
        $editexternal = '';
        try {
            if ($userid > 0 && !isguestuser($user)) {
                $authplugin = get_auth_plugin($user->auth);
                $canedit = $authplugin->can_edit_profile()
                    && has_capability('moodle/user:editownprofile', \context_system::instance());
                if ($canedit) {
                    $ext = $authplugin->edit_profile_url();
                    if ($ext) {
                        $editexternal = $ext->out(false);
                        // External IdP owns the form — keep LaunchPad edit as read-only + link.
                        $canedit = false;
                    }
                }
            }
        } catch (\Throwable $e) {
            debugging('employer_tools profile can_edit: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        $pictureurl = '';
        try {
            $userpicture = new \user_picture($user);
            $userpicture->size = 1;
            $pictureurl = $userpicture->get_url($PAGE)->out(false);
        } catch (\Throwable $e) {
            debugging('employer_tools profile picture: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        $sections = self::profile_display_sections($user);
        $form = self::profile_edit_form($user);
        $preflinks = self::profile_preference_links($userid, $portalpath);

        $ctx = array_merge([
            'page_intro' => self::str(
                'employer_profile_intro',
                null,
                'Your Moodle account details, editable profile fields, and preference links.'
            ),
            'moodle_url' => $moodleprofile,
            'moodle_edit_url' => $moodleedit,
            'moodle_prefs_url' => $moodleprefs,
            'edit_external_url' => $editexternal,
            'has_edit_external' => $editexternal !== '',
            'can_edit' => $canedit,
            'ptab_overview' => $ptab === 'overview',
            'ptab_edit' => $ptab === 'edit',
            'ptab_preferences' => $ptab === 'preferences',
            'tab_overview_url' => $taburls['overview'],
            'tab_edit_url' => $canedit ? $taburls['edit'] : ($editexternal !== '' ? $editexternal : $moodleedit),
            'tab_preferences_url' => $taburls['preferences'],
            'displayname' => $fullname,
            'email' => $email,
            'has_email' => $email !== '',
            'username' => (string) ($user->username ?? ''),
            'picture_url' => $pictureurl,
            'has_picture' => $pictureurl !== '',
            'initials' => self::conversation_initials($fullname),
            'sections' => $sections,
            'has_sections' => $sections !== [],
            'form' => $form,
            'action_url' => (new \moodle_url($actionpath))->out(false),
            'sesskey' => sesskey(),
            'preference_groups' => $preflinks,
            'has_preference_groups' => $preflinks !== [],
            'has_flash' => $flash !== null,
            'flash' => $flash,
            'has_pref_detail' => false,
            'pref_hub_url' => $taburls['preferences'],
        ], self::crumbs([
            ['label' => self::str('employer_nav_profile', null, 'Profile'), 'url' => ''],
        ]));

        if ($ptab === 'preferences' && $pref !== '') {
            $ctx = array_merge($ctx, self::preference_page(
                $user,
                $pref,
                $urls,
                $portalpath,
                $actionpath,
                $prefsaved,
                $preferr
            ));
        }

        return $ctx;
    }

    /**
     * Save editable profile fields via Moodle core APIs.
     *
     * @param \stdClass $user current user
     * @param array<string,mixed> $raw POST fields
     * @return string '' on success, else error code
     */
    public static function save_profile(\stdClass $user, array $raw): string {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/user/lib.php');
        require_once($CFG->dirroot . '/user/profile/lib.php');
        require_once($CFG->dirroot . '/user/editlib.php');

        if (isguestuser($user) || empty($user->id)) {
            return 'denied';
        }
        if (!has_capability('moodle/user:editownprofile', \context_system::instance())) {
            return 'denied';
        }

        try {
            $authplugin = get_auth_plugin($user->auth);
            if (!$authplugin->can_edit_profile()) {
                return 'denied';
            }
            if ($authplugin->edit_profile_url()) {
                return 'denied';
            }
        } catch (\Throwable $e) {
            return 'failed';
        }

        $usernew = clone $user;
        $editable = [
            'firstname', 'lastname', 'email', 'city', 'country', 'phone1', 'phone2',
            'institution', 'department', 'idnumber', 'address', 'description',
            'timezone', 'lang',
        ];
        foreach ($editable as $field) {
            if (!array_key_exists($field, $raw)) {
                continue;
            }
            if (self::profile_field_is_locked($user, $field)) {
                continue;
            }
            $type = \core_user::get_property_type($field);
            $value = clean_param((string) $raw[$field], $type ?: PARAM_TEXT);
            if ($field === 'email') {
                $value = trim(\core_text::strtolower($value));
                if ($value === '' || !validate_email($value)) {
                    return 'email';
                }
                // Duplicate check (other users).
                $dup = $DB->get_record('user', ['email' => $value, 'mnethostid' => $CFG->mnet_localhost_id], 'id,deleted');
                if ($dup && (int) $dup->id !== (int) $user->id && empty($dup->deleted)) {
                    return 'emaildup';
                }
            }
            if (in_array($field, ['firstname', 'lastname'], true) && trim($value) === '') {
                return 'name';
            }
            $usernew->{$field} = $value;
        }

        // Interests (comma-separated tags).
        $interests = null;
        if (array_key_exists('interests', $raw) && !self::profile_field_is_locked($user, 'interests')) {
            $rawinterests = trim(clean_param((string) $raw['interests'], PARAM_TEXT));
            $interests = $rawinterests === ''
                ? []
                : array_values(array_filter(array_map('trim', preg_split('/\s*,\s*/', $rawinterests) ?: [])));
        }

        // Simple custom profile fields (profile_field_*).
        $custom = [];
        foreach ($raw as $key => $val) {
            if (strpos((string) $key, 'profile_field_') !== 0) {
                continue;
            }
            if (self::profile_field_is_locked($user, (string) $key)) {
                continue;
            }
            $short = substr((string) $key, strlen('profile_field_'));
            $custom[$short] = clean_param((string) $val, PARAM_TEXT);
            $usernew->{$key} = $custom[$short];
        }

        try {
            // Email confirmation path (match Moodle edit.php).
            if (!empty($CFG->emailchangeconfirmation)
                    && isset($usernew->email)
                    && $usernew->email !== $user->email
                    && !has_capability('moodle/user:update', \context_system::instance())) {
                $validuntil = time() + 600;
                create_user_key('core_user/email_change', $user->id, null, null, $validuntil);
                set_user_preference('newemail', $usernew->email, $user->id);
                set_user_preference('newemailattemptsleft', 3, $user->id);
                $usernew->email = $user->email;
            }

            $usernew->timemodified = time();
            if (!$authplugin->user_update($user, $usernew)) {
                return 'failed';
            }
            user_update_user($usernew, false, false);

            if ($custom !== []) {
                profile_save_custom_fields((int) $user->id, $custom);
            }
            if ($interests !== null) {
                useredit_update_interests($usernew, $interests);
            }

            // Keep session $USER in sync for immediate UI (full reload, not field cherry-pick).
            global $USER;
            if ((int) $USER->id === (int) $user->id) {
                $reloaded = \core_user::get_user((int) $user->id, '*', MUST_EXIST);
                profile_load_data($reloaded);
                foreach ((array) $reloaded as $key => $val) {
                    $USER->{$key} = $val;
                }
            }
            return '';
        } catch (\Throwable $e) {
            debugging('employer_tools save_profile: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return 'failed';
        }
    }

    /**
     * @param \stdClass $user
     * @param string $field
     * @return bool
     */
    private static function profile_field_is_locked(\stdClass $user, string $field): bool {
        try {
            $authplugin = get_auth_plugin($user->auth);
            $configvariable = 'field_lock_' . $field;
            if (!isset($authplugin->config->{$configvariable})) {
                return false;
            }
            $lock = $authplugin->config->{$configvariable};
            if ($lock === 'locked') {
                return true;
            }
            if ($lock === 'unlockedifempty') {
                if (strpos($field, 'profile_field_') === 0) {
                    $short = substr($field, strlen('profile_field_'));
                    $data = profile_user_record((int) $user->id, false);
                    $value = isset($data->{$short}) ? (string) $data->{$short} : '';
                } else {
                    $value = (string) ($user->{$field} ?? '');
                }
                return $value !== '';
            }
        } catch (\Throwable $e) {
            // Treat as unlocked.
        }
        return false;
    }

    /**
     * Display sections for overview tab.
     *
     * @param \stdClass $user
     * @return list<array<string,mixed>>
     */
    private static function profile_display_sections(\stdClass $user): array {
        global $CFG;

        require_once($CFG->dirroot . '/user/profile/lib.php');

        $countries = get_string_manager()->get_list_of_countries(true);
        $country = (string) ($user->country ?? '');
        $countrylabel = ($country !== '' && isset($countries[$country])) ? $countries[$country] : $country;

        $tz = (string) ($user->timezone ?? '');
        if ($tz === '' || $tz === '99') {
            $servertz = \core_date::get_server_timezone();
            if (get_string_manager()->string_exists('timezoneserver', 'admin')) {
                $tzlabel = get_string('timezoneserver', 'admin', $servertz);
            } else {
                $tzlabel = 'Server timezone (' . $servertz . ')';
            }
        } else {
            $tzlist = \core_date::get_list_of_timezones($tz, true);
            $tzlabel = $tzlist[$tz] ?? $tz;
        }

        $lang = (string) ($user->lang ?? '');
        $translations = get_string_manager()->get_list_of_translations();
        $langlabel = ($lang !== '' && isset($translations[$lang])) ? $translations[$lang] : $lang;

        $interests = [];
        try {
            $tags = \core_tag_tag::get_item_tags_array('core', 'user', (int) $user->id);
            foreach ($tags as $tag) {
                $interests[] = (string) $tag;
            }
        } catch (\Throwable $e) {
            // Ignore.
        }

        $general = self::profile_kv_rows([
            [get_string('firstname'), (string) ($user->firstname ?? '')],
            [get_string('lastname'), (string) ($user->lastname ?? '')],
            [get_string('email'), (string) ($user->email ?? '')],
            [get_string('city'), (string) ($user->city ?? '')],
            [get_string('country'), $countrylabel],
            [get_string('timezone'), $tzlabel],
            [get_string('preferredlanguage'), $langlabel],
        ]);

        $contact = self::profile_kv_rows([
            [get_string('phone1'), (string) ($user->phone1 ?? '')],
            [get_string('phone2'), (string) ($user->phone2 ?? '')],
            [get_string('address'), (string) ($user->address ?? '')],
            [get_string('institution'), (string) ($user->institution ?? '')],
            [get_string('department'), (string) ($user->department ?? '')],
            [get_string('idnumber'), (string) ($user->idnumber ?? '')],
        ]);

        $about = [];
        $desc = trim(strip_tags((string) ($user->description ?? '')));
        if ($desc !== '') {
            $about[] = [
                'label' => get_string('userdescription'),
                'value' => format_text((string) $user->description, (int) ($user->descriptionformat ?? FORMAT_HTML), [
                    'filter' => false,
                    'para' => false,
                ]),
                'is_html' => true,
            ];
        }
        if ($interests !== []) {
            $about[] = [
                'label' => get_string('interests'),
                'value' => s(implode(', ', $interests)),
                'is_html' => false,
            ];
        }

        $sections = [];
        if ($general !== []) {
            $sections[] = [
                'title' => get_string('general'),
                'rows' => $general,
            ];
        }
        if ($contact !== []) {
            $sections[] = [
                'title' => self::str('employer_profile_section_contact', null, 'Contact & organisation'),
                'rows' => $contact,
            ];
        }
        if ($about !== []) {
            $sections[] = [
                'title' => self::str('employer_profile_section_about', null, 'About'),
                'rows' => $about,
            ];
        }

        // Custom profile fields by category.
        try {
            $bycat = profile_get_user_fields_with_data_by_category((int) $user->id);
            foreach ($bycat as $fields) {
                if ($fields === []) {
                    continue;
                }
                $catname = $fields[0]->get_category_name() ?: get_string('other');
                $rows = [];
                foreach ($fields as $fieldobj) {
                    if (!$fieldobj->is_visible()) {
                        continue;
                    }
                    $display = $fieldobj->display_data();
                    $text = trim(strip_tags((string) $display));
                    if ($text === '') {
                        continue;
                    }
                    $rows[] = [
                        'label' => format_string($fieldobj->field->name),
                        'value' => $display,
                        'is_html' => true,
                    ];
                }
                if ($rows !== []) {
                    $sections[] = [
                        'title' => format_string($catname),
                        'rows' => $rows,
                    ];
                }
            }
        } catch (\Throwable $e) {
            debugging('employer_tools profile custom fields: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        return $sections;
    }

    /**
     * @param list<array{0:string,1:string}> $pairs
     * @return list<array<string,mixed>>
     */
    private static function profile_kv_rows(array $pairs): array {
        $rows = [];
        foreach ($pairs as $pair) {
            $value = trim((string) ($pair[1] ?? ''));
            if ($value === '') {
                continue;
            }
            $rows[] = [
                'label' => (string) $pair[0],
                'value' => s($value),
                'is_html' => false,
            ];
        }
        return $rows;
    }

    /**
     * Edit form context.
     *
     * @param \stdClass $user
     * @return array<string,mixed>
     */
    private static function profile_edit_form(\stdClass $user): array {
        global $CFG;

        require_once($CFG->dirroot . '/user/profile/lib.php');
        profile_load_data($user);

        $countries = ['' => get_string('selectacountry') . '...'] + get_string_manager()->get_list_of_countries(true);
        $countryoptions = [];
        foreach ($countries as $code => $label) {
            $countryoptions[] = [
                'value' => (string) $code,
                'label' => (string) $label,
                'selected' => (string) ($user->country ?? '') === (string) $code,
            ];
        }

        $tzoptions = [];
        try {
            $tzlist = \core_date::get_list_of_timezones($user->timezone ?? 99, true);
            foreach ($tzlist as $code => $label) {
                $tzoptions[] = [
                    'value' => (string) $code,
                    'label' => (string) $label,
                    'selected' => (string) ($user->timezone ?? '') === (string) $code,
                ];
            }
        } catch (\Throwable $e) {
            $tzoptions = [];
        }

        $langoptions = [];
        $translations = get_string_manager()->get_list_of_translations();
        $currentlang = (string) ($user->lang ?? $CFG->lang);
        foreach ($translations as $code => $label) {
            $langoptions[] = [
                'value' => (string) $code,
                'label' => (string) $label,
                'selected' => $currentlang === (string) $code,
            ];
        }

        $interests = '';
        try {
            $tags = \core_tag_tag::get_item_tags_array('core', 'user', (int) $user->id);
            $interests = implode(', ', array_map('strval', $tags));
        } catch (\Throwable $e) {
            $interests = '';
        }

        $fields = [
            self::profile_form_text('firstname', get_string('firstname'), (string) ($user->firstname ?? ''), $user, true),
            self::profile_form_text('lastname', get_string('lastname'), (string) ($user->lastname ?? ''), $user, true),
            self::profile_form_text('email', get_string('email'), (string) ($user->email ?? ''), $user, true, 'email'),
            self::profile_form_text('city', get_string('city'), (string) ($user->city ?? ''), $user),
            self::profile_form_select('country', get_string('selectacountry'), $countryoptions, $user),
            self::profile_form_select('timezone', get_string('timezone'), $tzoptions, $user),
            self::profile_form_select('lang', get_string('preferredlanguage'), $langoptions, $user),
            self::profile_form_text('phone1', get_string('phone1'), (string) ($user->phone1 ?? ''), $user),
            self::profile_form_text('phone2', get_string('phone2'), (string) ($user->phone2 ?? ''), $user),
            self::profile_form_text('institution', get_string('institution'), (string) ($user->institution ?? ''), $user),
            self::profile_form_text('department', get_string('department'), (string) ($user->department ?? ''), $user),
            self::profile_form_text('idnumber', get_string('idnumber'), (string) ($user->idnumber ?? ''), $user),
            self::profile_form_text('address', get_string('address'), (string) ($user->address ?? ''), $user),
            self::profile_form_textarea(
                'description',
                get_string('userdescription'),
                self::plain_text((string) ($user->description ?? '')),
                $user
            ),
            self::profile_form_text('interests', get_string('interests'), $interests, $user),
        ];

        // Custom fields — text-like only in LaunchPad form; others link to Moodle.
        $customfields = [];
        try {
            require_once($CFG->dirroot . '/user/profile/lib.php');
            foreach (profile_get_user_fields_with_data((int) $user->id) as $fieldobj) {
                $cfg = (object) $fieldobj->get_field_config_for_external();
                if (!$fieldobj->is_editable()) {
                    continue;
                }
                $datatype = (string) ($cfg->datatype ?? '');
                $name = 'profile_field_' . $cfg->shortname;
                $label = format_string($cfg->name);
                $value = '';
                if (!empty($user->{$name})) {
                    $value = (string) $user->{$name};
                } else if (method_exists($fieldobj, 'display_data')) {
                    $value = self::plain_text((string) $fieldobj->display_data());
                }
                if (in_array($datatype, ['text', 'textarea'], true)) {
                    $customfields[] = $datatype === 'textarea'
                        ? self::profile_form_textarea($name, $label, $value, $user)
                        : self::profile_form_text($name, $label, $value, $user);
                }
            }
        } catch (\Throwable $e) {
            debugging('employer_tools profile edit custom: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        return [
            'fields' => array_values(array_filter($fields)),
            'custom_fields' => array_values(array_filter($customfields)),
            'has_custom_fields' => $customfields !== [],
        ];
    }

    /**
     * @param string $name
     * @param string $label
     * @param string $value
     * @param \stdClass $user
     * @param bool $required
     * @param string $inputtype
     * @return array<string,mixed>|null
     */
    private static function profile_form_text(
        string $name,
        string $label,
        string $value,
        \stdClass $user,
        bool $required = false,
        string $inputtype = 'text'
    ): ?array {
        $locked = self::profile_field_is_locked($user, $name);
        return [
            'name' => $name,
            'label' => $label,
            'value' => $value,
            'required' => $required,
            'locked' => $locked,
            'is_text' => true,
            'is_textarea' => false,
            'is_select' => false,
            'inputtype' => $inputtype,
            'options' => [],
        ];
    }

    /**
     * @param string $name
     * @param string $label
     * @param string $value
     * @param \stdClass $user
     * @return array<string,mixed>|null
     */
    private static function profile_form_textarea(
        string $name,
        string $label,
        string $value,
        \stdClass $user
    ): ?array {
        return [
            'name' => $name,
            'label' => $label,
            'value' => $value,
            'required' => false,
            'locked' => self::profile_field_is_locked($user, $name),
            'is_text' => false,
            'is_textarea' => true,
            'is_select' => false,
            'inputtype' => 'text',
            'options' => [],
        ];
    }

    /**
     * @param string $name
     * @param string $label
     * @param list<array<string,mixed>> $options
     * @param \stdClass $user
     * @return array<string,mixed>|null
     */
    private static function profile_form_select(
        string $name,
        string $label,
        array $options,
        \stdClass $user
    ): ?array {
        if ($options === []) {
            return null;
        }
        return [
            'name' => $name,
            'label' => $label,
            'value' => '',
            'required' => false,
            'locked' => self::profile_field_is_locked($user, $name),
            'is_text' => false,
            'is_textarea' => false,
            'is_select' => true,
            'inputtype' => 'text',
            'options' => $options,
        ];
    }

    /**
     * Preference hub links — stay inside Employer/Fellowship chrome via pref=.
     *
     * @param int $userid
     * @param string $portalpath
     * @return list<array<string,mixed>>
     */
    private static function profile_preference_links(int $userid, string $portalpath): array {
        $prefurl = static function (string $key) use ($portalpath): string {
            return (new \moodle_url($portalpath, [
                'view' => 'profile',
                'ptab' => 'preferences',
                'pref' => $key,
            ]))->out(false);
        };

        $groups = [
            [
                'title' => self::str('employer_profile_prefs_account', null, 'Account'),
                'items' => [
                    [
                        'label' => self::str('employer_profile_prefs_edit', null, 'Edit full profile'),
                        'desc' => self::str(
                            'employer_profile_prefs_edit_desc',
                            null,
                            'Picture upload, rich description, and all custom field types.'
                        ),
                        'url' => $prefurl('edit'),
                        'icon' => 'user-edit-01',
                    ],
                    [
                        'label' => self::str('employer_profile_prefs_public', null, 'Public profile'),
                        'desc' => self::str(
                            'employer_profile_prefs_public_desc',
                            null,
                            'How others see your Moodle profile page.'
                        ),
                        'url' => $prefurl('public'),
                        'icon' => 'user-circle',
                    ],
                    [
                        'label' => self::str('employer_profile_prefs_password', null, 'Change password'),
                        'desc' => self::str(
                            'employer_profile_prefs_password_desc',
                            null,
                            'Update the password for this Moodle account.'
                        ),
                        'url' => $prefurl('password'),
                        'icon' => 'lock-password',
                    ],
                ],
            ],
            [
                'title' => self::str('employer_profile_prefs_communication', null, 'Communication'),
                'items' => [
                    [
                        'label' => self::core_str('messagepreferences', 'message', 'Messaging preferences'),
                        'desc' => self::str(
                            'employer_profile_prefs_messages_desc',
                            null,
                            'Who can message you and how you receive chat.'
                        ),
                        'url' => $prefurl('messages'),
                        'icon' => 'message-01',
                    ],
                    [
                        'label' => self::core_str('notificationpreferences', 'message', 'Notification preferences'),
                        'desc' => self::str(
                            'employer_profile_prefs_notifs_desc',
                            null,
                            'Email and popup notification channels.'
                        ),
                        'url' => $prefurl('notifications'),
                        'icon' => 'notification-01',
                    ],
                    [
                        'label' => self::core_str('forumpreferences', 'moodle', 'Forum preferences'),
                        'desc' => self::str(
                            'employer_profile_prefs_forum_desc',
                            null,
                            'Forum digest and tracking preferences.'
                        ),
                        'url' => $prefurl('forum'),
                        'icon' => 'comment-01',
                    ],
                ],
            ],
            [
                'title' => self::str('employer_profile_prefs_system', null, 'System'),
                'items' => [
                    [
                        'label' => self::core_str('preferredlanguage', 'moodle', 'Preferred language'),
                        'desc' => self::str(
                            'employer_profile_prefs_lang_desc',
                            null,
                            'Interface language for Moodle.'
                        ),
                        'url' => $prefurl('language'),
                        'icon' => 'translate',
                    ],
                    [
                        'label' => self::core_str('editorpreferences', 'moodle', 'Editor preferences'),
                        'desc' => self::str(
                            'employer_profile_prefs_editor_desc',
                            null,
                            'Preferred text editor.'
                        ),
                        'url' => $prefurl('editor'),
                        'icon' => 'text-font',
                    ],
                    [
                        'label' => self::core_str('preferences', 'calendar', 'Calendar preferences'),
                        'desc' => self::str(
                            'employer_profile_prefs_cal_desc',
                            null,
                            'Time format, calendar type, and event filters.'
                        ),
                        'url' => $prefurl('calendar'),
                        'icon' => 'calendar-03',
                    ],
                    [
                        'label' => self::core_str('privatefiles', 'moodle', 'Private files'),
                        'desc' => self::str(
                            'employer_profile_prefs_files_desc',
                            null,
                            'Your private Moodle file area.'
                        ),
                        'url' => $prefurl('files'),
                        'icon' => 'folder-01',
                    ],
                    [
                        'label' => self::core_str('contentbank', 'moodle', 'Content bank'),
                        'desc' => self::str(
                            'employer_profile_prefs_contentbank_desc',
                            null,
                            'Personal content bank preferences.'
                        ),
                        'url' => $prefurl('contentbank'),
                        'icon' => 'database',
                    ],
                    [
                        'label' => self::core_str('managetokens', 'webservice', 'Manage tokens'),
                        'desc' => self::str(
                            'employer_profile_prefs_tokens_desc',
                            null,
                            'Security keys for web services and apps.'
                        ),
                        'url' => $prefurl('tokens'),
                        'icon' => 'key-01',
                    ],
                ],
            ],
        ];

        $out = [];
        foreach ($groups as $group) {
            $items = [];
            foreach ($group['items'] as $item) {
                if (empty($item['url'])) {
                    continue;
                }
                $items[] = $item;
            }
            if ($items !== []) {
                $out[] = [
                    'title' => $group['title'],
                    'items' => $items,
                ];
            }
        }
        return $out;
    }

    /**
     * @param bool $saved
     * @param string $err
     * @return array{ok:bool,text:string}|null
     */
    private static function profile_flash(bool $saved, string $err): ?array {
        if ($saved) {
            return [
                'ok' => true,
                'text' => self::str('employer_profile_save_ok', null, 'Profile updated.'),
            ];
        }
        if ($err === '') {
            return null;
        }
        $map = [
            'denied' => self::str('employer_profile_save_denied', null, 'You cannot edit this profile here.'),
            'email' => self::str('employer_profile_save_email', null, 'Enter a valid email address.'),
            'emaildup' => self::str('employer_profile_save_emaildup', null, 'That email is already used by another account.'),
            'name' => self::str('employer_profile_save_name', null, 'First and last name are required.'),
            'failed' => self::str('employer_profile_save_failed', null, 'Moodle could not save your profile. Try again or open Moodle edit.'),
        ];
        return [
            'ok' => false,
            'text' => $map[$err] ?? $map['failed'],
        ];
    }

    /**
     * Whitelisted preference subpage keys.
     *
     * @return list<string>
     */
    public static function preference_whitelist(): array {
        return [
            'password', 'language', 'editor', 'forum', 'calendar', 'messages', 'notifications',
            'edit', 'public', 'files', 'contentbank', 'tokens',
        ];
    }

    /**
     * Preference subpage context for Mustache.
     *
     * @param \stdClass $user
     * @param string $pref
     * @param array<string,string> $urls
     * @param string $portalpath
     * @param string $actionpath
     * @param bool $saved
     * @param string $err
     * @return array<string,mixed>
     */
    public static function preference_page(
        \stdClass $user,
        string $pref,
        array $urls,
        string $portalpath,
        string $actionpath,
        bool $saved = false,
        string $err = ''
    ): array {
        global $CFG;

        $userid = (int) ($user->id ?? 0);
        $huburl = (new \moodle_url($portalpath, [
            'view' => 'profile',
            'ptab' => 'preferences',
        ]))->out(false);

        $meta = self::preference_meta($pref);
        $flash = self::preference_flash($saved, $err);
        $base = [
            'has_pref_detail' => true,
            'pref_key' => $pref,
            'pref_title' => $meta['title'],
            'pref_help' => $meta['help'],
            'pref_icon' => $meta['icon'],
            'pref_hub_url' => $huburl,
            'pref_is_form' => false,
            'pref_is_handoff' => false,
            'pref_fields' => [],
            'pref_has_fields' => false,
            'pref_moodle_url' => '',
            'pref_handoff_body' => '',
            'pref_advanced_url' => '',
            'pref_has_advanced' => false,
            'pref_action_url' => (new \moodle_url($actionpath))->out(false),
            'pref_cancel_url' => $huburl,
            'pref_has_flash' => $flash !== null,
            'pref_flash' => $flash,
            'pref_password_policy' => '',
        ];

        $formkeys = ['password', 'language', 'editor', 'forum', 'calendar', 'messages', 'notifications'];
        if (in_array($pref, $formkeys, true)) {
            $fields = self::preference_form_fields($user, $pref);
            $base['pref_is_form'] = true;
            $base['pref_fields'] = $fields;
            $base['pref_has_fields'] = $fields !== [];
            if ($pref === 'password' && !empty($CFG->passwordpolicy)) {
                $base['pref_password_policy'] = trim(strip_tags(print_password_policy()));
            }
            if ($pref === 'notifications') {
                $adv = (string) ($urls['moodle_notification_prefs']
                    ?? (new \moodle_url('/message/notificationpreferences.php'))->out(false));
                $base['pref_advanced_url'] = $adv;
                $base['pref_has_advanced'] = $adv !== '';
            }
            if ($pref === 'messages') {
                $adv = (new \moodle_url('/message/edit.php', ['id' => $userid]))->out(false);
                $base['pref_advanced_url'] = $adv;
                $base['pref_has_advanced'] = true;
            }
            return $base;
        }

        // Handoff / redirect-style pages.
        $moodleurl = '';
        $body = $meta['help'];
        $editportal = (new \moodle_url($portalpath, [
            'view' => 'profile',
            'ptab' => 'edit',
        ]))->out(false);

        switch ($pref) {
            case 'edit':
                $moodleurl = (string) ($urls['moodle_profile_edit']
                    ?? (new \moodle_url('/user/edit.php', [
                        'id' => $userid,
                        'returnto' => 'profile',
                    ]))->out(false));
                $body = self::str(
                    'employer_pref_edit_body',
                    null,
                    'Use the in-portal editor for common fields, or open Moodle’s full profile editor for picture upload and advanced fields.'
                );
                $base['pref_portal_edit_url'] = $editportal;
                $base['pref_has_portal_edit'] = true;
                break;
            case 'public':
                $moodleurl = (string) ($urls['moodle_profile']
                    ?? (new \moodle_url('/user/profile.php', ['id' => $userid]))->out(false));
                break;
            case 'files':
                $moodleurl = (new \moodle_url('/user/files.php'))->out(false);
                break;
            case 'contentbank':
                $moodleurl = (new \moodle_url('/user/contentbank.php'))->out(false);
                break;
            case 'tokens':
                $moodleurl = (new \moodle_url('/user/managetoken.php'))->out(false);
                break;
            default:
                $moodleurl = (string) ($urls['preferences']
                    ?? (new \moodle_url('/user/preferences.php'))->out(false));
                break;
        }

        $base['pref_is_handoff'] = true;
        $base['pref_moodle_url'] = $moodleurl;
        $base['pref_handoff_body'] = $body;
        $base['pref_has_portal_edit'] = !empty($base['pref_has_portal_edit']);
        $base['pref_portal_edit_url'] = (string) ($base['pref_portal_edit_url'] ?? '');

        return $base;
    }

    /**
     * Collect preference POST fields for save_preference().
     *
     * @param string $pref
     * @return array<string,mixed>
     */
    public static function preference_post_payload(string $pref): array {
        $payload = ['pref' => $pref];
        switch ($pref) {
            case 'password':
                $payload['password'] = optional_param('password', '', PARAM_RAW);
                $payload['newpassword1'] = optional_param('newpassword1', '', PARAM_RAW);
                $payload['newpassword2'] = optional_param('newpassword2', '', PARAM_RAW);
                $payload['logoutothersessions'] = optional_param('logoutothersessions', 0, PARAM_INT);
                break;
            case 'language':
                $payload['lang'] = optional_param('lang', '', PARAM_LANG);
                break;
            case 'editor':
                $payload['htmleditor'] = optional_param('htmleditor', '', PARAM_PLUGIN);
                break;
            case 'forum':
                $payload['maildigest'] = optional_param('maildigest', 0, PARAM_INT);
                $payload['autosubscribe'] = optional_param('autosubscribe', 1, PARAM_INT);
                $payload['trackforums'] = optional_param('trackforums', 0, PARAM_INT);
                $payload['markasreadonnotification'] = optional_param('markasreadonnotification', 1, PARAM_INT);
                break;
            case 'calendar':
                $payload['timeformat'] = optional_param('timeformat', '', PARAM_NOTAGS);
                $payload['startwday'] = optional_param('startwday', 0, PARAM_INT);
                $payload['maxevents'] = optional_param('maxevents', 10, PARAM_INT);
                $payload['lookahead'] = optional_param('lookahead', 20, PARAM_INT);
                $payload['persistflt'] = optional_param('persistflt', 0, PARAM_INT);
                $payload['calendartype'] = optional_param('calendartype', '', PARAM_ALPHAEXT);
                break;
            case 'messages':
                $payload['message_blocknoncontacts'] = optional_param('message_blocknoncontacts', 0, PARAM_INT);
                break;
            case 'notifications':
                $payload['email_enabled'] = optional_param('email_enabled', 0, PARAM_INT);
                $payload['popup_enabled'] = optional_param('popup_enabled', 0, PARAM_INT);
                break;
        }
        return $payload;
    }

    /**
     * Save a preference subpage via Moodle APIs.
     *
     * @param \stdClass $user
     * @param string $pref
     * @param array<string,mixed> $raw
     * @return string '' on success, else error code
     */
    public static function save_preference(\stdClass $user, string $pref, array $raw): string {
        global $CFG, $USER;

        if (isguestuser($user) || empty($user->id)) {
            return 'denied';
        }
        if ($pref === '' || !in_array($pref, self::preference_whitelist(), true)) {
            return 'denied';
        }
        if (!in_array($pref, ['password', 'language', 'editor', 'forum', 'calendar', 'messages', 'notifications'], true)) {
            return 'denied';
        }

        require_once($CFG->dirroot . '/user/lib.php');
        require_once($CFG->dirroot . '/user/editlib.php');

        try {
            switch ($pref) {
                case 'password':
                    return self::save_preference_password($user, $raw);
                case 'language':
                    return self::save_preference_language($user, $raw);
                case 'editor':
                    return self::save_preference_editor($user, $raw);
                case 'forum':
                    return self::save_preference_forum($user, $raw);
                case 'calendar':
                    return self::save_preference_calendar($user, $raw);
                case 'messages':
                    return self::save_preference_messages($user, $raw);
                case 'notifications':
                    return self::save_preference_notifications($user, $raw);
            }
        } catch (\Throwable $e) {
            debugging('employer_tools save_preference: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return 'failed';
        }

        return 'failed';
    }

    /**
     * @param \stdClass $user
     * @param array<string,mixed> $raw
     * @return string
     */
    private static function save_preference_password(\stdClass $user, array $raw): string {
        global $CFG, $USER;

        require_once($CFG->libdir . '/authlib.php');
        require_once($CFG->dirroot . '/login/lib.php');

        if (!has_capability('moodle/user:changeownpassword', \context_system::instance())
                && !get_user_preferences('auth_forcepasswordchange', false, $user->id)) {
            return 'denied';
        }
        if (\core\session\manager::is_loggedinas()) {
            return 'denied';
        }

        $userauth = get_auth_plugin($user->auth);
        if (!$userauth->can_change_password()) {
            return 'denied';
        }
        if ($userauth->change_password_url()) {
            return 'external';
        }

        $current = (string) ($raw['password'] ?? '');
        $new1 = (string) ($raw['newpassword1'] ?? '');
        $new2 = (string) ($raw['newpassword2'] ?? '');
        if ($current === '' || $new1 === '' || $new2 === '') {
            return 'required';
        }
        if ($new1 !== $new2) {
            return 'mismatch';
        }
        if ($current === $new1) {
            return 'same';
        }

        $reason = null;
        if (!authenticate_user_login($user->username, $current, true, $reason, false)) {
            return 'current';
        }
        if (user_is_previously_used_password((int) $user->id, $new1)) {
            return 'reused';
        }
        $errmsg = '';
        if (!check_password_policy($new1, $errmsg, $user)) {
            return 'policy';
        }
        if (!$userauth->user_update_password($USER, $new1)) {
            return 'failed';
        }

        user_add_password_history((int) $user->id, $new1);
        if (!empty($CFG->passwordchangelogout) || !empty($raw['logoutothersessions'])) {
            \core\session\manager::destroy_user_sessions((int) $user->id, session_id());
        }
        unset_user_preference('auth_forcepasswordchange', $user);
        unset_user_preference('create_password', $user);
        login_unlock_account($user);

        return '';
    }

    /**
     * @param \stdClass $user
     * @param array<string,mixed> $raw
     * @return string
     */
    private static function save_preference_language(\stdClass $user, array $raw): string {
        global $USER;

        $lang = (string) ($raw['lang'] ?? '');
        if ($lang === '' || !get_string_manager()->translation_exists($lang, false)) {
            $lang = \core_user::get_property_default('lang');
        }
        $user->lang = $lang;
        user_update_user($user, false, false);
        \core\event\user_updated::create_from_userid((int) $user->id)->trigger();
        if ((int) $USER->id === (int) $user->id) {
            $USER->lang = $lang;
        }
        return '';
    }

    /**
     * @param \stdClass $user
     * @param array<string,mixed> $raw
     * @return string
     */
    private static function save_preference_editor(\stdClass $user, array $raw): string {
        global $CFG;
        require_once($CFG->libdir . '/editorlib.php');
        $editor = (string) ($raw['htmleditor'] ?? '');
        $editors = editors_get_enabled();
        if ($editor !== '' && !array_key_exists($editor, $editors)) {
            return 'invalid';
        }
        $user->preference_htmleditor = $editor;
        useredit_update_user_preference($user);
        \core\event\user_updated::create_from_userid((int) $user->id)->trigger();
        return '';
    }

    /**
     * @param \stdClass $user
     * @param array<string,mixed> $raw
     * @return string
     */
    private static function save_preference_forum(\stdClass $user, array $raw): string {
        global $CFG, $USER;

        $digest = (int) ($raw['maildigest'] ?? 0);
        if (!in_array($digest, [0, 1, 2], true)) {
            $digest = 0;
        }
        $user->maildigest = $digest;
        $user->autosubscribe = !empty($raw['autosubscribe']) ? 1 : 0;
        if (!empty($CFG->forum_trackreadposts)) {
            $user->trackforums = !empty($raw['trackforums']) ? 1 : 0;
            $user->preference_forum_markasreadonnotification = !empty($raw['markasreadonnotification']) ? 1 : 0;
        }
        useredit_update_user_preference($user);
        user_update_user($user, false, false);
        \core\event\user_updated::create_from_userid((int) $user->id)->trigger();
        if ((int) $USER->id === (int) $user->id) {
            $USER->maildigest = $user->maildigest;
            $USER->autosubscribe = $user->autosubscribe;
            if (!empty($CFG->forum_trackreadposts)) {
                $USER->trackforums = $user->trackforums;
            }
        }
        return '';
    }

    /**
     * @param \stdClass $user
     * @param array<string,mixed> $raw
     * @return string
     */
    private static function save_preference_calendar(\stdClass $user, array $raw): string {
        global $CFG, $USER;

        require_once($CFG->dirroot . '/calendar/lib.php');

        $calendartype = (string) ($raw['calendartype'] ?? '');
        $types = \core_calendar\type_factory::get_list_of_calendar_types();
        if ($calendartype === '' || !array_key_exists($calendartype, $types)) {
            $calendartype = (string) $CFG->calendartype;
        }

        $usernew = [
            'id' => (int) $user->id,
            'preference_calendar_timeformat' => (string) ($raw['timeformat'] ?? ''),
            'preference_calendar_startwday' => max(0, min(6, (int) ($raw['startwday'] ?? 0))),
            'preference_calendar_maxevents' => max(1, min(100, (int) ($raw['maxevents'] ?? 10))),
            'preference_calendar_lookahead' => max(1, min(365, (int) ($raw['lookahead'] ?? 20))),
            'preference_calendar_persistflt' => !empty($raw['persistflt']) ? 1 : 0,
        ];
        useredit_update_user_preference($usernew);

        $user->calendartype = $calendartype;
        user_update_user($user, false, false);
        \core\event\user_updated::create_from_userid((int) $user->id)->trigger();
        if ((int) $USER->id === (int) $user->id) {
            $USER->calendartype = $calendartype;
        }
        return '';
    }

    /**
     * @param \stdClass $user
     * @param array<string,mixed> $raw
     * @return string
     */
    private static function save_preference_messages(\stdClass $user, array $raw): string {
        global $CFG;

        require_once($CFG->dirroot . '/message/lib.php');

        if (!has_capability('moodle/user:editownmessageprofile', \context_system::instance())) {
            return 'denied';
        }

        $privacy = (int) ($raw['message_blocknoncontacts'] ?? \core_message\api::MESSAGE_PRIVACY_COURSEMEMBER);
        $allowed = [
            \core_message\api::MESSAGE_PRIVACY_ONLYCONTACTS,
            \core_message\api::MESSAGE_PRIVACY_COURSEMEMBER,
            \core_message\api::MESSAGE_PRIVACY_SITE,
        ];
        if (!in_array($privacy, $allowed, true)) {
            $privacy = \core_message\api::MESSAGE_PRIVACY_COURSEMEMBER;
        }
        if (empty($CFG->messagingallusers) && $privacy === \core_message\api::MESSAGE_PRIVACY_SITE) {
            $privacy = \core_message\api::MESSAGE_PRIVACY_COURSEMEMBER;
        }
        set_user_preference('message_blocknoncontacts', $privacy, (int) $user->id);
        return '';
    }

    /**
     * Simplified notification processor toggles (email + popup for personal messages).
     *
     * @param \stdClass $user
     * @param array<string,mixed> $raw
     * @return string
     */
    private static function save_preference_notifications(\stdClass $user, array $raw): string {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/message/lib.php');

        if (!has_capability('moodle/user:editownmessageprofile', \context_system::instance())) {
            return 'denied';
        }

        $emailon = !empty($raw['email_enabled']) ? 1 : 0;
        $popupon = !empty($raw['popup_enabled']) ? 1 : 0;
        $providers = ['moodle_instantmessage', 'moodle_badgeawarded'];

        foreach ($providers as $componentprovider) {
            // Preference keys: message_provider_<component>_<name>_enabled / loggedin / loggedoff.
            // Modern Moodle uses enabled processors CSV in preference message_provider_*_enabled.
            $pref = 'message_provider_' . $componentprovider . '_enabled';
            $enabled = [];
            if ($popupon) {
                $enabled[] = 'popup';
            }
            if ($emailon) {
                $enabled[] = 'email';
            }
            if ($enabled === []) {
                // Keep preference present but empty so processors stay off.
                set_user_preference($pref, 'none', (int) $user->id);
            } else {
                set_user_preference($pref, implode(',', $enabled), (int) $user->id);
            }
        }

        // Also set loggedin/loggedoff legacy keys when processors table exists.
        $processors = $DB->get_records('message_processors', null, '', 'name');
        foreach (['popup', 'email'] as $proc) {
            if (!isset($processors[$proc])) {
                continue;
            }
            $on = ($proc === 'popup') ? $popupon : $emailon;
            foreach ($providers as $componentprovider) {
                set_user_preference(
                    'message_provider_' . $componentprovider . '_loggedin',
                    $on ? $proc : 'none',
                    (int) $user->id
                );
                set_user_preference(
                    'message_provider_' . $componentprovider . '_loggedoff',
                    $on ? $proc : 'none',
                    (int) $user->id
                );
            }
        }

        return '';
    }

    /**
     * @param string $pref
     * @return array{title:string,help:string,icon:string}
     */
    private static function preference_meta(string $pref): array {
        $map = [
            'password' => [
                'title' => self::str('employer_profile_prefs_password', null, 'Change password'),
                'help' => self::str('employer_pref_password_help', null, 'Choose a strong password you have not used on this site before.'),
                'icon' => 'lock-password',
            ],
            'language' => [
                'title' => self::core_str('preferredlanguage', 'moodle', 'Preferred language'),
                'help' => self::str('employer_profile_prefs_lang_desc', null, 'Interface language for Moodle.'),
                'icon' => 'translate',
            ],
            'editor' => [
                'title' => self::core_str('editorpreferences', 'moodle', 'Editor preferences'),
                'help' => self::str('employer_profile_prefs_editor_desc', null, 'Preferred text editor.'),
                'icon' => 'text-font',
            ],
            'forum' => [
                'title' => self::core_str('forumpreferences', 'moodle', 'Forum preferences'),
                'help' => self::str('employer_profile_prefs_forum_desc', null, 'Forum digest and tracking preferences.'),
                'icon' => 'comment-01',
            ],
            'calendar' => [
                'title' => self::core_str('preferences', 'calendar', 'Calendar preferences'),
                'help' => self::str('employer_profile_prefs_cal_desc', null, 'Time format, calendar type, and event filters.'),
                'icon' => 'calendar-03',
            ],
            'messages' => [
                'title' => self::core_str('messagepreferences', 'message', 'Messaging preferences'),
                'help' => self::str('employer_profile_prefs_messages_desc', null, 'Who can message you and how you receive chat.'),
                'icon' => 'message-01',
            ],
            'notifications' => [
                'title' => self::core_str('notificationpreferences', 'message', 'Notification preferences'),
                'help' => self::str(
                    'employer_pref_notifications_help',
                    null,
                    'Quick toggles for email and popup on common alerts. Open Moodle for the full matrix.'
                ),
                'icon' => 'notification-01',
            ],
            'edit' => [
                'title' => self::str('employer_profile_prefs_edit', null, 'Edit full profile'),
                'help' => self::str(
                    'employer_profile_prefs_edit_desc',
                    null,
                    'Picture upload, rich description, and all custom field types.'
                ),
                'icon' => 'user-edit-01',
            ],
            'public' => [
                'title' => self::str('employer_profile_prefs_public', null, 'Public profile'),
                'help' => self::str(
                    'employer_profile_prefs_public_desc',
                    null,
                    'How others see your Moodle profile page.'
                ),
                'icon' => 'user-circle',
            ],
            'files' => [
                'title' => self::core_str('privatefiles', 'moodle', 'Private files'),
                'help' => self::str('employer_profile_prefs_files_desc', null, 'Your private Moodle file area.'),
                'icon' => 'folder-01',
            ],
            'contentbank' => [
                'title' => self::core_str('contentbank', 'moodle', 'Content bank'),
                'help' => self::str(
                    'employer_profile_prefs_contentbank_desc',
                    null,
                    'Personal content bank preferences.'
                ),
                'icon' => 'database',
            ],
            'tokens' => [
                'title' => self::core_str('managetokens', 'webservice', 'Manage tokens'),
                'help' => self::str(
                    'employer_profile_prefs_tokens_desc',
                    null,
                    'Security keys for web services and apps.'
                ),
                'icon' => 'key-01',
            ],
        ];
        return $map[$pref] ?? [
            'title' => self::str('employer_nav_preferences', null, 'Preferences'),
            'help' => '',
            'icon' => 'settings-01',
        ];
    }

    /**
     * @param bool $saved
     * @param string $err
     * @return array{ok:bool,text:string}|null
     */
    private static function preference_flash(bool $saved, string $err): ?array {
        if ($saved) {
            return [
                'ok' => true,
                'text' => self::str('employer_pref_save_ok', null, 'Preferences saved.'),
            ];
        }
        if ($err === '') {
            return null;
        }
        $map = [
            'denied' => self::str('employer_pref_save_denied', null, 'You cannot change this preference here.'),
            'external' => self::str(
                'employer_pref_save_external',
                null,
                'Your login method manages passwords outside LaunchPad. Open Moodle or your identity provider.'
            ),
            'required' => self::str('employer_pref_save_required', null, 'Fill in all required fields.'),
            'mismatch' => self::str('employer_pref_save_mismatch', null, 'New passwords do not match.'),
            'same' => self::str('employer_pref_save_same', null, 'New password must be different from the current one.'),
            'current' => self::str('employer_pref_save_current', null, 'Current password is incorrect.'),
            'reused' => self::str('employer_pref_save_reused', null, 'That password was used recently. Choose a new one.'),
            'policy' => self::str('employer_pref_save_policy', null, 'New password does not meet the site password policy.'),
            'invalid' => self::str('employer_pref_save_invalid', null, 'That option is not available.'),
            'failed' => self::str('employer_pref_save_failed', null, 'Moodle could not save this preference. Try again.'),
        ];
        return [
            'ok' => false,
            'text' => $map[$err] ?? $map['failed'],
        ];
    }

    /**
     * Build form fields for a preference subpage.
     *
     * @param \stdClass $user
     * @param string $pref
     * @return list<array<string,mixed>>
     */
    private static function preference_form_fields(\stdClass $user, string $pref): array {
        global $CFG;

        $fields = [];
        switch ($pref) {
            case 'password':
                $fields[] = self::pref_field_password('password', self::core_str('oldpassword', 'moodle', 'Current password'), true);
                $fields[] = self::pref_field_password('newpassword1', self::core_str('newpassword', 'moodle', 'New password'), true);
                $fields[] = self::pref_field_password(
                    'newpassword2',
                    self::core_str('newpassword', 'moodle', 'New password') . ' (' . self::core_str('again', 'moodle', 'again') . ')',
                    true
                );
                if (empty($CFG->passwordchangelogout)) {
                    $fields[] = self::pref_field_checkbox(
                        'logoutothersessions',
                        self::core_str('logoutalsosessions', 'moodle', 'Log out of other browsers'),
                        false
                    );
                }
                break;

            case 'language':
                $langs = get_string_manager()->get_list_of_translations();
                $options = [];
                foreach ($langs as $code => $name) {
                    $options[] = [
                        'value' => $code,
                        'label' => $name,
                        'selected' => ((string) ($user->lang ?? '') === (string) $code),
                    ];
                }
                $fields[] = self::pref_field_select(
                    'lang',
                    self::core_str('preferredlanguage', 'moodle', 'Preferred language'),
                    $options
                );
                break;

            case 'editor':
                require_once($CFG->libdir . '/editorlib.php');
                $editors = editors_get_enabled();
                $current = (string) get_user_preferences('htmleditor', '', (int) $user->id);
                $options = [[
                    'value' => '',
                    'label' => self::str('employer_pref_editor_default', null, 'Default editor'),
                    'selected' => $current === '',
                ]];
                foreach ($editors as $name => $unused) {
                    $options[] = [
                        'value' => $name,
                        'label' => get_string('pluginname', 'editor_' . $name),
                        'selected' => $current === $name,
                    ];
                }
                $fields[] = self::pref_field_select(
                    'htmleditor',
                    self::core_str('editorpreferences', 'moodle', 'Preferred editor'),
                    $options
                );
                break;

            case 'forum':
                $digest = (int) ($user->maildigest ?? 0);
                $fields[] = self::pref_field_select('maildigest', self::core_str('emaildigest', 'moodle', 'Email digest type'), [
                    ['value' => '0', 'label' => self::core_str('emaildigestoff', 'moodle', 'No digest'), 'selected' => $digest === 0],
                    ['value' => '1', 'label' => self::core_str('emaildigestcomplete', 'moodle', 'Complete'), 'selected' => $digest === 1],
                    ['value' => '2', 'label' => self::core_str('emaildigestsubjects', 'moodle', 'Subjects'), 'selected' => $digest === 2],
                ]);
                $fields[] = self::pref_field_select('autosubscribe', self::core_str('autosubscribe', 'moodle', 'Forum auto-subscribe'), [
                    [
                        'value' => '1',
                        'label' => self::core_str('autosubscribeyes', 'moodle', 'Yes: when I post, subscribe me'),
                        'selected' => !empty($user->autosubscribe),
                    ],
                    [
                        'value' => '0',
                        'label' => self::core_str('autosubscribeno', 'moodle', 'No: do not auto-subscribe'),
                        'selected' => empty($user->autosubscribe),
                    ],
                ]);
                if (!empty($CFG->forum_trackreadposts)) {
                    $fields[] = self::pref_field_select('trackforums', self::core_str('trackforums', 'moodle', 'Forum tracking'), [
                        [
                            'value' => '1',
                            'label' => self::core_str('trackforumsyes', 'moodle', 'Yes: highlight new posts'),
                            'selected' => !empty($user->trackforums),
                        ],
                        [
                            'value' => '0',
                            'label' => self::core_str('trackforumsno', 'moodle', 'No: do not highlight'),
                            'selected' => empty($user->trackforums),
                        ],
                    ]);
                    $mark = (int) get_user_preferences('forum_markasreadonnotification', 1, (int) $user->id);
                    $fields[] = self::pref_field_checkbox(
                        'markasreadonnotification',
                        self::core_str('markasreadonnotification', 'moodle', 'Mark as read on notification'),
                        $mark === 1
                    );
                }
                break;

            case 'calendar':
                require_once($CFG->dirroot . '/calendar/lib.php');
                $timeformat = (string) get_user_preferences('calendar_timeformat', '', (int) $user->id);
                $fields[] = self::pref_field_select('timeformat', self::str('employer_pref_cal_timeformat', null, 'Time format'), [
                    ['value' => '', 'label' => self::core_str('default', 'moodle', 'Default'), 'selected' => $timeformat === ''],
                    ['value' => '%H:%M', 'label' => self::str('employer_pref_cal_24h', null, '24-hour'), 'selected' => $timeformat === '%H:%M'],
                    [
                        'value' => '%I:%M %p',
                        'label' => self::str('employer_pref_cal_12h', null, '12-hour'),
                        'selected' => $timeformat === '%I:%M %p' || $timeformat === '%I:%M',
                    ],
                ]);

                $startwday = (int) calendar_get_starting_weekday();
                $weekdays = [
                    0 => self::core_str('sunday', 'calendar', 'Sunday'),
                    1 => self::core_str('monday', 'calendar', 'Monday'),
                    2 => self::core_str('tuesday', 'calendar', 'Tuesday'),
                    3 => self::core_str('wednesday', 'calendar', 'Wednesday'),
                    4 => self::core_str('thursday', 'calendar', 'Thursday'),
                    5 => self::core_str('friday', 'calendar', 'Friday'),
                    6 => self::core_str('saturday', 'calendar', 'Saturday'),
                ];
                $dayopts = [];
                foreach ($weekdays as $i => $label) {
                    $dayopts[] = [
                        'value' => (string) $i,
                        'label' => $label,
                        'selected' => $startwday === $i,
                    ];
                }
                $fields[] = self::pref_field_select(
                    'startwday',
                    self::str('employer_pref_cal_startwday', null, 'First day of week'),
                    $dayopts
                );

                $defaultmax = isset($CFG->calendar_maxevents) ? (int) $CFG->calendar_maxevents : CALENDAR_DEFAULT_UPCOMING_MAXEVENTS;
                $defaultlook = isset($CFG->calendar_lookahead) ? (int) $CFG->calendar_lookahead : CALENDAR_DEFAULT_UPCOMING_LOOKAHEAD;
                $maxevents = (int) get_user_preferences('calendar_maxevents', $defaultmax, (int) $user->id);
                $lookahead = (int) get_user_preferences('calendar_lookahead', $defaultlook, (int) $user->id);
                $persist = (int) get_user_preferences('calendar_persistflt', 0, (int) $user->id);

                $fields[] = self::pref_field_number(
                    'maxevents',
                    self::str('employer_pref_cal_maxevents', null, 'Upcoming events max'),
                    $maxevents
                );
                $fields[] = self::pref_field_number(
                    'lookahead',
                    self::str('employer_pref_cal_lookahead', null, 'Upcoming events look-ahead (days)'),
                    $lookahead
                );
                $fields[] = self::pref_field_checkbox(
                    'persistflt',
                    self::str('employer_pref_cal_persist', null, 'Remember filter settings'),
                    $persist === 1
                );

                $types = \core_calendar\type_factory::get_list_of_calendar_types();
                $caltype = (string) ($user->calendartype ?? $CFG->calendartype);
                $calopts = [];
                foreach ($types as $key => $label) {
                    $calopts[] = [
                        'value' => $key,
                        'label' => $label,
                        'selected' => $caltype === $key,
                    ];
                }
                if ($calopts !== []) {
                    $fields[] = self::pref_field_select(
                        'calendartype',
                        self::str('employer_pref_cal_type', null, 'Preferred calendar'),
                        $calopts
                    );
                }
                break;

            case 'messages':
                require_once($CFG->dirroot . '/message/lib.php');
                $privacy = (int) get_user_preferences(
                    'message_blocknoncontacts',
                    \core_message\api::MESSAGE_PRIVACY_COURSEMEMBER,
                    (int) $user->id
                );
                $opts = [
                    [
                        'value' => (string) \core_message\api::MESSAGE_PRIVACY_ONLYCONTACTS,
                        'label' => self::core_str('contactableprivacy_onlycontacts', 'message', 'My contacts only'),
                        'selected' => $privacy === \core_message\api::MESSAGE_PRIVACY_ONLYCONTACTS,
                    ],
                    [
                        'value' => (string) \core_message\api::MESSAGE_PRIVACY_COURSEMEMBER,
                        'label' => self::core_str(
                            'contactableprivacy_coursemember',
                            'message',
                            'My contacts and anyone in my courses'
                        ),
                        'selected' => $privacy === \core_message\api::MESSAGE_PRIVACY_COURSEMEMBER,
                    ],
                ];
                if (!empty($CFG->messagingallusers)) {
                    $opts[] = [
                        'value' => (string) \core_message\api::MESSAGE_PRIVACY_SITE,
                        'label' => self::core_str('contactableprivacy_site', 'message', 'Anyone on the site'),
                        'selected' => $privacy === \core_message\api::MESSAGE_PRIVACY_SITE,
                    ];
                }
                $fields[] = self::pref_field_select(
                    'message_blocknoncontacts',
                    self::core_str('contactableprivacy', 'message', 'Who can message me'),
                    $opts
                );
                break;

            case 'notifications':
                $emailpref = (string) get_user_preferences('message_provider_moodle_instantmessage_enabled', '', (int) $user->id);
                $popupon = $emailpref === '' || strpos($emailpref, 'popup') !== false;
                $emailon = $emailpref === '' || strpos($emailpref, 'email') !== false;
                // Empty preference usually means site defaults (often both on).
                if ($emailpref === 'none') {
                    $popupon = false;
                    $emailon = false;
                }
                $fields[] = self::pref_field_checkbox(
                    'popup_enabled',
                    self::str('employer_pref_notif_popup', null, 'Web / popup notifications'),
                    $popupon
                );
                $fields[] = self::pref_field_checkbox(
                    'email_enabled',
                    self::str('employer_pref_notif_email', null, 'Email notifications'),
                    $emailon
                );
                break;
        }

        return $fields;
    }

    /**
     * @param string $name
     * @param string $label
     * @param bool $required
     * @return array<string,mixed>
     */
    private static function pref_field_password(string $name, string $label, bool $required): array {
        return [
            'name' => $name,
            'label' => $label,
            'required' => $required,
            'is_password' => true,
            'is_text' => false,
            'is_number' => false,
            'is_checkbox' => false,
            'is_select' => false,
            'value' => '',
            'checked' => false,
            'options' => [],
        ];
    }

    /**
     * @param string $name
     * @param string $label
     * @param string|int $value
     * @return array<string,mixed>
     */
    private static function pref_field_number(string $name, string $label, $value): array {
        return [
            'name' => $name,
            'label' => $label,
            'required' => false,
            'is_password' => false,
            'is_text' => false,
            'is_number' => true,
            'is_checkbox' => false,
            'is_select' => false,
            'value' => (string) $value,
            'checked' => false,
            'options' => [],
        ];
    }

    /**
     * @param string $name
     * @param string $label
     * @param bool $checked
     * @return array<string,mixed>
     */
    private static function pref_field_checkbox(string $name, string $label, bool $checked): array {
        return [
            'name' => $name,
            'label' => $label,
            'required' => false,
            'is_password' => false,
            'is_text' => false,
            'is_number' => false,
            'is_checkbox' => true,
            'is_select' => false,
            'value' => '1',
            'checked' => $checked,
            'options' => [],
        ];
    }

    /**
     * @param string $name
     * @param string $label
     * @param list<array{value:string,label:string,selected:bool}> $options
     * @return array<string,mixed>
     */
    private static function pref_field_select(string $name, string $label, array $options): array {
        $selectedlabel = '';
        foreach ($options as $opt) {
            if (!empty($opt['selected'])) {
                $selectedlabel = (string) $opt['label'];
                break;
            }
        }
        if ($selectedlabel === '' && $options !== []) {
            $selectedlabel = (string) $options[0]['label'];
            $options[0]['selected'] = true;
        }
        return [
            'name' => $name,
            'label' => $label,
            'required' => false,
            'is_password' => false,
            'is_text' => false,
            'is_number' => false,
            'is_checkbox' => false,
            'is_select' => true,
            'value' => '',
            'checked' => false,
            'options' => $options,
            'selected_label' => $selectedlabel,
        ];
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

    /**
     * Safe core / component string with fallback (never [[missing]]).
     *
     * @param string $identifier
     * @param string $component
     * @param string $fallback
     */
    private static function core_str(string $identifier, string $component, string $fallback): string {
        if (get_string_manager()->string_exists($identifier, $component)) {
            $out = get_string($identifier, $component);
            if (is_string($out) && $out !== '' && strpos($out, '[[') === false) {
                return $out;
            }
        }
        return $fallback;
    }
}
