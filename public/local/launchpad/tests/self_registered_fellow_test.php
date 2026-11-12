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
 * Self-registered fellow tag routing (toba_portal) without lp-* enrolment.
 *
 * @package    local_launchpad
 * @category   phpunit
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_launchpad\registration
 * @covers     \local_launchpad\persona_home
 * @covers     \local_launchpad\fellowship_dashboard
 * @covers     \local_launchpad\portal_gate
 */
final class self_registered_fellow_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        persona_home::reset_home_cache();
    }

    public function test_tag_without_enrol_gets_fellow_home_and_access(): void {
        $user = $this->getDataGenerator()->create_user();
        set_user_preference(registration::PREF_PORTAL, registration::PORTAL_FELLOWSHIP, $user->id);
        persona_home::reset_home_cache();

        $this->assertTrue(registration::is_self_registered_fellow((int) $user->id));
        $this->assertFalse(account_persona::is_fellowship((int) $user->id));

        $home = persona_home::home_url_for((int) $user->id);
        $this->assertNotNull($home);
        $this->assertSame(persona_home::FELLOW_PATH, $home->out_as_local_url(false));
        $this->assertTrue(fellowship_dashboard::can_access((int) $user->id));
        $this->assertSame('fellowship', portal_gate::role_key_for_user((int) $user->id));
    }

    public function test_plain_user_without_tag_stays_non_fellow(): void {
        $user = $this->getDataGenerator()->create_user();
        persona_home::reset_home_cache();

        $this->assertFalse(registration::is_self_registered_fellow((int) $user->id));
        $this->assertFalse(account_persona::is_fellowship((int) $user->id));
        $this->assertNull(persona_home::home_url_for((int) $user->id));
        $this->assertFalse(fellowship_dashboard::can_access((int) $user->id));
        $this->assertSame('guest', portal_gate::role_key_for_user((int) $user->id));
    }

    public function test_employee_wins_over_toba_portal_tag(): void {
        $this->setAdminUser();
        global $USER;

        $target = $this->getDataGenerator()->create_user();
        set_user_preference(registration::PREF_PORTAL, registration::PORTAL_FELLOWSHIP, $target->id);
        account_provision::grant_employee($USER, (int) $target->id);
        persona_home::reset_home_cache();

        $this->assertTrue(registration::is_self_registered_fellow((int) $target->id));
        $this->assertTrue(account_persona::is_employee((int) $target->id));

        $home = persona_home::home_url_for((int) $target->id);
        $this->assertNotNull($home);
        $this->assertSame(persona_home::EMPLOYEE_PATH, $home->out_as_local_url(false));
        $this->assertSame('employee', portal_gate::role_key_for_user((int) $target->id));
        // Employee wins: tag must not open fellow.php.
        $this->assertFalse(fellowship_dashboard::can_access((int) $target->id));
    }

    public function test_mentor_wins_over_toba_portal_tag(): void {
        $course = $this->getDataGenerator()->create_course([
            'shortname' => 'LP-TAG-MENTOR',
            'idnumber' => 'lp-tag-mentor',
            'visible' => 1,
        ]);
        $mentor = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($mentor->id, $course->id, 'editingteacher');
        set_user_preference(registration::PREF_PORTAL, registration::PORTAL_FELLOWSHIP, $mentor->id);
        persona_home::reset_home_cache();

        $home = persona_home::home_url_for((int) $mentor->id);
        $this->assertNotNull($home);
        $this->assertSame(persona_home::MENTOR_PATH, $home->out_as_local_url(false));
        $this->assertSame('mentor', portal_gate::role_key_for_user((int) $mentor->id));
        $this->assertFalse(fellowship_dashboard::can_access((int) $mentor->id));
    }

    public function test_ensure_fellowship_wantsurl_overwrites_default_homes(): void {
        global $SESSION;

        $SESSION->wantsurl = (new \moodle_url('/my/'))->out(false);
        registration::ensure_fellowship_wantsurl();
        $this->assertStringContainsString(
            persona_home::FELLOW_PATH,
            (string) $SESSION->wantsurl
        );

        $deep = (new \moodle_url('/course/view.php', ['id' => 3]))->out(false);
        $SESSION->wantsurl = $deep;
        registration::ensure_fellowship_wantsurl();
        $this->assertSame($deep, (string) $SESSION->wantsurl);
    }
}
