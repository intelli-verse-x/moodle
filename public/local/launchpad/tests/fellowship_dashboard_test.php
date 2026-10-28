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

    public function test_data_region_marker(): void {
        $this->assertSame('local-launchpad-fellowship', fellowship_dashboard::DATA_REGION);
    }
}
