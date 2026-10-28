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
 * Language strings for local_launchpad.
 *
 * @package    local_launchpad
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'ToBa Learn LaunchPad';
$string['privacy:metadata'] = 'The LaunchPad plugin does not store personal data beyond normal Moodle account and enrolment records.';
$string['pagetitle'] = 'ToBa Learn · LaunchPad Fellowship';
$string['mentorformurl'] = 'Become a mentor · Google Form URL';
$string['mentorformurl_desc'] = 'Public Google Form link for mentor applications. Applicants are redirected here from the landing page. After they submit, your team contacts them for the next steps.';
$string['emptystate_no_course'] = 'You do not have a LaunchPad course yet. When your place is confirmed, your programme admin will enrol you and your track will appear here.';
$string['employee_area_title'] = 'Employee learning';
$string['employee_area_heading'] = 'Employee area';
$string['employee_area_body'] = 'Your employee access is active. Programme content will appear here when your organisation assigns it. This area does not enrol you in the Fellowship or grant mentor or admin rights.';
$string['employee_access_denied'] = 'You do not have employee access. Ask your programme administrator if you expected to see this page.';
$string['employee_cohort_name'] = 'ToBa Learn Employees';
$string['mentor_area_title'] = 'Mentor home';
$string['mentor_area_heading'] = 'Mentor area';
$string['mentor_area_body'] = 'Your mentor access is active for the LaunchPad courses listed below. Dedicated review tools will appear here later. This area does not enrol you as a Fellow or grant admin rights.';
$string['mentor_area_courses'] = 'Your assigned LaunchPad courses';
$string['mentor_area_no_courses'] = 'No LaunchPad mentor courses are assigned to you yet. Ask your programme administrator if you expected to see one.';
$string['mentor_access_denied'] = 'You do not have mentor access. Mentors are assigned by staff on LaunchPad courses only.';

// Fellowship dashboard shell.
$string['fellowship_area_title'] = 'Fellowship · ToBa Learn';
$string['fellowship_area_heading'] = 'Fellowship';
$string['fellowship_role_label'] = 'Fellow · LaunchPad';
$string['fellowship_access_denied'] = 'You do not have Fellowship access. Fellows are enrolled as students on LaunchPad courses by programme staff.';
$string['fellowship_course_denied'] = 'That LaunchPad course is not available on your account.';
$string['fellowship_no_courses'] = 'You do not have a LaunchPad course yet. When your place is confirmed, your programme admin will enrol you and your track will appear here.';
$string['fellowship_no_activities'] = 'No visible activities in this course yet.';
$string['fellowship_welcome_named'] = 'Welcome, {$a}';
$string['fellowship_welcome_back'] = 'Welcome back, {$a}';
$string['fellowship_nav_aria'] = 'Fellowship navigation';
$string['fellowship_nav_learn'] = 'Learn';
$string['fellowship_nav_you'] = 'You';
$string['fellowship_nav_home'] = 'Home';
$string['fellowship_nav_track'] = 'My track';
$string['fellowship_nav_progress'] = 'Progress';
$string['fellowship_nav_achievements'] = 'Achievements';
$string['fellowship_nav_help'] = 'Help & FAQ';
$string['fellowship_nav_account'] = 'Account';
$string['fellowship_nav_public'] = 'Public site';
$string['fellowship_nav_signout'] = 'Sign out';
$string['fellowship_your_courses'] = 'Your courses';
$string['fellowship_open_moodle_course'] = 'Open in Moodle';
$string['fellowship_open_activity'] = 'Open';
$string['fellowship_next_activity'] = 'Continue learning';
$string['fellowship_view_progress'] = 'View progress';
$string['fellowship_all_tracked_done'] = 'Tracked activities in this course are complete. Open My track for the full list, or continue in Moodle.';
$string['fellowship_progress_intro'] = 'Progress below comes from Moodle activity completion when it is enabled. It is not XP, badges, or a grade.';
$string['fellowship_progress_from_completion'] = 'Based on Moodle activity completion for activities that track completion.';
$string['fellowship_progress_not_tracked'] = 'Activity completion is not enabled (or no activities track completion) for this course yet. Open the Moodle course for the authoritative view.';
$string['fellowship_progress_summary'] = '{$a->done} of {$a->total} tracked activities complete ({$a->percent}%).';
$string['fellowship_complete'] = 'Complete';
$string['fellowship_incomplete'] = 'To do';
$string['fellowship_not_tracked'] = 'Not tracked';
$string['fellowship_native_activities_note'] = 'Quizzes, assignments, and pages open in Moodle. Submit and grade there — this view does not replace those workflows.';
$string['fellowship_section_general'] = 'General';
$string['fellowship_section_numbered'] = 'Section {$a}';
$string['fellowship_help_intro'] = 'Straight answers about how the Fellowship works in Moodle.';
$string['fellowship_faq_internship_q'] = 'Is this a paid internship?';
$string['fellowship_faq_internship_a'] = 'No. LaunchPad is structured learning. No job, internship, placement, or income is promised.';
$string['fellowship_faq_track_q'] = 'Why do I only see certain courses?';
$string['fellowship_faq_track_a'] = 'Your programme team enrols you on LaunchPad courses. Other tracks stay unavailable until you are enrolled.';
$string['fellowship_faq_grade_q'] = 'Where do grades and feedback live?';
$string['fellowship_faq_grade_a'] = 'In the Moodle activities themselves (for example Assign feedback and the gradebook). This dashboard does not invent grades.';
$string['fellowship_faq_quiz_q'] = 'Can I take quizzes here?';
$string['fellowship_faq_quiz_a'] = 'Open the quiz link to use Moodle’s quiz. Attempts and scoring stay in Moodle.';
$string['fellowship_home_lead'] = 'Your assigned LaunchPad courses and the next activity to open in Moodle.';
$string['fellowship_menu'] = 'Menu';
$string['fellowship_skip_to_content'] = 'Skip to content';
$string['fellowship_breadcrumb'] = 'Breadcrumb';
$string['fellowship_no_courses_heading'] = 'No LaunchPad course yet';
$string['fellowship_xp_total_label'] = 'Total XP';
$string['fellowship_xp_title'] = 'XP from your Moodle activity record (lessons, quizzes, submissions). Does not affect grades.';
$string['fellowship_notifications'] = 'Notifications';
$string['fellowship_notifications_unread'] = 'Notifications, {$a} unread';
$string['fellowship_your_journey'] = 'Your journey';
$string['fellowship_open_track'] = 'Open track';
$string['fellowship_journey_aria'] = 'Your journey through four gates';
$string['fellowship_achievements_eyebrow'] = 'Private to you';
$string['fellowship_achievements_lead'] = 'XP, levels and badges come only from work saved to your record. No rankings, no leaderboards — your progress isn’t a competition.';
$string['fellowship_level_path'] = 'Level path';
$string['fellowship_level_now'] = 'Current level:';
$string['fellowship_xp_to_level'] = 'to';
$string['fellowship_level_top'] = 'Top level reached.';
$string['fellowship_badges_heading'] = 'Badges';
$string['fellowship_badges_of'] = 'of';
$string['fellowship_badges_earned'] = 'earned';
$string['fellowship_xp_history'] = 'XP history';
$string['fellowship_xp_history_empty'] = 'Nothing yet. Finish a lesson, quiz or assignment in Moodle to earn your first XP.';
$string['fellowship_xp_provisional_note'] = 'XP and badges refresh from your Moodle completion, quiz and assignment records.';
$string['fellowship_xp_how'] = 'How XP works';
$string['fellowship_xp_how_note'] = 'Each listed action counts once from your Moodle record. XP never affects your grade. Portfolio XP stays unavailable until that feature ships.';
$string['fellowship_xp_when'] = 'When';
$string['fellowship_xp_what'] = 'What you did';
$string['fellowship_xp_col'] = 'XP';
$string['fellowship_xp_log_lesson'] = 'Finished {$a}';
$string['fellowship_xp_log_quiz'] = 'Submitted quiz attempt · {$a}';
$string['fellowship_xp_log_quiz_perfect'] = 'Full marks on {$a}';
$string['fellowship_xp_log_quiz_correct'] = 'Correct answers on {$a}';
$string['fellowship_xp_log_assign'] = 'Submitted assignment · {$a}';
$string['fellowship_xp_log_resubmit'] = 'Resubmitted after feedback · {$a}';
$string['fellowship_xp_log_gate'] = 'Passed Foundations gate';
$string['fellowship_badge_earned_on'] = 'Earned {$a}';
$string['fellowship_badge_earned_recent'] = 'Earned';
$string['fellowship_orbit_aria'] = 'Dot Dot';
$string['fellowship_orbit_dismiss'] = 'Dismiss tip';
$string['fellowship_orbit_hide'] = 'Hide Dot Dot';
$string['fellowship_orbit_show'] = 'Show Dot Dot';
$string['fellowship_orbit_tip_home'] = 'Your <b>next activity</b> is at the top. XP comes from work saved in Moodle.';
$string['fellowship_orbit_tip_track'] = 'Open activities in Moodle. Later phases unlock when the programme releases them.';
$string['fellowship_orbit_tip_progress'] = 'Progress here uses Moodle activity completion when it is enabled.';
$string['fellowship_orbit_tip_achievements'] = 'Levels and badges update from lessons, quizzes and submissions you complete.';
$string['fellowship_orbit_tip_help'] = 'Straight answers only. Contact the programme team if you are stuck.';
