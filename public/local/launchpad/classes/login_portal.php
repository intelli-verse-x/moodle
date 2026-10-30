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

/**
 * Login-page portal tab → gated wantsurl.
 *
 * @package    local_launchpad
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_launchpad;

defined('MOODLE_INTERNAL') || die();

/**
 * Maps the Boost login portal switch (employer / fellowship) onto real gates.
 *
 * The login form's portal field used to be UI-only, so navbar Sign in looked like
 * Employer but skipped employee.php. Pinning wantsurl here makes the tab real.
 *
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class login_portal {

    public const EMPLOYER = 'employer';
    public const FELLOWSHIP = 'fellowship';

    /**
     * Normalise raw portal query/post value.
     */
    public static function normalise(string $portal): string {
        $portal = strtolower(trim($portal));
        if ($portal === 'fellow' || $portal === 'fellowship') {
            return self::FELLOWSHIP;
        }
        if ($portal === 'employer' || $portal === 'employee') {
            return self::EMPLOYER;
        }
        return '';
    }

    /**
     * Gated return URL for a normalised portal, or null if unknown.
     */
    public static function wantsurl_for(string $portal): ?\moodle_url {
        $portal = self::normalise($portal);
        if ($portal === self::EMPLOYER) {
            return new \moodle_url('/local/launchpad/employee.php');
        }
        if ($portal === self::FELLOWSHIP) {
            return new \moodle_url('/local/launchpad/fellow.php');
        }
        return null;
    }

    /**
     * If request carries portal=employer|fellowship, pin SESSION wantsurl to that gate.
     *
     * Safe on non-login pages when the param is absent. When present on login GET/POST,
     * this must run before core_login_get_return_url() consumes wantsurl.
     *
     * @return bool True when wantsurl was set
     */
    public static function maybe_pin_wantsurl_from_request(): bool {
        global $SESSION, $SCRIPT;

        $raw = optional_param('portal', '', PARAM_ALPHANUMEXT);
        if ($raw === '') {
            return false;
        }

        // Prefer login index; also honour login POST (portal + logintoken) during
        // user_loggedin when SCRIPT may still be the login script.
        $script = str_replace('\\', '/', (string) ($SCRIPT ?? ''));
        $onlogin = ($script === '' || str_contains($script, '/login/index.php'));
        $loginpost = !empty($_POST['logintoken']) || !empty($_POST['username']);
        if (!$onlogin && !$loginpost) {
            return false;
        }

        $target = self::wantsurl_for($raw);
        if ($target === null) {
            return false;
        }

        $SESSION->wantsurl = $target->out(false);
        return true;
    }

    /**
     * Whether a logged-in GET of /login/index.php should skip Moodle's "Log out?" card.
     *
     * Browser back after Sign in reloads the login URL; core shows alreadyloggedin.
     * LaunchPad sends those users to the public landing (/) instead.
     */
    public static function should_redirect_logged_in_from_login(
        bool $loggedin,
        bool $isguest,
        string $method,
        string $script
    ): bool {
        if (!$loggedin || $isguest) {
            return false;
        }
        if (strtoupper($method) !== 'GET') {
            return false;
        }
        $script = str_replace('\\', '/', $script);
        return str_contains($script, '/login/index.php');
    }

    /**
     * Redirect already-signed-in users off /login/index.php onto the landing page.
     *
     * @return bool True when a redirect was issued
     */
    public static function maybe_redirect_logged_in_from_login(): bool {
        global $SCRIPT;

        if (during_initial_install()) {
            return false;
        }

        $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $script = (string) ($SCRIPT ?? ($_SERVER['SCRIPT_NAME'] ?? ''));
        if (!self::should_redirect_logged_in_from_login(
            isloggedin(),
            isguestuser(),
            $method,
            $script
        )) {
            return false;
        }

        redirect(new \moodle_url('/'));
        return true;
    }
}
