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
 * Unit tests for LaunchPad Moodle messaging helpers.
 *
 * @package    local_launchpad
 * @category   phpunit
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_launchpad\messaging
 */
final class messaging_test extends \basic_testcase {

    public function test_messages_url_launchpad_shells(): void {
        $fellow = messaging::messages_url(null, 'fellow');
        $this->assertStringContainsString('fellow.php', $fellow);
        $this->assertStringContainsString('view=messages', $fellow);

        $dm = messaging::messages_url(42, 'mentor');
        $this->assertStringContainsString('mentor.php', $dm);
        $this->assertStringContainsString('view=messages', $dm);
        $this->assertStringContainsString('id=42', $dm);

        $moodle = messaging::moodle_messages_url(9);
        $this->assertStringContainsString('/message/index.php', $moodle);
        $this->assertStringContainsString('id=9', $moodle);
    }

    public function test_can_message_false_for_invalid_ids(): void {
        $this->assertFalse(messaging::can_message(0, 2));
        $this->assertFalse(messaging::can_message(1, 0));
        $this->assertFalse(messaging::can_message(5, 5));
    }

    public function test_can_message_false_when_messaging_disabled(): void {
        global $CFG;
        $prev = $CFG->messaging ?? null;
        $CFG->messaging = 0;
        $this->assertFalse(messaging::can_message(1, 2));
        $CFG->messaging = $prev;
    }

    public function test_unread_count_zero_for_invalid_user(): void {
        $this->assertSame(0, messaging::unread_count(0));
        $this->assertSame(0, messaging::unread_count(-3));
    }

    public function test_messages_label_strings(): void {
        $plain = messaging::messages_label(0);
        $this->assertNotSame('', $plain);
        $unread = messaging::messages_label(3);
        $this->assertStringContainsString('3', $unread);
    }

    public function test_send_rejects_empty_and_invalid(): void {
        $empty = messaging::send(1, 2, '   ');
        $this->assertFalse($empty['ok']);
        $self = messaging::send(2, 2, 'Hi');
        $this->assertFalse($self['ok']);
    }

    public function test_delete_messages_rejects_empty_and_invalid_user(): void {
        $none = messaging::delete_messages(1, []);
        $this->assertFalse($none['ok']);
        $this->assertSame(0, $none['deleted']);
        $this->assertSame([], $none['deleted_ids']);

        $baduser = messaging::delete_messages(0, [12, 13]);
        $this->assertFalse($baduser['ok']);
        $this->assertSame(0, $baduser['deleted']);
        $this->assertSame([], $baduser['deleted_ids']);
    }
}
