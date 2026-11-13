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
 * Fellowship self-registration helpers.
 *
 * @package    local_launchpad
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_launchpad;

defined('MOODLE_INTERNAL') || die();

/**
 * Enable email self-registration and track Fellowship signup portal.
 *
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class registration {
    /** User preference: which portal the account registered for. */
    public const PREF_PORTAL = 'toba_portal';

    /** Session key while completing signup. */
    public const SESSION_PORTAL = 'toba_signup_portal';

    /** Session key: email address for the branded "check your inbox" page. */
    public const SESSION_PENDING_EMAIL = 'toba_signup_pending_email';

    /** Only Fellowship may self-register. */
    public const PORTAL_FELLOWSHIP = 'fellowship';

    /**
     * Enable auth_email and set Self registration to Email-based.
     *
     * @param bool $force When true, set registerauth=email even if another value is set
     *                    but signup is currently disabled / not usable.
     */
    public static function ensure_email_self_registration(bool $force = false): void {
        global $CFG;

        require_once($CFG->libdir . '/authlib.php');

        \core\plugininfo\auth::enable_plugin('email', 1);

        $needset = empty($CFG->registerauth);
        if ($force && !signup_is_enabled()) {
            $needset = true;
        }
        if ($force && !empty($CFG->registerauth) && $CFG->registerauth !== 'email') {
            // Fellowship self-register requires auth_email.
            $needset = true;
        }

        if ($needset) {
            set_config('registerauth', 'email');
            $CFG->registerauth = 'email';
            // Config cache can lag; purge the application config cache entry.
            if (class_exists('\cache')) {
                \cache_helper::purge_by_definition('core', 'config');
            }
        }
    }

    /**
     * Normalise a portal query/value to fellowship or empty.
     *
     * @param string $portal Raw portal value.
     * @return string
     */
    public static function normalise_portal(string $portal): string {
        $portal = strtolower(trim($portal));
        if ($portal === 'fellow' || $portal === 'fellowship') {
            return self::PORTAL_FELLOWSHIP;
        }
        return $portal;
    }

    /**
     * CI-safe Fellowship shell wantsurl (never hardcode host).
     */
    public static function fellowship_home_wantsurl(): string {
        return (new \moodle_url(persona_home::FELLOW_PATH))->out(false);
    }

    /**
     * Pin signup return URL to fellow.php when empty or still a Moodle default home.
     *
     * Landing / core signup may seed / or /my/ before auth_email saves
     * auth_email_wantsurl — overwrite those so confirm Continue lands on LaunchPad.
     */
    public static function ensure_fellowship_wantsurl(): void {
        global $SESSION;

        $current = isset($SESSION->wantsurl) ? (string) $SESSION->wantsurl : '';
        if ($current === '' || persona_home::should_override_default_home($current)) {
            $SESSION->wantsurl = self::fellowship_home_wantsurl();
        }
    }

    /**
     * True when the account was created via Fellowship self-registration tag.
     *
     * Enrolment-based {@see account_persona::is_fellowship()} stays separate
     * (messaging / peers). This tag only unlocks shell home + fellow.php access
     * for empty post-confirm accounts.
     *
     * @param int $userid
     */
    public static function is_self_registered_fellow(int $userid): bool {
        if ($userid <= 0) {
            return false;
        }
        $portal = get_user_preferences(self::PREF_PORTAL, '', $userid);
        return self::normalise_portal((string) $portal) === self::PORTAL_FELLOWSHIP;
    }

    /**
     * Stash Fellowship portal on the session for signup + confirmation.
     */
    public static function prepare_signup_session(): void {
        global $SESSION;

        $rawportal = optional_param('portal', '', PARAM_ALPHA);
        $portal = self::normalise_portal($rawportal);
        if ($portal === '' && !empty($SESSION->{self::SESSION_PORTAL})) {
            $portal = self::normalise_portal((string) $SESSION->{self::SESSION_PORTAL});
        }
        // Self-registration is Fellowship-only.
        if ($portal !== self::PORTAL_FELLOWSHIP) {
            $portal = self::PORTAL_FELLOWSHIP;
        }
        $SESSION->{self::SESSION_PORTAL} = $portal;

        self::ensure_fellowship_wantsurl();

        // Keep ?portal=fellowship on the signup URL for bookmarks / cancel flows (GET only).
        if ($rawportal === '' && $_SERVER['REQUEST_METHOD'] === 'GET') {
            redirect(new \moodle_url('/login/signup.php', ['portal' => self::PORTAL_FELLOWSHIP]));
        }
    }

    /**
     * Remember the signup email so we can replace Moodle's stock "email sent" notice.
     *
     * @param string $email
     */
    public static function stash_pending_email(string $email): void {
        global $SESSION;

        $email = trim($email);
        if ($email === '') {
            return;
        }
        $SESSION->{self::SESSION_PENDING_EMAIL} = $email;
    }

    /**
     * Consume the pending-email flash (one-shot).
     */
    public static function consume_pending_email(): string {
        global $SESSION;

        $email = '';
        if (!empty($SESSION->{self::SESSION_PENDING_EMAIL})) {
            $email = (string) $SESSION->{self::SESSION_PENDING_EMAIL};
        }
        unset($SESSION->{self::SESSION_PENDING_EMAIL});
        return $email;
    }

    /**
     * Replace stock Moodle signup/confirm notice pages with LaunchPad screens.
     *
     * Runs from before_http_headers while auth_email / confirm.php are about to
     * print $OUTPUT->header() + notice — no core file edits.
     */
    public static function maybe_redirect_stock_signup_notices(): void {
        global $SESSION, $USER;

        if (during_initial_install() || (defined('CLI_SCRIPT') && CLI_SCRIPT)
                || (defined('AJAX_SCRIPT') && AJAX_SCRIPT)
                || (defined('WS_SERVER') && WS_SERVER)
                || (defined('PHPUNIT_TEST') && PHPUNIT_TEST)) {
            return;
        }

        if (self::is_moodle_script('/login/signup.php')) {
            // auth_email sets PAGE title to emailconfirm only after a successful
            // send, immediately before header()+notice. Skip on SMTP exceptions.
            if (!empty($SESSION->{self::SESSION_PENDING_EMAIL})) {
                global $PAGE;
                // moodle_page::set_title() appends " | {shortname}" — exact === never matches.
                $title = (string) ($PAGE->title ?? '');
                $emailconfirm = get_string('emailconfirm');
                if ($title !== '' && str_starts_with($title, $emailconfirm)) {
                    redirect(new \moodle_url('/local/launchpad/signup_pending.php'));
                }
            }
            return;
        }

        if (!self::is_moodle_script('/login/confirm.php')) {
            return;
        }
        if (!isloggedin() || isguestuser() || empty($USER->id)) {
            return;
        }

        $userid = (int) $USER->id;
        // Higher-priority personas keep stock confirm (should not self-reg as fellow).
        if (account_persona::is_admin($userid) || account_persona::is_mentor($userid)
                || account_persona::is_employee($userid)) {
            return;
        }
        if (!self::is_self_registered_fellow($userid)) {
            return;
        }

        redirect(new \moodle_url('/local/launchpad/signup_confirmed.php'));
    }

    /**
     * Whether the current request is a given Moodle script path.
     *
     * @param string $suffix e.g. '/login/signup.php'
     */
    public static function is_moodle_script(string $suffix): bool {
        global $SCRIPT;

        $script = (string) ($SCRIPT ?? '');
        if ($script === '') {
            $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        }
        $script = str_replace('\\', '/', $script);
        $suffix = str_replace('\\', '/', $suffix);
        return str_ends_with($script, $suffix);
    }

    /**
     * Public Fellowship registration entry URL (enables signup, then Moodle form).
     *
     * Always returns the LaunchPad register entry so the CTA works even before
     * Self registration has been enabled in site config.
     *
     * @return string
     */
    public static function fellowship_signup_url(): string {
        return (new \moodle_url('/local/launchpad/register.php'))->out(false);
    }

    /**
     * Persist portal preference after the Moodle user row exists.
     *
     * @param int $userid New user id.
     */
    public static function apply_portal_preference(int $userid): void {
        global $SESSION;

        if ($userid <= 0) {
            return;
        }

        $portal = self::PORTAL_FELLOWSHIP;
        if (!empty($SESSION->{self::SESSION_PORTAL})) {
            $portal = self::normalise_portal((string) $SESSION->{self::SESSION_PORTAL});
        }
        if ($portal !== self::PORTAL_FELLOWSHIP) {
            $portal = self::PORTAL_FELLOWSHIP;
        }

        set_user_preference(self::PREF_PORTAL, $portal, $userid);
        unset($SESSION->{self::SESSION_PORTAL});
    }
}
