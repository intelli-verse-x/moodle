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
 * Wrong-portal handling: send the user to their real home and flash a ToBa modal.
 *
 * @package    local_launchpad
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class portal_gate {

    /** @var string Session key for one-shot mismatch notice. */
    public const SESSION_KEY = 'local_launchpad_portal_mismatch';

    /**
     * If access is denied, stash a notice and redirect to the user's persona home.
     *
     * @param bool $allowed Whether this gated portal allows the user
     * @param string $triedportal login_portal::EMPLOYER|FELLOWSHIP|MENTOR
     */
    public static function assert_or_redirect_home(bool $allowed, string $triedportal): void {
        global $USER;

        if ($allowed) {
            return;
        }

        $userid = (int) ($USER->id ?? 0);
        $tried = login_portal::normalise($triedportal);
        if ($tried === '') {
            $tried = login_portal::EMPLOYER;
        }

        self::stash_mismatch($userid, $tried);
        redirect(self::home_url_for_user($userid));
    }

    /**
     * Store one-shot modal payload for the next page render.
     */
    public static function stash_mismatch(int $userid, string $triedportal): void {
        global $SESSION;

        $rolekey = self::role_key_for_user($userid);
        $SESSION->{self::SESSION_KEY} = [
            'tried' => $triedportal,
            'role' => $rolekey,
            'home' => self::home_url_for_user($userid)->out(false),
        ];
    }

    /**
     * Consume the flash and return Mustache context for the mismatch modal (or empty).
     *
     * @return array{show_portal_mismatch:bool,portal_mismatch_title?:string,portal_mismatch_body?:string,portal_mismatch_continue?:string,portal_mismatch_home?:string}
     */
    public static function consume_modal_context(): array {
        global $SESSION;

        if (empty($SESSION->{self::SESSION_KEY}) || !is_array($SESSION->{self::SESSION_KEY})) {
            return ['show_portal_mismatch' => false];
        }

        $data = $SESSION->{self::SESSION_KEY};
        unset($SESSION->{self::SESSION_KEY});

        $tried = login_portal::normalise((string) ($data['tried'] ?? ''));
        $rolekey = (string) ($data['role'] ?? '');
        $home = (string) ($data['home'] ?? '/');

        $triedlabel = self::portal_label($tried);
        $rolelabel = self::role_label($rolekey);

        return [
            'show_portal_mismatch' => true,
            'portal_mismatch_eyebrow' => get_string('portal_mismatch_eyebrow', 'local_launchpad'),
            'portal_mismatch_title' => get_string('portal_mismatch_title', 'local_launchpad'),
            'portal_mismatch_body' => get_string('portal_mismatch_body', 'local_launchpad', (object) [
                'tried' => $triedlabel,
                'role' => $rolelabel,
            ]),
            'portal_mismatch_continue' => get_string('portal_mismatch_continue', 'local_launchpad', $rolelabel),
            'portal_mismatch_home' => $home,
        ];
    }

    /**
     * Persona home for this user, or site landing when none.
     */
    public static function home_url_for_user(int $userid): \moodle_url {
        $home = persona_home::home_url_for($userid);
        if ($home !== null) {
            return $home;
        }
        return new \moodle_url('/');
    }

    /**
     * Role key used in copy: admin|fellowship|mentor|employee|guest.
     */
    public static function role_key_for_user(int $userid): string {
        if ($userid <= 0) {
            return 'guest';
        }
        // Same priority as persona_home::home_url_for.
        if (account_persona::is_admin($userid)) {
            return 'admin';
        }
        if (account_persona::is_mentor($userid)) {
            return 'mentor';
        }
        if (account_persona::is_fellowship($userid)) {
            return 'fellowship';
        }
        if (account_persona::is_employee($userid)) {
            return 'employee';
        }
        return 'guest';
    }

    /**
     * Human label for a gated portal key.
     */
    public static function portal_label(string $portal): string {
        return match (login_portal::normalise($portal)) {
            login_portal::ADMIN => get_string('portal_name_admin', 'local_launchpad'),
            login_portal::FELLOWSHIP => get_string('portal_name_fellowship', 'local_launchpad'),
            login_portal::MENTOR => get_string('portal_name_mentor', 'local_launchpad'),
            default => get_string('portal_name_employer', 'local_launchpad'),
        };
    }

    /**
     * Human label for the account role key.
     */
    public static function role_label(string $rolekey): string {
        return match ($rolekey) {
            'admin' => get_string('portal_role_admin', 'local_launchpad'),
            'mentor' => get_string('portal_role_mentor', 'local_launchpad'),
            'fellowship' => get_string('portal_role_fellowship', 'local_launchpad'),
            'employee' => get_string('portal_role_employee', 'local_launchpad'),
            default => get_string('portal_role_guest', 'local_launchpad'),
        };
    }
}
