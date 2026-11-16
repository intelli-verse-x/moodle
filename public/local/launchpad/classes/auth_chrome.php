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
 * Auth-page chrome helpers (forgot / set password).
 *
 * @package    local_launchpad
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class auth_chrome {

    /** Session key for branded forgot-notice body copy. */
    public const SESSION_FORGOT_NOTICE = 'lp_forgot_notice_body';

    /**
     * True when the current request is the core forgot-password script.
     */
    public static function is_forgot_password_script(): bool {
        global $PAGE, $SCRIPT;

        $script = (string) ($SCRIPT ?? '');
        $path = '';
        if (!empty($PAGE) && $PAGE->url) {
            $path = $PAGE->url->get_path();
        }
        return str_contains($script, 'forgot_password.php')
            || str_contains($path, '/login/forgot_password.php');
    }

    /**
     * On forgot-password pages, force placeholders via Moodle footer JS
     * (more reliable than inline layout scripts).
     */
    public static function maybe_enhance_forgot_password_page(): void {
        global $PAGE;

        if (during_initial_install() || CLI_SCRIPT || AJAX_SCRIPT) {
            return;
        }

        if (!self::is_forgot_password_script()) {
            return;
        }

        $phuser = json_encode(
            get_string('forgot_username_placeholder', 'local_launchpad'),
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        $phemail = json_encode(
            get_string('forgot_email_placeholder', 'local_launchpad'),
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        $PAGE->requires->js_init_code(<<<JS
(function() {
    var form = document.querySelector('.login-container form')
        || document.querySelector('#region-main form')
        || document.querySelector('form');
    if (!form) {
        return;
    }
    var user = form.querySelector('#id_username') || form.querySelector('input[name="username"]');
    var email = form.querySelector('#id_email') || form.querySelector('input[name="email"]');
    if (user) {
        user.setAttribute('placeholder', {$phuser});
    }
    if (email) {
        email.setAttribute('placeholder', {$phemail});
    }
})();
JS
        );
    }

    /**
     * After a successful forgot-password submit, show LaunchPad notice instead of stock notice().
     *
     * @param \stdClass $data Submitted forgot form data.
     */
    public static function redirect_after_forgot_password(\stdClass $data): void {
        global $SESSION;

        unset($data); // Unused; core only passes form fields.

        // Generic privacy-safe copy (matches protectusernames behaviour).
        $SESSION->{self::SESSION_FORGOT_NOTICE} = get_string('forgot_notice_body', 'local_launchpad');
        redirect(new \moodle_url('/local/launchpad/forgot_notice.php'));
    }

    /**
     * Read and clear branded forgot-notice body from the session.
     */
    public static function consume_forgot_notice_body(): string {
        global $SESSION;

        $key = self::SESSION_FORGOT_NOTICE;
        $body = '';
        if (!empty($SESSION->{$key}) && is_string($SESSION->{$key})) {
            $body = $SESSION->{$key};
        }
        unset($SESSION->{$key});
        return $body;
    }
}
