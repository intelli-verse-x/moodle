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
 * Moodle-backed calendar helper for LaunchPad (browse + personal CRUD).
 *
 * @package    local_launchpad
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class calendar_service {

    /** Upcoming list window after "now". */
    public const UPCOMING_DAYS = 90;

    /** Chips shown in a month day cell (Moodle month UI folds around 5; rest via day panel). */
    public const MONTH_CELL_CHIP_LIMIT = 3;

    /** Initial Upcoming rows before "Show more" (Moodle uses lookahead settings, not an infinite list). */
    public const UPCOMING_PAGE_SIZE = 25;

    /**
     * Resolve calendar month from request time (unix) or explicit month/year.
     *
     * @return array{year:int,month:int,time:int}
     */
    public static function resolve_month(?int $time, ?int $year, ?int $month): array {
        if ($year !== null && $month !== null && $year >= 1970 && $month >= 1 && $month <= 12) {
            $t = make_timestamp($year, $month, 1, 0, 0, 0);
            return ['year' => $year, 'month' => $month, 'time' => $t];
        }
        if ($time !== null && $time > 0) {
            $y = (int) userdate($time, '%Y');
            $m = (int) userdate($time, '%m');
            return ['year' => $y, 'month' => $m, 'time' => make_timestamp($y, $m, 1, 0, 0, 0)];
        }
        $now = time();
        $y = (int) userdate($now, '%Y');
        $m = (int) userdate($now, '%m');
        return ['year' => $y, 'month' => $m, 'time' => make_timestamp($y, $m, 1, 0, 0, 0)];
    }

    /**
     * Inclusive month window [start, end) in server timestamps for user calendar month.
     *
     * @return array{0:int,1:int} tstart, tend
     */
    public static function month_range(int $year, int $month): array {
        $tstart = make_timestamp($year, $month, 1, 0, 0, 0);
        if ($month === 12) {
            $tend = make_timestamp($year + 1, 1, 1, 0, 0, 0);
        } else {
            $tend = make_timestamp($year, $month + 1, 1, 0, 0, 0);
        }
        return [$tstart, $tend];
    }

    /**
     * Fetch Moodle events visible to the user for a time window.
     *
     * @param list<\stdClass> $courses
     * @return list<\stdClass>
     */
    public static function fetch_raw_events(int $userid, array $courses, int $tstart, int $tend): array {
        global $CFG;

        require_once($CFG->dirroot . '/calendar/lib.php');

        $courseids = array_map(static fn($c) => (int) $c->id, $courses);
        $raw = calendar_get_legacy_events(
            $tstart,
            $tend,
            $userid,
            true,
            $courseids === [] ? true : $courseids,
            true,
            true
        );
        return array_values($raw ?: []);
    }

    /**
     * Map a Moodle event object to a LaunchPad DTO.
     *
     * @param array<int,string> $coursenames
     * @return array<string,mixed>
     */
    public static function map_event(\stdClass $event, array $coursenames, ?int $viewerid = null): array {
        $courseid = (int) ($event->courseid ?? 0);
        $timestart = (int) ($event->timestart ?? 0);
        $timeduration = (int) ($event->timeduration ?? 0);
        $ispersonal = $courseid <= 0 && (string) ($event->eventtype ?? '') !== 'site';
        $descplain = '';
        if (!empty($event->description)) {
            $html = format_text(
                (string) $event->description,
                (int) ($event->format ?? FORMAT_HTML),
                ['para' => false, 'filter' => false]
            );
            $descplain = trim(html_to_text($html, 0, false));
        }
        $descexcerpt = $descplain;
        if (\core_text::strlen($descexcerpt) > 280) {
            $descexcerpt = \core_text::substr($descexcerpt, 0, 277) . '…';
        }

        $canedit = false;
        if ($viewerid && empty($event->modulename) && empty($event->component)) {
            if ($ispersonal && (int) ($event->userid ?? 0) === $viewerid) {
                $canedit = has_capability(
                    'moodle/calendar:manageownentries',
                    \context_user::instance($viewerid)
                );
            } else if (!$ispersonal && $courseid > 0
                    && (string) ($event->eventtype ?? '') === 'course') {
                $canedit = has_capability(
                    'moodle/calendar:manageentries',
                    \context_course::instance($courseid)
                );
            }
        }

        return [
            'id' => (int) ($event->id ?? 0),
            'name' => format_string((string) ($event->name ?? '')),
            'when' => userdate($timestart, get_string('strftimedatetimeshort', 'langconfig')),
            'timestart' => $timestart,
            'timeduration' => $timeduration,
            'duration_minutes' => $timeduration > 0 ? (int) round($timeduration / 60) : 0,
            'date_value' => userdate($timestart, '%Y-%m-%d'),
            'time_value' => userdate($timestart, '%H:%M'),
            'day_key' => userdate($timestart, '%Y-%m-%d'),
            'courseid' => $courseid,
            'is_personal' => $ispersonal,
            'is_course_event' => !$ispersonal && (string) ($event->eventtype ?? '') === 'course',
            'can_edit' => $canedit,
            'course_name' => $ispersonal
                ? get_string('fellowship_calendar_personal', 'local_launchpad')
                : ($coursenames[$courseid] ?? ''),
            'description_excerpt' => $descexcerpt,
            'description_plain' => $descplain,
            'has_description' => $descplain !== '',
            'url' => (new \moodle_url('/calendar/view.php', [
                'view' => 'day',
                'time' => $timestart,
            ]))->out(false),
            'eventtype' => (string) ($event->eventtype ?? ''),
            'modulename' => (string) ($event->modulename ?? ''),
            'is_module' => !empty($event->modulename),
        ];
    }

    /**
     * Upcoming list (near past → +UPCOMING_DAYS).
     *
     * @param list<\stdClass> $courses
     * @return array{items:list<array>,has_items:bool,moodle_calendar_url:string}
     */
    public static function upcoming(int $userid, array $courses): array {
        $tstart = time() - DAYSECS;
        $tend = time() + (self::UPCOMING_DAYS * DAYSECS);
        $coursenames = self::course_name_map($courses);
        $items = [];
        foreach (self::fetch_raw_events($userid, $courses, $tstart, $tend) as $event) {
            $items[] = self::map_event($event, $coursenames, $userid);
        }
        usort($items, static fn(array $a, array $b): int => ($a['timestart'] <=> $b['timestart']));
        $paged = [];
        foreach ($items as $i => $item) {
            $item['paged_out'] = $i >= self::UPCOMING_PAGE_SIZE;
            $paged[] = $item;
        }
        return [
            'items' => $paged,
            'has_items' => $paged !== [],
            'upcoming_has_more' => count($paged) > self::UPCOMING_PAGE_SIZE,
            'upcoming_more_count' => max(0, count($paged) - self::UPCOMING_PAGE_SIZE),
            'upcoming_page_size' => self::UPCOMING_PAGE_SIZE,
            'moodle_calendar_url' => self::moodle_month_url(),
        ];
    }

    /**
     * Courses from the visible set where the user may manage course calendar events.
     *
     * @param list<\stdClass> $courses
     * @return list<array{id:int,name:string}>
     */
    public static function courses_user_can_manage(int $userid, array $courses): array {
        $out = [];
        foreach ($courses as $c) {
            $cid = (int) $c->id;
            if ($cid <= 0) {
                continue;
            }
            if (has_capability('moodle/calendar:manageentries', \context_course::instance($cid))) {
                $out[] = [
                    'id' => $cid,
                    'name' => format_string($c->fullname),
                ];
            }
        }
        return $out;
    }

    /**
     * Fellow calendar page context.
     *
     * @param list<\stdClass> $courses
     * @param string $mode month|upcoming
     */
    public static function fellow_context(
        int $userid,
        array $courses,
        int $year,
        int $month,
        string $mode = 'month'
    ): array {
        return self::page_context(
            $userid,
            $courses,
            $year,
            $month,
            $mode,
            persona_home::FELLOW_PATH,
            'fellow'
        );
    }

    /**
     * Mentor calendar page context.
     *
     * @param list<\stdClass> $courses
     * @param string $mode month|upcoming
     */
    public static function mentor_context(
        int $userid,
        array $courses,
        int $year,
        int $month,
        string $mode = 'month'
    ): array {
        return self::page_context(
            $userid,
            $courses,
            $year,
            $month,
            $mode,
            persona_home::MENTOR_PATH,
            'mentor'
        );
    }

    /**
     * Shared Calendar page context (month grid + upcoming + nav URLs).
     *
     * @param list<\stdClass> $courses
     * @param string $mode month|upcoming
     * @param string $basepath Persona home path for calendar URLs
     * @param string $portal fellow|mentor
     */
    public static function page_context(
        int $userid,
        array $courses,
        int $year,
        int $month,
        string $mode = 'month',
        string $basepath = '',
        string $portal = 'fellow'
    ): array {
        if ($mode !== 'upcoming') {
            $mode = 'month';
        }
        if ($basepath === '') {
            $basepath = persona_home::FELLOW_PATH;
        }
        if ($portal !== 'mentor') {
            $portal = 'fellow';
        }
        [$tstart, $tend] = self::month_range($year, $month);
        $padstart = $tstart - (7 * DAYSECS);
        $padend = $tend + (7 * DAYSECS);
        $coursenames = self::course_name_map($courses);
        $monthitems = [];
        foreach (self::fetch_raw_events($userid, $courses, $padstart, $padend) as $event) {
            $monthitems[] = self::map_event($event, $coursenames, $userid);
        }
        usort($monthitems, static fn(array $a, array $b): int => ($a['timestart'] <=> $b['timestart']));

        $upcoming = self::upcoming($userid, $courses);
        $grid = self::build_month_grid($year, $month, $monthitems);

        $basemonth = make_timestamp($year, $month, 1, 0, 0, 0);
        if ($month === 1) {
            $prevtime = make_timestamp($year - 1, 12, 1, 0, 0, 0);
        } else {
            $prevtime = make_timestamp($year, $month - 1, 1, 0, 0, 0);
        }
        if ($month === 12) {
            $nexttime = make_timestamp($year + 1, 1, 1, 0, 0, 0);
        } else {
            $nexttime = make_timestamp($year, $month + 1, 1, 0, 0, 0);
        }

        $manageable = self::courses_user_can_manage($userid, $courses);
        $canaddpersonal = has_capability(
            'moodle/calendar:manageownentries',
            \context_user::instance($userid)
        );
        $canaddcourse = $manageable !== [];

        return array_merge($grid, [
            'mode_month' => $mode === 'month',
            'mode_upcoming' => $mode === 'upcoming',
            'month_label' => userdate($basemonth, '%B %Y'),
            'current_time' => $basemonth,
            'prev_time' => $prevtime,
            'next_time' => $nexttime,
            'prev_url' => (new \moodle_url($basepath, [
                'view' => 'calendar',
                'cal' => 'month',
                'time' => $prevtime,
            ]))->out(false),
            'next_url' => (new \moodle_url($basepath, [
                'view' => 'calendar',
                'cal' => 'month',
                'time' => $nexttime,
            ]))->out(false),
            'month_toggle_url' => (new \moodle_url($basepath, [
                'view' => 'calendar',
                'cal' => 'month',
                'time' => $basemonth,
            ]))->out(false),
            'upcoming_toggle_url' => (new \moodle_url($basepath, [
                'view' => 'calendar',
                'cal' => 'upcoming',
                'time' => $basemonth,
            ]))->out(false),
            'ajax_url' => (new \moodle_url('/local/launchpad/ajax_calendar_month.php', [
                'portal' => $portal,
            ]))->out(false),
            'personal_ajax_url' => (new \moodle_url('/local/launchpad/ajax_calendar_personal.php', [
                'portal' => $portal,
            ]))->out(false),
            'portal' => $portal,
            'is_mentor_calendar' => $portal === 'mentor',
            'sesskey' => sesskey(),
            'can_add_personal' => $canaddpersonal,
            'can_add_course' => $canaddcourse,
            'can_add_event' => $canaddpersonal || $canaddcourse,
            'has_scope_choice' => $canaddpersonal && $canaddcourse,
            'default_scope' => ($portal === 'mentor' && $canaddcourse)
                ? 'course'
                : ($canaddcourse && !$canaddpersonal ? 'course' : 'personal'),
            'manageable_courses' => $manageable,
            'has_manageable_courses' => $manageable !== [],
            'upcoming_items' => $upcoming['items'],
            'has_upcoming_items' => $upcoming['has_items'],
            'upcoming_has_more' => !empty($upcoming['upcoming_has_more']),
            'upcoming_more_count' => (int) ($upcoming['upcoming_more_count'] ?? 0),
            'upcoming_page_size' => (int) ($upcoming['upcoming_page_size'] ?? self::UPCOMING_PAGE_SIZE),
            'moodle_calendar_url' => self::moodle_month_url(),
            'detail_events' => $monthitems,
            'has_detail_events' => $monthitems !== [],
        ]);
    }

    /**
     * Parse a user-local date (Y-m-d) + time (H:i) into a unix timestamp.
     */
    public static function parse_user_datetime(string $date, string $time): ?int {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($date), $d)) {
            return null;
        }
        if (!preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim($time), $t)) {
            return null;
        }
        $year = (int) $d[1];
        $month = (int) $d[2];
        $day = (int) $d[3];
        $hour = (int) $t[1];
        $minute = (int) $t[2];
        if ($year < 1970 || $month < 1 || $month > 12 || $day < 1 || $day > 31
                || $hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            return null;
        }
        return make_timestamp($year, $month, $day, $hour, $minute, 0);
    }

    /**
     * Create a personal (user) calendar event for the given user.
     *
     * @return array{ok:bool,error:?string,id:?int}
     */
    public static function create_personal(
        int $userid,
        string $name,
        int $timestart,
        int $timeduration = 0,
        string $description = ''
    ): array {
        global $CFG, $USER;

        require_once($CFG->dirroot . '/calendar/lib.php');

        $name = trim($name);
        if ($name === '') {
            return ['ok' => false, 'error' => get_string('fellowship_calendar_err_name', 'local_launchpad'), 'id' => null];
        }
        if ($timestart <= 0) {
            return ['ok' => false, 'error' => get_string('fellowship_calendar_err_when', 'local_launchpad'), 'id' => null];
        }
        if ($timeduration < 0) {
            $timeduration = 0;
        }
        if ((int) $USER->id !== $userid) {
            return ['ok' => false, 'error' => get_string('fellowship_calendar_err_permission', 'local_launchpad'), 'id' => null];
        }
        if (!has_capability('moodle/calendar:manageownentries', \context_user::instance($userid))) {
            return ['ok' => false, 'error' => get_string('fellowship_calendar_err_permission', 'local_launchpad'), 'id' => null];
        }

        $props = (object) [
            'name' => $name,
            'description' => $description,
            'format' => FORMAT_PLAIN,
            'courseid' => 0,
            'groupid' => 0,
            'userid' => $userid,
            'modulename' => '',
            'instance' => 0,
            'eventtype' => 'user',
            'timestart' => $timestart,
            'timeduration' => $timeduration,
            'visible' => 1,
        ];
        try {
            $probe = new \calendar_event($props);
            if (!calendar_add_event_allowed($probe)) {
                return ['ok' => false, 'error' => get_string('fellowship_calendar_err_permission', 'local_launchpad'), 'id' => null];
            }
            $created = \calendar_event::create($props, true);
            if (!$created) {
                return ['ok' => false, 'error' => get_string('fellowship_calendar_err_save', 'local_launchpad'), 'id' => null];
            }
            return ['ok' => true, 'error' => null, 'id' => (int) $created->id];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => get_string('fellowship_calendar_err_save', 'local_launchpad'), 'id' => null];
        }
    }

    /**
     * Update an owned personal calendar event.
     *
     * @return array{ok:bool,error:?string,id:?int}
     */
    public static function update_personal(
        int $userid,
        int $eventid,
        string $name,
        int $timestart,
        int $timeduration = 0,
        string $description = ''
    ): array {
        global $CFG, $DB, $USER;

        require_once($CFG->dirroot . '/calendar/lib.php');

        $name = trim($name);
        if ($eventid <= 0) {
            return ['ok' => false, 'error' => get_string('fellowship_calendar_err_missing', 'local_launchpad'), 'id' => null];
        }
        if ($name === '') {
            return ['ok' => false, 'error' => get_string('fellowship_calendar_err_name', 'local_launchpad'), 'id' => null];
        }
        if ($timestart <= 0) {
            return ['ok' => false, 'error' => get_string('fellowship_calendar_err_when', 'local_launchpad'), 'id' => null];
        }
        if ($timeduration < 0) {
            $timeduration = 0;
        }
        if ((int) $USER->id !== $userid) {
            return ['ok' => false, 'error' => get_string('fellowship_calendar_err_permission', 'local_launchpad'), 'id' => null];
        }

        $record = $DB->get_record('event', ['id' => $eventid]);
        if (!$record) {
            return ['ok' => false, 'error' => get_string('fellowship_calendar_err_missing', 'local_launchpad'), 'id' => null];
        }
        if ((int) $record->userid !== $userid || (int) $record->courseid !== 0
                || (string) $record->eventtype !== 'user' || !empty($record->modulename)) {
            return ['ok' => false, 'error' => get_string('fellowship_calendar_err_permission', 'local_launchpad'), 'id' => null];
        }

        try {
            $event = new \calendar_event($record);
            if (!calendar_edit_event_allowed($event, true)) {
                return ['ok' => false, 'error' => get_string('fellowship_calendar_err_permission', 'local_launchpad'), 'id' => null];
            }
            $event->update((object) [
                'name' => $name,
                'description' => $description,
                'format' => FORMAT_PLAIN,
                'timestart' => $timestart,
                'timeduration' => $timeduration,
                'eventtype' => 'user',
                'courseid' => 0,
                'userid' => $userid,
            ], true);
            return ['ok' => true, 'error' => null, 'id' => $eventid];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => get_string('fellowship_calendar_err_save', 'local_launchpad'), 'id' => null];
        }
    }

    /**
     * Delete an owned personal calendar event.
     *
     * @return array{ok:bool,error:?string,id:?int}
     */
    public static function delete_personal(int $userid, int $eventid): array {
        global $CFG, $DB, $USER;

        require_once($CFG->dirroot . '/calendar/lib.php');

        if ($eventid <= 0 || (int) $USER->id !== $userid) {
            return ['ok' => false, 'error' => get_string('fellowship_calendar_err_permission', 'local_launchpad'), 'id' => null];
        }

        $record = $DB->get_record('event', ['id' => $eventid]);
        if (!$record) {
            return ['ok' => false, 'error' => get_string('fellowship_calendar_err_missing', 'local_launchpad'), 'id' => null];
        }
        if ((int) $record->userid !== $userid || (int) $record->courseid !== 0
                || (string) $record->eventtype !== 'user' || !empty($record->modulename)) {
            return ['ok' => false, 'error' => get_string('fellowship_calendar_err_permission', 'local_launchpad'), 'id' => null];
        }

        try {
            $event = new \calendar_event($record);
            if (!calendar_delete_event_allowed($event)) {
                return ['ok' => false, 'error' => get_string('fellowship_calendar_err_permission', 'local_launchpad'), 'id' => null];
            }
            $event->delete(false);
            return ['ok' => true, 'error' => null, 'id' => $eventid];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => get_string('fellowship_calendar_err_save', 'local_launchpad'), 'id' => null];
        }
    }

    /**
     * Create a course calendar event (eventtype=course). Fail closed on caps.
     *
     * @return array{ok:bool,error:?string,id:?int}
     */
    public static function create_course(
        int $userid,
        int $courseid,
        string $name,
        int $timestart,
        int $timeduration = 0,
        string $description = ''
    ): array {
        global $CFG, $USER;

        require_once($CFG->dirroot . '/calendar/lib.php');

        $name = trim($name);
        if ($name === '') {
            return ['ok' => false, 'error' => get_string('fellowship_calendar_err_name', 'local_launchpad'), 'id' => null];
        }
        if ($timestart <= 0) {
            return ['ok' => false, 'error' => get_string('fellowship_calendar_err_when', 'local_launchpad'), 'id' => null];
        }
        if ($courseid <= 0 || (int) $USER->id !== $userid) {
            return ['ok' => false, 'error' => get_string('fellowship_calendar_err_permission', 'local_launchpad'), 'id' => null];
        }
        if ($timeduration < 0) {
            $timeduration = 0;
        }
        $coursectx = \context_course::instance($courseid);
        if (!has_capability('moodle/calendar:manageentries', $coursectx)) {
            return ['ok' => false, 'error' => get_string('fellowship_calendar_err_permission', 'local_launchpad'), 'id' => null];
        }

        $props = (object) [
            'name' => $name,
            'description' => $description,
            'format' => FORMAT_PLAIN,
            'courseid' => $courseid,
            'groupid' => 0,
            'userid' => $userid,
            'modulename' => '',
            'instance' => 0,
            'eventtype' => 'course',
            'timestart' => $timestart,
            'timeduration' => $timeduration,
            'visible' => 1,
        ];
        try {
            $probe = new \calendar_event($props);
            if (!calendar_add_event_allowed($probe)) {
                return ['ok' => false, 'error' => get_string('fellowship_calendar_err_permission', 'local_launchpad'), 'id' => null];
            }
            $created = \calendar_event::create($props, true);
            if (!$created) {
                return ['ok' => false, 'error' => get_string('fellowship_calendar_err_save', 'local_launchpad'), 'id' => null];
            }
            return ['ok' => true, 'error' => null, 'id' => (int) $created->id];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => get_string('fellowship_calendar_err_save', 'local_launchpad'), 'id' => null];
        }
    }

    /**
     * Update a managed course calendar event (not module-generated).
     *
     * @return array{ok:bool,error:?string,id:?int}
     */
    public static function update_course(
        int $userid,
        int $eventid,
        int $courseid,
        string $name,
        int $timestart,
        int $timeduration = 0,
        string $description = ''
    ): array {
        global $CFG, $DB, $USER;

        require_once($CFG->dirroot . '/calendar/lib.php');

        $name = trim($name);
        if ($eventid <= 0) {
            return ['ok' => false, 'error' => get_string('fellowship_calendar_err_missing', 'local_launchpad'), 'id' => null];
        }
        if ($name === '') {
            return ['ok' => false, 'error' => get_string('fellowship_calendar_err_name', 'local_launchpad'), 'id' => null];
        }
        if ($timestart <= 0 || $courseid <= 0) {
            return ['ok' => false, 'error' => get_string('fellowship_calendar_err_when', 'local_launchpad'), 'id' => null];
        }
        if ($timeduration < 0) {
            $timeduration = 0;
        }
        if ((int) $USER->id !== $userid) {
            return ['ok' => false, 'error' => get_string('fellowship_calendar_err_permission', 'local_launchpad'), 'id' => null];
        }

        $record = $DB->get_record('event', ['id' => $eventid]);
        if (!$record) {
            return ['ok' => false, 'error' => get_string('fellowship_calendar_err_missing', 'local_launchpad'), 'id' => null];
        }
        if ((string) $record->eventtype !== 'course' || !empty($record->modulename) || !empty($record->component)) {
            return ['ok' => false, 'error' => get_string('fellowship_calendar_err_permission', 'local_launchpad'), 'id' => null];
        }
        // Must manage both the existing and target course.
        foreach ([(int) $record->courseid, $courseid] as $cid) {
            if ($cid <= 0 || !has_capability('moodle/calendar:manageentries', \context_course::instance($cid))) {
                return ['ok' => false, 'error' => get_string('fellowship_calendar_err_permission', 'local_launchpad'), 'id' => null];
            }
        }

        try {
            $event = new \calendar_event($record);
            if (!calendar_edit_event_allowed($event, true)) {
                return ['ok' => false, 'error' => get_string('fellowship_calendar_err_permission', 'local_launchpad'), 'id' => null];
            }
            $event->update((object) [
                'name' => $name,
                'description' => $description,
                'format' => FORMAT_PLAIN,
                'timestart' => $timestart,
                'timeduration' => $timeduration,
                'eventtype' => 'course',
                'courseid' => $courseid,
                'userid' => $userid,
            ], true);
            return ['ok' => true, 'error' => null, 'id' => $eventid];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => get_string('fellowship_calendar_err_save', 'local_launchpad'), 'id' => null];
        }
    }

    /**
     * Delete a managed course calendar event.
     *
     * @return array{ok:bool,error:?string,id:?int}
     */
    public static function delete_course(int $userid, int $eventid): array {
        global $CFG, $DB, $USER;

        require_once($CFG->dirroot . '/calendar/lib.php');

        if ($eventid <= 0 || (int) $USER->id !== $userid) {
            return ['ok' => false, 'error' => get_string('fellowship_calendar_err_permission', 'local_launchpad'), 'id' => null];
        }

        $record = $DB->get_record('event', ['id' => $eventid]);
        if (!$record) {
            return ['ok' => false, 'error' => get_string('fellowship_calendar_err_missing', 'local_launchpad'), 'id' => null];
        }
        if ((string) $record->eventtype !== 'course' || (int) $record->courseid <= 0
                || !empty($record->modulename) || !empty($record->component)) {
            return ['ok' => false, 'error' => get_string('fellowship_calendar_err_permission', 'local_launchpad'), 'id' => null];
        }
        if (!has_capability('moodle/calendar:manageentries', \context_course::instance((int) $record->courseid))) {
            return ['ok' => false, 'error' => get_string('fellowship_calendar_err_permission', 'local_launchpad'), 'id' => null];
        }

        try {
            $event = new \calendar_event($record);
            if (!calendar_delete_event_allowed($event)) {
                return ['ok' => false, 'error' => get_string('fellowship_calendar_err_permission', 'local_launchpad'), 'id' => null];
            }
            $event->delete(false);
            return ['ok' => true, 'error' => null, 'id' => $eventid];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => get_string('fellowship_calendar_err_save', 'local_launchpad'), 'id' => null];
        }
    }

    /**
     * Delete personal or course event by inspecting the stored row.
     *
     * @return array{ok:bool,error:?string,id:?int}
     */
    public static function delete_event(int $userid, int $eventid): array {
        global $DB;

        $record = $DB->get_record('event', ['id' => $eventid]);
        if (!$record) {
            return ['ok' => false, 'error' => get_string('fellowship_calendar_err_missing', 'local_launchpad'), 'id' => null];
        }
        if ((string) $record->eventtype === 'course' && (int) $record->courseid > 0) {
            return self::delete_course($userid, $eventid);
        }
        return self::delete_personal($userid, $eventid);
    }

    /**
     * Build weeks/days Mustache structure for a calendar month.
     *
     * @param list<array> $items Mapped events (need day_key, name, id, …)
     * @return array{weeks:list<array>,weekday_labels:list<array>,has_month_events:bool}
     */
    public static function build_month_grid(int $year, int $month, array $items): array {
        global $CFG;

        $byday = [];
        foreach ($items as $item) {
            $key = (string) ($item['day_key'] ?? '');
            if ($key === '') {
                continue;
            }
            $byday[$key][] = $item;
        }

        // Moodle calendar start weekday (0=Sun … 6=Sat). Default Monday.
        $startwday = isset($CFG->calendar_startwday) ? (int) $CFG->calendar_startwday : 1;

        $monthstart = make_timestamp($year, $month, 1, 0, 0, 0);
        $daysinmonth = (int) date('t', $monthstart);
        // PHP date('w'): 0=Sun … 6=Sat for the 1st.
        $firstw = (int) date('w', $monthstart);
        $lead = ($firstw - $startwday + 7) % 7;

        $weekdaylabels = [];
        $names = [
            get_string('sunday', 'calendar'),
            get_string('monday', 'calendar'),
            get_string('tuesday', 'calendar'),
            get_string('wednesday', 'calendar'),
            get_string('thursday', 'calendar'),
            get_string('friday', 'calendar'),
            get_string('saturday', 'calendar'),
        ];
        for ($i = 0; $i < 7; $i++) {
            $idx = ($startwday + $i) % 7;
            $label = $names[$idx];
            $weekdaylabels[] = [
                'label' => \core_text::substr($label, 0, 2),
                'label_full' => $label,
            ];
        }

        $todaykey = userdate(time(), '%Y-%m-%d');
        $cells = [];
        // Leading days from previous month.
        if ($lead > 0) {
            $prevmonth = $month === 1 ? 12 : $month - 1;
            $prevyear = $month === 1 ? $year - 1 : $year;
            $prevdays = (int) date('t', make_timestamp($prevyear, $prevmonth, 1, 0, 0, 0));
            for ($i = $lead - 1; $i >= 0; $i--) {
                $d = $prevdays - $i;
                $cells[] = self::day_cell($prevyear, $prevmonth, $d, true, $byday, $todaykey);
            }
        }
        for ($d = 1; $d <= $daysinmonth; $d++) {
            $cells[] = self::day_cell($year, $month, $d, false, $byday, $todaykey);
        }
        // Trailing to complete weeks.
        $trail = (7 - (count($cells) % 7)) % 7;
        if ($trail > 0) {
            $nextmonth = $month === 12 ? 1 : $month + 1;
            $nextyear = $month === 12 ? $year + 1 : $year;
            for ($d = 1; $d <= $trail; $d++) {
                $cells[] = self::day_cell($nextyear, $nextmonth, $d, true, $byday, $todaykey);
            }
        }

        $weeks = [];
        foreach (array_chunk($cells, 7) as $weekdays) {
            $weeks[] = ['days' => $weekdays];
        }

        $inmonthcount = 0;
        foreach ($items as $item) {
            $ts = (int) ($item['timestart'] ?? 0);
            if ($ts >= make_timestamp($year, $month, 1, 0, 0, 0)
                    && $ts < ($month === 12
                        ? make_timestamp($year + 1, 1, 1, 0, 0, 0)
                        : make_timestamp($year, $month + 1, 1, 0, 0, 0))) {
                $inmonthcount++;
            }
        }

        return [
            'weeks' => $weeks,
            'weekday_labels' => $weekdaylabels,
            'has_month_events' => $inmonthcount > 0,
        ];
    }

    /**
     * @param array<string,list<array>> $byday
     * @return array<string,mixed>
     */
    private static function day_cell(
        int $year,
        int $month,
        int $day,
        bool $othermonth,
        array $byday,
        string $todaykey
    ): array {
        $ts = make_timestamp($year, $month, $day, 12, 0, 0);
        // Match map_event day_key (user timezone).
        $key = userdate($ts, '%Y-%m-%d');
        $events = $byday[$key] ?? [];
        // Cap chips in cell; full day list opens via "+N more" / day number (Moodle-style fold).
        $chips = array_slice($events, 0, self::MONTH_CELL_CHIP_LIMIT);
        $more = max(0, count($events) - count($chips));
        return [
            'day' => $day,
            'day_key' => $key,
            'day_label' => userdate($ts, get_string('strftimedaydate', 'langconfig')),
            'is_othermonth' => $othermonth,
            'is_today' => ($key === $todaykey),
            'has_events' => $events !== [],
            'events' => $chips,
            'all_events' => $events,
            'event_count' => count($events),
            'more_count' => $more,
            'has_more' => $more > 0,
        ];
    }

    /**
     * @param list<\stdClass> $courses
     * @return array<int,string>
     */
    private static function course_name_map(array $courses): array {
        $map = [];
        foreach ($courses as $c) {
            $map[(int) $c->id] = format_string($c->fullname);
        }
        return $map;
    }

    private static function moodle_month_url(): string {
        return (new \moodle_url('/calendar/view.php', ['view' => 'month']))->out(false);
    }
}
