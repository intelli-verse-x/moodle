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

use local_launchpad\fellowship_dashboard;
use local_launchpad\login_portal;
use local_launchpad\messaging;
use local_launchpad\persona_home;
use local_launchpad\portal_gate;

require_login();

$userid = (int) $USER->id;
// Mentors/employees must not land here; admins may preview (can_access).
portal_gate::assert_or_redirect_home(
    fellowship_dashboard::can_access($userid),
    login_portal::FELLOWSHIP
);

$view = optional_param('view', 'home', PARAM_ALPHA);
// On messages, id is the other user — never a course (avoids fellowship_course_denied).
$messageuserid = 0;
$courseid = 0;
$tab = optional_param('tab', 'all', PARAM_ALPHA);
if ($tab !== 'earned') {
    $tab = 'all';
}
if ($view === 'messages') {
    $messageuserid = optional_param('id', 0, PARAM_INT);
} else {
    $courseid = optional_param('id', 0, PARAM_INT);
}

$courses = fellowship_dashboard::list_courses($userid);
$courseids = array_map(static fn($c) => (int) $c->id, $courses);

// Send / delete LaunchPad messages (Moodle-backed) before rendering.
if ($view === 'messages' && data_submitted() && confirm_sesskey()) {
    $toid = required_param('id', PARAM_INT);
    $msgaction = optional_param('msgaction', 'send', PARAM_ALPHA);
    $redirect = new moodle_url(persona_home::FELLOW_PATH, ['view' => 'messages', 'id' => $toid]);
    if ($msgaction === 'delete') {
        $messageids = optional_param_array('messageids', [], PARAM_INT);
        $result = messaging::delete_messages($userid, $messageids);
    } else {
        $body = required_param('message', PARAM_RAW_TRIMMED);
        $result = messaging::send($userid, $toid, $body);
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
        : ($courseid > 0 ? $courseid : null),
    'tab' => ($view === 'achievements' && $tab !== 'all') ? $tab : null,
])));
$PAGE->set_pagelayout('embedded');
$PAGE->set_title(get_string('fellowship_area_title', 'local_launchpad'));
$PAGE->set_heading(get_string('fellowship_area_heading', 'local_launchpad'));

$pluginbase = $CFG->wwwroot . '/local/launchpad';
// Cache-bust CSS/JS from this plugin's version.php (same pattern as employee.php).
$plugin = new \stdClass();
require(__DIR__ . '/version.php');
$assetv = (string) ($plugin->version ?? time());
$cssurl = $pluginbase . '/styles_fellowship.css?v=' . rawurlencode($assetv);
$jsurl = $pluginbase . '/fellowship.js?v=' . rawurlencode($assetv);
$logouturl = (new moodle_url('/login/logout.php', ['sesskey' => sesskey()]))->out(false);
$profileurl = (new moodle_url('/user/profile.php', ['id' => $userid]))->out(false);
$homeurl = (new moodle_url(persona_home::FELLOW_PATH))->out(false);
// Legacy Progress tab redirects to Phase progress; keep URL for shell/home links.
$progressurl = (new moodle_url(persona_home::FELLOW_PATH, ['view' => 'phases']))->out(false);
$notificationsurl = messaging::moodle_notifications_url();
$messagesurl = messaging::messages_url(null, 'fellow');
$msgunread = messaging::conversation_unread_count($userid);

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
        'group' => get_string('fellowship_nav_learn', 'local_launchpad'),
        'items' => [
            [
                'label' => get_string('fellowship_nav_home', 'local_launchpad'),
                'url' => $homeurl,
                'current' => $view === 'home',
                'key' => 'home',
                'icon_svg' => $icon('home-01'),
            ],
            [
                'label' => get_string('fellowship_nav_track', 'local_launchpad'),
                'url' => (new moodle_url(persona_home::FELLOW_PATH, ['view' => 'track']))->out(false),
                'current' => $view === 'track',
                'key' => 'track',
                'icon_svg' => $icon('layers-01'),
            ],
            [
                'label' => get_string('fellowship_nav_achievements', 'local_launchpad'),
                'url' => (new moodle_url(persona_home::FELLOW_PATH, ['view' => 'achievements']))->out(false),
                'current' => $view === 'achievements',
                'key' => 'achievements',
                'icon_svg' => $icon('award-01'),
            ],
        ],
    ],
    [
        'group' => get_string('fellowship_nav_record', 'local_launchpad'),
        'items' => [
            [
                'label' => get_string('fellowship_nav_feedback', 'local_launchpad'),
                'url' => (new moodle_url(persona_home::FELLOW_PATH, ['view' => 'feedback']))->out(false),
                'current' => $view === 'feedback',
                'key' => 'feedback',
                'icon_svg' => $icon('message-01'),
            ],
            [
                'label' => get_string('fellowship_nav_phases', 'local_launchpad'),
                'url' => (new moodle_url(persona_home::FELLOW_PATH, ['view' => 'phases']))->out(false),
                'current' => $view === 'phases',
                'key' => 'phases',
                'icon_svg' => $icon('chart-histogram'),
            ],
            [
                'label' => get_string('fellowship_nav_completion', 'local_launchpad'),
                'url' => (new moodle_url(persona_home::FELLOW_PATH, ['view' => 'completion']))->out(false),
                'current' => $view === 'completion',
                'key' => 'completion',
                'icon_svg' => $icon('checkmark-circle-02'),
            ],
        ],
    ],
    [
        'group' => get_string('fellowship_nav_you', 'local_launchpad'),
        'items' => [
            [
                'label' => get_string('fellowship_nav_news', 'local_launchpad'),
                'url' => (new moodle_url(persona_home::FELLOW_PATH, ['view' => 'news']))->out(false),
                'current' => $view === 'news',
                'key' => 'news',
                'icon_svg' => $icon('news'),
            ],
            [
                'label' => get_string('fellowship_nav_calendar', 'local_launchpad'),
                'url' => (new moodle_url(persona_home::FELLOW_PATH, ['view' => 'calendar']))->out(false),
                'current' => $view === 'calendar',
                'key' => 'calendar',
                'icon_svg' => $icon('calendar-03'),
            ],
            [
                'label' => get_string('fellowship_nav_portfolio', 'local_launchpad'),
                'url' => (new moodle_url(persona_home::FELLOW_PATH, ['view' => 'portfolio']))->out(false),
                'current' => $view === 'portfolio',
                'key' => 'portfolio',
                'icon_svg' => $icon('briefcase-01'),
            ],
            [
                'label' => get_string('fellowship_messages', 'local_launchpad'),
                'url' => $messagesurl,
                'current' => $view === 'messages',
                'key' => 'messages',
                'icon_svg' => $icon('<path d="M5 5h14v10H9l-4 4V5z"/>'),
                'has_badge' => $msgunread > 0,
                'badge' => $msgunread > 0 ? (string) $msgunread : '',
            ],
            [
                'label' => get_string('fellowship_nav_help', 'local_launchpad'),
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
    'label' => get_string('fellowship_nav_home', 'local_launchpad'),
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
} else if (count($courses) === 1) {
    $selected = $courses[0];
    $courseid = (int) $selected->id;
}

$outline = null;
$coursecards = [];
foreach ($courses as $c) {
    $curl = new moodle_url(persona_home::FELLOW_PATH, ['view' => 'track', 'id' => $c->id]);
    $moodlecourseurl = new moodle_url('/course/view.php', ['id' => $c->id]);
    $card = [
        'id' => (int) $c->id,
        'fullname' => format_string($c->fullname),
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
$pagetitle = get_string('fellowship_area_heading', 'local_launchpad');

switch ($view) {
    case 'track':
        $pagetitle = get_string('fellowship_nav_track', 'local_launchpad');
        if ($selected === null) {
            $content = $OUTPUT->render_from_template('local_launchpad/fellowship_empty_courses', [
                'message' => get_string('fellowship_no_courses', 'local_launchpad'),
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
                        'label' => get_string('fellowship_nav_track', 'local_launchpad'),
                        'url' => '',
                    ],
                ],
            ]));
        }
        break;

    case 'progress':
        // Sidebar Progress removed — Phase progress is the programme record view.
        redirect(new moodle_url(persona_home::FELLOW_PATH, ['view' => 'phases']));
        break;

    case 'news':
        $pagetitle = get_string('fellowship_nav_news', 'local_launchpad');
        $newsdata = fellowship_dashboard::list_announcements($userid, $courses);
        $content = $OUTPUT->render_from_template('local_launchpad/fellowship_news', array_merge($newsdata, [
            'has_crumbs' => true,
            'crumbs' => [
                $crumbhome,
                [
                    'label' => get_string('fellowship_nav_news', 'local_launchpad'),
                    'url' => '',
                ],
            ],
        ]));
        break;

    case 'messages':
        $pagetitle = get_string('fellowship_messages_heading', 'local_launchpad');
        $otherid = $messageuserid > 0 ? $messageuserid : null;
        $msgdata = messaging::page_context($userid, 'fellow', $otherid);
        $msgcrumbs = [
            $crumbhome,
            [
                'label' => get_string('fellowship_messages', 'local_launchpad'),
                'url' => !empty($msgdata['has_thread']) ? messaging::messages_url(null, 'fellow') : '',
            ],
        ];
        if (!empty($msgdata['has_thread']) && !empty($msgdata['other_name'])) {
            $pagetitle = (string) $msgdata['other_name'];
            $msgcrumbs[] = [
                'label' => (string) $msgdata['other_name'],
                'url' => '',
            ];
        }
        $content = $OUTPUT->render_from_template('local_launchpad/fellowship_messages', array_merge($msgdata, [
            'has_crumbs' => true,
            'crumbs' => $msgcrumbs,
        ]));
        break;

    case 'calendar':
        $pagetitle = get_string('fellowship_nav_calendar', 'local_launchpad');
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
        $pagetitle = get_string('fellowship_nav_portfolio', 'local_launchpad');
        $portdata = fellowship_dashboard::list_portfolio_evidence($userid, $courses);
        $content = $OUTPUT->render_from_template('local_launchpad/fellowship_portfolio', array_merge($portdata, [
            'has_crumbs' => true,
            'crumbs' => [
                $crumbhome,
                [
                    'label' => get_string('fellowship_nav_portfolio', 'local_launchpad'),
                    'url' => '',
                ],
            ],
        ]));
        break;

    case 'achievements':
        $pagetitle = get_string('fellowship_nav_achievements', 'local_launchpad');
        // Content filled after live_achievements() so we compute Moodle XP/badges once.
        $content = '';
        break;

    case 'feedback':
        $pagetitle = get_string('fellowship_nav_feedback', 'local_launchpad');
        // Content filled after list_assignment_feedback() (shared with nav badge).
        $content = '';
        break;

    case 'phases':
        $pagetitle = get_string('fellowship_nav_phases', 'local_launchpad');
        $phasedata = fellowship_dashboard::phase_progress_for_user($userid, $courses, $selected);
        $content = $OUTPUT->render_from_template('local_launchpad/fellowship_phases', array_merge($phasedata, [
            'bot_url' => $pluginbase . '/assets/img/bot.webp',
            'empty_message' => get_string('fellowship_no_courses', 'local_launchpad'),
            'has_crumbs' => true,
            'crumbs' => [
                $crumbhome,
                [
                    'label' => get_string('fellowship_nav_phases', 'local_launchpad'),
                    'url' => '',
                ],
            ],
        ]));
        break;

    case 'completion':
        $pagetitle = get_string('fellowship_nav_completion', 'local_launchpad');
        $completiondata = fellowship_dashboard::completion_for_user($userid, $courses, $selected);
        $content = $OUTPUT->render_from_template('local_launchpad/fellowship_completion', array_merge($completiondata, [
            'prof_url' => $pluginbase . '/assets/img/prof.webp',
        ]));
        break;

    case 'help':
        $pagetitle = get_string('fellowship_nav_help', 'local_launchpad');
        $content = $OUTPUT->render_from_template('local_launchpad/fellowship_help', [
            'has_crumbs' => true,
            'crumbs' => [
                $crumbhome,
                [
                    'label' => get_string('fellowship_nav_help', 'local_launchpad'),
                    'url' => '',
                ],
            ],
            'faqs' => [
                [
                    'q' => get_string('fellowship_faq_internship_q', 'local_launchpad'),
                    'a' => get_string('fellowship_faq_internship_a', 'local_launchpad'),
                ],
                [
                    'q' => get_string('fellowship_faq_track_q', 'local_launchpad'),
                    'a' => get_string('fellowship_faq_track_a', 'local_launchpad'),
                ],
                [
                    'q' => get_string('fellowship_faq_grade_q', 'local_launchpad'),
                    'a' => get_string('fellowship_faq_grade_a', 'local_launchpad'),
                ],
                [
                    'q' => get_string('fellowship_faq_quiz_q', 'local_launchpad'),
                    'a' => get_string('fellowship_faq_quiz_a', 'local_launchpad'),
                ],
            ],
        ]);
        break;

    case 'home':
    default:
        $view = 'home';
        $pagetitle = get_string('fellowship_nav_home', 'local_launchpad');
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
    $quest = [];
    if ($next !== null) {
        $meta = (string) ($next['modname_display'] ?? '');
        if ($selected) {
            $coursename = format_string($selected->fullname);
            $meta .= ($meta !== '' ? ' · ' : '') . $coursename;
        }
        $quest = [
            'title' => (string) ($next['name'] ?? ''),
            'meta' => $meta,
            'url' => (string) ($next['url'] ?? $trackurl),
            'cta' => get_string('fellowship_cta_resume', 'local_launchpad'),
            'progress' => max(0, min(100, $progresspct)),
            'progresslabel' => $hasprogress
                ? get_string('fellowship_progress_pct', 'local_launchpad', $progresspct)
                : get_string('fellowship_progress_na', 'local_launchpad'),
        ];
    } else if ($selected !== null) {
        $quest = [
            'title' => format_string($selected->fullname),
            'meta' => (string) ($selected->idnumber ?? ''),
            'url' => $trackurl,
            'cta' => get_string('fellowship_cta_view_track', 'local_launchpad'),
            'progress' => max(0, min(100, $progresspct)),
            'progresslabel' => $hasprogress
                ? get_string('fellowship_progress_pct', 'local_launchpad', $progresspct)
                : get_string('fellowship_progress_na', 'local_launchpad'),
        ];
    }
    $mycourse = [];
    if ($selected !== null) {
        $pct = $homeoutline['percent'] ?? null;
        $mycourse = [
            'name' => format_string($selected->fullname),
            'detail_url' => $trackurl,
            'continue_url' => $next['url'] ?? $trackurl,
            'cta_label' => $next !== null
                ? get_string('fellowship_cta_resume', 'local_launchpad')
                : get_string('fellowship_cta_view_track', 'local_launchpad'),
            'status' => $pct !== null
                ? get_string('fellowship_progress_pct', 'local_launchpad', (int) $pct)
                : get_string('fellowship_course_status_open', 'local_launchpad'),
            'hasprogress' => $pct !== null,
            'progress_display' => $pct !== null ? ((int) $pct) . '%' : '',
            'media_label' => get_string('fellowship_home_course_media', 'local_launchpad'),
        ];
    }
    $content = $OUTPUT->render_from_template('local_launchpad/fellowship_home', array_merge([
        'firstname' => $firstname,
        'role_label' => get_string('fellowship_role_label', 'local_launchpad'),
        'welcome_heading' => get_string('fellowship_welcome_named', 'local_launchpad', $firstname),
        'welcome_back' => get_string('fellowship_welcome_back', 'local_launchpad', $firstname),
        'welcome_html' => get_string('fellowship_welcome_prefix', 'local_launchpad')
            . ' <span class="lp-hero-name">' . s($firstname) . '</span>',
        // Student campus lounge hero (no mascot baked in — ring stays UI).
        'hero_image' => $pluginbase . '/assets/img/fellowship-hero-campus.jpg',
        'courses' => $coursecards,
        'has_courses' => $coursecards !== [],
        'empty_message' => get_string('fellowship_no_courses', 'local_launchpad'),
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
        'selected_course_name' => $selected ? format_string($selected->fullname) : '',
        'completion_enabled' => $homeoutline['completion_enabled'] ?? false,
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

$feedbackdata = fellowship_dashboard::list_assignment_feedback($userid, $courses);
$feedbackattention = (int) ($feedbackdata['attention_count'] ?? 0);
if ($view === 'feedback') {
    $content = $OUTPUT->render_from_template('local_launchpad/fellowship_feedback', array_merge($feedbackdata, [
        'has_courses' => $courses !== [],
        'empty_message' => get_string('fellowship_no_courses', 'local_launchpad'),
        'track_url' => (new moodle_url(persona_home::FELLOW_PATH, ['view' => 'track']))->out(false),
        'empty_avatar_url' => $pluginbase . '/assets/img/prof.webp',
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
    'home' => get_string('fellowship_orbit_tip_home', 'local_launchpad'),
    'track' => get_string('fellowship_orbit_tip_track', 'local_launchpad'),
    'progress' => get_string('fellowship_orbit_tip_phases', 'local_launchpad'),
    'achievements' => get_string('fellowship_orbit_tip_achievements', 'local_launchpad'),
    'feedback' => get_string('fellowship_orbit_tip_feedback', 'local_launchpad'),
    'phases' => get_string('fellowship_orbit_tip_phases', 'local_launchpad'),
    'completion' => get_string('fellowship_orbit_tip_completion', 'local_launchpad'),
    'help' => get_string('fellowship_orbit_tip_help', 'local_launchpad'),
    'news' => get_string('fellowship_orbit_tip_news', 'local_launchpad'),
    'calendar' => get_string('fellowship_orbit_tip_calendar', 'local_launchpad'),
    'portfolio' => get_string('fellowship_orbit_tip_portfolio', 'local_launchpad'),
];
$orbittip = $orbittips[$view] ?? $orbittips['home'];

// Time-of-day greeting for home topbar (Employer chrome parity).
$hour = (int) userdate(time(), '%H');
if ($hour < 12) {
    $greeting = get_string('fellowship_greeting_morning', 'local_launchpad', $firstname);
} else if ($hour < 17) {
    $greeting = get_string('fellowship_greeting_afternoon', 'local_launchpad', $firstname);
} else {
    $greeting = get_string('fellowship_greeting_evening', 'local_launchpad', $firstname);
}

$mismatch = portal_gate::consume_modal_context();
$shell = $OUTPUT->render_from_template('local_launchpad/fellowship_shell', array_merge([
    'data_region' => fellowship_dashboard::DATA_REGION,
    'assets' => $pluginbase . '/assets/img',
    'displayname' => $displayname,
    'firstname' => $firstname,
    'role_label' => get_string('fellowship_role_label', 'local_launchpad'),
    'portallabel' => get_string('fellowship_portal_label', 'local_launchpad'),
    'greeting' => $greeting,
    'is_home' => $view === 'home',
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
    'initials' => fellowship_dashboard::initials($USER),
    'bot_url' => $pluginbase . '/assets/img/bot.webp',
    'orbit_tip' => $orbittip,
], $mismatch));

header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html>' . "\n";
echo '<html lang="' . s(current_language()) . '">' . "\n";
echo '<head>' . "\n";
echo '<meta charset="utf-8">' . "\n";
echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
echo '<title>' . s(get_string('fellowship_area_title', 'local_launchpad')) . '</title>' . "\n";
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
