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
 * LaunchPad-branded "registration confirmed" page → Fellowship shell.
 *
 * @package    local_launchpad
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_launchpad\persona_home;
use local_launchpad\registration;

require_once(__DIR__ . '/classes/registration.php');
require_once(__DIR__ . '/classes/persona_home.php');

require_login();

$userid = (int) $USER->id;
if (!registration::is_self_registered_fellow($userid)
        && !\local_launchpad\account_persona::is_fellowship($userid)) {
    // Not a Fellowship signup confirm — send them to their real home.
    $home = persona_home::home_url_for($userid) ?? new moodle_url('/my/');
    redirect($home);
}

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/launchpad/signup_confirmed.php'));
$PAGE->set_pagelayout('embedded');
$PAGE->set_title(get_string('signup_confirmed_title', 'local_launchpad'));
$PAGE->add_body_class('local-launchpad-fellowship');
$PAGE->add_body_class('local-launchpad-signup-notice');

$plugin = new stdClass();
require(__DIR__ . '/version.php');
$assetv = (string) ($plugin->version ?? time());
$pluginbase = $CFG->wwwroot . '/local/launchpad';
$PAGE->requires->css(new moodle_url($pluginbase . '/styles_fellowship.css', ['v' => $assetv]));

$continue = persona_home::home_url_for($userid);
if ($continue === null) {
    $continue = new moodle_url(persona_home::FELLOW_PATH);
}
// Keep session wantsurl aligned so MFA / follow-on redirects stay on LaunchPad.
$SESSION->wantsurl = $continue->out(false);

$assets = $pluginbase . '/assets/img';
$displayname = fullname($USER);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_launchpad/signup_notice', [
    'assets' => $assets,
    'eyebrow' => get_string('signup_confirmed_eyebrow', 'local_launchpad'),
    'title' => get_string('signup_confirmed_title', 'local_launchpad'),
    'body' => get_string('signup_confirmed_body', 'local_launchpad', $displayname),
    'note' => get_string('signup_confirmed_note', 'local_launchpad'),
    'continue_url' => $continue->out(false),
    'continue_label' => get_string('signup_confirmed_continue', 'local_launchpad'),
    'has_secondary' => false,
    'mode_pending' => false,
    'mode_confirmed' => true,
]);
echo $OUTPUT->footer();
exit;
