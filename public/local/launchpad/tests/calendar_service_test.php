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
 * Unit tests for Moodle-backed calendar helper (Phase 1 read path).
 *
 * @package    local_launchpad
 * @category   phpunit
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_launchpad\calendar_service
 */
final class calendar_service_test extends \advanced_testcase {

    public function test_resolve_month_explicit_and_time(): void {
        $this->resetAfterTest();

        $explicit = calendar_service::resolve_month(null, 2026, 3);
        $this->assertSame(2026, $explicit['year']);
        $this->assertSame(3, $explicit['month']);
        $this->assertSame(make_timestamp(2026, 3, 1, 0, 0, 0), $explicit['time']);

        $fromtime = calendar_service::resolve_month(make_timestamp(2025, 11, 20, 15, 0, 0), null, null);
        $this->assertSame(2025, $fromtime['year']);
        $this->assertSame(11, $fromtime['month']);
    }

    public function test_month_range_exclusive_end(): void {
        $this->resetAfterTest();

        [$start, $end] = calendar_service::month_range(2026, 2);
        $this->assertSame(make_timestamp(2026, 2, 1, 0, 0, 0), $start);
        $this->assertSame(make_timestamp(2026, 3, 1, 0, 0, 0), $end);

        [$ystart, $yend] = calendar_service::month_range(2026, 12);
        $this->assertSame(make_timestamp(2026, 12, 1, 0, 0, 0), $ystart);
        $this->assertSame(make_timestamp(2027, 1, 1, 0, 0, 0), $yend);
    }

    public function test_build_month_grid_places_event_and_caps_chips(): void {
        $this->resetAfterTest();

        $ts = make_timestamp(2026, 6, 15, 10, 0, 0);
        $daykey = calendar_service::user_ymd($ts);
        $items = [];
        for ($i = 1; $i <= 5; $i++) {
            $items[] = [
                'id' => $i,
                'name' => 'Event ' . $i,
                'when' => 'when',
                'timestart' => $ts + $i,
                'day_key' => $daykey,
                'courseid' => 1,
                'is_personal' => false,
                'course_name' => 'Course',
                'description_excerpt' => '',
                'has_description' => false,
                'url' => 'https://example.test/cal',
                'eventtype' => 'course',
                'modulename' => '',
                'is_module' => false,
            ];
        }

        $grid = calendar_service::build_month_grid(2026, 6, $items);
        $this->assertTrue($grid['has_month_events']);
        $this->assertCount(7, $grid['weekday_labels']);
        $this->assertNotEmpty($grid['weeks']);

        $found = null;
        foreach ($grid['weeks'] as $week) {
            foreach ($week['days'] as $day) {
                if ((int) $day['day'] === 15 && empty($day['is_othermonth'])) {
                    $found = $day;
                    break 2;
                }
            }
        }
        $this->assertNotNull($found);
        $this->assertTrue($found['has_events']);
        $this->assertCount(3, $found['events']);
        $this->assertCount(5, $found['all_events']);
        $this->assertTrue($found['has_more']);
        $this->assertSame(2, $found['more_count']);
    }

    public function test_map_event_marks_personal_and_module(): void {
        $this->resetAfterTest();

        $personal = (object) [
            'id' => 9,
            'name' => 'Personal block',
            'courseid' => 0,
            'timestart' => make_timestamp(2026, 4, 2, 9, 0, 0),
            'description' => '',
            'format' => FORMAT_HTML,
            'eventtype' => 'user',
            'modulename' => '',
        ];
        $mapped = calendar_service::map_event($personal, []);
        $this->assertTrue($mapped['is_personal']);
        $this->assertFalse($mapped['is_module']);
        $this->assertSame(get_string('fellowship_calendar_personal', 'local_launchpad'), $mapped['course_name']);

        $module = (object) [
            'id' => 10,
            'name' => 'Assign due',
            'courseid' => 42,
            'timestart' => make_timestamp(2026, 4, 3, 17, 0, 0),
            'description' => '<p>Submit evidence</p>',
            'format' => FORMAT_HTML,
            'eventtype' => 'due',
            'modulename' => 'assign',
        ];
        $mappedmod = calendar_service::map_event($module, [42 => 'Track A']);
        $this->assertTrue($mappedmod['is_module']);
        $this->assertSame('Track A', $mappedmod['course_name']);
        $this->assertTrue($mappedmod['has_description']);
        $this->assertStringContainsString('Submit evidence', $mappedmod['description_excerpt']);
    }

    public function test_parse_user_datetime(): void {
        $this->resetAfterTest();
        $ts = calendar_service::parse_user_datetime('2026-06-15', '10:30');
        $this->assertNotNull($ts);
        $this->assertSame(make_timestamp(2026, 6, 15, 10, 30, 0), $ts);
        $this->assertNull(calendar_service::parse_user_datetime('bad', '10:30'));
        $this->assertNull(calendar_service::parse_user_datetime('2026-06-15', '25:00'));
    }

    public function test_create_update_delete_personal_event(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $timestart = make_timestamp(2026, 7, 4, 14, 0, 0);

        $created = calendar_service::create_personal(
            (int) $user->id,
            'LP personal probe',
            $timestart,
            1800,
            'Bring laptop'
        );
        $this->assertTrue($created['ok'], $created['error'] ?? '');
        $this->assertNotEmpty($created['id']);

        $row = $DB->get_record('event', ['id' => $created['id']], '*', MUST_EXIST);
        $this->assertSame('user', $row->eventtype);
        $this->assertSame(0, (int) $row->courseid);
        $this->assertSame((int) $user->id, (int) $row->userid);
        $this->assertSame('LP personal probe', $row->name);

        $updated = calendar_service::update_personal(
            (int) $user->id,
            (int) $created['id'],
            'LP personal updated',
            $timestart + HOURSECS,
            0,
            ''
        );
        $this->assertTrue($updated['ok'], $updated['error'] ?? '');
        $row2 = $DB->get_record('event', ['id' => $created['id']], '*', MUST_EXIST);
        $this->assertSame('LP personal updated', $row2->name);

        $deleted = calendar_service::delete_personal((int) $user->id, (int) $created['id']);
        $this->assertTrue($deleted['ok'], $deleted['error'] ?? '');
        $this->assertFalse($DB->record_exists('event', ['id' => $created['id']]));
    }

    public function test_create_update_delete_course_event(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['idnumber' => 'lp-cal-p3']);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);
        $timestart = make_timestamp(2026, 8, 12, 16, 0, 0);

        $created = calendar_service::create_course(
            (int) $teacher->id,
            (int) $course->id,
            'LP course deadline',
            $timestart,
            0,
            'Bring evidence'
        );
        $this->assertTrue($created['ok'], $created['error'] ?? '');
        $row = $DB->get_record('event', ['id' => $created['id']], '*', MUST_EXIST);
        $this->assertSame('course', $row->eventtype);
        $this->assertSame((int) $course->id, (int) $row->courseid);

        $manageable = calendar_service::courses_user_can_manage((int) $teacher->id, [$course]);
        $this->assertCount(1, $manageable);
        $this->assertSame((int) $course->id, $manageable[0]['id']);

        $updated = calendar_service::update_course(
            (int) $teacher->id,
            (int) $created['id'],
            (int) $course->id,
            'LP course deadline updated',
            $timestart + DAYSECS,
            0,
            ''
        );
        $this->assertTrue($updated['ok'], $updated['error'] ?? '');

        $fellow = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($fellow);
        $mapped = calendar_service::map_event($row, [(int) $course->id => $course->fullname], (int) $fellow->id);
        $this->assertFalse($mapped['can_edit']);

        $this->setUser($teacher);
        $deleted = calendar_service::delete_course((int) $teacher->id, (int) $created['id']);
        $this->assertTrue($deleted['ok'], $deleted['error'] ?? '');
        $this->assertFalse($DB->record_exists('event', ['id' => $created['id']]));
    }

    public function test_upcoming_returns_course_event(): void {
        global $CFG;
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/calendar/lib.php');

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $this->setUser($user);

        $timestart = time() + (3 * DAYSECS);
        $created = \calendar_event::create([
            'name' => 'LP cal probe',
            'courseid' => $course->id,
            'userid' => $user->id,
            'eventtype' => 'course',
            'timestart' => $timestart,
            'timeduration' => 0,
            'description' => 'Probe body',
            'format' => FORMAT_PLAIN,
            'visible' => 1,
        ], false);
        $this->assertNotFalse($created);

        $result = calendar_service::upcoming((int) $user->id, [$course]);
        $this->assertTrue($result['has_items']);
        $names = array_column($result['items'], 'name');
        $this->assertContains('LP cal probe', $names);
    }

    public function test_user_ymd_zero_pads(): void {
        $this->resetAfterTest();
        $ts = make_timestamp(2026, 11, 3, 9, 5, 0);
        $this->assertSame('2026-11-03', calendar_service::user_ymd($ts));
        $this->assertSame('09:05', calendar_service::user_hi($ts));
    }

    public function test_resolve_week_has_seven_days(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->calendar_startwday = 1; // Monday.
        $week = calendar_service::resolve_week(make_timestamp(2026, 11, 4, 15, 0, 0)); // Wednesday.
        $this->assertCount(7, $week['days']);
        $this->assertSame(2026, $week['days'][0]['year']);
        $this->assertSame(11, $week['days'][0]['month']);
        $this->assertSame(2, $week['days'][0]['day']); // Monday.
        $this->assertSame(8, $week['days'][6]['day']); // Sunday.
        $this->assertSame(7 * DAYSECS, $week['end'] - $week['start']);
    }

    public function test_normalize_repeats(): void {
        $this->assertSame(1, calendar_service::normalize_repeats(0));
        $this->assertSame(4, calendar_service::normalize_repeats(4));
        $this->assertSame(52, calendar_service::normalize_repeats(99));
    }

    public function test_create_personal_weekly_repeat(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $timestart = make_timestamp(2026, 12, 1, 10, 0, 0);
        $created = calendar_service::create_personal(
            (int) $user->id,
            'LP weekly probe',
            $timestart,
            0,
            '',
            '',
            3
        );
        $this->assertTrue($created['ok'], $created['error'] ?? '');
        $rows = $DB->get_records('event', ['userid' => $user->id, 'name' => 'LP weekly probe'], 'timestart ASC');
        $this->assertCount(3, $rows);
        $starts = array_values(array_map(static fn($r) => (int) $r->timestart, $rows));
        $this->assertSame($timestart, $starts[0]);
        $this->assertSame($timestart + (7 * DAYSECS), $starts[1]);
        $this->assertSame($timestart + (14 * DAYSECS), $starts[2]);
        $repeatid = (int) reset($rows)->repeatid;
        $this->assertGreaterThan(0, $repeatid);
        foreach ($rows as $row) {
            $this->assertSame($repeatid, (int) $row->repeatid);
            $DB->delete_records('event', ['id' => $row->id]);
        }
    }

    public function test_resolve_day_and_filter_items(): void {
        $this->resetAfterTest();

        $day = calendar_service::resolve_day(make_timestamp(2026, 9, 18, 15, 30, 0));
        $this->assertSame(2026, $day['year']);
        $this->assertSame(9, $day['month']);
        $this->assertSame(18, $day['day']);

        $items = [
            ['id' => 1, 'filter_bucket' => 'personal', 'is_personal' => true],
            ['id' => 2, 'filter_bucket' => 'course', 'is_personal' => false],
            ['id' => 3, 'is_personal' => true],
        ];
        $this->assertCount(3, calendar_service::filter_items($items, 'all'));
        $this->assertCount(2, calendar_service::filter_items($items, 'personal'));
        $this->assertCount(1, calendar_service::filter_items($items, 'course'));
    }

    public function test_timeduration_from_form_modes(): void {
        $this->resetAfterTest();

        $start = make_timestamp(2026, 10, 1, 9, 0, 0);
        $none = calendar_service::timeduration_from_form('none', $start, 0, '', '');
        $this->assertTrue($none['ok']);
        $this->assertSame(0, $none['timeduration']);

        $mins = calendar_service::timeduration_from_form('minutes', $start, 45, '', '');
        $this->assertTrue($mins['ok']);
        $this->assertSame(45 * 60, $mins['timeduration']);

        $until = calendar_service::timeduration_from_form('until', $start, 0, '2026-10-01', '10:30');
        $this->assertTrue($until['ok']);
        $this->assertSame(90 * 60, $until['timeduration']);

        $bad = calendar_service::timeduration_from_form('until', $start, 0, '2026-10-01', '08:00');
        $this->assertFalse($bad['ok']);
    }

    public function test_map_event_location_html_and_duration(): void {
        $this->resetAfterTest();

        $event = (object) [
            'id' => 44,
            'name' => 'Studio block',
            'courseid' => 0,
            'timestart' => make_timestamp(2026, 10, 5, 11, 0, 0),
            'timeduration' => 3600,
            'description' => '<p><strong>Bring</strong> kit</p>',
            'format' => FORMAT_HTML,
            'eventtype' => 'user',
            'modulename' => '',
            'location' => 'Lab 2',
            'userid' => 1,
        ];
        $mapped = calendar_service::map_event($event, []);
        $this->assertTrue($mapped['has_location']);
        $this->assertSame('Lab 2', $mapped['location']);
        $this->assertTrue($mapped['has_description']);
        $this->assertStringContainsString('<strong>', $mapped['description_html']);
        $this->assertStringContainsString('Bring', $mapped['description_plain']);
        $this->assertSame('until', $mapped['duration_mode']);
        $this->assertSame(60, $mapped['duration_minutes']);
        $this->assertSame('personal', $mapped['filter_bucket']);
    }

    public function test_reschedule_to_day_keeps_clock_time(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $timestart = make_timestamp(2026, 11, 2, 14, 30, 0);
        $created = calendar_service::create_personal(
            (int) $user->id,
            'LP drag probe',
            $timestart,
            0,
            '',
            'Room A'
        );
        $this->assertTrue($created['ok'], $created['error'] ?? '');

        $moved = calendar_service::reschedule_to_day(
            (int) $user->id,
            (int) $created['id'],
            '2026-11-09'
        );
        $this->assertTrue($moved['ok'], $moved['error'] ?? '');
        $row = $DB->get_record('event', ['id' => $created['id']], '*', MUST_EXIST);
        $this->assertSame(make_timestamp(2026, 11, 9, 14, 30, 0), (int) $row->timestart);
        $this->assertSame('Room A', $row->location);
    }

    public function test_build_ical_includes_event(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $this->setUser($user);

        $created = calendar_service::create_personal(
            (int) $user->id,
            'LP ical probe',
            time() + DAYSECS,
            1800,
            'Body',
            'Hall'
        );
        $this->assertTrue($created['ok'], $created['error'] ?? '');

        $ical = calendar_service::build_ical((int) $user->id, [$course]);
        $this->assertStringContainsString('BEGIN:VCALENDAR', $ical);
        $this->assertStringContainsString('LP ical probe', $ical);
        $this->assertStringContainsString('Hall', $ical);
    }
}
