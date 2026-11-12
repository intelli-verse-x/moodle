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
 * Callbacks for local_launchpad.
 *
 * @package    local_launchpad
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Ensure Email self-registration is available before pages render.
 *
 * Covers sites that have not yet run the LaunchPad upgrade step.
 * Persona /my/ redirects live in hook_callbacks → persona_home (Fellow,
 * Mentor, Employer) so this callback stays registration-only.
 */
function local_launchpad_before_http_headers(): void {
    if (during_initial_install()) {
        return;
    }
    \local_launchpad\registration::ensure_email_self_registration(true);
    // On /login/index.php?portal=… pin gated wantsurl early (GET display + POST).
    \local_launchpad\login_portal::maybe_pin_wantsurl_from_request();
    // Logged-in browser-back to login → landing, not Moodle "Log out?" card.
    \local_launchpad\login_portal::maybe_redirect_logged_in_from_login();
    // Forgot password: force field placeholders (footer JS).
    \local_launchpad\auth_chrome::maybe_enhance_forgot_password_page();
}

/**
 * Before signup form: force Fellowship portal on the session / URL.
 */
function local_launchpad_pre_signup_requests(): void {
    \local_launchpad\registration::prepare_signup_session();
}

/**
 * Brand forgot-password form like Fellowship signup (logo + heading).
 *
 * @param \MoodleQuickForm $mform Forgot password form.
 */
function local_launchpad_extend_forgot_password_form($mform): void {
    global $CFG;

    $logopath = $CFG->dirroot . '/local/launchpad/assets/img/logo.webp';
    $logourl = is_readable($logopath)
        ? (new moodle_url('/local/launchpad/assets/img/logo.webp'))->out(false)
        : '';

    $logohtml = '';
    if ($logourl !== '') {
        $logohtml = '<div id="loginlogo" class="d-flex justify-content-center">'
            . '<img id="logoimage" src="' . s($logourl) . '" class="img-fluid" alt="ToBa Learn"/>'
            . '</div>';
    }

    $phuser = get_string('forgot_username_placeholder', 'local_launchpad');
    $phemail = get_string('forgot_email_placeholder', 'local_launchpad');
    // Inline script survives even if layout JS aborts (e.g. insertBefore hierarchy errors).
    $phscript = '<script>(function(){function lpForgotPh(){var u=document.getElementById("id_username");'
        . 'var e=document.getElementById("id_email");'
        . 'if(u){u.setAttribute("placeholder",' . json_encode($phuser) . ');}'
        . 'if(e){e.setAttribute("placeholder",' . json_encode($phemail) . ');}}'
        . 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",lpForgotPh);}'
        . 'else{lpForgotPh();}setTimeout(lpForgotPh,0);})();</script>';

    $mform->addElement(
        'html',
        '<div class="toba-forgot-head" data-toba-forgot-head>'
            . $logohtml
            . '<h1 class="h2 toba-login-heading">' . get_string('forgot_heading', 'local_launchpad') . '</h1>'
            . '<p class="toba-login-sub text-muted mb-0">' . get_string('forgot_lead', 'local_launchpad') . '</p>'
            . '</div>'
            . $phscript
    );
}

/**
 * Brand set-password form (email reset link) with the same ToBa chrome.
 *
 * Hook runs at the END of core definition — insert logo/heading before the stock header.
 *
 * @param \MoodleQuickForm $mform Set password form.
 * @param \stdClass $user User being reset.
 */
function local_launchpad_extend_set_password_form($mform, $user): void {
    global $CFG, $PAGE;

    // Same script as forgot_password.php — mark body so CSS can target set-password.
    if (!empty($PAGE)) {
        $PAGE->add_body_class('lp-set-password');
    }

    $logopath = $CFG->dirroot . '/local/launchpad/assets/img/logo.webp';
    $logourl = is_readable($logopath)
        ? (new moodle_url('/local/launchpad/assets/img/logo.webp'))->out(false)
        : '';

    $logohtml = '';
    if ($logourl !== '') {
        $logohtml = '<div id="loginlogo" class="d-flex justify-content-center">'
            . '<img id="logoimage" src="' . s($logourl) . '" class="img-fluid" alt="ToBa Learn"/>'
            . '</div>';
    }

    $ph = get_string('setpassword_placeholder', 'local_launchpad');
    $ph2 = get_string('setpassword_placeholder_again', 'local_launchpad');
    $phscript = '<script>(function(){function lpSetPh(){'
        . 'var p=document.getElementById("id_password");'
        . 'var p2=document.getElementById("id_password2");'
        . 'if(p){p.setAttribute("placeholder",' . json_encode($ph) . ');}'
        . 'if(p2){p2.setAttribute("placeholder",' . json_encode($ph2) . ');}'
        . 'document.body.classList.add("lp-set-password");'
        . '}'
        . 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",lpSetPh);}'
        . 'else{lpSetPh();}setTimeout(lpSetPh,0);})();</script>';

    $html = '<div class="toba-forgot-head" data-toba-forgot-head>'
        . $logohtml
        . '<h1 class="h2 toba-login-heading">' . get_string('setpassword_heading', 'local_launchpad') . '</h1>'
        . '<p class="toba-login-sub text-muted mb-0">' . get_string('setpassword_lead', 'local_launchpad') . '</p>'
        . '</div>'
        . $phscript;

    // Core calls this hook after fields exist — put brand block first.
    if ($mform->elementExists('setpassword')) {
        $mform->insertElementBefore($mform->createElement('html', $html), 'setpassword');
    } else {
        $mform->addElement('html', $html);
    }

    // One clean line: "Username – realusername" (not stacked label + value).
    $uname = trim((string) ($user->username ?? ''));
    if ($uname !== '' && $mform->elementExists('username2')) {
        $mform->removeElement('username2');
        $line = '<p class="lp-set-password-user">'
            . get_string('setpassword_username_line', 'local_launchpad', s($uname))
            . '</p>';
        $before = $mform->elementExists('passwordpolicyinfo') ? 'passwordpolicyinfo' : 'password';
        if ($mform->elementExists($before)) {
            $mform->insertElementBefore($mform->createElement('html', $line), $before);
        } else {
            $mform->addElement('html', $line);
        }
    }
}

/**
 * Inject Fellowship portal field into signup.
 *
 * @param \MoodleQuickForm $mform Signup form.
 */
function local_launchpad_extend_signup_form($mform): void {
    // Keep Moodle's native country <select> (accurate ISO list + empty default).
    // The Fellowship signup template enhances it into one searchable dropdown.
    if ($mform->elementExists('country')) {
        $mform->setDefault('country', '');
    }

    $mform->addElement('hidden', 'portal', \local_launchpad\registration::PORTAL_FELLOWSHIP);
    $mform->setType('portal', PARAM_ALPHA);
    $mform->setConstant('portal', \local_launchpad\registration::PORTAL_FELLOWSHIP);
}

/**
 * Fellowship-only self-registration validation.
 *
 * @param array $data Submitted signup data.
 * @return array Field errors.
 */
function local_launchpad_validate_extend_signup_form(array $data): array {
    $errors = [];
    $portal = \local_launchpad\registration::normalise_portal((string) ($data['portal'] ?? ''));
    if ($portal !== \local_launchpad\registration::PORTAL_FELLOWSHIP) {
        $errors['portal'] = get_string('signupportalinvalid', 'local_launchpad');
    }
    return $errors;
}

/**
 * After forgot-password validation, swap stock Moodle notice for LaunchPad screen.
 *
 * @param \stdClass $data Submitted forgot form.
 */
function local_launchpad_post_forgot_password_requests($data): void {
    \local_launchpad\auth_chrome::redirect_after_forgot_password($data);
}

/**
 * After validated signup data: keep Fellowship portal on the session for user_created.
 *
 * Preference is written in the user_created observer (user id does not exist yet here).
 *
 * @param \stdClass $user Signup user data (no id yet).
 */
function local_launchpad_post_signup_requests($user): void {
    global $SESSION;

    $portal = \local_launchpad\registration::PORTAL_FELLOWSHIP;
    if (!empty($user->portal)) {
        $portal = \local_launchpad\registration::normalise_portal((string) $user->portal);
    }
    if ($portal !== \local_launchpad\registration::PORTAL_FELLOWSHIP) {
        $portal = \local_launchpad\registration::PORTAL_FELLOWSHIP;
    }
    $SESSION->{\local_launchpad\registration::SESSION_PORTAL} = $portal;

    // Ensure auth_email saves fellow.php as auth_email_wantsurl (not /my/).
    \local_launchpad\registration::ensure_fellowship_wantsurl();

    // Stash email so before_http_headers can swap Moodle's stock notice page.
    if (!empty($user->email)) {
        \local_launchpad\registration::stash_pending_email((string) $user->email);
    }
}
