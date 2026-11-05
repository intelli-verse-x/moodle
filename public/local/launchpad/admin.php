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
 * Admin portal — Overview, Tracks, Builder, Calendar, Announcements, Cohorts, People, Mentors, Reviews.
 *
 * @package    local_launchpad
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_once(__DIR__ . '/classes/calendar_service.php');
require_once(__DIR__ . '/classes/announcement_service.php');

use local_launchpad\admin_dashboard;
use local_launchpad\announcement_service;
use local_launchpad\calendar_service;
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
$allowedviews = [
    'home', 'tracks', 'courseops', 'builder', 'manage', 'activity', 'calendar',
    'announcements', 'cohorts', 'people', 'mentors', 'reviews',
];
if (!in_array($view, $allowedviews, true)) {
    $view = 'home';
}

$courseopssection = optional_param('section', 'hub', PARAM_ALPHANUMEXT);
if (!in_array($courseopssection, ['hub', 'settings', 'participants', 'grades', 'completion', 'reports', 'qbank'], true)) {
    $courseopssection = 'hub';
}

$peopletab = optional_param('tab', 'all', PARAM_ALPHA);
if (!in_array($peopletab, ['all', 'fellow', 'mentor', 'admin'], true)) {
    $peopletab = 'all';
}

$trackid = optional_param('trackid', 0, PARAM_INT);
$sectionnum = optional_param('section', 0, PARAM_INT);
$cmid = optional_param('cmid', 0, PARAM_INT);

$flash = [];
if (!empty($SESSION->lp_admin_flash) && is_array($SESSION->lp_admin_flash)) {
    $flash = $SESSION->lp_admin_flash;
    unset($SESSION->lp_admin_flash);
}

if (($view === 'cohorts' || $view === 'mentors' || $view === 'builder' || $view === 'manage'
            || $view === 'activity' || $view === 'announcements' || $view === 'reviews'
            || $view === 'courseops')
        && data_submitted() && confirm_sesskey()) {
    $action = optional_param('action', '', PARAM_ALPHAEXT);
    if ($view === 'courseops' && $action === 'save_course_settings') {
        $trackid = optional_param('trackid', 0, PARAM_INT);
        $fullname = optional_param('fullname', '', PARAM_TEXT);
        $shortname = optional_param('shortname', '', PARAM_TEXT);
        $visible = (bool) optional_param('visible', 0, PARAM_BOOL);
        $startdate = optional_param('startdate', '', PARAM_TEXT);
        $enddate = optional_param('enddate', '', PARAM_TEXT);
        $enablecompletion = (bool) optional_param('enablecompletion', 0, PARAM_BOOL);
        $summary = optional_param('summary', '', PARAM_RAW);
        $flash = admin_dashboard::save_courseops_settings(
            $USER,
            $trackid,
            $fullname,
            $shortname,
            $visible,
            $startdate,
            $enddate,
            $enablecompletion,
            $summary
        );
        $SESSION->lp_admin_flash = $flash;
        redirect(new moodle_url(persona_home::ADMIN_PATH, [
            'view' => 'courseops',
            'trackid' => $trackid,
            'section' => 'settings',
        ]));
    }
    if ($view === 'courseops' && $action === 'unenrol_fellow') {
        $trackid = optional_param('trackid', 0, PARAM_INT);
        $targetuserid = optional_param('userid', 0, PARAM_INT);
        $flash = admin_dashboard::unenrol_fellow_from_track($USER, $targetuserid, $trackid);
        $SESSION->lp_admin_flash = $flash;
        redirect(new moodle_url(persona_home::ADMIN_PATH, [
            'view' => 'courseops',
            'trackid' => $trackid,
            'section' => 'participants',
        ]));
    }
    if ($view === 'courseops' && $action === 'remove_mentor') {
        $trackid = optional_param('trackid', 0, PARAM_INT);
        $targetuserid = optional_param('userid', 0, PARAM_INT);
        $flash = admin_dashboard::remove_mentor_from_track($USER, $targetuserid, $trackid);
        $SESSION->lp_admin_flash = $flash;
        redirect(new moodle_url(persona_home::ADMIN_PATH, [
            'view' => 'courseops',
            'trackid' => $trackid,
            'section' => 'participants',
        ]));
    }
    if ($view === 'courseops' && $action === 'save_course_completion') {
        $trackid = optional_param('trackid', 0, PARAM_INT);
        $enablecompletion = (bool) optional_param('enablecompletion', 0, PARAM_BOOL);
        $flash = admin_dashboard::save_courseops_completion($USER, $trackid, $enablecompletion);
        $SESSION->lp_admin_flash = $flash;
        redirect(new moodle_url(persona_home::ADMIN_PATH, [
            'view' => 'courseops',
            'trackid' => $trackid,
            'section' => 'completion',
        ]));
    }
    if ($view === 'reviews' && $action === 'ping_review') {
        $submissionid = optional_param('submissionid', 0, PARAM_INT);
        $pingteacherid = optional_param('teacherid', 0, PARAM_INT);
        $flash = admin_dashboard::ping_pending_review_mentor($USER, $submissionid, $pingteacherid);
        $SESSION->lp_admin_flash = $flash;
        redirect(new moodle_url(persona_home::ADMIN_PATH, ['view' => 'reviews']));
    }
    if ($view === 'reviews' && $action === 'set_ping_target') {
        $submissionid = optional_param('submissionid', 0, PARAM_INT);
        $pingteacherid = optional_param('teacherid', 0, PARAM_INT);
        $result = admin_dashboard::save_review_ping_target($USER, $submissionid, $pingteacherid);
        if (optional_param('ajax', 0, PARAM_BOOL)) {
            @header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => empty($result['error']),
                'error' => (string) ($result['error'] ?? ''),
            ]);
            exit;
        }
        if (!empty($result['error'])) {
            $SESSION->lp_admin_flash = $result;
        }
        redirect(new moodle_url(persona_home::ADMIN_PATH, ['view' => 'reviews']));
    }
    if ($view === 'announcements' && $action === 'post_announcement') {
        $subject = optional_param('subject', '', PARAM_TEXT);
        $message = optional_param('message', '', PARAM_RAW);
        $alltracks = (bool) optional_param('all_tracks', 0, PARAM_BOOL);
        $pinned = (bool) optional_param('pinned', 0, PARAM_BOOL);
        $publishat = optional_param('publish_at', '', PARAM_TEXT);
        $trackids = optional_param_array('trackids', [], PARAM_INT);
        $tags = optional_param_array('tags', [], PARAM_TEXT);
        $customtag = optional_param('customtag', '', PARAM_TEXT);
        foreach (preg_split('/\s*,\s*/', trim($customtag)) ?: [] as $extra) {
            if ($extra !== '') {
                $tags[] = $extra;
            }
        }
        $annfiles = (isset($_FILES['annfiles']) && is_array($_FILES['annfiles'])) ? $_FILES['annfiles'] : null;
        $flash = announcement_service::post(
            $USER,
            $subject,
            $message,
            $trackids,
            $alltracks,
            $tags,
            $pinned,
            $publishat,
            $annfiles
        );
        $SESSION->lp_admin_flash = $flash;
        redirect(new moodle_url(persona_home::ADMIN_PATH, ['view' => 'announcements']));
    }
    if ($view === 'announcements' && $action === 'update_announcement') {
        $discussionid = optional_param('discussionid', 0, PARAM_INT);
        $subject = optional_param('subject', '', PARAM_TEXT);
        $message = optional_param('message', '', PARAM_RAW);
        $pinned = (bool) optional_param('pinned', 0, PARAM_BOOL);
        $publishat = optional_param('publish_at', '', PARAM_TEXT);
        $tags = optional_param_array('tags', [], PARAM_TEXT);
        $customtag = optional_param('customtag', '', PARAM_TEXT);
        foreach (preg_split('/\s*,\s*/', trim($customtag)) ?: [] as $extra) {
            if ($extra !== '') {
                $tags[] = $extra;
            }
        }
        $annfiles = (isset($_FILES['annfiles']) && is_array($_FILES['annfiles'])) ? $_FILES['annfiles'] : null;
        $removefiles = optional_param_array('removeannfiles', [], PARAM_FILE);
        $flash = announcement_service::update(
            $USER,
            $discussionid,
            $subject,
            $message,
            $tags,
            $pinned,
            $publishat,
            $annfiles,
            $removefiles
        );
        $SESSION->lp_admin_flash = $flash;
        redirect(new moodle_url(persona_home::ADMIN_PATH, ['view' => 'announcements']));
    }
    if ($view === 'announcements' && $action === 'delete_announcement') {
        $discussionid = optional_param('discussionid', 0, PARAM_INT);
        $flash = announcement_service::delete($USER, $discussionid);
        $SESSION->lp_admin_flash = $flash;
        redirect(new moodle_url(persona_home::ADMIN_PATH, ['view' => 'announcements']));
    }
    if ($view === 'cohorts' && $action === 'enrol_fellow') {
        $email = optional_param('email', '', PARAM_RAW_TRIMMED);
        $trackid = optional_param('trackid', 0, PARAM_INT);
        $alsoorient = (bool) optional_param('also_orient', 0, PARAM_BOOL);
        $flash = admin_dashboard::enrol_fellow_by_email($USER, $email, $trackid, $alsoorient);
    } else if ($view === 'mentors' && $action === 'assign_mentor') {
        $email = optional_param('email', '', PARAM_RAW_TRIMMED);
        $trackid = optional_param('trackid', 0, PARAM_INT);
        $flash = admin_dashboard::assign_mentor_by_email($USER, $email, $trackid);
    } else if (($view === 'builder' || $view === 'manage') && $action === 'mark_reviewed') {
        $trackid = optional_param('trackid', 0, PARAM_INT);
        $cmid = optional_param('cmid', 0, PARAM_INT);
        $flash = admin_dashboard::mark_activity_reviewed($USER, $trackid, $cmid);
    } else if ($view === 'builder' && $action === 'toggle_section') {
        $trackid = optional_param('trackid', 0, PARAM_INT);
        $sectionnum = optional_param('sectionnum', 0, PARAM_INT);
        $show = (bool) optional_param('show', 0, PARAM_BOOL);
        $flash = admin_dashboard::set_phase_visible($USER, $trackid, $sectionnum, $show);
    } else if ($view === 'manage' && $action === 'add_activity') {
        $trackid = optional_param('trackid', 0, PARAM_INT);
        $sectionnum = optional_param('sectionnum', 0, PARAM_INT);
        $mod = optional_param('mod', '', PARAM_ALPHA);
        $name = optional_param('name', '', PARAM_TEXT);
        $flash = admin_dashboard::create_phase_activity($USER, $trackid, $sectionnum, $mod, $name);
        // Phase 4: land in LaunchPad settings after create.
        if (!empty($flash['ok']) && !empty($flash['cmid'])) {
            $SESSION->lp_admin_flash = $flash;
            redirect(new moodle_url(persona_home::ADMIN_PATH, [
                'view' => 'activity',
                'trackid' => $trackid,
                'cmid' => (int) $flash['cmid'],
            ]));
        }
    } else if ($view === 'manage' && $action === 'toggle_cm') {
        $trackid = optional_param('trackid', 0, PARAM_INT);
        $cmid = optional_param('cmid', 0, PARAM_INT);
        $show = (bool) optional_param('show', 0, PARAM_BOOL);
        $flash = admin_dashboard::set_activity_visible($USER, $trackid, $cmid, $show);
    } else if ($view === 'manage' && $action === 'duplicate_cm') {
        $trackid = optional_param('trackid', 0, PARAM_INT);
        $cmid = optional_param('cmid', 0, PARAM_INT);
        $flash = admin_dashboard::duplicate_activity($USER, $trackid, $cmid);
    } else if ($view === 'manage' && $action === 'reorder_cm') {
        $trackid = optional_param('trackid', 0, PARAM_INT);
        $sectionnum = optional_param('section', $sectionnum, PARAM_INT);
        $cmorder = optional_param_array('cmorder', [], PARAM_INT);
        // Fallback if browsers/JS post a single csv field instead of cmorder[].
        if ($cmorder === []) {
            $csv = optional_param('cmordercsv', '', PARAM_SEQUENCE);
            if ($csv !== '') {
                $cmorder = array_map('intval', explode(',', $csv));
            }
        }
        $flash = admin_dashboard::reorder_section_activities($USER, $trackid, $sectionnum, $cmorder);
    } else if ($view === 'manage' && $action === 'rename_cm') {
        $trackid = optional_param('trackid', 0, PARAM_INT);
        $cmid = optional_param('cmid', 0, PARAM_INT);
        $name = optional_param('name', '', PARAM_TEXT);
        $flash = admin_dashboard::rename_activity($USER, $trackid, $cmid, $name);
    } else if (($view === 'manage' || $view === 'activity') && $action === 'delete_cm') {
        $trackid = optional_param('trackid', 0, PARAM_INT);
        $cmid = optional_param('cmid', 0, PARAM_INT);
        $sectionnum = optional_param('section', $sectionnum, PARAM_INT);
        $flash = admin_dashboard::delete_activity($USER, $trackid, $cmid);
        // After delete, always land on Manage for that phase (activity page no longer exists).
        if (!empty($flash['ok'])) {
            $SESSION->lp_admin_flash = $flash;
            redirect(new moodle_url(persona_home::ADMIN_PATH, array_filter([
                'view' => 'manage',
                'trackid' => $trackid > 0 ? $trackid : null,
                'section' => $sectionnum > 0 ? $sectionnum : null,
            ])));
        }
        $view = 'manage';
    } else if ($view === 'activity' && $action === 'save_activity') {
        $trackid = optional_param('trackid', 0, PARAM_INT);
        $cmid = optional_param('cmid', 0, PARAM_INT);
        $name = optional_param('name', '', PARAM_TEXT);
        $intro = optional_param('intro', '', PARAM_RAW);
        $content = optional_param('content', '', PARAM_RAW);
        $gradeparam = optional_param('grade', null, PARAM_RAW_TRIMMED);
        $grade = ($gradeparam === null || $gradeparam === '') ? null : (float) $gradeparam;
        $trackcompletion = (bool) optional_param('trackcompletion', 0, PARAM_BOOL);
        $uploadfiles = null;
        if (!empty($_FILES['resourcefiles'])) {
            $uploadfiles = $_FILES['resourcefiles'];
        }
        $removefiles = optional_param_array('removeresourcefiles', [], PARAM_FILE);
        $flash = admin_dashboard::save_activity_settings(
            $USER,
            $trackid,
            $cmid,
            $name,
            $intro,
            $content,
            $grade,
            $trackcompletion,
            $uploadfiles,
            $removefiles
        );
    }
}

$calmode = optional_param('cal', 'month', PARAM_ALPHA);
$calfilter = optional_param('filter', 'all', PARAM_ALPHA);
$caltime = optional_param('time', 0, PARAM_INT);
$calyear = optional_param('year', 0, PARAM_INT);
$calmonth = optional_param('month', 0, PARAM_INT);
$caltrack = optional_param('track', 0, PARAM_INT);
$calincludeorient = optional_param('include_orient', 0, PARAM_BOOL);
if ($view === 'calendar') {
    $caltrack = admin_dashboard::resolve_calendar_trackid($caltrack);
} else {
    $caltrack = 0;
    $calincludeorient = false;
}

$coursopssectionparam = null;
if ($view === 'courseops' && $courseopssection !== 'hub') {
    $coursopssectionparam = $courseopssection;
} else if ($view === 'manage' && $sectionnum > 0) {
    $coursopssectionparam = $sectionnum;
}

$urlparams = array_filter([
    'view' => $view !== 'home' ? $view : null,
    'tab' => ($view === 'people' && $peopletab !== 'all') ? $peopletab : null,
    'trackid' => (($view === 'builder' || $view === 'manage' || $view === 'activity' || $view === 'courseops')
        && $trackid > 0) ? $trackid : null,
    'section' => $coursopssectionparam,
    'cmid' => ($view === 'activity' && $cmid > 0) ? $cmid : null,
    'cal' => ($view === 'calendar' && $calmode !== 'month') ? $calmode : null,
    'filter' => ($view === 'calendar' && ($calfilter === 'course' || $calfilter === 'personal')) ? $calfilter : null,
    'time' => ($view === 'calendar' && $caltime > 0) ? $caltime : null,
    'track' => ($view === 'calendar' && $caltrack > 0) ? $caltrack : null,
    'include_orient' => ($view === 'calendar' && $calincludeorient) ? 1 : null,
]);

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url(persona_home::ADMIN_PATH, $urlparams));
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
$builderurl = (new moodle_url(persona_home::ADMIN_PATH, array_filter([
    'view' => 'builder',
    'trackid' => $trackid > 0 ? $trackid : null,
])))->out(false);
$cohortsurl = (new moodle_url(persona_home::ADMIN_PATH, ['view' => 'cohorts']))->out(false);
$peopleurl = (new moodle_url(persona_home::ADMIN_PATH, ['view' => 'people']))->out(false);
$mentorsurl = (new moodle_url(persona_home::ADMIN_PATH, ['view' => 'mentors']))->out(false);
$reviewsurl = (new moodle_url(persona_home::ADMIN_PATH, ['view' => 'reviews']))->out(false);
$calendarurl = (new moodle_url(persona_home::ADMIN_PATH, ['view' => 'calendar']))->out(false);
$announcementsurl = (new moodle_url(persona_home::ADMIN_PATH, ['view' => 'announcements']))->out(false);
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
                'current' => ($view === 'tracks' || $view === 'courseops'),
                'key' => 'tracks',
                'icon_svg' => $icon(
                    '<path d="M4 6h16M4 12h10M4 18h14"/>'
                ),
            ],
            [
                'label' => get_string('admin_nav_builder', 'local_launchpad'),
                'url' => $builderurl,
                'current' => ($view === 'builder' || $view === 'manage' || $view === 'activity'),
                'key' => 'builder',
                'icon_svg' => $icon(
                    '<path d="M14 5l5 5-9.5 9.5H5v-4.5L14 5z"/>'
                    . '<path d="M12 7l5 5"/>'
                ),
            ],
            [
                'label' => get_string('admin_nav_calendar', 'local_launchpad'),
                'url' => $calendarurl,
                'current' => $view === 'calendar',
                'key' => 'calendar',
                'icon_svg' => $icon(
                    '<rect x="3" y="5" width="18" height="16" rx="2"/>'
                    . '<path d="M3 10h18M8 3v4M16 3v4"/>'
                ),
            ],
            [
                'label' => get_string('admin_nav_announcements', 'local_launchpad'),
                'url' => $announcementsurl,
                'current' => $view === 'announcements',
                'key' => 'announcements',
                'icon_svg' => $icon(
                    '<path d="M4 5h12v10H4z"/>'
                    . '<path d="M16 9l4-2v8l-4-2"/>'
                    . '<path d="M7 19h6"/>'
                ),
            ],
            [
                'label' => get_string('admin_nav_cohorts', 'local_launchpad'),
                'url' => $cohortsurl,
                'current' => $view === 'cohorts',
                'key' => 'cohorts',
                'icon_svg' => $icon(
                    '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>'
                    . '<circle cx="9" cy="7" r="4"/>'
                    . '<path d="M23 21v-2a4 4 0 0 0-3-3.87"/>'
                    . '<path d="M16 3.13a4 4 0 0 1 0 7.75"/>'
                ),
            ],
        ],
    ],
    [
        'group' => get_string('admin_nav_people_group', 'local_launchpad'),
        'items' => [
            [
                'label' => get_string('admin_nav_people', 'local_launchpad'),
                'url' => $peopleurl,
                'current' => $view === 'people',
                'key' => 'people',
                'icon_svg' => $icon(
                    '<circle cx="9" cy="8" r="3.5"/>'
                    . '<path d="M2 20c1-4 4-6 7-6s6 2 7 6"/>'
                    . '<path d="M16 4.5a3.5 3.5 0 010 7M22 20c-.6-2.6-2-4.4-4-5.3"/>'
                ),
            ],
            [
                'label' => get_string('admin_nav_mentors', 'local_launchpad'),
                'url' => $mentorsurl,
                'current' => $view === 'mentors',
                'key' => 'mentors',
                'icon_svg' => $icon(
                    '<circle cx="12" cy="8" r="3.5"/>'
                    . '<path d="M4 20c1.2-4 4.2-6 8-6s6.8 2 8 6"/>'
                    . '<path d="M17 11l2 2 4-4"/>'
                ),
            ],
            [
                'label' => get_string('admin_nav_reviews', 'local_launchpad'),
                'url' => $reviewsurl,
                'current' => $view === 'reviews',
                'key' => 'reviews',
                'icon_svg' => $icon(
                    '<path d="M4 5h16v14H4z"/><path d="M8 9h8M8 13h5"/>'
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
} else if ($view === 'courseops') {
    $pagetitle = get_string('admin_courseops_heading', 'local_launchpad');
    $content = $OUTPUT->render_from_template(
        'local_launchpad/admin_courseops',
        admin_dashboard::courseops_context($USER, $trackid, $courseopssection, $flash)
    );
} else if ($view === 'builder') {
    $pagetitle = get_string('admin_nav_builder', 'local_launchpad');
    $content = $OUTPUT->render_from_template(
        'local_launchpad/admin_builder',
        admin_dashboard::builder_context($USER, $trackid, $flash)
    );
} else if ($view === 'manage') {
    $pagetitle = get_string('admin_manage_heading', 'local_launchpad');
    // Keep Course builder nav highlighted while managing a phase.
    foreach ($nav as &$navgroup) {
        foreach ($navgroup['items'] as &$navitem) {
            if (($navitem['key'] ?? '') === 'builder') {
                $navitem['current'] = true;
            }
        }
        unset($navitem);
    }
    unset($navgroup);
    $content = $OUTPUT->render_from_template(
        'local_launchpad/admin_manage',
        admin_dashboard::manage_context($USER, $trackid, $sectionnum, $flash)
    );
} else if ($view === 'activity') {
    $pagetitle = get_string('admin_activity_eyebrow', 'local_launchpad');
    foreach ($nav as &$navgroup) {
        foreach ($navgroup['items'] as &$navitem) {
            if (($navitem['key'] ?? '') === 'builder') {
                $navitem['current'] = true;
            }
        }
        unset($navitem);
    }
    unset($navgroup);
    $content = $OUTPUT->render_from_template(
        'local_launchpad/admin_activity',
        admin_dashboard::activity_settings_context($USER, $trackid, $cmid, $flash)
    );
} else if ($view === 'calendar') {
    $pagetitle = get_string('admin_nav_calendar', 'local_launchpad');
    $resolved = calendar_service::resolve_month(
        $caltime > 0 ? $caltime : null,
        $calyear > 0 ? $calyear : null,
        $calmonth > 0 ? $calmonth : null
    );
    $alltracks = [];
    foreach (admin_dashboard::list_track_courses() as $trackcourse) {
        $alltracks[] = [
            'id' => (int) $trackcourse->id,
            'name' => format_string($trackcourse->fullname),
        ];
    }
    $caldata = calendar_service::admin_context(
        $userid,
        admin_dashboard::calendar_courses($caltrack, (bool) $calincludeorient),
        (int) $resolved['year'],
        (int) $resolved['month'],
        $calmode,
        $calfilter,
        $caltime > 0 ? $caltime : null,
        [
            'trackid' => $caltrack,
            'include_orient' => (bool) $calincludeorient,
            'preferred_courseid' => $caltrack,
            'show_track_filter' => true,
            'all_tracks' => $alltracks,
        ]
    );
    $content = $OUTPUT->render_from_template('local_launchpad/fellowship_calendar', $caldata);
} else if ($view === 'announcements') {
    $pagetitle = get_string('admin_nav_announcements', 'local_launchpad');
    $content = $OUTPUT->render_from_template(
        'local_launchpad/admin_announcements',
        announcement_service::admin_context($USER, $flash)
    );
} else if ($view === 'cohorts') {
    $pagetitle = get_string('admin_nav_cohorts', 'local_launchpad');
    $content = $OUTPUT->render_from_template(
        'local_launchpad/admin_cohorts',
        admin_dashboard::cohorts_context($USER, $flash, $trackid)
    );
} else if ($view === 'people') {
    $pagetitle = get_string('admin_nav_people', 'local_launchpad');
    $content = $OUTPUT->render_from_template(
        'local_launchpad/admin_people',
        admin_dashboard::people_context($USER, $peopletab)
    );
} else if ($view === 'mentors') {
    $pagetitle = get_string('admin_nav_mentors', 'local_launchpad');
    $content = $OUTPUT->render_from_template(
        'local_launchpad/admin_mentors',
        admin_dashboard::mentors_context($USER, $flash, $trackid)
    );
} else if ($view === 'reviews') {
    $pagetitle = get_string('admin_nav_reviews', 'local_launchpad');
    $content = $OUTPUT->render_from_template(
        'local_launchpad/admin_reviews',
        admin_dashboard::reviews_context($USER, $flash)
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
    'logo_url' => $pluginbase . '/assets/img/logo-on-dark.png',
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
echo '<script src="https://code.iconify.design/iconify-icon/2.1.0/iconify-icon.min.js"></script>' . "\n";
echo '<script src="' . s($jsurl) . '" defer></script>' . "\n";
echo '</body>' . "\n";
echo '</html>';
