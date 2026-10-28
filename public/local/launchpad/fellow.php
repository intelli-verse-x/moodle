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

use local_launchpad\account_persona;
use local_launchpad\fellowship_dashboard;
use local_launchpad\persona_home;

require_login();

$userid = (int) $USER->id;
if (!fellowship_dashboard::can_access($userid)) {
    throw new \moodle_exception('fellowship_access_denied', 'local_launchpad');
}

$view = optional_param('view', 'home', PARAM_ALPHA);
$courseid = optional_param('id', 0, PARAM_INT);

$courses = fellowship_dashboard::list_courses($userid);
$courseids = array_map(static fn($c) => (int) $c->id, $courses);

// Mentors/employees must not land here via persona_home; still refuse if someone
// is mentor-only without fellowship (admins may preview).
if (!account_persona::is_fellowship($userid) && !is_siteadmin()) {
    throw new \moodle_exception('fellowship_access_denied', 'local_launchpad');
}

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url(persona_home::FELLOW_PATH, array_filter([
    'view' => $view !== 'home' ? $view : null,
    'id' => $courseid > 0 ? $courseid : null,
])));
$PAGE->set_pagelayout('embedded');
$PAGE->set_title(get_string('fellowship_area_title', 'local_launchpad'));
$PAGE->set_heading(get_string('fellowship_area_heading', 'local_launchpad'));

$pluginbase = $CFG->wwwroot . '/local/launchpad';
$cssurl = $pluginbase . '/styles_fellowship.css';
$logouturl = (new moodle_url('/login/logout.php', ['sesskey' => sesskey()]))->out(false);
$profileurl = (new moodle_url('/user/profile.php', ['id' => $userid]))->out(false);
$publicurl = $CFG->wwwroot . '/';
$homeurl = (new moodle_url(persona_home::FELLOW_PATH))->out(false);

$firstname = format_string($USER->firstname);
$displayname = fullname($USER);

$nav = [
    [
        'group' => get_string('fellowship_nav_learn', 'local_launchpad'),
        'items' => [
            [
                'label' => get_string('fellowship_nav_home', 'local_launchpad'),
                'url' => $homeurl,
                'current' => $view === 'home',
                'key' => 'home',
            ],
            [
                'label' => get_string('fellowship_nav_track', 'local_launchpad'),
                'url' => (new moodle_url(persona_home::FELLOW_PATH, ['view' => 'track']))->out(false),
                'current' => $view === 'track',
                'key' => 'track',
            ],
            [
                'label' => get_string('fellowship_nav_progress', 'local_launchpad'),
                'url' => (new moodle_url(persona_home::FELLOW_PATH, ['view' => 'progress']))->out(false),
                'current' => $view === 'progress',
                'key' => 'progress',
            ],
        ],
    ],
    [
        'group' => get_string('fellowship_nav_you', 'local_launchpad'),
        'items' => [
            [
                'label' => get_string('fellowship_nav_help', 'local_launchpad'),
                'url' => (new moodle_url(persona_home::FELLOW_PATH, ['view' => 'help']))->out(false),
                'current' => $view === 'help',
                'key' => 'help',
            ],
            [
                'label' => get_string('fellowship_nav_account', 'local_launchpad'),
                'url' => $profileurl,
                'current' => false,
                'key' => 'account',
                'external' => true,
            ],
        ],
    ],
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
            $progresslabel = '';
            if ($outline['percent'] !== null) {
                $progresslabel = get_string('fellowship_progress_summary', 'local_launchpad', [
                    'done' => $outline['completed'],
                    'total' => $outline['trackable'],
                    'percent' => $outline['percent'],
                ]);
            }
            $content = $OUTPUT->render_from_template('local_launchpad/fellowship_track', [
                'course_name' => format_string($selected->fullname),
                'course_idnumber' => s($selected->idnumber),
                'moodle_course_url' => (new moodle_url('/course/view.php', ['id' => $selected->id]))->out(false),
                'sections' => $outline['sections'],
                'has_sections' => $outline['sections'] !== [],
                'completion_enabled' => $outline['completion_enabled'],
                'completed' => $outline['completed'],
                'trackable' => $outline['trackable'],
                'percent' => $outline['percent'],
                'has_percent' => $outline['percent'] !== null,
                'progress_label' => $progresslabel,
                'courses' => $coursecards,
                'multi_course' => count($coursecards) > 1,
            ]);
        }
        break;

    case 'progress':
        $pagetitle = get_string('fellowship_nav_progress', 'local_launchpad');
        $progresscards = [];
        foreach ($courses as $c) {
            $o = fellowship_dashboard::course_outline($c, $userid);
            $progresscards[] = [
                'fullname' => format_string($c->fullname),
                'idnumber' => s($c->idnumber),
                'url' => (new moodle_url(persona_home::FELLOW_PATH, ['view' => 'track', 'id' => $c->id]))->out(false),
                'moodle_url' => (new moodle_url('/course/view.php', ['id' => $c->id]))->out(false),
                'completion_enabled' => $o['completion_enabled'],
                'completed' => $o['completed'],
                'trackable' => $o['trackable'],
                'percent' => $o['percent'],
                'has_percent' => $o['percent'] !== null,
                'honest_note' => !$o['completion_enabled'] || $o['trackable'] === 0
                    ? get_string('fellowship_progress_not_tracked', 'local_launchpad')
                    : get_string('fellowship_progress_summary', 'local_launchpad', [
                        'done' => $o['completed'],
                        'total' => $o['trackable'],
                        'percent' => $o['percent'],
                    ]),
            ];
        }
        $content = $OUTPUT->render_from_template('local_launchpad/fellowship_progress', [
            'courses' => $progresscards,
            'has_courses' => $progresscards !== [],
            'empty_message' => get_string('fellowship_no_courses', 'local_launchpad'),
        ]);
        break;

    case 'help':
        $pagetitle = get_string('fellowship_nav_help', 'local_launchpad');
        $content = $OUTPUT->render_from_template('local_launchpad/fellowship_help', [
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
        $next = $outline['next'] ?? null;
        $content = $OUTPUT->render_from_template('local_launchpad/fellowship_home', [
            'firstname' => $firstname,
            'welcome_heading' => get_string('fellowship_welcome_named', 'local_launchpad', $firstname),
            'welcome_back' => get_string('fellowship_welcome_back', 'local_launchpad', $firstname),
            'courses' => $coursecards,
            'has_courses' => $coursecards !== [],
            'empty_message' => get_string('fellowship_no_courses', 'local_launchpad'),
            'next' => $next,
            'has_next' => $next !== null,
            'selected_course_name' => $selected ? format_string($selected->fullname) : '',
            'completion_enabled' => $outline['completion_enabled'] ?? false,
            'completed' => $outline['completed'] ?? 0,
            'trackable' => $outline['trackable'] ?? 0,
            'percent' => $outline['percent'] ?? null,
            'has_percent' => isset($outline['percent']) && $outline['percent'] !== null,
            'track_url' => (new moodle_url(persona_home::FELLOW_PATH, array_filter([
                'view' => 'track',
                'id' => $courseid > 0 ? $courseid : null,
            ])))->out(false),
            'progress_url' => (new moodle_url(persona_home::FELLOW_PATH, ['view' => 'progress']))->out(false),
        ]);
        break;
}

// Mark current nav from actual view after switch normalisation.
foreach ($nav as &$group) {
    foreach ($group['items'] as &$item) {
        $item['current'] = ($item['key'] === $view);
    }
}
unset($group, $item);

$shell = $OUTPUT->render_from_template('local_launchpad/fellowship_shell', [
    'data_region' => fellowship_dashboard::DATA_REGION,
    'displayname' => $displayname,
    'firstname' => $firstname,
    'role_label' => get_string('fellowship_role_label', 'local_launchpad'),
    'nav_groups' => $nav,
    'content' => $content,
    'pagetitle' => $pagetitle,
    'home_url' => $homeurl,
    'public_url' => $publicurl,
    'logout_url' => $logouturl,
    'profile_url' => $profileurl,
    'initials' => fellowship_dashboard::initials($USER),
]);

header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html>' . "\n";
echo '<html lang="' . s(current_language()) . '">' . "\n";
echo '<head>' . "\n";
echo '<meta charset="utf-8">' . "\n";
echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
echo '<title>' . s(get_string('fellowship_area_title', 'local_launchpad')) . '</title>' . "\n";
echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
echo '<link href="https://fonts.googleapis.com/css2?family=Gantari:wght@400;500;600;700&family=Prosto+One&display=swap" rel="stylesheet">' . "\n";
echo '<link rel="stylesheet" href="' . s($cssurl) . '">' . "\n";
echo '</head>' . "\n";
echo '<body class="local-launchpad-fellowship">' . "\n";
echo $shell;
echo '</body>' . "\n";
echo '</html>';
