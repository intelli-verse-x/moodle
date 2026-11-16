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
 * LaunchPad-branded "check your email" page after Fellowship signup.
 *
 * @package    local_launchpad
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_launchpad\login_portal;
use local_launchpad\registration;

require_once(__DIR__ . '/classes/registration.php');

if (isloggedin() && !isguestuser()) {
    redirect(new moodle_url('/my/'));
}

$email = registration::consume_pending_email();
$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/launchpad/signup_pending.php'));
$PAGE->set_pagelayout('embedded');
$PAGE->set_title(get_string('signup_pending_title', 'local_launchpad'));
$PAGE->add_body_class('local-launchpad-fellowship');
$PAGE->add_body_class('local-launchpad-signup-notice');

$plugin = new stdClass();
require(__DIR__ . '/version.php');
$assetv = (string) ($plugin->version ?? time());
$pluginbase = $CFG->wwwroot . '/local/launchpad';
$PAGE->requires->css(new moodle_url($pluginbase . '/styles_fellowship.css', ['v' => $assetv]));

$loginurl = (new moodle_url('/login/index.php', ['portal' => login_portal::FELLOWSHIP]))->out(false);
$homeurl = (new moodle_url('/'))->out(false);
$assets = $pluginbase . '/assets/img';

$body = $email !== ''
    ? get_string('signup_pending_body', 'local_launchpad', $email)
    : get_string('signup_pending_body_generic', 'local_launchpad');

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_launchpad/signup_notice', [
    'assets' => $assets,
    'eyebrow' => get_string('signup_pending_eyebrow', 'local_launchpad'),
    'title' => get_string('signup_pending_title', 'local_launchpad'),
    'body' => $body,
    'note' => get_string('signup_pending_note', 'local_launchpad'),
    'continue_url' => $homeurl,
    'continue_label' => get_string('signup_pending_continue', 'local_launchpad'),
    'secondary_url' => $loginurl,
    'secondary_label' => get_string('signup_pending_signin', 'local_launchpad'),
    'has_secondary' => true,
    'mode_pending' => true,
    'mode_confirmed' => false,
]);
echo $OUTPUT->footer();
exit;
