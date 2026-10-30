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
 * Login portal helper tests (no DB writes).
 *
 * @package    local_launchpad
 * @category   phpunit
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_launchpad\login_portal
 */
final class login_portal_test extends \basic_testcase {

    public function test_normalise_portals(): void {
        $this->assertSame(login_portal::FELLOWSHIP, login_portal::normalise('fellowship'));
        $this->assertSame(login_portal::FELLOWSHIP, login_portal::normalise('Fellow'));
        $this->assertSame(login_portal::EMPLOYER, login_portal::normalise('employer'));
        $this->assertSame(login_portal::EMPLOYER, login_portal::normalise('employee'));
        $this->assertSame('', login_portal::normalise('admin'));
    }

    public function test_should_redirect_logged_in_from_login(): void {
        $this->assertTrue(login_portal::should_redirect_logged_in_from_login(
            true,
            false,
            'GET',
            '/login/index.php'
        ));
        $this->assertTrue(login_portal::should_redirect_logged_in_from_login(
            true,
            false,
            'get',
            'C:\\dev\\moodle\\public\\login\\index.php'
        ));
        $this->assertFalse(login_portal::should_redirect_logged_in_from_login(
            true,
            false,
            'POST',
            '/login/index.php'
        ));
        $this->assertFalse(login_portal::should_redirect_logged_in_from_login(
            false,
            false,
            'GET',
            '/login/index.php'
        ));
        $this->assertFalse(login_portal::should_redirect_logged_in_from_login(
            true,
            true,
            'GET',
            '/login/index.php'
        ));
        $this->assertFalse(login_portal::should_redirect_logged_in_from_login(
            true,
            false,
            'GET',
            '/local/launchpad/fellow.php'
        ));
    }
}
