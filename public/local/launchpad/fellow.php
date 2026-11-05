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
 * Fellowship dashboard (assigned lp-* courses; native Moodle activities).
 *
 * @package    local_launchpad
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

// New classes from main — load explicitly so a stale Moodle classmap cannot 404 them.
require_once(__DIR__ . '/classes/portal_gate.php');
require_once(__DIR__ . '/classes/messaging.php');
require_once(__DIR__ . '/classes/calendar_service.php');

use local_launchpad\activity_portal;
use local_launchpad\announcement_service;
use local_launchpad\employer_dashboard;
use local_launchpad\employer_tools;
use local_launchpad\fellowship_dashboard;
use local_launchpad\login_portal;
use local_launchpad\messaging;
use local_launchpad\persona_home;
use local_launchpad\portal_gate;

/**
 * Resolve a Moodle lang key for Fellowship UI (never show [[missing]] markers).
 *
 * @param string $key
 * @param mixed $a
 */
$lpstr = static function (string $key, $a = null, string $fallback = ''): string {
    return fellowship_dashboard::str($key, $a, $fallback);
};

require_login();

$userid = (int) $USER->id;
// Mentors/employees/admins must not land here — wrong portal → their home.
portal_gate::assert_or_redirect_home(
    fellowship_dashboard::can_access($userid),
    login_portal::FELLOWSHIP
);

$view = optional_param('view', 'home', PARAM_ALPHA);
// On messages, id is the other user — never a course (avoids fellowship_course_denied).
$messageuserid = 0;
$messageconvid = 0;
$messagetab = 'all';
$courseid = 0;
$cmid = optional_param('cmid', 0, PARAM_INT);
$ptab = optional_param('ptab', 'overview', PARAM_ALPHA);
if (!in_array($ptab, ['overview', 'edit', 'preferences'], true)) {
    $ptab = 'overview';
}
$profilesaved = optional_param('profilesaved', 0, PARAM_INT);
$profileerr = optional_param('profileerr', '', PARAM_ALPHAEXT);
$prefsaved = optional_param('prefsaved', 0, PARAM_INT);
$preferr = optional_param('preferr', '', PARAM_ALPHAEXT);
$pref = optional_param('pref', '', PARAM_ALPHAEXT);
$allowedprefs = [
    'password', 'language', 'editor', 'forum', 'calendar', 'messages', 'notifications',
    'edit', 'public', 'files', 'contentbank', 'tokens',
];
if ($pref !== '' && !in_array($pref, $allowedprefs, true)) {
    $pref = '';
}
$tab = optional_param('tab', 'all', PARAM_ALPHA);
if ($tab !== 'earned') {
    $tab = 'all';
}
if ($view !== 'profile') {
    $ptab = 'overview';
    $pref = '';
}
if ($ptab !== 'preferences') {
    $pref = '';
}

// Save profile / preference from Fellowship shell (form posts here without view= in action URL).
if (data_submitted() && confirm_sesskey()) {
    $postaction = optional_param('action', '', PARAM_ALPHAEXT);
    if ($postaction === 'update_profile') {
        $err = employer_tools::save_profile($USER, employer_tools::profile_post_payload());
        if ($err !== '') {
            redirect(new moodle_url(persona_home::FELLOW_PATH, [
                'view' => 'profile',
                'ptab' => 'edit',
                'profileerr' => $err,
            ]));
        }
        redirect(new moodle_url(persona_home::FELLOW_PATH, [
            'view' => 'profile',
            'ptab' => 'overview',
            'profilesaved' => 1,
        ]));
    }
    if ($postaction === 'update_preference') {
        $prefkey = optional_param('pref', '', PARAM_ALPHAEXT);
        $err = employer_tools::save_preference($USER, $prefkey, employer_tools::preference_post_payload($prefkey));
        if ($err !== '') {
            redirect(new moodle_url(persona_home::FELLOW_PATH, [
                'view' => 'profile',
                'ptab' => 'preferences',
                'pref' => $prefkey !== '' ? $prefkey : null,
                'preferr' => $err,
            ]));
        }
        redirect(new moodle_url(persona_home::FELLOW_PATH, [
            'view' => 'profile',
            'ptab' => 'preferences',
            'pref' => $prefkey !== '' ? $prefkey : null,
            'prefsaved' => 1,
        ]));
    }
}

if ($view === 'messages') {
    $messageuserid = optional_param('id', 0, PARAM_INT);
    $messageconvid = optional_param('convid', 0, PARAM_INT);
    $messagetab = optional_param('mtab', 'all', PARAM_ALPHA);
    if (!in_array($messagetab, ['all', 'starred', 'private', 'group'], true)) {
        $messagetab = 'all';
    }
    if ($messageconvid < 0) {
        $messageconvid = 0;
    }
} else if ($view === 'activity' && $cmid > 0) {
    // Resolve course from the CM so track nav / access checks stay coherent.
    $cmforcourse = get_coursemodule_from_id('', $cmid, 0, false, IGNORE_MISSING);
    if ($cmforcourse) {
        $courseid = (int) $cmforcourse->course;
    }
} else {
    $courseid = optional_param('id', 0, PARAM_INT);
}

$courses = fellowship_dashboard::list_courses($userid);
$courseids = array_map(static fn($c) => (int) $c->id, $courses);

// Send / delete LaunchPad messages (Moodle-backed) before rendering.
if ($view === 'messages' && data_submitted() && confirm_sesskey()) {
    $toid = required_param('id', PARAM_INT);
    $msgaction = optional_param('msgaction', 'send', PARAM_ALPHA);
    $posttab = optional_param('mtab', 'all', PARAM_ALPHA);
    if (!in_array($posttab, ['all', 'starred', 'private', 'group'], true)) {
        $posttab = 'all';
    }
    $redirect = new moodle_url(persona_home::FELLOW_PATH, array_filter([
        'view' => 'messages',
        'id' => $toid,
        'mtab' => $posttab !== 'all' ? $posttab : null,
    ]));
    if ($msgaction === 'delete') {
        $messageids = optional_param_array('messageids', [], PARAM_INT);
        $result = messaging::delete_messages($userid, $messageids);
    } else {
        $body = required_param('message', PARAM_RAW_TRIMMED);
        $result = messaging::send($userid, $toid, $body, 'fellow');
    }
    if (!$result['ok']) {
        redirect($redirect, $result['error'], null, \core\output\notification::NOTIFY_ERROR);
    }
    redirect($redirect);
}

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url(persona_home::FELLOW_PATH, array_filter([
    'view' => $view !== 'home' ? $view : null,
    'id' => $view === 'messages'
        ? ($messageuserid > 0 ? $messageuserid : null)
        : (($view !== 'activity' && $courseid > 0) ? $courseid : null),
    'cmid' => ($view === 'activity' && $cmid > 0) ? $cmid : null,
    'convid' => ($view === 'messages' && $messageuserid <= 0 && $messageconvid > 0) ? $messageconvid : null,
    'mtab' => ($view === 'messages' && $messagetab !== 'all') ? $messagetab : null,
    'tab' => ($view === 'achievements' && $tab !== 'all') ? $tab : null,
    'ptab' => ($view === 'profile' && $ptab !== 'overview') ? $ptab : null,
    'pref' => ($view === 'profile' && $ptab === 'preferences' && $pref !== '') ? $pref : null,
])));
$PAGE->set_pagelayout('embedded');
$PAGE->set_title($lpstr('fellowship_area_title', null, 'Fellowship · ToBa Learn'));
$PAGE->set_heading($lpstr('fellowship_area_heading', null, 'Fellowship'));

$pluginbase = $CFG->wwwroot . '/local/launchpad';
// Cache-bust CSS/JS from this plugin's version.php (same pattern as employee.php).
$plugin = new \stdClass();
require(__DIR__ . '/version.php');
$assetv = (string) ($plugin->version ?? time());
$cssurl = $pluginbase . '/styles_fellowship.css?v=' . rawurlencode($assetv);
$jsurl = $pluginbase . '/fellowship.js?v=' . rawurlencode($assetv);
$logouturl = (new moodle_url('/login/logout.php', ['sesskey' => sesskey()]))->out(false);
$profileurl = (new moodle_url(persona_home::FELLOW_PATH, ['view' => 'profile']))->out(false);
$preferencesurl = (new moodle_url(persona_home::FELLOW_PATH, [
    'view' => 'profile',
    'ptab' => 'preferences',
]))->out(false);
$homeurl = (new moodle_url(persona_home::FELLOW_PATH))->out(false);
$progressurl = (new moodle_url(persona_home::FELLOW_PATH, ['view' => 'progress']))->out(false);
$notificationsurl = messaging::moodle_notifications_url();
$messagesurl = messaging::messages_url(null, 'fellow');
$msgunread = messaging::conversation_unread_count($userid);
// Opening Announcements clears the news badge (Messages use Moodle read state; Feedback stays until revise).
if ($view === 'news') {
    announcement_service::mark_seen($userid);
}
$newsunread = announcement_service::unread_count_for_courses($userid, $courses);

$firstname = format_string($USER->firstname);
$displayname = fullname($USER);

// Hugeicons (Iconify) — same stroke-rounded set as Employer / LaunchPad landing.
$icon = static function (string $name, int $size = 18): string {
    $safe = preg_replace('/[^a-z0-9-]/', '', $name) ?? 'circle';
    return '<iconify-icon icon="hugeicons:' . $safe . '" width="' . $size . '" height="' . $size
        . '" aria-hidden="true"></iconify-icon>';
};

$nav = [
    [
        'group' => $lpstr('fellowship_nav_learn', null, 'Learn'),
        'items' => [
            [
                'label' => $lpstr('fellowship_nav_home', null, 'Home'),
                'url' => $homeurl,
                'current' => $view === 'home',
                'key' => 'home',
                'icon_svg' => $icon('home-01'),
            ],
            [
                'label' => $lpstr('fellowship_nav_track', null, 'My track'),
                'url' => (new moodle_url(persona_home::FELLOW_PATH, ['view' => 'track']))->out(false),
                'current' => ($view === 'track' || $view === 'activity'),
                'key' => 'track',
                'icon_svg' => $icon('layers-01'),
            ],
            [
                'label' => $lpstr('fellowship_nav_achievements', null, 'Achievements'),
                'url' => (new moodle_url(persona_home::FELLOW_PATH, ['view' => 'achievements']))->out(false),
                'current' => $view === 'achievements',
                'key' => 'achievements',
                'icon_svg' => $icon('award-01'),
            ],
        ],
    ],
    [
        'group' => $lpstr('fellowship_nav_record', null, 'My record'),
        'items' => [
            [
                'label' => $lpstr('fellowship_nav_feedback', null, 'Feedback'),
                'url' => (new moodle_url(persona_home::FELLOW_PATH, ['view' => 'feedback']))->out(false),
                'current' => $view === 'feedback',
                'key' => 'feedback',
                'icon_svg' => $icon('message-01'),
            ],
            [
                'label' => $lpstr('fellowship_nav_progress', null, 'Progress'),
                'url' => $progressurl,
                'current' => $view === 'progress',
                'key' => 'progress',
                'icon_svg' => $icon('chart-histogram'),
            ],
        ],
    ],
    [
        'group' => $lpstr('fellowship_nav_you', null, 'You'),
        'items' => [
            [
                'label' => $lpstr('fellowship_nav_news', null, 'Announcements'),
                'url' => (new moodle_url(persona_home::FELLOW_PATH, ['view' => 'news']))->out(false),
                'current' => $view === 'news',
                'key' => 'news',
                'icon_svg' => $icon('news'),
                'has_badge' => $newsunread > 0,
                'badge' => $newsunread > 0 ? (string) $newsunread : '',
            ],
            [
                'label' => $lpstr('fellowship_nav_calendar', null, 'Calendar'),
                'url' => (new moodle_url(persona_home::FELLOW_PATH, ['view' => 'calendar']))->out(false),
                'current' => $view === 'calendar',
                'key' => 'calendar',
                'icon_svg' => $icon('calendar-03'),
            ],
            [
                'label' => $lpstr('fellowship_nav_portfolio', null, 'Portfolio'),
                'url' => (new moodle_url(persona_home::FELLOW_PATH, ['view' => 'portfolio']))->out(false),
                'current' => $view === 'portfolio',
                'key' => 'portfolio',
                'icon_svg' => $icon('briefcase-01'),
            ],
            [
                'label' => $lpstr('fellowship_nav_messages', null, 'Messages'),
                'url' => $messagesurl,
                'current' => $view === 'messages',
                'key' => 'messages',
                'icon_svg' => $icon('message-01'),
                'has_badge' => $msgunread > 0,
                'badge' => $msgunread > 0 ? (string) $msgunread : '',
            ],
            [
                'label' => $lpstr('fellowship_nav_profile', null, 'Profile'),
                'url' => $profileurl,
                'current' => $view === 'profile',
                'key' => 'profile',
                'icon_svg' => $icon('user-circle'),
            ],
            [
                'label' => $lpstr('fellowship_nav_help', null, 'Help & FAQ'),
                'url' => (new moodle_url(persona_home::FELLOW_PATH, ['view' => 'help']))->out(false),
                'current' => $view === 'help',
                'key' => 'help',
                'icon_svg' => $icon('help-circle'),
            ],
        ],
    ],
];
$achievementsurl = (new moodle_url(persona_home::FELLOW_PATH, ['view' => 'achievements']))->out(false);

$crumbhome = [
    'label' => $lpstr('fellowship_nav_home', null, 'Home'),
    'url' => $homeurl,
];

$selected = null;
if ($courseid > 0) {
    if (!in_array($courseid, $courseids, true) && !is_siteadmin()) {
        throw new \moodle_exception('fellowship_course_denied', 'local_launchpad');
    }
    foreach ($courses as $c) {
        if ((int) $c->id === $courseid) {
            $selected = $c;
            break;
        }
    }
    if ($selected === null && is_siteadmin()) {
        $selected = get_course($courseid);
    }
} else if ($courses !== []) {
    // Prefer a track course over Shared orientation when no ?id= is given.
    $selected = fellowship_dashboard::prefer_course($courses);
    $courseid = $selected ? (int) $selected->id : 0;
}

$outline = null;
$coursecards = [];
foreach ($courses as $c) {
    $curl = new moodle_url(persona_home::FELLOW_PATH, ['view' => 'track', 'id' => $c->id]);
    $moodlecourseurl = new moodle_url('/course/view.php', ['id' => $c->id]);
    $card = [
        'id' => (int) $c->id,
        'fullname' => fellowship_dashboard::display_label(format_string($c->fullname)),
        'idnumber' => s($c->idnumber),
        'url' => $curl->out(false),
        'moodle_url' => $moodlecourseurl->out(false),
        'selected' => $selected && (int) $c->id === (int) $selected->id,
    ];
    if ($selected && (int) $c->id === (int) $selected->id) {
        $outline = fellowship_dashboard::course_outline($c, $userid);
        $card['percent'] = $outline['percent'];
        $card['completed'] = $outline['completed'];
        $card['trackable'] = $outline['trackable'];
        $card['completion_enabled'] = $outline['completion_enabled'];
    }
    $coursecards[] = $card;
}

if ($selected && $outline === null) {
    $outline = fellowship_dashboard::course_outline($selected, $userid);
}

$content = '';
$pagetitle = $lpstr('fellowship_area_heading', null, 'Fellowship');
$nocourses = $lpstr(
    'fellowship_no_courses',
    null,
    'You do not have a LaunchPad course yet. When your place is confirmed, your programme admin will enrol you and your track will appear here.'
);

switch ($view) {
    case 'activity':
        $pagetitle = get_string('fellowship_activity_eyebrow', 'local_launchpad');
        if ($cmid <= 0) {
            redirect(new moodle_url(persona_home::FELLOW_PATH, ['view' => 'track']));
        }
        $trackurl = (new moodle_url(persona_home::FELLOW_PATH, array_filter([
            'view' => 'track',
            'id' => $courseid > 0 ? $courseid : null,
        ])))->out(false);
        $activityctx = activity_portal::export($USER, $cmid, ['courseurl' => $trackurl]);
        $act = $activityctx['activity'] ?? [];
        $pagetitle = (string) ($activityctx['pagetitle'] ?? $pagetitle);
        $modtype = (string) ($act['modtype'] ?? '');
        $showjudging = in_array($modtype, ['assign', 'quiz'], true);
        $content = $OUTPUT->render_from_template('local_launchpad/fellowship_activity', [
            'activity' => array_merge($act, [
                'show_judging' => $showjudging,
                'judging_title' => get_string('fellowship_judging_title', 'local_launchpad'),
                'judging_body' => $modtype === 'assign'
                    ? get_string('fellowship_judging_body', 'local_launchpad')
                    : get_string('fellowship_judging_short', 'local_launchpad'),
            ]),
            'home_url' => $homeurl,
            'strings' => [
                'navhome' => get_string('fellowship_nav_home', 'local_launchpad'),
                'navtrack' => get_string('fellowship_nav_track', 'local_launchpad'),
                'backtotrack' => get_string('fellowship_activity_back', 'local_launchpad'),
                'openactivity' => get_string('fellowship_activity_open', 'local_launchpad'),
                'openlink' => get_string('fellowship_activity_open_link', 'local_launchpad'),
                'activityempty' => get_string('fellowship_activity_empty', 'local_launchpad'),
                'contentcomingsoon' => get_string('fellowship_activity_coming_soon', 'local_launchpad'),
                'activityenginehelp' => get_string('fellowship_activity_engine_help', 'local_launchpad'),
                'lessonobjective' => get_string('fellowship_lesson_objective', 'local_launchpad'),
                'lessonexplain' => get_string('fellowship_lesson_explain', 'local_launchpad'),
                'lessonexample' => get_string('fellowship_lesson_example', 'local_launchpad'),
                'lessonexercise' => get_string('fellowship_lesson_exercise', 'local_launchpad'),
                'lessonmissing' => get_string('fellowship_lesson_missing', 'local_launchpad'),
                'lessonnext' => get_string('fellowship_lesson_next', 'local_launchpad'),
                'lessonnextlabel' => get_string('fellowship_lesson_next_label', 'local_launchpad'),
                'assignbrief' => get_string('fellowship_assign_brief', 'local_launchpad'),
                'assignbriefmissing' => get_string('fellowship_assign_brief_missing', 'local_launchpad'),
                'assigndeliverable' => get_string('fellowship_assign_deliverable', 'local_launchpad'),
                'assigncriteria' => get_string('fellowship_assign_criteria', 'local_launchpad'),
                'assignformats' => get_string('fellowship_assign_formats', 'local_launchpad'),
                'assigndeadline' => get_string('fellowship_assign_deadline', 'local_launchpad'),
                'assignopens' => get_string('fellowship_assign_opens', 'local_launchpad'),
                'assigngrade' => get_string('fellowship_assign_grade', 'local_launchpad'),
                'assignpassbar' => get_string('fellowship_assign_pass_bar', 'local_launchpad'),
                'assignstatus' => get_string('fellowship_assign_status', 'local_launchpad'),
                'assigncontinue' => get_string('fellowship_assign_continue', 'local_launchpad'),
                'assignenginehelp' => get_string('fellowship_assign_engine_help', 'local_launchpad'),
                'quizbrief' => get_string('fellowship_quiz_brief', 'local_launchpad'),
                'quizbriefmissing' => get_string('fellowship_quiz_brief_missing', 'local_launchpad'),
                'quizpractice' => get_string('fellowship_quiz_practice', 'local_launchpad'),
                'quizquestions' => get_string('fellowship_quiz_questions', 'local_launchpad'),
                'quizattempts' => get_string('fellowship_quiz_attempts', 'local_launchpad'),
                'quiztimelimit' => get_string('fellowship_quiz_timelimit', 'local_launchpad'),
                'quizopens' => get_string('fellowship_quiz_opens', 'local_launchpad'),
                'quizcloses' => get_string('fellowship_quiz_closes', 'local_launchpad'),
                'quizgrade' => get_string('fellowship_quiz_grade', 'local_launchpad'),
                'quizbest' => get_string('fellowship_quiz_best', 'local_launchpad'),
                'quizstatus' => get_string('fellowship_quiz_status', 'local_launchpad'),
                'quizcontinue' => get_string('fellowship_quiz_continue', 'local_launchpad'),
                'quizenginehelp' => get_string('fellowship_quiz_engine_help', 'local_launchpad'),
            ],
        ]);
        break;

    case 'track':
        $pagetitle = $lpstr('fellowship_nav_track', null, 'My track');
        if ($selected === null) {
            $content = $OUTPUT->render_from_template('local_launchpad/fellowship_empty_courses', [
                'message' => $nocourses,
            ]);
        } else {
            $trackdata = fellowship_dashboard::track_for_user($userid, $selected);
            $content = $OUTPUT->render_from_template('local_launchpad/fellowship_track', array_merge($trackdata, [
                'moodle_course_url' => (new moodle_url('/course/view.php', ['id' => $selected->id]))->out(false),
                'bot_url' => $pluginbase . '/assets/img/bot.webp',
                'courses' => $coursecards,
                'multi_course' => count($coursecards) > 1,
                'has_crumbs' => true,
                'crumbs' => [
                    $crumbhome,
                    [
                        'label' => $lpstr('fellowship_nav_track', null, 'My track'),
                        'url' => '',
                    ],
                ],
            ]));
        }
        break;

    case 'progress':
        $pagetitle = $lpstr('fellowship_nav_progress', null, 'Progress');
        $progressdata = fellowship_dashboard::completion_for_user($userid, $courses, $selected);
        $progressswitch = [];
        $progressselectedid = $selected !== null ? (int) $selected->id : 0;
        foreach ($coursecards as $card) {
            $cid = (int) $card['id'];
            $progressswitch[] = [
                'fullname' => $card['fullname'],
                'url' => (new moodle_url(persona_home::FELLOW_PATH, [
                    'view' => 'progress',
                    'id' => $cid,
                ]))->out(false),
                'selected' => $progressselectedid > 0 && $cid === $progressselectedid,
            ];
        }
        $content = $OUTPUT->render_from_template('local_launchpad/fellowship_progress', array_merge($progressdata, [
            'bot_url' => $pluginbase . '/assets/img/bot.webp',
            'prof_url' => $pluginbase . '/assets/img/prof.webp',
            'multi_course' => count($progressswitch) > 1,
            'course_switch' => $progressswitch,
        ]));
        break;

    case 'news':
        $pagetitle = $lpstr('fellowship_nav_news', null, 'Announcements');
        $newsdata = fellowship_dashboard::list_announcements($userid, $courses);
        $content = $OUTPUT->render_from_template('local_launchpad/fellowship_news', $newsdata);
        break;

    case 'messages':
        $pagetitle = messaging::messages_label(0);
        $otherid = $messageuserid > 0 ? $messageuserid : null;
        $msgdata = messaging::page_context($userid, 'fellow', $otherid, $messagetab, $messageconvid);
        if (!empty($msgdata['has_thread']) && !empty($msgdata['other_name'])) {
            $pagetitle = (string) $msgdata['other_name'];
        }
        $content = $OUTPUT->render_from_template('local_launchpad/fellowship_messages', $msgdata);
        break;

    case 'calendar':
        $pagetitle = $lpstr('fellowship_nav_calendar', null, 'Calendar');
        $calmode = optional_param('cal', 'month', PARAM_ALPHA);
        $calfilter = optional_param('filter', 'all', PARAM_ALPHA);
        $caltime = optional_param('time', 0, PARAM_INT);
        $calyear = optional_param('year', 0, PARAM_INT);
        $calmonth = optional_param('month', 0, PARAM_INT);
        $resolved = \local_launchpad\calendar_service::resolve_month(
            $caltime > 0 ? $caltime : null,
            $calyear > 0 ? $calyear : null,
            $calmonth > 0 ? $calmonth : null
        );
        $caldata = \local_launchpad\calendar_service::fellow_context(
            $userid,
            $courses,
            (int) $resolved['year'],
            (int) $resolved['month'],
            $calmode,
            $calfilter,
            $caltime > 0 ? $caltime : null
        );
        $content = $OUTPUT->render_from_template('local_launchpad/fellowship_calendar', $caldata);
        break;

    case 'portfolio':
        $pagetitle = $lpstr('fellowship_nav_portfolio', null, 'Portfolio');
        // Same multi-course scope as Home / Feedback / Progress: selected track only.
        $portfolioscope = $selected ? [$selected] : $courses;
        $portfoliocourseid = $selected ? (int) $selected->id : ($courseid > 0 ? $courseid : null);
        $portdata = fellowship_dashboard::list_portfolio_evidence(
            $userid,
            $portfolioscope,
            $portfoliocourseid
        );
        $portfolioswitch = [];
        $portfolioselectedid = $selected !== null ? (int) $selected->id : 0;
        foreach ($coursecards as $card) {
            $cid = (int) $card['id'];
            $portfolioswitch[] = [
                'fullname' => $card['fullname'],
                'url' => (new moodle_url(persona_home::FELLOW_PATH, [
                    'view' => 'portfolio',
                    'id' => $cid,
                ]))->out(false),
                'selected' => $portfolioselectedid > 0 && $cid === $portfolioselectedid,
            ];
        }
        if (empty($portdata['selected_course_name']) && $selected) {
            $portdata['selected_course_name'] = fellowship_dashboard::display_label(format_string($selected->fullname));
        }
        $content = $OUTPUT->render_from_template('local_launchpad/fellowship_portfolio', array_merge($portdata, [
            'multi_course' => count($portfolioswitch) > 1,
            'course_switch' => $portfolioswitch,
            'track_url' => (new moodle_url(persona_home::FELLOW_PATH, array_filter([
                'view' => 'track',
                'id' => $portfoliocourseid,
            ])))->out(false),
        ]));
        break;

    case 'achievements':
        $pagetitle = $lpstr('fellowship_nav_achievements', null, 'Achievements');
        // Content filled after live_achievements() so we compute Moodle XP/badges once.
        $content = '';
        break;

    case 'feedback':
        $pagetitle = $lpstr('fellowship_nav_feedback', null, 'Feedback');
        // Content filled after list_assignment_feedback() (shared with nav badge).
        $content = '';
        break;

    case 'phases':
    case 'completion':
        // Legacy URLs — Progress combines phase gates + completion record.
        redirect(new moodle_url(persona_home::FELLOW_PATH, ['view' => 'progress']));
        break;

    case 'profile':
        $pagetitle = $lpstr('fellowship_nav_profile', null, 'Profile');
        $profilectx = employer_tools::profile_page(
            $USER,
            [
                'moodle_profile' => (new moodle_url('/user/profile.php', ['id' => $userid]))->out(false),
                'moodle_profile_edit' => (new moodle_url('/user/edit.php', [
                    'id' => $userid,
                    'returnto' => 'profile',
                ]))->out(false),
                'preferences' => (new moodle_url('/user/preferences.php'))->out(false),
            ],
            $ptab,
            $profilesaved > 0,
            $profileerr,
            persona_home::FELLOW_PATH,
            persona_home::FELLOW_PATH,
            $pref,
            $prefsaved > 0,
            $preferr
        );
        $content = $OUTPUT->render_from_template('local_launchpad/employer_profile', array_merge(
            $profilectx,
            ['strings' => employer_tools::profile_strings()]
        ));
        break;

    case 'help':
        $pagetitle = $lpstr('fellowship_nav_help', null, 'Help & FAQ');
        $fellowview = static function (string $v) use ($courseid): string {
            $params = ['view' => $v];
            if ($courseid > 0) {
                $params['id'] = $courseid;
            }
            return (new moodle_url(persona_home::FELLOW_PATH, $params))->out(false);
        };
        $content = $OUTPUT->render_from_template('local_launchpad/fellowship_help', [
            'links' => [
                [
                    'label' => $lpstr('fellowship_nav_track', null, 'My track'),
                    'hint' => $lpstr(
                        'fellowship_help_link_track_hint',
                        null,
                        'Assigned course outline and activities'
                    ),
                    'url' => $fellowview('track'),
                    'icon' => 'book-open-01',
                ],
                [
                    'label' => $lpstr('fellowship_nav_progress', null, 'Progress'),
                    'hint' => $lpstr(
                        'fellowship_help_link_progress_hint',
                        null,
                        'Phase gates, orientation, weighted result, and certificate'
                    ),
                    'url' => $fellowview('progress'),
                    'icon' => 'flag-02',
                ],
                [
                    'label' => $lpstr('fellowship_nav_feedback', null, 'Feedback'),
                    'hint' => $lpstr(
                        'fellowship_help_link_feedback_hint',
                        null,
                        'Released mentor comments and scores'
                    ),
                    'url' => $fellowview('feedback'),
                    'icon' => 'comment-01',
                ],
                [
                    'label' => $lpstr('fellowship_nav_messages', null, 'Messages'),
                    'hint' => $lpstr(
                        'fellowship_help_link_messages_hint',
                        null,
                        'Private threads synced with Moodle'
                    ),
                    'url' => $messagesurl,
                    'icon' => 'message-01',
                ],
            ],
            'faqs' => [
                [
                    'q' => $lpstr('fellowship_faq_internship_q', null, 'Is this a paid internship?'),
                    'a' => $lpstr(
                        'fellowship_faq_internship_a',
                        null,
                        'No. LaunchPad is structured learning for assigned fellows. Jobs, stipends, placements, and income are never promised.'
                    ),
                ],
                [
                    'q' => $lpstr('fellowship_faq_track_q', null, 'Why do I only see certain courses?'),
                    'a' => $lpstr(
                        'fellowship_faq_track_a',
                        null,
                        'There is no open marketplace. Your programme admin enrols you on LaunchPad courses (including Shared orientation when assigned). Other tracks stay unavailable until you are enrolled.'
                    ),
                ],
                [
                    'q' => $lpstr('fellowship_faq_phases_q', null, 'How do phases pass?'),
                    'a' => $lpstr(
                        'fellowship_faq_phases_a',
                        null,
                        'Each phase passes only after a released Moodle assign review at 70/100 or above. A missing or unreviewed grade stays pending. A high mark later cannot make up for a phase that has not passed.'
                    ),
                ],
                [
                    'q' => $lpstr('fellowship_faq_grade_q', null, 'Where do grades and feedback live?'),
                    'a' => $lpstr(
                        'fellowship_faq_grade_a',
                        null,
                        'In Moodle Assign activities and the gradebook. Feedback and Progress read the same released grades. This dashboard does not invent grades.'
                    ),
                ],
                [
                    'q' => $lpstr('fellowship_faq_quiz_q', null, 'Can I take quizzes here?'),
                    'a' => $lpstr(
                        'fellowship_faq_quiz_a',
                        null,
                        'Open the quiz activity link to use Moodle’s quiz. Attempts and scoring stay in Moodle — LaunchPad only links you there.'
                    ),
                ],
                [
                    'q' => $lpstr('fellowship_faq_cert_q', null, 'When is the certificate issued?'),
                    'a' => $lpstr(
                        'fellowship_faq_cert_a',
                        null,
                        'Only when published criteria are met (phase gates at 70+, Shared orientation when required) and your programme confirms issuance. Until then Progress keeps the certificate locked. A certificate does not imply a job, internship, or placement.'
                    ),
                ],
                [
                    'q' => $lpstr('fellowship_faq_messages_q', null, 'How do messages work?'),
                    'a' => $lpstr(
                        'fellowship_faq_messages_a',
                        null,
                        'Read and reply in Messages. Conversations sync with Moodle messaging — the same threads mentors and staff see there.'
                    ),
                ],
                [
                    'q' => $lpstr('fellowship_faq_xp_q', null, 'What are XP and badges?'),
                    'a' => $lpstr(
                        'fellowship_faq_xp_a',
                        null,
                        'XP and badges come from your Moodle activity record (lessons, quizzes, submissions, site badges). They do not change grades or unlock phases.'
                    ),
                ],
            ],
        ]);
        break;

    case 'home':
    default:
        $view = 'home';
        $pagetitle = $lpstr('fellowship_nav_home', null, 'Home');
        // Content filled after live_achievements() (hero ring + recent badges).
        $content = '';
        break;
}

// Live XP + badges once per request (sidebar, topbar, Achievements, Home).
$live = fellowship_dashboard::live_achievements($userid, $courses);
$ach = fellowship_dashboard::achievements_context_from_live($live, $view === 'achievements' ? $tab : 'all');
$xptotal = (int) $ach['xp_total'];
$badgesearned = (int) $ach['badges_earned'];
$badgestotal = (int) $ach['badges_total'];

if ($view === 'home') {
    $homeoutline = $outline;
    if ($homeoutline === null && $courses !== []) {
        $homecourse = $selected ?? $courses[0];
        $homeoutline = fellowship_dashboard::course_outline($homecourse, $userid);
        if ($selected === null) {
            $selected = $homecourse;
            $courseid = (int) $homecourse->id;
        }
    }
    if ($homeoutline === null) {
        $homeoutline = [
            'sections' => [],
            'completion_enabled' => false,
            'completed' => 0,
            'trackable' => 0,
            'percent' => null,
            'next' => null,
        ];
    }
    $next = $homeoutline['next'] ?? null;
    $journey = fellowship_dashboard::journey_from_outline($homeoutline);
    $homesummary = fellowship_dashboard::home_summary($homeoutline, $journey, $live);
    $trackurl = (new moodle_url(persona_home::FELLOW_PATH, array_filter([
        'view' => 'track',
        'id' => $courseid > 0 ? $courseid : null,
    ])))->out(false);
    $progresspct = (int) ($homesummary['progresspct'] ?? 0);
    $hasprogress = !empty($homesummary['has_percent']) || $progresspct > 0;
    $completionenabled = !empty($homeoutline['completion_enabled']);
    $alltrackeddone = !empty($homesummary['all_tracked_done']);
    $quest = [];
    // Only show a resume quest when Moodle still has a next incomplete activity.
    // When everything tracked is done, leave hasquest false so the all-done copy shows.
    if ($next !== null) {
        $meta = (string) ($next['modname_display'] ?? '');
        if ($selected) {
            $coursename = fellowship_dashboard::display_label(format_string($selected->fullname));
            $meta .= ($meta !== '' ? ' · ' : '') . $coursename;
        }
        $quest = [
            'title' => (string) ($next['name'] ?? ''),
            'meta' => $meta,
            'url' => (string) ($next['url'] ?? $trackurl),
            'cta' => $lpstr('fellowship_cta_resume', null, 'Resume learning'),
            'progress' => max(0, min(100, $progresspct)),
            'progresslabel' => $hasprogress
                ? $lpstr('fellowship_progress_pct', $progresspct, '{$a}% complete')
                : $lpstr('fellowship_progress_na', null, 'Open track for activities'),
        ];
    } else if ($selected !== null && !$alltrackeddone && !$completionenabled) {
        // Completion off: still offer Open track without pretending work remains.
        $quest = [
            'title' => fellowship_dashboard::display_label(format_string($selected->fullname)),
            'meta' => (string) ($selected->idnumber ?? ''),
            'url' => $trackurl,
            'cta' => $lpstr('fellowship_cta_view_track', null, 'Open track'),
            'progress' => max(0, min(100, $progresspct)),
            'progresslabel' => $lpstr('fellowship_progress_na', null, 'Open track for activities'),
        ];
    }

    // Hero lead matches Moodle state (topbar already owns the greeting).
    if ($courses === []) {
        $herolead = $lpstr(
            'fellowship_home_lead_empty',
            null,
            'You are all set to begin. When your programme enrols you in a LaunchPad course, your journey and next activity will appear here.'
        );
    } else if ($next !== null) {
        $herolead = $lpstr(
            'fellowship_home_lead_next',
            null,
            'You are in a good place. Resume below to keep moving through your next Moodle activity on this track.'
        );
    } else if ($alltrackeddone) {
        $herolead = $lpstr(
            'fellowship_home_lead_done',
            null,
            'Great progress. Tracked activities in this course are complete. Open My track for the full list, or continue in Moodle whenever you like.'
        );
    } else {
        $herolead = $lpstr(
            'fellowship_home_lead_open',
            null,
            'Browse your track to explore Moodle activities. Completion tracking is not enabled on this course yet, so percentages may stay at zero.'
        );
    }

    $homecourseswitch = [];
    $selectedid = $selected !== null ? (int) $selected->id : 0;
    foreach ($coursecards as $card) {
        $cid = (int) $card['id'];
        $homecourseswitch[] = [
            'fullname' => $card['fullname'],
            'url' => (new moodle_url(persona_home::FELLOW_PATH, [
                'view' => 'home',
                'id' => $cid,
            ]))->out(false),
            'selected' => $selectedid > 0 && $cid === $selectedid,
        ];
    }

    $mycourse = [];
    if ($selected !== null) {
        $pct = $homeoutline['percent'] ?? null;
        $courseimage = fellowship_dashboard::course_image_url($selected);
        $mycourse = [
            'name' => fellowship_dashboard::display_label(format_string($selected->fullname)),
            'detail_url' => $trackurl,
            'continue_url' => $next['url'] ?? $trackurl,
            'cta_label' => $next !== null
                ? $lpstr('fellowship_cta_resume', null, 'Resume learning')
                : $lpstr('fellowship_cta_view_track', null, 'Open track'),
            'status' => $pct !== null
                ? $lpstr('fellowship_progress_pct', (int) $pct, '{$a}% complete')
                : ($alltrackeddone
                    ? $lpstr('fellowship_gate_complete', null, 'Complete')
                    : $lpstr('fellowship_course_status_open', null, 'Ready to continue')),
            'hasprogress' => $pct !== null,
            'progress_display' => $pct !== null ? ((int) $pct) . '%' : '',
            'media_label' => $lpstr('fellowship_home_course_media', null, 'Track'),
            'imageurl' => $courseimage,
            'has_image' => $courseimage !== '',
        ];
    }
    $content = $OUTPUT->render_from_template('local_launchpad/fellowship_home', array_merge([
        'firstname' => $firstname,
        // Student campus lounge hero (no mascot baked in — ring stays UI).
        'hero_image' => $pluginbase . '/assets/img/fellowship-hero-campus.jpg',
        'hero_lead' => $herolead,
        'show_hero_welcome' => false,
        'courses' => $coursecards,
        'has_courses' => $coursecards !== [],
        'multi_course' => count($homecourseswitch) > 1,
        'course_switch' => $homecourseswitch,
        'calendar_url' => (new moodle_url(persona_home::FELLOW_PATH, ['view' => 'calendar']))->out(false),
        'news_url' => (new moodle_url(persona_home::FELLOW_PATH, ['view' => 'news']))->out(false),
        'help_url' => (new moodle_url(persona_home::FELLOW_PATH, ['view' => 'help']))->out(false),
        'profile_url' => $profileurl,
        'hasquest' => $quest !== [],
        'quest' => $quest,
        'mycourse' => $mycourse,
        'hasmycourse' => $mycourse !== [],
        'next' => $next,
        'has_next' => $next !== null,
        'selected_course_name' => $selected
            ? fellowship_dashboard::display_label(format_string($selected->fullname))
            : '',
        'completion_enabled' => $completionenabled,
        'journey' => $journey,
        'bot_url' => $pluginbase . '/assets/img/bot.webp',
        'track_url' => $trackurl,
        'progress_url' => $progressurl,
        'phases_url' => $progressurl,
    ], $homesummary));
}

if ($view === 'achievements') {
    $content = $OUTPUT->render_from_template('local_launchpad/fellowship_achievements', $ach);
}

$feedbacktab = optional_param('ftab', 'all', PARAM_ALPHAEXT);
// Nav badge: attention across every enrolled track (do not miss revise on another course).
$feedbackattention = (int) (
    fellowship_dashboard::list_assignment_feedback($userid, $courses, 'all')['attention_count'] ?? 0
);
if ($view === 'feedback') {
    // Page data: selected track only (same scoping as Progress / My Track).
    $feedbackscope = $selected ? [$selected] : $courses;
    $feedbackcourseid = $selected ? (int) $selected->id : ($courseid > 0 ? $courseid : null);
    $feedbackdata = fellowship_dashboard::list_assignment_feedback(
        $userid,
        $feedbackscope,
        $feedbacktab,
        $feedbackcourseid
    );

    // Same multi-course switch as Home: one chip per enrolled course via $coursecards.
    $feedbackswitch = [];
    $feedbackselectedid = $selected !== null ? (int) $selected->id : 0;
    foreach ($coursecards as $card) {
        $cid = (int) $card['id'];
        $feedbackswitch[] = [
            'fullname' => $card['fullname'],
            'url' => (new moodle_url(persona_home::FELLOW_PATH, array_filter([
                'view' => 'feedback',
                'id' => $cid,
                'ftab' => $feedbacktab !== 'all' ? $feedbacktab : null,
            ])))->out(false),
            'selected' => $feedbackselectedid > 0 && $cid === $feedbackselectedid,
        ];
    }

    $content = $OUTPUT->render_from_template('local_launchpad/fellowship_feedback', array_merge($feedbackdata, [
        'has_courses' => $courses !== [],
        'empty_message' => $nocourses,
        'track_url' => (new moodle_url(persona_home::FELLOW_PATH, array_filter([
            'view' => 'track',
            'id' => $feedbackcourseid,
        ])))->out(false),
        'empty_avatar_url' => $pluginbase . '/assets/img/prof.webp',
        'multi_course' => count($feedbackswitch) > 1,
        'course_switch' => $feedbackswitch,
        'selected_course_name' => $selected
            ? fellowship_dashboard::display_label(format_string($selected->fullname))
            : '',
    ]));
}

// Mark current nav + Achievements earned/total + Feedback attention.
foreach ($nav as &$group) {
    foreach ($group['items'] as &$item) {
        $item['current'] = ($item['key'] === $view);
        if (($item['key'] ?? '') === 'achievements') {
            $item['has_badge'] = true;
            $item['badge'] = $badgesearned . '/' . $badgestotal;
        }
        if (($item['key'] ?? '') === 'feedback' && $feedbackattention > 0) {
            $item['has_badge'] = true;
            $item['badge'] = (string) $feedbackattention;
        }
    }
}
unset($group, $item);

$unread = messaging::notification_unread_count($userid);
$notificationslabel = messaging::notifications_label($unread);

$orbittips = [
    'home' => $lpstr(
        'fellowship_orbit_tip_home',
        null,
        'Your <b>next activity</b> is at the top. XP comes from work saved in Moodle.'
    ),
    'track' => $lpstr(
        'fellowship_orbit_tip_track',
        null,
        'Open activities in Moodle. Later phases unlock when the programme releases them.'
    ),
    'progress' => $lpstr(
        'fellowship_orbit_tip_progress_page',
        null,
        'Gates need a released review at 70/100. Orientation and the weighted result sit on this page — the certificate stays locked until issuance is on.'
    ),
    'achievements' => $lpstr(
        'fellowship_orbit_tip_achievements',
        null,
        'Levels and badges update from lessons, quizzes and submissions you complete.'
    ),
    'feedback' => $lpstr(
        'fellowship_orbit_tip_feedback',
        null,
        'Mentor comments and grades live on Moodle assignments. Open an item to revise or read the full review.'
    ),
    'help' => $lpstr(
        'fellowship_orbit_tip_help',
        null,
        'Straight answers only. Contact the programme team if you are stuck.'
    ),
    'news' => $lpstr(
        'fellowship_orbit_tip_news',
        null,
        'Announcements come from each course <b>news forum</b>. Open a post to read the full thread in Moodle.'
    ),
    'calendar' => $lpstr(
        'fellowship_orbit_tip_calendar',
        null,
        'Add personal events here — course deadlines stay read-only.'
    ),
    'portfolio' => $lpstr(
        'fellowship_orbit_tip_portfolio',
        null,
        'Portfolio counts Moodle submissions and released mentor reviews for the <b>selected track</b>.'
    ),
    'profile' => $lpstr(
        'fellowship_orbit_tip_profile',
        null,
        'Profile saves to your Moodle account. Use <b>Preferences</b> for messages, notifications, and language.'
    ),
    'messages' => $lpstr(
        'fellowship_orbit_tip_messages',
        null,
        'LaunchPad messages sync with Moodle messaging. Mentors and peers you can message appear here.'
    ),
];
$orbittip = $orbittips[$view] ?? $orbittips['home'];

// Time-of-day greeting for home topbar (Employer chrome parity).
$hour = (int) userdate(time(), '%H');
if ($hour < 12) {
    $greeting = $lpstr('fellowship_greeting_morning', $firstname, 'Morning, {$a}');
} else if ($hour < 17) {
    $greeting = $lpstr('fellowship_greeting_afternoon', $firstname, 'Afternoon, {$a}');
} else {
    $greeting = $lpstr('fellowship_greeting_evening', $firstname, 'Evening, {$a}');
}

$mismatch = portal_gate::consume_modal_context();
$fellowinitials = fellowship_dashboard::initials($USER);
$fellowemail = trim((string) ($USER->email ?? ''));
$fellowrole = $lpstr('fellowship_role_label', null, 'Fellow · LaunchPad');
$profilemenu = employer_dashboard::profile_menu_context(
    $fellowinitials,
    $displayname,
    $fellowemail,
    $profileurl,
    $preferencesurl,
    $logouturl
);
$shell = $OUTPUT->render_from_template('local_launchpad/fellowship_shell', array_merge([
    'data_region' => fellowship_dashboard::DATA_REGION,
    'assets' => $pluginbase . '/assets/img',
    'displayname' => $displayname,
    'firstname' => $firstname,
    'greeting' => $greeting,
    'portallabel' => $lpstr('fellowship_portal_label', null, 'Fellowship portal'),
    'role_label' => $fellowrole,
    'is_home' => $view === 'home',
    'has_courses' => $courses !== [],
    'nav_groups' => $nav,
    'content' => $content,
    'pagetitle' => $pagetitle,
    'home_url' => $homeurl,
    'logout_url' => $logouturl,
    'profile_url' => $profileurl,
    'progress_url' => $progressurl,
    'achievements_url' => $achievementsurl,
    'notifications_url' => $notificationsurl,
    'notifications_label' => $notificationslabel,
    'unread_notifications' => $unread,
    'has_unread_notifications' => $unread > 0,
    'xp_total' => $xptotal,
    'level_name' => $ach['level_name'],
    'level_index' => $ach['level_index'],
    'level_color' => $ach['level_color'],
    'has_next_level' => $ach['has_next_level'],
    'next_level_name' => $ach['next_level_name'],
    'xp_to_next' => $ach['xp_to_next'],
    'pct_to_next' => $ach['pct_to_next'],
    'initials' => $fellowinitials,
    'profile_menu' => $profilemenu,
    'profile_menu_side' => [
        'profile_menu' => employer_dashboard::profile_menu_context(
            $fellowinitials,
            $displayname,
            $fellowemail,
            $profileurl,
            $preferencesurl,
            $logouturl,
            true,
            $fellowrole
        ),
    ],
    'bot_url' => $pluginbase . '/assets/img/bot.webp',
    'orbit_tip' => $orbittip,
], $mismatch));

header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html>' . "\n";
echo '<html lang="' . s(current_language()) . '">' . "\n";
echo '<head>' . "\n";
echo '<meta charset="utf-8">' . "\n";
echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
echo '<title>' . s($lpstr('fellowship_area_title', null, 'Fellowship · ToBa Learn')) . '</title>' . "\n";
echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
echo '<link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700&family=Syne:wght@600;700&display=swap" rel="stylesheet">' . "\n";
echo '<link rel="stylesheet" href="' . s($cssurl) . '">' . "\n";
echo '<script src="https://code.iconify.design/iconify-icon/2.1.0/iconify-icon.min.js"></script>' . "\n";
echo '</head>' . "\n";
$bodyclass = 'local-launchpad-fellowship';
if (!empty($mismatch['show_portal_mismatch'])) {
    $bodyclass .= ' lp-portal-mismatch-lock';
}
echo '<body class="' . $bodyclass . '">' . "\n";
echo $shell;
echo '<script src="' . s($jsurl) . '" defer></script>' . "\n";
echo '</body>' . "\n";
echo '</html>';
