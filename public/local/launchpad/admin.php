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
 * Admin portal — Overview + Tracks & courses (no Course builder yet).
 *
 * @package    local_launchpad
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_launchpad\admin_dashboard;
use local_launchpad\fellowship_dashboard;
use local_launchpad\login_portal;
use local_launchpad\messaging;
use local_launchpad\persona_home;
use local_launchpad\portal_gate;

require_login();

$userid = (int) $USER->id;
portal_gate::assert_or_redirect_home(
    admin_dashboard::can_access($userid),
    login_portal::ADMIN
);

$view = optional_param('view', 'home', PARAM_ALPHA);
$allowedviews = ['home', 'tracks'];
if (!in_array($view, $allowedviews, true)) {
    $view = 'home';
}

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url(persona_home::ADMIN_PATH, array_filter([
    'view' => $view !== 'home' ? $view : null,
])));
$PAGE->set_pagelayout('embedded');
$PAGE->set_title(get_string('admin_area_title', 'local_launchpad'));
$PAGE->set_heading(get_string('admin_area_heading', 'local_launchpad'));

$pluginbase = $CFG->wwwroot . '/local/launchpad';
$assetv = (string) (get_config('local_launchpad', 'version') ?: '1');
$cssurl = $pluginbase . '/styles_fellowship.css?v=' . rawurlencode($assetv);
$jsurl = $pluginbase . '/fellowship.js?v=' . rawurlencode($assetv);
$logouturl = (new moodle_url('/login/logout.php', ['sesskey' => sesskey()]))->out(false);
$profileurl = (new moodle_url('/user/profile.php', ['id' => $userid]))->out(false);
$homeurl = (new moodle_url(persona_home::ADMIN_PATH))->out(false);
$tracksurl = (new moodle_url(persona_home::ADMIN_PATH, ['view' => 'tracks']))->out(false);
$notificationsurl = messaging::moodle_notifications_url();

$firstname = format_string($USER->firstname);
$displayname = fullname($USER);

$icon = static function (string $paths): string {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">'
        . $paths . '</svg>';
};

$nav = [
    [
        'group' => get_string('admin_nav_programme', 'local_launchpad'),
        'items' => [
            [
                'label' => get_string('admin_nav_overview', 'local_launchpad'),
                'url' => $homeurl,
                'current' => $view === 'home',
                'key' => 'home',
                'icon_svg' => $icon(
                    '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/>'
                ),
            ],
            [
                'label' => get_string('admin_nav_tracks', 'local_launchpad'),
                'url' => $tracksurl,
                'current' => $view === 'tracks',
                'key' => 'tracks',
                'icon_svg' => $icon(
                    '<path d="M4 6h16M4 12h10M4 18h14"/>'
                ),
            ],
        ],
    ],
];

if ($view === 'tracks') {
    $pagetitle = get_string('admin_nav_tracks', 'local_launchpad');
    $content = $OUTPUT->render_from_template(
        'local_launchpad/admin_tracks',
        admin_dashboard::tracks_context($USER)
    );
} else {
    $view = 'home';
    $pagetitle = get_string('admin_nav_overview', 'local_launchpad');
    $content = $OUTPUT->render_from_template(
        'local_launchpad/admin_overview',
        admin_dashboard::overview_context($USER)
    );
}

$unread = messaging::notification_unread_count($userid);
$notificationslabel = messaging::notifications_label($unread);

$mismatch = portal_gate::consume_modal_context();
$shell = $OUTPUT->render_from_template('local_launchpad/admin_shell', array_merge([
    'data_region' => admin_dashboard::DATA_REGION,
    'displayname' => $displayname,
    'firstname' => $firstname,
    'role_label' => get_string('admin_role_label', 'local_launchpad'),
    'nav_groups' => $nav,
    'content' => $content,
    'pagetitle' => $pagetitle,
    'home_url' => $homeurl,
    'logout_url' => $logouturl,
    'profile_url' => $profileurl,
    'notifications_url' => $notificationsurl,
    'notifications_label' => $notificationslabel,
    'unread_notifications' => $unread,
    'has_unread_notifications' => $unread > 0,
    'initials' => fellowship_dashboard::initials($USER),
    // Pre-resolve chrome strings — avoid Mustache str helpers on this custom shell.
    'str_skip' => get_string('admin_skip_to_content', 'local_launchpad'),
    'str_nav_aria' => get_string('admin_nav_aria', 'local_launchpad'),
    'str_signout' => get_string('fellowship_nav_signout', 'local_launchpad'),
    'str_menu' => get_string('fellowship_menu', 'local_launchpad'),
    'str_account' => get_string('fellowship_nav_account', 'local_launchpad'),
], $mismatch));

header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html>' . "\n";
echo '<html lang="' . s(current_language()) . '">' . "\n";
echo '<head>' . "\n";
echo '<meta charset="utf-8">' . "\n";
echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
echo '<title>' . s(get_string('admin_area_title', 'local_launchpad')) . '</title>' . "\n";
echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
echo '<link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700&family=Syne:wght@600;700&display=swap" rel="stylesheet">' . "\n";
echo '<link rel="stylesheet" href="' . s($cssurl) . '">' . "\n";
echo '</head>' . "\n";
$bodyclass = 'local-launchpad-fellowship local-launchpad-admin';
if (!empty($mismatch['show_portal_mismatch'])) {
    $bodyclass .= ' lp-portal-mismatch-lock';
}
echo '<body class="' . $bodyclass . '">' . "\n";
echo $shell;
echo '<script src="' . s($jsurl) . '" defer></script>' . "\n";
echo '</body>' . "\n";
echo '</html>';
