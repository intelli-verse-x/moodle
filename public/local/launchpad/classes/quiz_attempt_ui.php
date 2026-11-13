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

namespace local_launchpad;

defined('MOODLE_INTERNAL') || die();

use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;

/**
 * Fellow quiz attempt inside LaunchPad chrome (Moodle quiz engine underneath).
 *
 * @package    local_launchpad
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class quiz_attempt_ui {

    /**
     * Activity URL for a quiz CM inside the fellow shell.
     *
     * @param array<string,scalar|null> $extra
     */
    public static function activity_url(int $cmid, array $extra = []): \moodle_url {
        $params = array_filter(array_merge([
            'view' => 'activity',
            'cmid' => $cmid,
        ], $extra), static fn($v) => $v !== null && $v !== '');
        return new \moodle_url(persona_home::FELLOW_PATH, $params);
    }

    /**
     * Start a new attempt or resume the unfinished one. Returns attempt id.
     */
    public static function start_or_resume(int $cmid, int $userid): int {
        global $CFG, $USER;

        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $quizobj = quiz_settings::create_for_cmid($cmid, $userid);
        require_capability('mod/quiz:attempt', $quizobj->get_context());

        if (!$quizobj->has_questions()) {
            throw new \moodle_exception('noquestionsfound', 'quiz');
        }

        $timenow = time();
        $accessmanager = $quizobj->get_access_manager($timenow);
        [$currentattemptid, $attemptnumber, $lastattempt, $messages, $page] =
            quiz_validate_new_attempt($quizobj, $accessmanager, false, -1, false);

        if (!$quizobj->is_preview_user() && $messages) {
            throw new \moodle_exception('attempterror', 'quiz', '', implode(' ', $messages));
        }

        if ($currentattemptid) {
            $attemptobj = quiz_attempt::create($currentattemptid);
            if ((int) $attemptobj->get_userid() !== $userid) {
                throw new \moodle_exception('notyourattempt', 'quiz');
            }
            if ($attemptobj->get_state() === quiz_attempt::IN_PROGRESS
                || $attemptobj->get_state() === quiz_attempt::OVERDUE) {
                return (int) $currentattemptid;
            }
        }

        $attempt = quiz_prepare_and_start_new_attempt($quizobj, $attemptnumber, $lastattempt, false);
        return (int) $attempt->id;
    }

    /**
     * Process a posted attempt page (save / next / previous / finish / open summary).
     *
     * @return array{attemptid:int, page:int, finished:bool, summary:bool, state:string}
     */
    public static function process_post(int $cmid, int $userid): array {
        global $CFG;

        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_sesskey();

        $attemptid = required_param('attempt', PARAM_INT);
        $thispage = optional_param('thispage', 0, PARAM_INT);
        $nextpage = optional_param('nextpage', 0, PARAM_INT);
        $previous = optional_param('previous', false, PARAM_BOOL);
        $next = optional_param('next', false, PARAM_BOOL);
        $opensummary = optional_param('opensummary', false, PARAM_BOOL);
        $finishattempt = optional_param('finishattempt', false, PARAM_BOOL);
        $timeup = optional_param('timeup', 0, PARAM_BOOL);

        $attemptobj = quiz_attempt::create($attemptid);
        if ((int) $attemptobj->get_cmid() !== $cmid) {
            throw new \moodle_exception('invalidcoursemodule');
        }
        if ((int) $attemptobj->get_userid() !== $userid) {
            throw new \moodle_exception('notyourattempt', 'quiz');
        }
        if (!$attemptobj->is_preview_user()) {
            $attemptobj->require_capability('mod/quiz:attempt');
        }
        if ($attemptobj->is_finished()) {
            return [
                'attemptid' => $attemptid,
                'page' => 0,
                'finished' => true,
                'summary' => false,
                'state' => quiz_attempt::FINISHED,
            ];
        }

        // Finish from the review-before-submit screen (no thispage question form).
        if ($finishattempt && optional_param('fromsummary', 0, PARAM_INT)) {
            $timenow = time();
            $state = $attemptobj->process_attempt($timenow, true, $timeup, $attemptobj->get_currentpage());
            return [
                'attemptid' => $attemptid,
                'page' => 0,
                'finished' => true,
                'summary' => false,
                'state' => $state,
            ];
        }

        if (!$finishattempt && !$opensummary && !$attemptobj->check_page_access($thispage)) {
            throw new \moodle_exception(
                'submissionoutofsequencefriendlymessage',
                'question',
                self::activity_url($cmid, ['qattempt' => $attemptid, 'page' => $attemptobj->get_currentpage()])
            );
        }

        $timenow = time();
        // Never finish from the last question page — save, then open summary.
        $dofinish = $finishattempt && !$opensummary && (bool) $timeup;
        $state = $attemptobj->process_attempt($timenow, $dofinish, $timeup, $thispage);

        if ($state === quiz_attempt::FINISHED || $state === quiz_attempt::ABANDONED) {
            return [
                'attemptid' => $attemptid,
                'page' => 0,
                'finished' => true,
                'summary' => false,
                'state' => $state,
            ];
        }

        if ($opensummary || ($next && (int) $nextpage < 0)) {
            return [
                'attemptid' => $attemptid,
                'page' => $thispage,
                'finished' => false,
                'summary' => true,
                'state' => $state,
            ];
        }

        if ($next) {
            $page = $nextpage;
        } else if ($previous && $thispage > 0) {
            $page = $thispage - 1;
        } else {
            $page = $thispage;
        }
        if ($page < 0) {
            $page = 0;
        }

        return [
            'attemptid' => $attemptid,
            'page' => (int) $page,
            'finished' => false,
            'summary' => false,
            'state' => $state,
        ];
    }

    /**
     * Mustache context for an in-progress attempt page.
     *
     * @return array<string,mixed>
     */
    public static function export_attempt(int $cmid, int $attemptid, int $page, int $userid): array {
        global $CFG, $PAGE, $OUTPUT;

        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $attemptobj = quiz_attempt::create($attemptid);
        if ((int) $attemptobj->get_cmid() !== $cmid) {
            throw new \moodle_exception('invalidcoursemodule');
        }
        if ((int) $attemptobj->get_userid() !== $userid) {
            throw new \moodle_exception('notyourattempt', 'quiz');
        }
        if ($attemptobj->is_finished()) {
            return self::export_review($cmid, $attemptid, $userid);
        }

        $page = $attemptobj->force_page_number_into_range($page);
        $attemptobj->set_currentpage($page);

        $accessmanager = $attemptobj->get_access_manager(time());
        $messages = $accessmanager->prevent_access();
        if (!$attemptobj->is_preview_user() && $messages) {
            throw new \moodle_exception('attempterror', 'quiz', '', implode(' ', $messages));
        }

        // Fake URL so question renderers can resolve page requirements.
        $PAGE->set_url(self::activity_url($cmid, ['qattempt' => $attemptid, 'page' => $page]));
        $renderer = $PAGE->get_renderer('mod_quiz');

        $slots = $attemptobj->get_slots($page);
        $questions = [];
        foreach ($slots as $slot) {
            $html = $attemptobj->render_question($slot, false, $renderer);
            $questions[] = [
                'slot' => (int) $slot,
                'html' => $html,
            ];
        }

        $numpages = $attemptobj->get_num_pages();
        $islast = $attemptobj->is_last_page($page);
        $nextpage = $islast ? -1 : ($page + 1);
        $quiz = $attemptobj->get_quiz();
        $timelimit = (int) ($quiz->timelimit ?? 0);
        $timestart = (int) $attemptobj->get_attempt()->timestart;
        $timeleft = 0;
        $hastimer = false;
        if ($timelimit > 0) {
            $hastimer = true;
            $timeleft = max(0, ($timestart + $timelimit) - time());
        }

        $attemptobj->fire_attempt_viewed_event();

        return [
            'quiz_attempting' => true,
            'quiz_summarising' => false,
            'quiz_reviewing' => false,
            'quiz_needsengine' => false,
            'attemptid' => $attemptid,
            'attempt_page' => $page,
            'attempt_nextpage' => $nextpage,
            'attempt_is_last' => $islast,
            'attempt_has_prev' => $page > 0,
            'attempt_page_label' => get_string(
                'fellowship_quiz_page_of',
                'local_launchpad',
                (object) ['page' => $page + 1, 'total' => max(1, $numpages)]
            ),
            'attempt_questions' => $questions,
            'attempt_has_questions' => $questions !== [],
            'attempt_form_action' => self::activity_url($cmid)->out(false),
            'attempt_sesskey' => sesskey(),
            'attempt_cmid' => $cmid,
            'attempt_has_timer' => $hastimer,
            'attempt_timeleft' => $timeleft,
            'attempt_timeleft_label' => $hastimer
                ? get_string('fellowship_quiz_timeleft', 'local_launchpad', format_time($timeleft))
                : '',
            'end_code' => $PAGE->requires->get_end_code(),
        ];
    }

    /**
     * Review-before-submit: answered / not answered / flagged, jump back to any question.
     *
     * @return array<string,mixed>
     */
    public static function export_summary(int $cmid, int $attemptid, int $userid): array {
        global $CFG;

        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $attemptobj = quiz_attempt::create($attemptid);
        if ((int) $attemptobj->get_cmid() !== $cmid) {
            throw new \moodle_exception('invalidcoursemodule');
        }
        if ((int) $attemptobj->get_userid() !== $userid) {
            throw new \moodle_exception('notyourattempt', 'quiz');
        }
        if ($attemptobj->is_finished()) {
            return self::export_review($cmid, $attemptid, $userid);
        }

        $rows = [];
        $flaggedcount = 0;
        $unansweredcount = 0;
        foreach ($attemptobj->get_slots() as $slot) {
            if (!$attemptobj->is_real_question($slot)) {
                continue;
            }
            $state = $attemptobj->get_question_state($slot);
            // Still todo/invalid = not answered yet; anything else counts as answered for the summary.
            $answered = !($state == \question_state::$todo || $state == \question_state::$invalid);
            $flagged = $attemptobj->is_question_flagged($slot);
            if ($flagged) {
                $flaggedcount++;
            }
            if (!$answered) {
                $unansweredcount++;
            }
            $page = (int) $attemptobj->get_question_page($slot);
            $qno = $attemptobj->get_question_number($slot);
            $statuskey = $flagged ? 'flagged' : ($answered ? 'answered' : 'todo');
            $statuslabel = get_string('fellowship_quiz_sum_' . $statuskey, 'local_launchpad');
            $rows[] = [
                'slot' => (int) $slot,
                'number' => $qno,
                'number_label' => get_string('fellowship_quiz_sum_q', 'local_launchpad', $qno),
                'answered' => $answered,
                'flagged' => $flagged,
                'status_key' => $statuskey,
                'status_label' => $statuslabel,
                'jump_url' => self::activity_url($cmid, [
                    'qattempt' => $attemptid,
                    'page' => $page,
                ])->out(false),
            ];
        }

        $quiz = $attemptobj->get_quiz();
        $timelimit = (int) ($quiz->timelimit ?? 0);
        $timestart = (int) $attemptobj->get_attempt()->timestart;
        $timeleft = 0;
        $hastimer = false;
        if ($timelimit > 0) {
            $hastimer = true;
            $timeleft = max(0, ($timestart + $timelimit) - time());
        }

        return [
            'quiz_attempting' => false,
            'quiz_summarising' => true,
            'quiz_reviewing' => false,
            'quiz_needsengine' => false,
            'attemptid' => $attemptid,
            'summary_rows' => $rows,
            'summary_has_rows' => $rows !== [],
            'summary_flagged_count' => $flaggedcount,
            'summary_unanswered_count' => $unansweredcount,
            'summary_flagged_label' => get_string(
                'fellowship_quiz_sum_flagged_n',
                'local_launchpad',
                $flaggedcount
            ),
            'summary_unanswered_label' => get_string(
                'fellowship_quiz_sum_unanswered_n',
                'local_launchpad',
                $unansweredcount
            ),
            'summary_form_action' => self::activity_url($cmid)->out(false),
            'summary_sesskey' => sesskey(),
            'summary_cmid' => $cmid,
            'summary_back_url' => self::activity_url($cmid, [
                'qattempt' => $attemptid,
                'page' => (int) $attemptobj->get_currentpage(),
            ])->out(false),
            'attempt_has_timer' => $hastimer,
            'attempt_timeleft' => $timeleft,
            'attempt_timeleft_label' => $hastimer
                ? get_string('fellowship_quiz_timeleft', 'local_launchpad', format_time($timeleft))
                : '',
        ];
    }

    /**
     * Mustache context for a finished attempt review (when quiz allows it).
     *
     * @return array<string,mixed>
     */
    public static function export_review(int $cmid, int $attemptid, int $userid): array {
        global $CFG, $PAGE;

        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->libdir . '/gradelib.php');

        $attemptobj = quiz_attempt::create($attemptid);
        if ((int) $attemptobj->get_cmid() !== $cmid) {
            throw new \moodle_exception('invalidcoursemodule');
        }
        if ((int) $attemptobj->get_userid() !== $userid) {
            throw new \moodle_exception('notyourattempt', 'quiz');
        }

        $PAGE->set_url(self::activity_url($cmid, ['qreview' => $attemptid]));
        $renderer = $PAGE->get_renderer('mod_quiz');

        $options = $attemptobj->get_display_options(true);
        $canreview = $attemptobj->check_review_capability() && $attemptobj->is_finished();

        $questions = [];
        $gradehtml = '';
        $hasgrade = false;
        $summary = get_string('fellowship_quiz_submitted', 'local_launchpad');

        if ($canreview && $options->attempt) {
            foreach ($attemptobj->get_slots() as $slot) {
                $questions[] = [
                    'slot' => (int) $slot,
                    'html' => $attemptobj->render_question($slot, true, $renderer),
                ];
            }
        }

        $quiz = $attemptobj->get_quiz();
        $grades = grade_get_grades(
            $attemptobj->get_courseid(),
            'mod',
            'quiz',
            (int) $quiz->id,
            $userid
        );
        $gg = $grades->items[0]->grades[$userid] ?? null;
        if ($gg && $gg->grade !== null && !empty($options->marks)) {
            $hasgrade = true;
            $gradehtml = format_float((float) $gg->grade, 1)
                . ' / '
                . format_float((float) ($quiz->grade ?? 0), 1);
            $summary = get_string('fellowship_quiz_score_summary', 'local_launchpad', $gradehtml);
        }

        return [
            'quiz_attempting' => false,
            'quiz_summarising' => false,
            'quiz_reviewing' => true,
            'quiz_needsengine' => false,
            'attemptid' => $attemptid,
            'review_summary' => $summary,
            'review_has_grade' => $hasgrade,
            'review_grade' => $gradehtml,
            'review_questions' => $questions,
            'review_has_questions' => $questions !== [],
            'review_back_url' => self::activity_url($cmid)->out(false),
            'end_code' => $PAGE->requires->get_end_code(),
        ];
    }

    /**
     * CTA flags for the quiz brief (no Moodle handoff).
     *
     * @return array<string,mixed>
     */
    public static function brief_actions(int $cmid, int $quizid, int $userid): array {
        global $CFG;

        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $finished = quiz_get_user_attempts($quizid, $userid, 'finished', true);
        $unfinished = quiz_get_user_attempts($quizid, $userid, 'unfinished', true);
        $quiz = quiz_settings::create($quizid, $userid)->get_quiz();
        $maxattempts = (int) ($quiz->attempts ?? 0);
        $finishedcount = count($finished);
        $hasinprogress = $unfinished !== [];
        $exhausted = $maxattempts > 0 && $finishedcount >= $maxattempts && !$hasinprogress;
        $hasquestions = (bool) quiz_settings::create($quizid, $userid)->has_questions();

        $starturl = '';
        $continueurl = '';
        $reviewurl = '';
        $canstart = false;
        $cancontinue = false;
        $canreview = false;

        if ($hasinprogress) {
            $open = reset($unfinished);
            $cancontinue = true;
            $continueurl = self::activity_url($cmid, [
                'qattempt' => (int) $open->id,
                'page' => (int) ($open->currentpage ?? 0),
            ])->out(false);
        } else if (!$exhausted && $hasquestions) {
            $canstart = true;
            $starturl = self::activity_url($cmid, ['qstart' => 1])->out(false);
        }

        if ($finished !== []) {
            $last = end($finished);
            $canreview = true;
            $reviewurl = self::activity_url($cmid, ['qreview' => (int) $last->id])->out(false);
        }

        return [
            'quiz_needsengine' => false,
            'quiz_can_start' => $canstart,
            'quiz_start_url' => $starturl,
            'quiz_can_continue' => $cancontinue,
            'quiz_continue_url' => $continueurl,
            'quiz_can_review' => $canreview,
            'quiz_review_url' => $reviewurl,
            'quiz_no_questions' => !$hasquestions,
            'quiz_exhausted' => $exhausted,
        ];
    }
}
