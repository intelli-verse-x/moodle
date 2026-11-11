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
use local_launchpad\messaging;
use local_launchpad\portal_gate;

require_login();

$context = context_system::instance();
$view = optional_param('view', 'home', PARAM_ALPHA);
$courseid = optional_param('id', 0, PARAM_INT);
$tab = optional_param('tab', 'all', PARAM_ALPHA);
$mtab = optional_param('mtab', 'all', PARAM_ALPHA);
$gtab = optional_param('gtab', 'all', PARAM_ALPHA);
$ntab = optional_param('ntab', 'all', PARAM_ALPHA);
$ptab = optional_param('ptab', 'overview', PARAM_ALPHA);
$convid = optional_param('convid', 0, PARAM_INT);
$notifid = optional_param('notifid', 0, PARAM_INT);
$msgsent = optional_param('msgsent', 0, PARAM_INT);
$msgerr = optional_param('msgerr', '', PARAM_ALPHA);
$notifdone = optional_param('notifdone', 0, PARAM_INT);
$notiferr = optional_param('notiferr', '', PARAM_ALPHA);
$profilesaved = optional_param('profilesaved', 0, PARAM_INT);
$profileerr = optional_param('profileerr', '', PARAM_ALPHAEXT);
$prefsaved = optional_param('prefsaved', 0, PARAM_INT);
$preferr = optional_param('preferr', '', PARAM_ALPHAEXT);
$pref = optional_param('pref', '', PARAM_ALPHAEXT);
$allowedviews = [
    'home', 'courses', 'course', 'grades', 'badges', 'calendar', 'messages',
    'notifications', 'notificationprefs', 'profile',
];
$allowedprefs = [
    'password', 'language', 'editor', 'forum', 'calendar', 'messages', 'notifications',
    'edit', 'public', 'files', 'contentbank', 'tokens',
];
if (!in_array($view, $allowedviews, true)) {
    $view = 'home';
}
if ($pref !== '' && !in_array($pref, $allowedprefs, true)) {
    $pref = '';
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
if (!in_array($ntab, ['all', 'unread'], true)) {
    $ntab = 'all';
}
if (!in_array($ptab, ['overview', 'edit', 'preferences'], true)) {
    $ptab = 'overview';
}
if ($convid < 0) {
    $convid = 0;
}
if ($notifid < 0) {
    $notifid = 0;
}
// Course id: home focus, course detail, or grades detail. Cleared on other tools.
if ($view === 'courses' || $view === 'badges'
        || $view === 'calendar' || $view === 'messages' || $view === 'notifications'
        || $view === 'notificationprefs'
        || $view === 'profile'
        || $courseid < 0) {
    $courseid = 0;
}
if ($view !== 'messages') {
    $convid = 0;
}
if ($view !== 'notifications') {
    $notifid = 0;
}

if (($view === 'notifications' || $view === 'notificationprefs')
        && data_submitted() && confirm_sesskey()) {
    $naction = optional_param('action', '', PARAM_ALPHAEXT);
    if ($view === 'notifications' && $naction === 'mark_all_notifications_read') {
        $result = messaging::mark_all_popup_notifications_read((int) $USER->id);
        redirect(new moodle_url('/local/launchpad/employee.php', array_filter([
            'view' => 'notifications',
            'ntab' => $ntab !== 'all' ? $ntab : null,
            'notifdone' => !empty($result['ok']) ? 1 : null,
            'notiferr' => empty($result['ok']) ? 'failed' : null,
        ])));
    }
    if ($view === 'notificationprefs' && $naction === 'save_notification_preferences') {
        $errsave = messaging::save_notification_preferences((int) $USER->id, [
            'popup_enabled' => optional_param('popup_enabled', 0, PARAM_BOOL),
            'email_enabled' => optional_param('email_enabled', 0, PARAM_BOOL),
        ]);
        redirect(new moodle_url('/local/launchpad/employee.php', array_filter([
            'view' => 'notificationprefs',
            'prefsaved' => $errsave === '' ? 1 : null,
            'preferr' => $errsave !== '' ? $errsave : null,
        ])));
    }
}
if ($view !== 'profile') {
    $ptab = 'overview';
    $pref = '';
}
if ($ptab !== 'preferences') {
    $pref = '';
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/launchpad/employee.php', array_filter([
    'view' => $view !== 'home' ? $view : null,
    'id' => $courseid > 0 ? $courseid : null,
    'tab' => ($view === 'badges' && $tab !== 'all') ? $tab : null,
    'mtab' => ($view === 'messages' && $mtab !== 'all') ? $mtab : null,
    'gtab' => ($view === 'grades' && $courseid <= 0 && $gtab !== 'all') ? $gtab : null,
    'convid' => ($view === 'messages' && $convid > 0) ? $convid : null,
    'ntab' => ($view === 'notifications' && $ntab !== 'all') ? $ntab : null,
    'notifid' => ($view === 'notifications' && $notifid > 0) ? $notifid : null,
    'ptab' => ($view === 'profile' && $ptab !== 'overview') ? $ptab : null,
    'pref' => ($view === 'profile' && $ptab === 'preferences' && $pref !== '') ? $pref : null,
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
        $gtab,
        $ntab,
        $notifid,
        $notifdone > 0,
        $notiferr,
        $ptab,
        $profilesaved > 0,
        $profileerr,
        $pref,
        $prefsaved > 0,
        $preferr
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
