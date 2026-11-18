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
 * Cross-role portal access: wrong login tab must not open another shell.
 *
 * @package    local_launchpad
 * @category   phpunit
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_launchpad\registration
 * @covers     \local_launchpad\mentor_dashboard
 * @covers     \local_launchpad\fellowship_dashboard
 * @covers     \local_launchpad\persona_home
 * @covers     \local_launchpad\portal_gate
 */
final class portal_cross_role_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        persona_home::reset_home_cache();
    }

    public function test_employee_poisoned_mentor_tag_cannot_open_mentor_shell(): void {
        $this->setAdminUser();
        global $USER;

        $emp = $this->getDataGenerator()->create_user();
        account_provision::grant_employee($USER, (int) $emp->id);
        // Simulate wrong mentor login tab stamping the preference.
        set_user_preference(registration::PREF_PORTAL, registration::PORTAL_MENTOR, $emp->id);
        persona_home::reset_home_cache();

        $this->assertFalse(registration::is_portal_mentor((int) $emp->id));
        $this->assertFalse(mentor_dashboard::can_access((int) $emp->id));
        $this->assertFalse(fellowship_dashboard::can_access((int) $emp->id));
        $this->assertSame(persona_home::EMPLOYEE_PATH, persona_home::home_url_for((int) $emp->id)->out_as_local_url(false));
        $this->assertSame('employee', portal_gate::role_key_for_user((int) $emp->id));
    }

    public function test_fellow_poisoned_mentor_tag_cannot_open_mentor_shell(): void {
        $course = $this->getDataGenerator()->create_course([
            'shortname' => 'LP-XROLE-F',
            'idnumber' => 'lp-xrole-fellow',
            'visible' => 1,
        ]);
        $fellow = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($fellow->id, $course->id, 'student');
        set_user_preference(registration::PREF_PORTAL, registration::PORTAL_MENTOR, $fellow->id);
        persona_home::reset_home_cache();

        $this->assertFalse(registration::is_portal_mentor((int) $fellow->id));
        $this->assertFalse(mentor_dashboard::can_access((int) $fellow->id));
        $this->assertTrue(fellowship_dashboard::can_access((int) $fellow->id));
        $this->assertSame(persona_home::FELLOW_PATH, persona_home::home_url_for((int) $fellow->id)->out_as_local_url(false));
    }

    public function test_remember_login_portal_does_not_stamp_wrong_persona(): void {
        $this->setAdminUser();
        global $USER;

        $emp = $this->getDataGenerator()->create_user();
        account_provision::grant_employee($USER, (int) $emp->id);
        registration::remember_login_portal((int) $emp->id, login_portal::MENTOR);
        $this->assertSame('', (string) get_user_preferences(registration::PREF_PORTAL, '', $emp->id));
        $this->assertFalse(mentor_dashboard::can_access((int) $emp->id));

        $course = $this->getDataGenerator()->create_course([
            'shortname' => 'LP-XROLE-M',
            'idnumber' => 'lp-xrole-mentor',
            'visible' => 1,
        ]);
        $mentor = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($mentor->id, $course->id, 'editingteacher');
        set_user_preference(registration::PREF_PORTAL, registration::PORTAL_MENTOR, $mentor->id);
        registration::remember_login_portal((int) $mentor->id, login_portal::FELLOWSHIP);
        // Must not overwrite mentor with fellowship.
        $this->assertSame(
            registration::PORTAL_MENTOR,
            (string) get_user_preferences(registration::PREF_PORTAL, '', $mentor->id)
        );
        $this->assertTrue(mentor_dashboard::can_access((int) $mentor->id));
        $this->assertFalse(fellowship_dashboard::can_access((int) $mentor->id));
        $this->assertSame('mentor', portal_gate::role_key_for_user((int) $mentor->id));
    }

    public function test_portal_mentor_cannot_open_fellow_shell_or_be_labelled_fellow(): void {
        // Empty mentor shell (tag only, no teacher seat yet) — the live bug case.
        $mentor = $this->getDataGenerator()->create_user();
        set_user_preference(registration::PREF_PORTAL, registration::PORTAL_MENTOR, $mentor->id);
        persona_home::reset_home_cache();

        registration::remember_login_portal((int) $mentor->id, login_portal::FELLOWSHIP);
        $this->assertSame(
            registration::PORTAL_MENTOR,
            (string) get_user_preferences(registration::PREF_PORTAL, '', $mentor->id)
        );
        $this->assertFalse(fellowship_dashboard::can_access((int) $mentor->id));
        $this->assertTrue(mentor_dashboard::can_access((int) $mentor->id));
        $this->assertSame('mentor', portal_gate::role_key_for_user((int) $mentor->id));
        $this->assertSame(persona_home::MENTOR_PATH, persona_home::home_url_for((int) $mentor->id)->out_as_local_url(false));
    }

    public function test_reconcile_clears_employee_poison_and_repairs_fellow_mentor_flip(): void {
        $this->setAdminUser();
        global $USER;

        $emp = $this->getDataGenerator()->create_user();
        account_provision::grant_employee($USER, (int) $emp->id);
        set_user_preference(registration::PREF_PORTAL, registration::PORTAL_MENTOR, $emp->id);
        registration::reconcile_portal_preference((int) $emp->id);
        $this->assertSame('', (string) get_user_preferences(registration::PREF_PORTAL, '', $emp->id));

        $course = $this->getDataGenerator()->create_course([
            'shortname' => 'LP-XROLE-R',
            'idnumber' => 'lp-xrole-repair',
            'visible' => 1,
        ]);
        $fellow = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($fellow->id, $course->id, 'student');
        set_user_preference(registration::PREF_PORTAL, registration::PORTAL_MENTOR, $fellow->id);
        registration::reconcile_portal_preference((int) $fellow->id);
        $this->assertSame(
            registration::PORTAL_FELLOWSHIP,
            (string) get_user_preferences(registration::PREF_PORTAL, '', $fellow->id)
        );
        $this->assertTrue(fellowship_dashboard::can_access((int) $fellow->id));
        $this->assertFalse(mentor_dashboard::can_access((int) $fellow->id));
    }

    public function test_empty_mentor_tag_still_opens_mentor_shell(): void {
        $mentor = $this->getDataGenerator()->create_user();
        set_user_preference(registration::PREF_PORTAL, registration::PORTAL_MENTOR, $mentor->id);
        persona_home::reset_home_cache();

        $this->assertTrue(registration::is_portal_mentor((int) $mentor->id));
        $this->assertTrue(mentor_dashboard::can_access((int) $mentor->id));
        $this->assertFalse(fellowship_dashboard::can_access((int) $mentor->id));
        $this->assertSame(persona_home::MENTOR_PATH, persona_home::home_url_for((int) $mentor->id)->out_as_local_url(false));
    }
}
