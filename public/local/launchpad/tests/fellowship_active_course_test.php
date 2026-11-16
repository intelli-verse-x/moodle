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
 * Sticky active-course resolve / preference tests (M7).
 *
 * @package    local_launchpad
 * @category   phpunit
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_launchpad\fellowship_dashboard
 */
final class fellowship_active_course_test extends \advanced_testcase {

    /**
     * @return list<\stdClass>
     */
    private function sample_courses(): array {
        return [
            (object) [
                'id' => 10,
                'fullname' => 'Orientation',
                'shortname' => 'orient',
                'idnumber' => 'lp-orient-shared',
            ],
            (object) [
                'id' => 20,
                'fullname' => 'AI Pilot',
                'shortname' => 'ai',
                'idnumber' => 'lp-ai-pilot',
            ],
            (object) [
                'id' => 30,
                'fullname' => 'Full Stack',
                'shortname' => 'fs',
                'idnumber' => 'lp-fullstack-pilot',
            ],
        ];
    }

    public function test_pref_constant_and_empty_user(): void {
        $this->assertSame('local_launchpad_fellow_course', fellowship_dashboard::PREF_ACTIVE_COURSE);
        $this->assertSame(0, fellowship_dashboard::get_active_course_preference(0));
        $this->assertSame(0, fellowship_dashboard::get_active_course_preference(-1));
    }

    public function test_resolve_request_wins_over_sticky_and_prefer(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $courses = $this->sample_courses();

        // Sticky + prefer would both pick AI (20) over orientation; request asks for Full Stack.
        fellowship_dashboard::set_active_course_preference((int) $user->id, 20);
        $picked = fellowship_dashboard::resolve_selected_course($courses, 30, (int) $user->id);
        $this->assertNotNull($picked);
        $this->assertSame(30, (int) $picked->id);
    }

    public function test_resolve_sticky_over_prefer_when_no_request(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $courses = $this->sample_courses();

        // prefer_course would skip orientation and return AI (20). Sticky Full Stack must win.
        fellowship_dashboard::set_active_course_preference((int) $user->id, 30);
        $this->assertSame(30, fellowship_dashboard::get_active_course_preference((int) $user->id));

        $picked = fellowship_dashboard::resolve_selected_course($courses, 0, (int) $user->id);
        $this->assertNotNull($picked);
        $this->assertSame(30, (int) $picked->id);

        $prefer = fellowship_dashboard::prefer_course($courses);
        $this->assertNotNull($prefer);
        $this->assertSame(20, (int) $prefer->id);
    }

    public function test_resolve_ignores_unenrolled_sticky(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $courses = $this->sample_courses();

        fellowship_dashboard::set_active_course_preference((int) $user->id, 9999);
        $picked = fellowship_dashboard::resolve_selected_course($courses, 0, (int) $user->id);
        $this->assertNotNull($picked);
        // Falls through to prefer_course (first non-orientation).
        $this->assertSame(20, (int) $picked->id);
    }

    public function test_resolve_empty_courses_null(): void {
        $this->assertNull(fellowship_dashboard::resolve_selected_course([], 20, 1));
    }
}
