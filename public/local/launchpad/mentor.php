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
 * Mentor dashboard — review queue for assigned lp-* courses (HTML-scoped: Dashboard + Queue).
 *
 * @package    local_launchpad
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_launchpad\account_persona;
use local_launchpad\fellowship_dashboard;
use local_launchpad\login_portal;
use local_launchpad\mentor_dashboard;
use local_launchpad\persona_home;
use local_launchpad\portal_gate;

require_login();

$userid = (int) $USER->id;
portal_gate::assert_or_redirect_home(
    mentor_dashboard::can_access($userid),
    login_portal::MENTOR
);

$view = optional_param('view', 'home', PARAM_ALPHA);
$filter = optional_param('filter', 'waiting', PARAM_ALPHA);
$cmid = optional_param('id', 0, PARAM_INT);
$fellowid = optional_param('userid', 0, PARAM_INT);
$saved = optional_param('saved', '', PARAM_ALPHA);
$err = optional_param('err', '', PARAM_ALPHANUMEXT);

$courses = persona_home::list_mentor_courses($userid);

// Persist review into Moodle assign before rendering.
if ($view === 'review' && $cmid > 0 && $fellowid > 0 && data_submitted() && confirm_sesskey()) {
    $gradepct = required_param('grade_pct', PARAM_RAW_TRIMMED);
    $comment = required_param('comment', PARAM_RAW_TRIMMED);
    $outcome = optional_param('outcome', 'met', PARAM_ALPHA);
    try {
        $result = mentor_dashboard::save_review($userid, $cmid, $fellowid, $gradepct, $comment, $outcome);
        redirect(new moodle_url(persona_home::MENTOR_PATH, [
            'view' => 'review',
            'id' => $cmid,
            'userid' => $fellowid,
            'saved' => $result,
        ]));
    } catch (\moodle_exception $e) {
        // Stay in LaunchPad chrome — don't dump a bare Moodle exception page.
        redirect(new moodle_url(persona_home::MENTOR_PATH, [
            'view' => 'review',
            'id' => $cmid,
            'userid' => $fellowid,
            'err' => $e->errorcode,
            'grade_pct' => $gradepct,
            'outcome' => $outcome,
        ]), $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url(persona_home::MENTOR_PATH, array_filter([
    'view' => $view !== 'home' ? $view : null,
    'filter' => ($view === 'queue' && $filter !== 'waiting') ? $filter : null,
    'id' => ($view === 'review' && $cmid > 0) ? $cmid : null,
    'userid' => ($view === 'review' && $fellowid > 0) ? $fellowid : null,
])));
$PAGE->set_pagelayout('embedded');
$PAGE->set_title(get_string('mentor_area_title', 'local_launchpad'));
$PAGE->set_heading(get_string('mentor_area_heading', 'local_launchpad'));

$pluginbase = $CFG->wwwroot . '/local/launchpad';
$assetv = (string) (get_config('local_launchpad', 'version') ?: '1');
$cssurl = $pluginbase . '/styles_fellowship.css?v=' . rawurlencode($assetv);
$jsurl = $pluginbase . '/fellowship.js?v=' . rawurlencode($assetv);
$logouturl = (new moodle_url('/login/logout.php', ['sesskey' => sesskey()]))->out(false);
$profileurl = (new moodle_url('/user/profile.php', ['id' => $userid]))->out(false);
$homeurl = (new moodle_url(persona_home::MENTOR_PATH))->out(false);
$queueurl = (new moodle_url(persona_home::MENTOR_PATH, ['view' => 'queue']))->out(false);
$fellowsurl = (new moodle_url(persona_home::MENTOR_PATH, ['view' => 'fellows']))->out(false);
$notificationsurl = (new moodle_url('/message/index.php'))->out(false);

$firstname = format_string($USER->firstname);
$displayname = fullname($USER);

$icon = static function (string $paths): string {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">'
        . $paths . '</svg>';
};

// Nav: Dashboard + Review queue + My fellows (HTML also has track/history — deferred).
$queuepreview = mentor_dashboard::list_review_queue($userid, $courses, 'waiting');
$waitingcount = (int) ($queuepreview['waiting_count'] ?? 0);

$nav = [
    [
        'group' => get_string('mentor_nav_review', 'local_launchpad'),
        'items' => [
            [
                'label' => get_string('mentor_nav_home', 'local_launchpad'),
                'url' => $homeurl,
                'current' => $view === 'home',
                'key' => 'home',
                'icon_svg' => $icon('<path d="M3 11.5L12 4l9 7.5"/><path d="M6 10.5V20h12v-9.5"/>'),
            ],
            [
                'label' => get_string('mentor_nav_queue', 'local_launchpad'),
                'url' => $queueurl,
                'current' => $view === 'queue',
                'key' => 'queue',
                'icon_svg' => $icon('<path d="M5 5h14v10H9l-4 4V5z"/>'),
                'has_badge' => $waitingcount > 0,
                'badge' => $waitingcount > 0 ? (string) $waitingcount : '',
            ],
        ],
    ],
    [
        'group' => get_string('mentor_nav_course', 'local_launchpad'),
        'items' => [
            [
                'label' => get_string('mentor_nav_fellows', 'local_launchpad'),
                'url' => $fellowsurl,
                'current' => $view === 'fellows',
                'key' => 'fellows',
                'icon_svg' => $icon('<path d="M16 11a4 4 0 10-8 0 4 4 0 008 0z"/><path d="M4 20a8 8 0 0116 0"/><path d="M19 8a3 3 0 110 6"/>'),
            ],
        ],
    ],
];

$content = '';
$pagetitle = get_string('mentor_area_heading', 'local_launchpad');

switch ($view) {
    case 'queue':
        $pagetitle = get_string('mentor_nav_queue', 'local_launchpad');
        $queuedata = mentor_dashboard::list_review_queue($userid, $courses, $filter);
        $content = $OUTPUT->render_from_template('local_launchpad/mentor_queue', array_merge($queuedata, [
            'has_courses' => $courses !== [],
            'empty_courses_message' => get_string('mentor_area_no_courses', 'local_launchpad'),
        ]));
        break;

    case 'fellows':
        $pagetitle = get_string('mentor_nav_fellows', 'local_launchpad');
        $fellowsdata = mentor_dashboard::list_fellows($userid, $courses);
        $content = $OUTPUT->render_from_template('local_launchpad/mentor_fellows', array_merge($fellowsdata, [
            'has_courses' => $courses !== [],
            'empty_courses_message' => get_string('mentor_area_no_courses', 'local_launchpad'),
            'queue_url' => $queueurl,
        ]));
        break;

    case 'review':
        $pagetitle = get_string('mentor_nav_review_page', 'local_launchpad');
        if ($cmid <= 0 || $fellowid <= 0) {
            redirect(new moodle_url(persona_home::MENTOR_PATH, ['view' => 'queue']));
        }
        $reviewdata = mentor_dashboard::get_review_context($userid, $cmid, $fellowid);
        // Preserve attempted form values after a soft validation redirect.
        $postedgrade = optional_param('grade_pct', '', PARAM_RAW_TRIMMED);
        if ($postedgrade !== '') {
            $reviewdata['grade_pct'] = s($postedgrade);
        }
        $postedoutcome = optional_param('outcome', '', PARAM_ALPHA);
        if ($postedoutcome === 'changes' || $postedoutcome === 'met') {
            $reviewdata['outcome_met'] = ($postedoutcome === 'met');
            $reviewdata['outcome_changes'] = ($postedoutcome === 'changes');
        } else if (is_number((string) ($reviewdata['grade_pct'] ?? ''))
                && (float) $reviewdata['grade_pct'] < 70 && (float) $reviewdata['grade_pct'] >= 0
                && (string) $reviewdata['grade_pct'] !== '') {
            // Existing score below pass bar → default to Changes.
            $reviewdata['outcome_met'] = false;
            $reviewdata['outcome_changes'] = true;
        }
        $reviewdata['has_saved'] = ($saved === 'released' || $saved === 'changes');
        $reviewdata['saved_message'] = match ($saved) {
            'released' => get_string('mentor_review_saved_released', 'local_launchpad'),
            'changes' => get_string('mentor_review_saved_changes', 'local_launchpad'),
            default => '',
        };
        $reviewdata['has_error'] = ($err !== '');
        $reviewdata['error_message'] = $err !== ''
            ? get_string($err, 'local_launchpad')
            : '';
        $content = $OUTPUT->render_from_template('local_launchpad/mentor_review', $reviewdata);
        break;

    case 'home':
    default:
        $view = 'home';
        $pagetitle = get_string('mentor_nav_home', 'local_launchpad');
        $homesummary = mentor_dashboard::home_summary($userid, $courses);
        $content = $OUTPUT->render_from_template('local_launchpad/mentor_home', array_merge([
            'firstname' => $firstname,
            'welcome_back' => get_string('mentor_welcome_back', 'local_launchpad', $firstname),
            'prof_url' => $pluginbase . '/assets/img/prof.webp',
        ], $homesummary));
        break;
}

foreach ($nav as &$group) {
    foreach ($group['items'] as &$item) {
        $key = $item['key'] ?? '';
        // Keep Review queue highlighted while grading.
        $item['current'] = ($key === $view) || ($view === 'review' && $key === 'queue');
    }
}
unset($group, $item);

$unread = 0;
if (class_exists('\message_popup\api')) {
    try {
        $unread = (int) \message_popup\api::count_unread_popup_notifications($userid);
    } catch (\Throwable $e) {
        $unread = 0;
    }
}
$notificationslabel = $unread > 0
    ? get_string('fellowship_notifications_unread', 'local_launchpad', $unread)
    : get_string('fellowship_notifications', 'local_launchpad');

$mismatch = portal_gate::consume_modal_context();
$shell = $OUTPUT->render_from_template('local_launchpad/mentor_shell', array_merge([
    'data_region' => mentor_dashboard::DATA_REGION,
    'displayname' => $displayname,
    'firstname' => $firstname,
    'role_label' => get_string('mentor_role_label', 'local_launchpad'),
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
], $mismatch));

header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html>' . "\n";
echo '<html lang="' . s(current_language()) . '">' . "\n";
echo '<head>' . "\n";
echo '<meta charset="utf-8">' . "\n";
echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
echo '<title>' . s(get_string('mentor_area_title', 'local_launchpad')) . '</title>' . "\n";
echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
echo '<link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700&family=Syne:wght@600;700&display=swap" rel="stylesheet">' . "\n";
echo '<link rel="stylesheet" href="' . s($cssurl) . '">' . "\n";
echo '</head>' . "\n";
$bodyclass = 'local-launchpad-fellowship local-launchpad-mentor';
if (!empty($mismatch['show_portal_mismatch'])) {
    $bodyclass .= ' lp-portal-mismatch-lock';
}
echo '<body class="' . $bodyclass . '">' . "\n";
echo $shell;
echo '<script src="' . s($jsurl) . '" defer></script>' . "\n";
echo '</body>' . "\n";
echo '</html>';
