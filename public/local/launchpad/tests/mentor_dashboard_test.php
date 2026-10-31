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
 * Mentor dashboard helper tests (no DB writes).
 *
 * @package    local_launchpad
 * @category   phpunit
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_launchpad\mentor_dashboard
 */
final class mentor_dashboard_test extends \basic_testcase {

    public function test_can_access_rejects_invalid(): void {
        $this->assertFalse(mentor_dashboard::can_access(0));
        $this->assertFalse(mentor_dashboard::can_access(-1));
    }

    public function test_data_region_marker(): void {
        $this->assertSame('local-launchpad-mentor', mentor_dashboard::DATA_REGION);
    }

    public function test_list_review_queue_empty_without_courses(): void {
        $out = mentor_dashboard::list_review_queue(1, [], 'waiting');
        $this->assertFalse($out['has_items']);
        $this->assertSame([], $out['items']);
        $this->assertSame(0, $out['waiting_count']);
        $this->assertSame('waiting', $out['filter']);
        $this->assertCount(4, $out['filters']);
    }

    public function test_home_summary_empty_without_courses(): void {
        $out = mentor_dashboard::home_summary(1, []);
        $this->assertFalse($out['has_courses']);
        $this->assertFalse($out['has_waiting']);
        $this->assertFalse($out['has_waiting_preview']);
        $this->assertSame(0, $out['waiting_count']);
        $this->assertSame([], $out['courses']);
        $this->assertCount(4, $out['rubric_rows']);
        $this->assertNotSame('', $out['coach_tip']);
    }

    public function test_rubric_at_a_glance_weights(): void {
        $rows = mentor_dashboard::rubric_at_a_glance();
        $this->assertCount(4, $rows);
        $sum = 0;
        foreach ($rows as $row) {
            $sum += (int) $row['weight'];
        }
        $this->assertSame(100, $sum);
        $this->assertSame(6, mentor_dashboard::HOME_QUEUE_PREVIEW);
    }

    public function test_list_fellows_empty_without_courses(): void {
        $out = mentor_dashboard::list_fellows(1, []);
        $this->assertFalse($out['has_items']);
        $this->assertSame([], $out['items']);
        $this->assertSame(0, $out['fellow_count']);
    }

    public function test_review_url_contains_view_and_ids(): void {
        $url = mentor_dashboard::review_url(15, 3);
        $this->assertStringContainsString('view=review', $url);
        $this->assertStringContainsString('id=15', $url);
        $this->assertStringContainsString('userid=3', $url);
    }
}
