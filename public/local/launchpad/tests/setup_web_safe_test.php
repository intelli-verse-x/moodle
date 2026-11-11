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
 * Phase 0: setup logger must buffer for web callers (no cli_writeln fatal).
 *
 * @package    local_launchpad
 * @category   phpunit
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_launchpad\setup
 */
final class setup_web_safe_test extends \advanced_testcase {

    public function test_line_buffers_to_get_log(): void {
        $setup = new setup('Pilot', true);
        $method = new \ReflectionMethod(setup::class, 'line');
        $method->setAccessible(true);
        $method->invoke($setup, 'web-safe probe');
        $this->assertSame(['web-safe probe'], $setup->get_log());
    }

    public function test_dry_run_execute_does_not_fatal_and_fills_log(): void {
        $this->resetAfterTest(true);

        $setup = new setup('Pilot', true);
        $setup->execute();
        $log = $setup->get_log();

        $this->assertNotEmpty($log);
        $this->assertStringContainsString('DRY-RUN', $log[0]);
        $this->assertStringContainsString('Cohort label: Pilot', $log[1]);
        $this->assertSame('Done.', $log[array_key_last($log)]);
    }
}
