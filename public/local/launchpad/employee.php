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
 * Minimal Employee learning area (cohort-gated; no Fellowship privileges).
 *
 * @package    local_launchpad
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_launchpad\account_persona;
use local_launchpad\login_portal;
use local_launchpad\portal_gate;

require_login();

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/launchpad/employee.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('employee_area_title', 'local_launchpad'));
$PAGE->set_heading(get_string('employee_area_heading', 'local_launchpad'));

$allowed = account_persona::is_employee((int) $USER->id) || is_siteadmin();
portal_gate::assert_or_redirect_home($allowed, login_portal::EMPLOYER);

$mismatch = portal_gate::consume_modal_context();
$pluginbase = $CFG->wwwroot . '/local/launchpad';
$assetv = (string) (get_config('local_launchpad', 'version') ?: '1');
$cssurl = $pluginbase . '/styles_fellowship.css?v=' . rawurlencode($assetv);
$jsurl = $pluginbase . '/fellowship.js?v=' . rawurlencode($assetv);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('employee_area_heading', 'local_launchpad'));
echo html_writer::tag('p', get_string('employee_area_body', 'local_launchpad'));
if (!empty($mismatch['show_portal_mismatch'])) {
    // ToBa modal over Boost employee chrome (same notice as fellow/mentor shells).
    echo '<link rel="stylesheet" href="' . s($cssurl) . '">' . "\n";
    echo $OUTPUT->render_from_template('local_launchpad/portal_mismatch_modal', $mismatch);
    echo '<script src="' . s($jsurl) . '" defer></script>' . "\n";
}
echo $OUTPUT->footer();
