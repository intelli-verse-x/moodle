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
 * Moodle-backed calendar helper for LaunchPad (read path Phase 1).
 *
 * @package    local_launchpad
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class calendar_service {

    /** Upcoming list window after "now". */
    public const UPCOMING_DAYS = 90;

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
    public static function map_event(\stdClass $event, array $coursenames): array {
        $courseid = (int) ($event->courseid ?? 0);
        $timestart = (int) ($event->timestart ?? 0);
        $ispersonal = $courseid <= 0;
        $desc = '';
        if (!empty($event->description)) {
            $html = format_text(
                (string) $event->description,
                (int) ($event->format ?? FORMAT_HTML),
                ['para' => false, 'filter' => false]
            );
            $desc = trim(html_to_text($html, 0, false));
            if (\core_text::strlen($desc) > 280) {
                $desc = \core_text::substr($desc, 0, 277) . '…';
            }
        }

        return [
            'id' => (int) ($event->id ?? 0),
            'name' => format_string((string) ($event->name ?? '')),
            'when' => userdate($timestart, get_string('strftimedatetimeshort', 'langconfig')),
            'timestart' => $timestart,
            'day_key' => userdate($timestart, '%Y-%m-%d'),
            'courseid' => $courseid,
            'is_personal' => $ispersonal,
            'course_name' => $ispersonal
                ? get_string('fellowship_calendar_personal', 'local_launchpad')
                : ($coursenames[$courseid] ?? ''),
            'description_excerpt' => $desc,
            'has_description' => $desc !== '',
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
            $items[] = self::map_event($event, $coursenames);
        }
        usort($items, static fn(array $a, array $b): int => ($a['timestart'] <=> $b['timestart']));
        return [
            'items' => $items,
            'has_items' => $items !== [],
            'moodle_calendar_url' => self::moodle_month_url(),
        ];
    }

    /**
     * Full fellow Calendar page context (month grid + upcoming + nav URLs).
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
        if ($mode !== 'upcoming') {
            $mode = 'month';
        }
        [$tstart, $tend] = self::month_range($year, $month);
        // Load a slightly wider window so grid edge padding days can show events.
        $padstart = $tstart - (7 * DAYSECS);
        $padend = $tend + (7 * DAYSECS);
        $coursenames = self::course_name_map($courses);
        $monthitems = [];
        foreach (self::fetch_raw_events($userid, $courses, $padstart, $padend) as $event) {
            $monthitems[] = self::map_event($event, $coursenames);
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

        $fellowpath = persona_home::FELLOW_PATH;
        return array_merge($grid, [
            'mode_month' => $mode === 'month',
            'mode_upcoming' => $mode === 'upcoming',
            'month_label' => userdate($basemonth, '%B %Y'),
            'current_time' => $basemonth,
            'prev_time' => $prevtime,
            'next_time' => $nexttime,
            'prev_url' => (new \moodle_url($fellowpath, [
                'view' => 'calendar',
                'cal' => 'month',
                'time' => $prevtime,
            ]))->out(false),
            'next_url' => (new \moodle_url($fellowpath, [
                'view' => 'calendar',
                'cal' => 'month',
                'time' => $nexttime,
            ]))->out(false),
            'month_toggle_url' => (new \moodle_url($fellowpath, [
                'view' => 'calendar',
                'cal' => 'month',
                'time' => $basemonth,
            ]))->out(false),
            'upcoming_toggle_url' => (new \moodle_url($fellowpath, [
                'view' => 'calendar',
                'cal' => 'upcoming',
                'time' => $basemonth,
            ]))->out(false),
            'ajax_url' => (new \moodle_url('/local/launchpad/ajax_calendar_month.php'))->out(false),
            'sesskey' => sesskey(),
            'upcoming_items' => $upcoming['items'],
            'has_upcoming_items' => $upcoming['has_items'],
            'moodle_calendar_url' => self::moodle_month_url(),
            // Flat list for detail modal lookups.
            'detail_events' => $monthitems,
            'has_detail_events' => $monthitems !== [],
        ]);
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
        // Cap chips in cell; rest implied via detail.
        $chips = array_slice($events, 0, 3);
        $more = max(0, count($events) - count($chips));
        return [
            'day' => $day,
            'day_key' => $key,
            'is_othermonth' => $othermonth,
            'is_today' => ($key === $todaykey),
            'has_events' => $events !== [],
            'events' => $chips,
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
