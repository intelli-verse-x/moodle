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

    public function test_score_from_levels_math(): void {
        // All None (0) → 0.
        $none = mentor_dashboard::score_from_levels([0, 0, 0, 0]);
        $this->assertNotNull($none);
        $this->assertSame([0, 0, 0, 0], $none['scores']);
        $this->assertSame(0, $none['total']);

        // All Partial (0.6) → 18+15+15+12 = 60.
        $partial = mentor_dashboard::score_from_levels([2, 2, 2, 2]);
        $this->assertNotNull($partial);
        $this->assertSame([18, 15, 15, 12], $partial['scores']);
        $this->assertSame(60, $partial['total']);

        // All Missing (0.4) → 12+10+10+8 = 40 (floor without None).
        $missing = mentor_dashboard::score_from_levels([1, 1, 1, 1]);
        $this->assertNotNull($missing);
        $this->assertSame(40, $missing['total']);

        // Solid across → 24+20+20+16 = 80 (Met).
        $solid = mentor_dashboard::score_from_levels([3, 3, 3, 3]);
        $this->assertNotNull($solid);
        $this->assertSame(80, $solid['total']);

        // Example total 79: Solid / Solid / Partial / Strong.
        $mixed = mentor_dashboard::score_from_levels([3, 3, 2, 4]);
        $this->assertNotNull($mixed);
        $this->assertSame([24, 20, 15, 20], $mixed['scores']);
        $this->assertSame(79, $mixed['total']);

        $this->assertNull(mentor_dashboard::score_from_levels([2, 2, 2]));
        $this->assertNull(mentor_dashboard::score_from_levels([2, 2, 2, 9]));
        $this->assertCount(5, mentor_dashboard::rubric_level_labels());
    }

    public function test_pack_unpack_rubric_and_next_step(): void {
        $scored = mentor_dashboard::score_from_levels([3, 3, 2, 4]);
        $this->assertNotNull($scored);
        $packed = mentor_dashboard::pack_review_comment('Tighten the acceptance table.', 'Add two missing ACs', $scored);
        $this->assertStringContainsString(mentor_dashboard::RUBRIC_MARKER, $packed);
        $this->assertStringContainsString(mentor_dashboard::NEXT_STEP_MARKER, $packed);
        $this->assertStringContainsString('"total":79', $packed);
        $this->assertStringContainsString('"v":' . mentor_dashboard::RUBRIC_PAYLOAD_VERSION, $packed);

        $parts = mentor_dashboard::unpack_review_comment($packed);
        $this->assertTrue($parts['has_rubric']);
        $this->assertSame([3, 3, 2, 4], $parts['levels']);
        $this->assertSame([24, 20, 15, 20], $parts['scores']);
        $this->assertSame(79, $parts['total']);
        $this->assertSame('Add two missing ACs', $parts['next_step']);
        $this->assertSame('Tighten the acceptance table.', $parts['comment']);

        // v1 payload (Missing=0…Strong=3) shifts +1 so fellow labels stay correct.
        $v1 = mentor_dashboard::RUBRIC_MARKER
            . '{"v":1,"levels":[2,2,1,3],"scores":[24,20,15,20],"total":79}' . "\n\n"
            . 'Legacy packed review';
        $v1parts = mentor_dashboard::unpack_review_comment($v1);
        $this->assertTrue($v1parts['has_rubric']);
        $this->assertSame([3, 3, 2, 4], $v1parts['levels']);
        $this->assertSame(79, $v1parts['total']);
        $rows = mentor_dashboard::rubric_breakdown_rows($v1parts);
        $this->assertSame(get_string('mentor_review_level_solid', 'local_launchpad'), $rows[0]['level_label']);
        $this->assertSame(get_string('mentor_review_level_partial', 'local_launchpad'), $rows[2]['level_label']);

        // v3 stores labels/weights so fellow rows stay honest after pack edits.
        $this->assertSame($scored['labels'], $parts['labels']);
        $this->assertSame($scored['weights'], $parts['weights']);
        $this->assertStringContainsString('"labels"', $packed);
        $v3rows = mentor_dashboard::rubric_breakdown_rows([
            'has_rubric' => true,
            'levels' => [4, 4, 4, 4],
            'scores' => [35, 30, 20, 15],
            'total' => 100,
            'labels' => ['Routing', 'Eval', 'Safe', 'Runbook'],
            'weights' => [35, 30, 20, 15],
        ]);
        $this->assertSame('Routing', $v3rows[0]['label']);
        $this->assertSame(35, $v3rows[0]['weight']);
        $this->assertSame(35, $v3rows[0]['score_max']);

        $legacy = mentor_dashboard::unpack_review_comment(
            mentor_dashboard::NEXT_STEP_MARKER . " Fix the brief\n\nPlain change note"
        );
        $this->assertFalse($legacy['has_rubric']);
        $this->assertSame([], $legacy['levels']);
        $this->assertSame('Fix the brief', $legacy['next_step']);
        $this->assertSame('Plain change note', $legacy['comment']);
        $this->assertSame([], mentor_dashboard::rubric_breakdown_rows($legacy));
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

    public function test_fellow_detail_url_contains_view_and_ids(): void {
        $url = mentor_dashboard::fellow_detail_url(9, 42);
        $this->assertStringContainsString('view=fellow', $url);
        $this->assertStringContainsString('userid=9', $url);
        $this->assertStringContainsString('course=42', $url);
    }

    public function test_get_fellow_detail_fail_closed(): void {
        $course = (object) ['id' => 3, 'fullname' => 'Track', 'idnumber' => 'lp-track'];
        $this->assertNull(mentor_dashboard::get_fellow_detail(0, 2, 3, [$course]));
        $this->assertNull(mentor_dashboard::get_fellow_detail(1, 0, 3, [$course]));
        $this->assertNull(mentor_dashboard::get_fellow_detail(1, 2, 0, [$course]));
        $this->assertNull(mentor_dashboard::get_fellow_detail(1, 2, 3, []));
        // Course not in mentor list — no enroll lookup.
        $this->assertNull(mentor_dashboard::get_fellow_detail(1, 2, 99, [$course]));
    }
}
