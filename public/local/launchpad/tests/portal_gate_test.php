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
 * Portal gate helper tests (no DB writes).
 *
 * @package    local_launchpad
 * @category   phpunit
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_launchpad\portal_gate
 */
final class portal_gate_test extends \basic_testcase {

    public function test_portal_and_role_labels(): void {
        $this->assertNotSame('', portal_gate::portal_label(login_portal::EMPLOYER));
        $this->assertNotSame('', portal_gate::portal_label(login_portal::FELLOWSHIP));
        $this->assertNotSame('', portal_gate::portal_label(login_portal::MENTOR));
        $this->assertNotSame('', portal_gate::role_label('employee'));
        $this->assertNotSame('', portal_gate::role_label('fellowship'));
        $this->assertNotSame('', portal_gate::role_label('mentor'));
    }

    public function test_role_key_for_invalid_user(): void {
        $this->assertSame('guest', portal_gate::role_key_for_user(0));
        $this->assertSame('guest', portal_gate::role_key_for_user(-1));
    }

    public function test_consume_modal_empty_without_flash(): void {
        global $SESSION;
        unset($SESSION->{portal_gate::SESSION_KEY});
        $ctx = portal_gate::consume_modal_context();
        $this->assertFalse($ctx['show_portal_mismatch']);
    }

    public function test_consume_modal_once(): void {
        global $SESSION;
        $SESSION->{portal_gate::SESSION_KEY} = [
            'tried' => login_portal::MENTOR,
            'role' => 'fellowship',
            'home' => '/local/launchpad/fellow.php',
        ];
        $ctx = portal_gate::consume_modal_context();
        $this->assertTrue($ctx['show_portal_mismatch']);
        $this->assertStringContainsString('Mentor', $ctx['portal_mismatch_body']);
        $this->assertStringContainsString('Fellow', $ctx['portal_mismatch_continue']);
        $this->assertFalse(portal_gate::consume_modal_context()['show_portal_mismatch']);
    }
}
