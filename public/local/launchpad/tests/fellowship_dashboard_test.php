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
 * Fellowship dashboard helper tests (no DB writes).
 *
 * @package    local_launchpad
 * @category   phpunit
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_launchpad\fellowship_dashboard
 */
final class fellowship_dashboard_test extends \basic_testcase {

    public function test_list_courses_invalid_user_empty(): void {
        $this->assertSame([], fellowship_dashboard::list_courses(0));
        $this->assertSame([], fellowship_dashboard::list_courses(-1));
    }

    public function test_can_access_rejects_invalid(): void {
        $this->assertFalse(fellowship_dashboard::can_access(0));
        $this->assertFalse(fellowship_dashboard::can_access(-3));
    }

    public function test_mod_icon_class_known_modules(): void {
        $this->assertSame('quiz', fellowship_dashboard::mod_icon_class('quiz'));
        $this->assertSame('assign', fellowship_dashboard::mod_icon_class('assign'));
        $this->assertSame('page', fellowship_dashboard::mod_icon_class('page'));
        $this->assertSame('activity', fellowship_dashboard::mod_icon_class('unknownmod'));
    }

    public function test_initials(): void {
        $user = (object) ['firstname' => 'Aisha', 'lastname' => 'Khan'];
        $this->assertSame('AK', fellowship_dashboard::initials($user));
        $empty = (object) ['firstname' => '', 'lastname' => ''];
        $this->assertSame('?', fellowship_dashboard::initials($empty));
    }

    public function test_display_label_strips_placeholder_suffix(): void {
        $this->assertSame(
            'Industry simulation brief',
            fellowship_dashboard::display_label('Industry simulation brief [placeholder — content pending]')
        );
        $this->assertSame(
            'Submitted assignment · Industry simulation submission',
            fellowship_dashboard::display_label(
                'Submitted assignment · Industry simulation submission [placeholder — content pending]'
            )
        );
    }

    public function test_data_region_marker(): void {
        $this->assertSame('local-launchpad-fellowship', fellowship_dashboard::DATA_REGION);
    }

    public function test_home_summary_orders_recent_badges_newest_first(): void {
        $outline = [
            'percent' => 20,
            'completed' => 1,
            'trackable' => 5,
        ];
        $journey = [
            'gates' => [
                [
                    'name' => 'Foundations',
                    'short' => 'Foundations',
                    'is_current' => true,
                    'is_done' => false,
                ],
            ],
        ];
        $live = [
            'badges_earned' => 2,
            'badges' => [
                [
                    'id' => 'older',
                    'name' => 'Older badge',
                    'earned' => true,
                    'at' => 100,
                    'icon' => 'plant-01',
                    'detail' => 'Earned earlier',
                    'css' => 'on',
                ],
                [
                    'id' => 'newer',
                    'name' => 'Newer badge',
                    'earned' => true,
                    'at' => 200,
                    'icon' => 'search-01',
                    'detail' => 'Earned later',
                    'css' => 'on',
                ],
                [
                    'id' => 'locked',
                    'name' => 'Locked',
                    'earned' => false,
                    'at' => 0,
                    'icon' => 'books-01',
                    'detail' => 'How',
                    'css' => '',
                ],
            ],
        ];

        $summary = fellowship_dashboard::home_summary($outline, $journey, $live, 4);
        $this->assertTrue($summary['show_hero_ring']);
        $this->assertTrue($summary['has_percent']);
        $this->assertSame(20, $summary['ring_pct']);
        $this->assertSame('Foundations', $summary['ring_label']);
        $this->assertSame('2/3', $summary['stat_badges_value']);
        $this->assertSame('2/3', $summary['stat_cards'][0]['value']);
        $this->assertSame('1/5', $summary['stat_required_value']);
        $this->assertSame('Foundations', $summary['stat_gate_value']);
        $this->assertFalse($summary['all_tracked_done']);
        $this->assertTrue($summary['has_recent_badges']);
        $this->assertCount(2, $summary['recent_badges']);
        $this->assertSame('newer', $summary['recent_badges'][0]['id']);
        $this->assertSame('older', $summary['recent_badges'][1]['id']);
    }

    public function test_home_summary_shows_ring_when_completion_untracked(): void {
        $summary = fellowship_dashboard::home_summary(
            ['percent' => null, 'completed' => 0, 'trackable' => 0, 'sections' => []],
            ['gates' => [[
                'name' => 'Foundations',
                'short' => 'Foundations',
                'sectionnum' => 1,
                'is_current' => true,
                'is_done' => false,
            ]]],
            ['badges_earned' => 0, 'badges' => []],
        );
        $this->assertTrue($summary['show_hero_ring']);
        $this->assertFalse($summary['has_percent']);
        $this->assertSame(0, $summary['ring_pct']);
        $this->assertSame('Foundations', $summary['ring_label']);
        $this->assertFalse($summary['has_recent_badges']);
        $this->assertSame(
            get_string('fellowship_stat_required_na', 'local_launchpad'),
            $summary['stat_required_value']
        );
        $this->assertFalse($summary['has_foundations_activities']);
        $this->assertSame([], $summary['foundations_activities']);
    }

    public function test_journey_prefers_moodle_section_names(): void {
        $outline = [
            'sections' => [
                [
                    'sectionnum' => 1,
                    'name' => 'Week 1 · Orientation',
                    'activities' => [
                        ['tracked' => true, 'complete' => true],
                        ['tracked' => true, 'complete' => true],
                    ],
                ],
                [
                    'sectionnum' => 2,
                    'name' => 'Client brief workshop',
                    'activities' => [
                        ['tracked' => true, 'complete' => false],
                    ],
                ],
            ],
        ];
        $journey = fellowship_dashboard::journey_from_outline($outline);
        $this->assertTrue($journey['has_gates']);
        $this->assertCount(4, $journey['gates']);
        $this->assertSame('Week 1 · Orientation', $journey['gates'][0]['name']);
        $this->assertTrue($journey['gates'][0]['is_done']);
        $this->assertTrue($journey['gates'][0]['has_moodle_section']);
        $this->assertSame('Client brief workshop', $journey['gates'][1]['name']);
        $this->assertTrue($journey['gates'][1]['is_current']);
        $this->assertFalse($journey['gates'][2]['has_moodle_section']);
        $this->assertStringContainsString('Not in this course', $journey['gates'][2]['sublabel']);
    }

    public function test_apply_sequence_locks_keeps_current_open(): void {
        $course = (object) ['id' => 1, 'idnumber' => 'lp-ai-pilot'];
        $outline = [
            'sections' => [
                [
                    'sectionnum' => 1,
                    'name' => 'Foundations',
                    'activities' => [
                        [
                            'cmid' => 11,
                            'modname' => 'page',
                            'name' => 'Lesson 1',
                            'url' => '/a/1',
                            'access_complete' => true,
                            'tracked' => true,
                            'complete' => true,
                        ],
                        [
                            'cmid' => 12,
                            'modname' => 'assign',
                            'name' => 'Submit 1',
                            'url' => '/a/2',
                            'access_complete' => false,
                            'tracked' => true,
                            'complete' => false,
                        ],
                    ],
                ],
                [
                    'sectionnum' => 2,
                    'name' => 'Guided',
                    'activities' => [
                        [
                            'cmid' => 21,
                            'modname' => 'page',
                            'name' => 'Lesson 2',
                            'url' => '/a/3',
                            'access_complete' => false,
                            'tracked' => true,
                            'complete' => false,
                        ],
                    ],
                ],
            ],
            'next' => null,
        ];
        $out = fellowship_dashboard::apply_sequence_locks($outline, $course);
        $p1 = $out['sections'][0]['activities'];
        $p2 = $out['sections'][1]['activities'];
        $this->assertFalse(!empty($p1[0]['locked']));
        $this->assertFalse(!empty($p1[1]['locked']), 'Current incomplete assign stays open');
        $this->assertTrue(!empty($p2[0]['locked']), 'Later phase locks until assign fellow-complete');
        $this->assertSame('', $p2[0]['url']);
        $this->assertSame(12, (int) ($out['next']['cmid'] ?? 0));
    }

    public function test_assign_pending_is_access_complete_not_met(): void {
        // Submit unlocks next access; Met chip remains separate (complete=false).
        $this->assertTrue(fellowship_dashboard::activity_access_complete([
            'modname' => 'assign',
            'access_complete' => true,
            'complete' => false,
        ]));
        $this->assertFalse(fellowship_dashboard::activity_access_complete([
            'modname' => 'assign',
            'access_complete' => false,
            'complete' => false,
        ]));
    }

    public function test_section_activities_from_outline_picks_section(): void {
        $outline = [
            'sections' => [
                [
                    'sectionnum' => 0,
                    'activities' => [['name' => 'Orient']],
                ],
                [
                    'sectionnum' => 1,
                    'activities' => [
                        ['name' => 'Lesson A', 'modname' => 'page'],
                        ['name' => 'Quiz 1', 'modname' => 'quiz'],
                    ],
                ],
            ],
        ];
        $acts = fellowship_dashboard::section_activities_from_outline($outline, 1);
        $this->assertCount(2, $acts);
        $this->assertSame('Lesson A', $acts[0]['name']);
        $this->assertSame([], fellowship_dashboard::section_activities_from_outline($outline, 9));
    }

    public function test_list_portfolio_evidence_empty_without_courses(): void {
        $out = fellowship_dashboard::list_portfolio_evidence(1, []);
        $this->assertFalse($out['has_items']);
        $this->assertSame([], $out['items']);
        $this->assertSame(0, $out['count_submitted']);
        $this->assertSame(0, $out['count_reviewed']);
        $this->assertSame(0, $out['count_awaiting']);
        $this->assertSame(0, $out['count_open']);
        $this->assertFalse($out['has_attention']);
        $this->assertFalse($out['has_submitted']);
    }
}
