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
 * Employer portal dashboard (cohort-gated; Moodle core for courses/grades).
 *
 * @package    local_launchpad
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

// New class from main — load explicitly so a stale Moodle classmap cannot 404 it.
require_once(__DIR__ . '/classes/portal_gate.php');

use local_launchpad\account_persona;
use local_launchpad\employer_dashboard;
use local_launchpad\login_portal;
use local_launchpad\portal_gate;

require_login();

$context = context_system::instance();
$view = optional_param('view', 'home', PARAM_ALPHA);
$courseid = optional_param('id', 0, PARAM_INT);
$tab = optional_param('tab', 'all', PARAM_ALPHA);
$mtab = optional_param('mtab', 'all', PARAM_ALPHA);
$gtab = optional_param('gtab', 'all', PARAM_ALPHA);
$convid = optional_param('convid', 0, PARAM_INT);
$msgsent = optional_param('msgsent', 0, PARAM_INT);
$msgerr = optional_param('msgerr', '', PARAM_ALPHA);
$allowedviews = ['home', 'courses', 'course', 'grades', 'badges', 'calendar', 'messages', 'notifications'];
if (!in_array($view, $allowedviews, true)) {
    $view = 'home';
}
if ($tab !== 'earned') {
    $tab = 'all';
}
if (!in_array($mtab, ['all', 'starred', 'private', 'group'], true)) {
    $mtab = 'all';
}
if (!in_array($gtab, ['all', 'graded', 'pending'], true)) {
    $gtab = 'all';
}
if ($convid < 0) {
    $convid = 0;
}
// Course id: home focus, course detail, or grades detail. Cleared on other tools.
if ($view === 'courses' || $view === 'badges'
        || $view === 'calendar' || $view === 'messages' || $view === 'notifications'
        || $courseid < 0) {
    $courseid = 0;
}
if ($view !== 'messages') {
    $convid = 0;
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/launchpad/employee.php', array_filter([
    'view' => $view !== 'home' ? $view : null,
    'id' => $courseid > 0 ? $courseid : null,
    'tab' => ($view === 'badges' && $tab !== 'all') ? $tab : null,
    'mtab' => ($view === 'messages' && $mtab !== 'all') ? $mtab : null,
    'gtab' => ($view === 'grades' && $courseid <= 0 && $gtab !== 'all') ? $gtab : null,
    'convid' => ($view === 'messages' && $convid > 0) ? $convid : null,
])));
$PAGE->set_pagelayout('embedded');
$PAGE->set_title(get_string('employer_area_title', 'local_launchpad'));
$PAGE->set_heading(get_string('employer_area_heading', 'local_launchpad'));

// Site admins use admin.php — do not linger on the Employer shell.
$allowed = account_persona::is_employee((int) $USER->id);
portal_gate::assert_or_redirect_home($allowed, login_portal::EMPLOYER);

$mismatch = portal_gate::consume_modal_context();
$templatecontext = array_merge(
    employer_dashboard::export_for_template(
        $USER,
        $view,
        $courseid,
        $tab,
        $mtab,
        $convid,
        $msgsent > 0,
        $msgerr,
        $gtab
    ),
    $mismatch
);
$pluginbase = $CFG->wwwroot . '/local/launchpad';
// Cache-bust CSS/JS from this plugin's version.php (lives next to employee.php under public/).
$plugin = new \stdClass();
require(__DIR__ . '/version.php');
$assetv = (string) ($plugin->version ?? time());
// Same visual system as Fellowship shell (styles_fellowship → styles_employer).
$cssurl = $pluginbase . '/styles_employer.css?v=' . rawurlencode($assetv);
$jsurl = $pluginbase . '/employer.js?v=' . rawurlencode($assetv);
$title = s(get_string('employer_area_title', 'local_launchpad'));
$body = $OUTPUT->render_from_template('local_launchpad/employer_dashboard', $templatecontext);

// Standalone LaunchPad shell. My tools: custom UI + Moodle deep-links (Fellowship pattern).
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html>' . "\n";
echo '<html lang="' . s(current_language()) . '">' . "\n";
echo '<head>' . "\n";
echo '<meta charset="utf-8">' . "\n";
echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
echo '<title>' . $title . '</title>' . "\n";
echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
echo '<link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700&family=Syne:wght@600;700&display=swap" rel="stylesheet">' . "\n";
echo '<link rel="stylesheet" href="' . s($cssurl) . '">' . "\n";
echo '<script src="https://code.iconify.design/iconify-icon/2.1.0/iconify-icon.min.js"></script>' . "\n";
echo '</head>' . "\n";
$bodyclass = 'local-launchpad-employer';
if (!empty($mismatch['show_portal_mismatch'])) {
    $bodyclass .= ' lp-portal-mismatch-lock';
}
echo '<body class="' . $bodyclass . '">' . "\n";
echo $body;
echo '<script src="' . s($jsurl) . '" defer></script>' . "\n";
echo '</body>' . "\n";
echo '</html>';
