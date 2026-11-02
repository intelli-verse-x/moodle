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
 * Admin dashboard policy / helper tests (no DB).
 *
 * @package    local_launchpad
 * @category   phpunit
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_launchpad\admin_dashboard
 */
final class admin_dashboard_test extends \basic_testcase {

    public function test_can_access_rejects_invalid(): void {
        $this->assertFalse(admin_dashboard::can_access(0));
        $this->assertFalse(admin_dashboard::can_access(-1));
    }

    public function test_data_region_marker(): void {
        $this->assertSame('local-launchpad-admin', admin_dashboard::DATA_REGION);
    }

    public function test_is_track_idnumber(): void {
        $this->assertTrue(admin_dashboard::is_track_idnumber('lp-ai-pilot'));
        $this->assertTrue(admin_dashboard::is_track_idnumber('LP-fullstack-2026'));
        $this->assertFalse(admin_dashboard::is_track_idnumber('lp-orient-pilot'));
        $this->assertFalse(admin_dashboard::is_track_idnumber('lp-orient-'));
        $this->assertFalse(admin_dashboard::is_track_idnumber('course-1'));
        $this->assertFalse(admin_dashboard::is_track_idnumber(''));
    }

    public function test_activity_and_teacher_count_invalid_course(): void {
        $this->assertSame(0, admin_dashboard::activity_count(0));
        $this->assertSame(0, admin_dashboard::activity_count(-3));
        $this->assertSame(0, admin_dashboard::teacher_count(0));
    }

    public function test_assign_teacher_url_invalid_course_falls_back(): void {
        $url = admin_dashboard::assign_teacher_url(0);
        $this->assertStringContainsString('/user/index.php', $url->out(false));
    }
}
