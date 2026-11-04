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

/**
 * LaunchPad activity viewer — Moodle CM data in portal shells.
 *
 * Page / URL / resource render inside the shell. Interactive modules
 * (quiz, assign, …) keep Moodle engines but open from this consistent chrome.
 * Used by Employer (`activity.php`) and Fellowship (`fellow.php?view=activity`).
 *
 * @package    local_launchpad
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class activity_portal {

    /** Mods rendered fully inside the portal shell. */
    private const PORTAL_RENDER = ['page', 'url', 'resource'];

    /**
     * Employer portal activity URL for a course module.
     */
    public static function url(int $cmid): string {
        return (new \moodle_url('/local/launchpad/activity.php', ['id' => $cmid]))->out(false);
    }

    /**
     * Fellowship portal activity URL for a course module.
     */
    public static function fellow_url(int $cmid): string {
        return (new \moodle_url(persona_home::FELLOW_PATH, [
            'view' => 'activity',
            'cmid' => $cmid,
        ]))->out(false);
    }

    /**
     * Whether this modname is rendered inside the portal (not Moodle chrome).
     */
    public static function is_portal_renderable(string $modname): bool {
        return in_array($modname, self::PORTAL_RENDER, true);
    }

    /**
     * Rewrite a workspace activity row URL to the Employer portal activity page.
     *
     * @param array<string,mixed> $activity
     * @return array<string,mixed>
     */
    public static function rewrite_row(array $activity): array {
        $cmid = (int) ($activity['id'] ?? $activity['cmid'] ?? 0);
        if ($cmid > 0) {
            $activity['url'] = self::url($cmid);
            $activity['portal'] = true;
        }
        return $activity;
    }

    /**
     * Rewrite a Fellowship track/home activity row to the fellow activity shell.
     *
     * @param array<string,mixed> $activity
     * @return array<string,mixed>
     */
    public static function rewrite_fellow_row(array $activity): array {
        $cmid = (int) ($activity['cmid'] ?? $activity['id'] ?? 0);
        if ($cmid > 0) {
            $activity['url'] = self::fellow_url($cmid);
            $activity['portal'] = true;
        }
        return $activity;
    }

    /**
     * Build Mustache context for one CM inside a LaunchPad shell.
     *
     * @param \stdClass $user
     * @param int $cmid
     * @param array{courseurl?:string} $options Optional courseurl override (fellow track vs employee course).
     * @return array<string,mixed>
     */
    public static function export(\stdClass $user, int $cmid, array $options = []): array {
        global $CFG, $DB;

        require_once($CFG->libdir . '/completionlib.php');
        require_once($CFG->libdir . '/filelib.php');

        $cmrecord = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        $course = get_course((int) $cmrecord->course);
        require_course_login($course, true, $cmrecord);

        $modinfo = get_fast_modinfo($course, (int) $user->id);
        $cm = $modinfo->get_cm($cmid);
        if (!$cm->uservisible) {
            throw new \moodle_exception('activityiscurrentlyhidden');
        }

        $modname = $cm->modname;
        $context = \context_module::instance($cm->id);
        if (!empty($options['courseurl'])) {
            $courseurl = (string) $options['courseurl'];
        } else {
            $courseurl = (new \moodle_url('/local/launchpad/employee.php', [
                'view' => 'course',
                'id' => (int) $course->id,
            ]))->out(false);
        }
        $moodleurl = $cm->url
            ? $cm->url->out(false)
            : (new \moodle_url('/mod/' . $modname . '/view.php', ['id' => $cm->id]))->out(false);

        $completion = new \completion_info($course);
        $tracked = false;
        $complete = false;
        if ($completion->is_enabled($cm) != COMPLETION_TRACKING_NONE) {
            $tracked = true;
            $data = $completion->get_data($cm, false, (int) $user->id);
            $complete = ((int) $data->completionstate === COMPLETION_COMPLETE
                || (int) $data->completionstate === COMPLETION_COMPLETE_PASS);
        }

        $typename = method_exists($cm, 'get_module_type_name')
            ? (string) $cm->get_module_type_name()
            : get_string('modulename', $modname);
        $title = $cm->get_formatted_name();
        // Figma card: primary title + grey subtitle in brackets.
        $titleplain = trim(html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $titlemain = $titleplain;
        $titlesub = '';
        if (preg_match('/^(.*?)\s*(\[[^\]]+\])\s*$/u', $titleplain, $m)) {
            $titlemain = trim($m[1]);
            $titlesub = trim($m[2]);
        }

        $bodyhtml = '';
        $introhtml = '';
        $hasintro = false;
        $externalurl = '';
        $hasexternal = false;
        $embedhtml = '';
        $hasembed = false;
        $videourl = '';
        $hasvideo = false;
        $files = [];
        $hasfiles = false;
        $isinportal = self::is_portal_renderable($modname);
        $needsengine = !$isinportal;
        $callouttext = '';
        $assignbrief = self::empty_assign_brief();
        $quizbrief = self::empty_quiz_brief();

        if ($modname === 'page') {
            require_capability('mod/page:view', $context);
            require_once($CFG->dirroot . '/mod/page/lib.php');
            $page = $DB->get_record('page', ['id' => $cm->instance], '*', MUST_EXIST);
            // Same completion / events as mod/page/view.php.
            page_view($page, $course, $cm, $context);

            $content = file_rewrite_pluginfile_urls(
                $page->content,
                'pluginfile.php',
                $context->id,
                'mod_page',
                'content',
                $page->revision
            );
            $formatoptions = new \stdClass();
            $formatoptions->noclean = true;
            $formatoptions->overflowdiv = true;
            $formatoptions->context = $context;
            $bodyhtml = format_text($content, (int) $page->contentformat, $formatoptions);
            $callouttext = trim(html_entity_decode(strip_tags($bodyhtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if (!empty($page->intro)) {
                $introhtml = format_module_intro('page', $page, $cm->id);
                $hasintro = trim(strip_tags($introhtml)) !== '';
            }
        } else if ($modname === 'url') {
            require_capability('mod/url:view', $context);
            require_once($CFG->dirroot . '/mod/url/lib.php');
            $urlmod = $DB->get_record('url', ['id' => $cm->instance], '*', MUST_EXIST);
            if (function_exists('url_view')) {
                url_view($urlmod, $course, $cm, $context);
            } else {
                $completion->set_module_viewed($cm);
            }
            // Only expose http(s) destinations in the portal shell.
            $rawurl = clean_param((string) ($urlmod->externalurl ?? ''), PARAM_URL);
            $scheme = $rawurl !== '' ? strtolower((string) (parse_url($rawurl, PHP_URL_SCHEME) ?? '')) : '';
            if ($rawurl !== '' && in_array($scheme, ['http', 'https'], true)) {
                $externalurl = $rawurl;
                $hasexternal = true;
                $embedhtml = self::video_embed_html($rawurl);
                $hasembed = $embedhtml !== '';
            }
            if (!empty($urlmod->intro)) {
                $introhtml = format_module_intro('url', $urlmod, $cm->id);
                $hasintro = trim(strip_tags($introhtml)) !== '';
            }
        } else if ($modname === 'resource') {
            require_capability('mod/resource:view', $context);
            require_once($CFG->dirroot . '/mod/resource/lib.php');
            $resource = $DB->get_record('resource', ['id' => $cm->instance], '*', MUST_EXIST);
            if (function_exists('resource_view')) {
                resource_view($resource, $course, $cm, $context);
            } else {
                $completion->set_module_viewed($cm);
            }
            if (!empty($resource->intro)) {
                $introhtml = format_module_intro('resource', $resource, $cm->id);
                $hasintro = trim(strip_tags($introhtml)) !== '';
            }
            $fs = get_file_storage();
            $stored = $fs->get_area_files($context->id, 'mod_resource', 'content', 0, 'sortorder DESC, id ASC', false);
            foreach ($stored as $file) {
                if ($file->is_directory()) {
                    continue;
                }
                $mimetype = (string) $file->get_mimetype();
                $isvideo = str_starts_with($mimetype, 'video/');
                $streamurl = \moodle_url::make_pluginfile_url(
                    $context->id,
                    'mod_resource',
                    'content',
                    $file->get_itemid(),
                    $file->get_filepath(),
                    $file->get_filename(),
                    false
                )->out(false);
                $downloadurl = \moodle_url::make_pluginfile_url(
                    $context->id,
                    'mod_resource',
                    'content',
                    $file->get_itemid(),
                    $file->get_filepath(),
                    $file->get_filename(),
                    true
                )->out(false);
                // First video file plays in-shell (Udemy-style showcase player).
                if ($isvideo && !$hasvideo) {
                    $hasvideo = true;
                    $videourl = $streamurl;
                }
                $files[] = [
                    'filename' => $file->get_filename(),
                    'url' => $downloadurl,
                    'size' => display_size($file->get_filesize()),
                    'isvideo' => $isvideo,
                ];
            }
            $hasfiles = $files !== [];
        } else if ($modname === 'assign') {
            // E9: LaunchPad brief chrome; draft/submit stay in Moodle.
            require_capability('mod/assign:view', $context);
            $needsengine = true;
            $isinportal = false;
            $assignbrief = self::build_assign_brief($cm, $course, $context, (int) $user->id);
        } else if ($modname === 'quiz') {
            // E8: LaunchPad quiz intro chrome; attempt UI stays in Moodle.
            require_capability('mod/quiz:view', $context);
            $needsengine = true;
            $isinportal = false;
            $quizbrief = self::build_quiz_brief($cm, $course, $context, (int) $user->id);
        } else {
            // Interactive Moodle engines — keep completion for the native mod view.
            $needsengine = true;
            $isinportal = false;
        }

        if ($tracked && $complete) {
            $status = get_string('employer_activity_complete', 'local_launchpad');
            $statustone = 'ok';
        } else if ($tracked) {
            $status = get_string('employer_activity_incomplete', 'local_launchpad');
            $statustone = 'run';
        } else {
            $status = get_string('employer_activity_not_tracked', 'local_launchpad');
            $statustone = 'line';
        }

        $sectionname = '';
        $sectionnum = (int) $cm->sectionnum;
        if ($sectionnum > 0) {
            $sectionname = get_section_name($course, $sectionnum);
        }

        $hasbody = trim(strip_tags($bodyhtml)) !== '';
        // Placeholder only when there is nothing to show.
        $isplaceholder = !$hasbody && !$hasfiles && !$hasexternal && !$hasembed && !$hasvideo;

        // E7: structured lesson layout for page activities (text works without video).
        $islesson = ($modname === 'page' && !$isplaceholder);
        $lesson = $islesson
            ? self::build_lesson_layout($bodyhtml, $introhtml, $hasintro, $course, $cm, (int) $user->id, $courseurl)
            : self::empty_lesson_layout();

        return [
            'is_activity' => true,
            'is_home' => false,
            'is_courses' => false,
            'is_course' => false,
            'pagetitle' => format_string($title),
            'activity' => array_merge([
                'cmid' => (int) $cm->id,
                'name' => format_string($title),
                // Plain strings — Mustache {{ }} escapes; do not pre-s().
                'title_main' => $titlemain !== '' ? $titlemain : $titleplain,
                'title_sub' => $titlesub,
                'hastitlesub' => $titlesub !== '',
                'modname' => format_string($typename),
                'modtype' => $modname,
                'mod_icon' => self::mod_icon($modname),
                'courseid' => (int) $course->id,
                'coursename' => format_string($course->fullname),
                'courseurl' => $courseurl,
                'sectionname' => format_string($sectionname),
                'hassection' => $sectionname !== '',
                'section_anchor' => $sectionnum > 0 ? 'section-' . $sectionnum : '',
                'moodleurl' => $moodleurl,
                'status' => $status,
                'statustone' => $statustone,
                'tracked' => $tracked,
                'complete' => $complete,
                'bodyhtml' => $bodyhtml,
                // When lesson layout is on, body is shown in sections — avoid double dump.
                'hasbody' => $hasbody && !$isplaceholder && !$islesson,
                'callouttext' => $callouttext,
                'hascallout' => $isplaceholder && $callouttext !== '',
                'isplaceholder' => $isplaceholder,
                'introhtml' => $introhtml,
                'hasintro' => $hasintro && !$isplaceholder && !$islesson,
                'externalurl' => $externalurl,
                'hasexternal' => $hasexternal,
                'embedhtml' => $embedhtml,
                'hasembed' => $hasembed,
                'videourl' => $videourl,
                'hasvideo' => $hasvideo,
                'files' => $files,
                'hasfiles' => $hasfiles && !$hasvideo, // When playing video, don't duplicate as a file list.
                'isinportal' => $isinportal,
                'needsengine' => $needsengine,
                'is_lesson' => $islesson,
            ], $lesson, $assignbrief, $quizbrief),
        ];
    }

    /**
     * Empty quiz brief context (non-quiz activities).
     *
     * @return array<string,mixed>
     */
    public static function empty_quiz_brief(): array {
        return [
            'is_quiz' => false,
            'quiz_brief_html' => '',
            'quiz_has_brief' => false,
            'quiz_practice_note' => '',
            'quiz_attempts_label' => '',
            'quiz_has_attempts' => false,
            'quiz_timelimit' => '',
            'quiz_has_timelimit' => false,
            'quiz_opens' => '',
            'quiz_has_opens' => false,
            'quiz_closes' => '',
            'quiz_has_closes' => false,
            'quiz_grade_max' => '',
            'quiz_has_grade' => false,
            'quiz_questions' => '',
            'quiz_has_questions' => false,
            'quiz_attempt_status' => '',
            'quiz_attempt_status_key' => '',
            'quiz_has_attempt_status' => false,
            'quiz_best_grade' => '',
            'quiz_has_best_grade' => false,
            'quiz_track_label' => '',
            'quiz_phase_label' => '',
            'quiz_has_phase' => false,
        ];
    }

    /**
     * Fellow-facing quiz intro for LaunchPad chrome (attempt stays in Moodle).
     *
     * @return array<string,mixed>
     */
    public static function build_quiz_brief(
        \cm_info $cm,
        \stdClass $course,
        \context_module $context,
        int $userid
    ): array {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->libdir . '/gradelib.php');

        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);

        $introhtml = '';
        if (!empty($quiz->intro)) {
            $introhtml = format_module_intro('quiz', $quiz, $cm->id);
        }
        $parts = self::parse_quiz_intro_sections((string) ($quiz->intro ?? ''));
        $briefhtml = $parts['brief_html'];
        if ($briefhtml === '' && $introhtml !== '') {
            $briefhtml = $introhtml;
        } else if ($briefhtml !== '') {
            $briefhtml = format_text($briefhtml, FORMAT_HTML, ['context' => $context, 'para' => true]);
        }

        $maxattempts = (int) ($quiz->attempts ?? 0); // 0 = unlimited.
        $finished = quiz_get_user_attempts((int) $quiz->id, $userid, 'finished', true);
        $unfinished = quiz_get_user_attempts((int) $quiz->id, $userid, 'unfinished', true);
        $finishedcount = count($finished);
        $hasinprogress = $unfinished !== [];

        if ($maxattempts === 0) {
            $attemptslabel = get_string('fellowship_quiz_attempts_unlimited', 'local_launchpad', $finishedcount);
        } else {
            $attemptslabel = get_string('fellowship_quiz_attempts_of', 'local_launchpad', (object) [
                'used' => $finishedcount,
                'max' => $maxattempts,
            ]);
        }

        $statuskey = 'none';
        if ($hasinprogress) {
            $statuskey = 'inprogress';
        } else if ($finishedcount > 0) {
            $statuskey = 'finished';
        }
        if ($maxattempts > 0 && $finishedcount >= $maxattempts && !$hasinprogress) {
            $statuskey = 'exhausted';
        }
        $statuslabel = get_string('fellowship_quiz_status_' . $statuskey, 'local_launchpad');

        $bestgrade = '';
        $hasbest = false;
        if ($finished !== []) {
            $grades = grade_get_grades($course->id, 'mod', 'quiz', (int) $quiz->id, $userid);
            $gg = $grades->items[0]->grades[$userid] ?? null;
            if ($gg && $gg->grade !== null) {
                $bestgrade = format_float((float) $gg->grade, 1);
                $hasbest = true;
            }
        }

        $timelimit = (int) ($quiz->timelimit ?? 0);
        $timeopen = (int) ($quiz->timeopen ?? 0);
        $timeclose = (int) ($quiz->timeclose ?? 0);
        $grademax = (float) ($quiz->grade ?? 0);

        $qcount = (int) $DB->count_records('quiz_slots', ['quizid' => (int) $quiz->id]);

        $sectionname = '';
        if ((int) $cm->sectionnum > 0) {
            $sectionname = get_section_name($course, (int) $cm->sectionnum);
        }

        return [
            'is_quiz' => true,
            'quiz_brief_html' => $briefhtml,
            'quiz_has_brief' => trim(strip_tags($briefhtml)) !== '',
            'quiz_practice_note' => get_string('fellowship_quiz_practice_note', 'local_launchpad'),
            'quiz_attempts_label' => $attemptslabel,
            'quiz_has_attempts' => true,
            'quiz_timelimit' => $timelimit > 0
                ? format_time($timelimit)
                : get_string('fellowship_quiz_timelimit_none', 'local_launchpad'),
            'quiz_has_timelimit' => true,
            'quiz_timelimit_set' => $timelimit > 0,
            'quiz_opens' => $timeopen > 0
                ? userdate($timeopen, get_string('strftimedatetimeshort', 'langconfig'))
                : '',
            'quiz_has_opens' => $timeopen > 0,
            'quiz_closes' => $timeclose > 0
                ? userdate($timeclose, get_string('strftimedatetimeshort', 'langconfig'))
                : get_string('fellowship_quiz_closes_none', 'local_launchpad'),
            'quiz_has_closes' => true,
            'quiz_closes_set' => $timeclose > 0,
            'quiz_grade_max' => $grademax > 0 ? format_float($grademax, 0) : '',
            'quiz_has_grade' => $grademax > 0,
            'quiz_questions' => $qcount > 0
                ? get_string('fellowship_quiz_questions_n', 'local_launchpad', $qcount)
                : get_string('fellowship_quiz_questions_none', 'local_launchpad'),
            'quiz_has_questions' => true,
            'quiz_attempt_status' => $statuslabel,
            'quiz_attempt_status_key' => $statuskey,
            'quiz_has_attempt_status' => true,
            'quiz_best_grade' => $bestgrade,
            'quiz_has_best_grade' => $hasbest,
            'quiz_track_label' => format_string($course->fullname),
            'quiz_phase_label' => format_string($sectionname),
            'quiz_has_phase' => $sectionname !== '',
        ];
    }

    /**
     * Optional structured quiz intro markers.
     *
     * @return array{brief_html:string}
     */
    public static function parse_quiz_intro_sections(string $raw): array {
        $out = ['brief_html' => ''];
        $raw = trim($raw);
        if ($raw === '') {
            return $out;
        }
        if (preg_match('/\[LP-BRIEF\]/i', $raw)) {
            $parts = preg_split('/\[LP-BRIEF\]/i', $raw, 2);
            $out['brief_html'] = self::cleanup_lesson_chunk(trim($parts[1] ?? ''));
            return $out;
        }
        return $out;
    }

    /**
     * Empty assign brief context (non-assign activities).
     *
     * @return array<string,mixed>
     */
    public static function empty_assign_brief(): array {
        return [
            'is_assign' => false,
            'assign_brief_html' => '',
            'assign_has_brief' => false,
            'assign_deliverable' => '',
            'assign_has_deliverable' => false,
            'assign_criteria_html' => '',
            'assign_has_criteria' => false,
            'assign_formats' => '',
            'assign_has_formats' => false,
            'assign_deadline' => '',
            'assign_has_deadline' => false,
            'assign_opens' => '',
            'assign_has_opens' => false,
            'assign_grade_max' => '',
            'assign_has_grade' => false,
            'assign_pass_bar' => '70',
            'assign_sub_status' => '',
            'assign_sub_status_key' => '',
            'assign_has_sub_status' => false,
            'assign_track_label' => '',
            'assign_phase_label' => '',
        ];
    }

    /**
     * Fellow-facing assignment brief for LaunchPad chrome (submit stays in Moodle).
     *
     * @return array<string,mixed>
     */
    public static function build_assign_brief(
        \cm_info $cm,
        \stdClass $course,
        \context_module $context,
        int $userid
    ): array {
        global $CFG;

        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        $assign = new \assign($context, $cm, $course);
        $instance = $assign->get_instance();

        $introhtml = '';
        if (!empty($instance->intro)) {
            $introhtml = format_module_intro('assign', $instance, $cm->id);
        }
        $parts = self::parse_assign_intro_sections($introhtml !== '' ? (string) $instance->intro : '');
        // Prefer formatted intro as brief when no markers; re-format marker chunks.
        $briefhtml = $parts['brief_html'];
        $criteriahtml = $parts['criteria_html'];
        $deliverable = $parts['deliverable'];
        $formatoverride = $parts['formats'];

        if ($briefhtml === '' && $introhtml !== '') {
            $briefhtml = $introhtml;
        } else if ($briefhtml !== '') {
            $briefhtml = format_text($briefhtml, FORMAT_HTML, ['context' => $context, 'para' => true]);
        }
        if ($criteriahtml !== '') {
            $criteriahtml = format_text($criteriahtml, FORMAT_HTML, ['context' => $context, 'para' => true]);
        } else {
            // Always show judging baseline when content did not publish criteria.
            $criteriahtml = format_text(
                get_string('fellowship_assign_criteria_default', 'local_launchpad'),
                FORMAT_MOODLE,
                ['context' => $context, 'para' => true]
            );
        }
        if ($deliverable === '') {
            $deliverable = get_string('fellowship_assign_deliverable_default', 'local_launchpad');
        }

        $formats = [];
        if ($formatoverride !== '') {
            $formats[] = $formatoverride;
        } else {
            foreach (['onlinetext' => 'fellowship_assign_format_onlinetext',
                'file' => 'fellowship_assign_format_file'] as $plugin => $str) {
                $p = $assign->get_submission_plugin_by_type($plugin);
                if ($p && $p->is_enabled()) {
                    $formats[] = get_string($str, 'local_launchpad');
                }
            }
        }
        $formatslabel = $formats !== [] ? implode(' · ', $formats)
            : get_string('fellowship_assign_format_unknown', 'local_launchpad');

        $duedate = (int) ($instance->duedate ?? 0);
        $opendate = (int) ($instance->allowsubmissionsfromdate ?? 0);
        $grademax = (float) ($instance->grade ?? 0);

        $submission = $assign->get_user_submission($userid, false);
        $substatus = $submission ? (string) $submission->status : '';
        $statuskey = 'none';
        if ($substatus === 'submitted') {
            $statuskey = 'submitted';
        } else if ($substatus === 'draft') {
            $statuskey = 'draft';
        } else if ($substatus === 'reopened') {
            $statuskey = 'reopened';
        } else if ($substatus === 'new' || $substatus === '') {
            $statuskey = 'none';
        }
        $statuslabel = get_string('fellowship_assign_status_' . $statuskey, 'local_launchpad');

        $sectionname = '';
        if ((int) $cm->sectionnum > 0) {
            $sectionname = get_section_name($course, (int) $cm->sectionnum);
        }

        return [
            'is_assign' => true,
            'assign_brief_html' => $briefhtml,
            'assign_has_brief' => trim(strip_tags($briefhtml)) !== '',
            'assign_deliverable' => $deliverable,
            'assign_has_deliverable' => $deliverable !== '',
            'assign_criteria_html' => $criteriahtml,
            'assign_has_criteria' => trim(strip_tags($criteriahtml)) !== '',
            'assign_formats' => $formatslabel,
            'assign_has_formats' => $formatslabel !== '',
            'assign_deadline' => $duedate > 0
                ? userdate($duedate, get_string('strftimedatetimeshort', 'langconfig'))
                : get_string('fellowship_assign_deadline_none', 'local_launchpad'),
            'assign_has_deadline' => true,
            'assign_deadline_set' => $duedate > 0,
            'assign_opens' => $opendate > 0
                ? userdate($opendate, get_string('strftimedatetimeshort', 'langconfig'))
                : '',
            'assign_has_opens' => $opendate > 0,
            'assign_grade_max' => $grademax > 0 ? format_float($grademax, 0) : '',
            'assign_has_grade' => $grademax > 0,
            'assign_pass_bar' => '70',
            'assign_sub_status' => $statuslabel,
            'assign_sub_status_key' => $statuskey,
            'assign_has_sub_status' => true,
            'assign_track_label' => format_string($course->fullname),
            'assign_phase_label' => format_string($sectionname),
            'assign_has_phase' => $sectionname !== '',
        ];
    }

    /**
     * Optional structured assign intro markers.
     *
     * @return array{brief_html:string,deliverable:string,criteria_html:string,formats:string}
     */
    public static function parse_assign_intro_sections(string $raw): array {
        $out = [
            'brief_html' => '',
            'deliverable' => '',
            'criteria_html' => '',
            'formats' => '',
        ];
        $raw = trim($raw);
        if ($raw === '' || !preg_match('/\[LP-(BRIEF|DELIVERABLE|CRITERIA|FORMAT)\]/i', $raw)) {
            return $out;
        }
        $map = [
            'BRIEF' => 'brief_html',
            'DELIVERABLE' => 'deliverable',
            'CRITERIA' => 'criteria_html',
            'FORMAT' => 'formats',
        ];
        $parts = preg_split('/\[LP-(BRIEF|DELIVERABLE|CRITERIA|FORMAT)\]/i', $raw, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false || count($parts) < 3) {
            return $out;
        }
        for ($i = 1; $i < count($parts) - 1; $i += 2) {
            $label = strtoupper(trim($parts[$i]));
            $chunk = trim($parts[$i + 1] ?? '');
            if (!isset($map[$label]) || $chunk === '') {
                continue;
            }
            $key = $map[$label];
            if ($key === 'deliverable' || $key === 'formats') {
                $out[$key] = trim(html_entity_decode(strip_tags($chunk), ENT_QUOTES | ENT_HTML5));
            } else {
                $out[$key] = self::cleanup_lesson_chunk($chunk);
            }
        }
        return $out;
    }

    /**
     * Empty lesson context (non-page / placeholder).
     *
     * @return array<string,mixed>
     */
    public static function empty_lesson_layout(): array {
        return [
            'lesson_structured' => false,
            'lesson_objective_html' => '',
            'lesson_has_objective' => false,
            'lesson_explain_html' => '',
            'lesson_has_explain' => false,
            'lesson_example_html' => '',
            'lesson_has_example' => false,
            'lesson_exercise_html' => '',
            'lesson_has_exercise' => false,
            'lesson_has_next' => false,
            'lesson_next_name' => '',
            'lesson_next_url' => '',
            'lesson_next_mod' => '',
        ];
    }

    /**
     * Build E7 lesson sections + next activity for a page CM.
     *
     * @return array<string,mixed>
     */
    public static function build_lesson_layout(
        string $bodyhtml,
        string $introhtml,
        bool $hasintro,
        \stdClass $course,
        \cm_info $cm,
        int $userid,
        string $courseurl
    ): array {
        $parsed = self::parse_lesson_sections($bodyhtml);
        $objective = $parsed['objective_html'];
        $explain = $parsed['explain_html'];
        $example = $parsed['example_html'];
        $exercise = $parsed['exercise_html'];
        $structured = $parsed['structured'];

        // Unstructured pages: intro → objective, body → explanation (still show section chrome).
        if (!$structured) {
            if ($hasintro && trim(strip_tags($introhtml)) !== '') {
                $objective = $introhtml;
            }
            if ($explain === '' && trim(strip_tags($bodyhtml)) !== '') {
                $explain = $bodyhtml;
            }
        }

        $next = self::next_activity_after($cm, $course, $userid);

        return [
            'lesson_structured' => $structured,
            'lesson_objective_html' => $objective,
            'lesson_has_objective' => trim(strip_tags($objective)) !== '',
            'lesson_explain_html' => $explain,
            'lesson_has_explain' => trim(strip_tags($explain)) !== '',
            'lesson_example_html' => $example,
            'lesson_has_example' => trim(strip_tags($example)) !== '',
            'lesson_exercise_html' => $exercise,
            'lesson_has_exercise' => trim(strip_tags($exercise)) !== '',
            'lesson_has_next' => $next !== null,
            'lesson_next_name' => $next['name'] ?? '',
            'lesson_next_url' => $next['url'] ?? '',
            'lesson_next_mod' => $next['modname'] ?? '',
            'lesson_track_label' => format_string($course->fullname),
            'lesson_phase_label' => format_string(get_section_name($course, (int) $cm->sectionnum)),
            'lesson_back_url' => $courseurl,
        ];
    }

    /**
     * Split page HTML into Objective / Explanation / Example / Exercise.
     *
     * Prefers `[LP-OBJECTIVE]` markers; falls back to h2/h3 headings with those words.
     *
     * @return array{structured:bool,objective_html:string,explain_html:string,example_html:string,exercise_html:string}
     */
    public static function parse_lesson_sections(string $html): array {
        $empty = [
            'structured' => false,
            'objective_html' => '',
            'explain_html' => '',
            'example_html' => '',
            'exercise_html' => '',
        ];
        $html = trim($html);
        if ($html === '') {
            return $empty;
        }

        $marker = self::split_lesson_by_markers($html);
        if ($marker['structured']) {
            return $marker;
        }

        $byheading = self::split_lesson_by_headings($html);
        if ($byheading['structured']) {
            return $byheading;
        }

        return $empty;
    }

    /**
     * @return array{structured:bool,objective_html:string,explain_html:string,example_html:string,exercise_html:string}
     */
    private static function split_lesson_by_markers(string $html): array {
        $map = [
            'OBJECTIVE' => 'objective_html',
            'EXPLAIN' => 'explain_html',
            'EXAMPLE' => 'example_html',
            'EXERCISE' => 'exercise_html',
        ];
        $out = [
            'structured' => false,
            'objective_html' => '',
            'explain_html' => '',
            'example_html' => '',
            'exercise_html' => '',
        ];
        if (!preg_match('/\[LP-(OBJECTIVE|EXPLAIN|EXAMPLE|EXERCISE)\]/i', $html)) {
            return $out;
        }
        $parts = preg_split('/\[LP-(OBJECTIVE|EXPLAIN|EXAMPLE|EXERCISE)\]/i', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false || count($parts) < 3) {
            return $out;
        }
        // parts: [preamble, LABEL, content, LABEL, content, ...]
        for ($i = 1; $i < count($parts) - 1; $i += 2) {
            $label = strtoupper(trim($parts[$i]));
            $chunk = trim($parts[$i + 1] ?? '');
            if (!isset($map[$label]) || $chunk === '') {
                continue;
            }
            $out[$map[$label]] = self::cleanup_lesson_chunk($chunk);
            $out['structured'] = true;
        }
        return $out;
    }

    /**
     * @return array{structured:bool,objective_html:string,explain_html:string,example_html:string,exercise_html:string}
     */
    private static function split_lesson_by_headings(string $html): array {
        $out = [
            'structured' => false,
            'objective_html' => '',
            'explain_html' => '',
            'example_html' => '',
            'exercise_html' => '',
        ];
        if (!preg_match_all('/<(h[23])\b[^>]*>(.*?)<\/\1>/is', $html, $matches, PREG_OFFSET_CAPTURE)) {
            return $out;
        }
        $n = count($matches[0]);
        if ($n < 1) {
            return $out;
        }
        for ($i = 0; $i < $n; $i++) {
            $headingplain = strtolower(trim(html_entity_decode(strip_tags($matches[2][$i][0]), ENT_QUOTES | ENT_HTML5)));
            $start = $matches[0][$i][1] + strlen($matches[0][$i][0]);
            $end = ($i + 1 < $n) ? $matches[0][$i + 1][1] : strlen($html);
            $chunk = self::cleanup_lesson_chunk(substr($html, $start, $end - $start));
            if ($chunk === '') {
                continue;
            }
            $key = null;
            if (str_contains($headingplain, 'objective') || str_contains($headingplain, 'learning goal')) {
                $key = 'objective_html';
            } else if (str_contains($headingplain, 'example')) {
                $key = 'example_html';
            } else if (str_contains($headingplain, 'exercise') || str_contains($headingplain, 'try it')
                    || str_contains($headingplain, 'practice')) {
                $key = 'exercise_html';
            } else if (str_contains($headingplain, 'explain') || str_contains($headingplain, 'lesson')
                    || str_contains($headingplain, 'overview') || str_contains($headingplain, 'content')) {
                $key = 'explain_html';
            }
            if ($key === null) {
                continue;
            }
            $out[$key] = $chunk;
            $out['structured'] = true;
        }
        return $out;
    }

    private static function cleanup_lesson_chunk(string $chunk): string {
        $chunk = trim($chunk);
        // Drop leftover empty wrappers from marker splits.
        $chunk = preg_replace('/^(<\/p>|<br\s*\/?>)+/i', '', $chunk) ?? $chunk;
        $chunk = preg_replace('/^(<p>\s*<\/p>\s*)+/i', '', $chunk) ?? $chunk;
        return trim($chunk);
    }

    /**
     * Next visible activity after this CM on the course (fellow shell URL).
     *
     * @return array{cmid:int,name:string,url:string,modname:string}|null
     */
    public static function next_activity_after(\cm_info $cm, \stdClass $course, int $userid): ?array {
        $modinfo = get_fast_modinfo($course, $userid);
        $found = false;
        foreach ($modinfo->get_cms() as $other) {
            if (!$other->uservisible || $other->deletioninprogress) {
                continue;
            }
            // Skip site news / orphan labels if any.
            if ($other->modname === 'label') {
                continue;
            }
            if ($found) {
                return [
                    'cmid' => (int) $other->id,
                    'name' => $other->get_formatted_name(),
                    'url' => self::fellow_url((int) $other->id),
                    'modname' => (string) $other->modname,
                ];
            }
            if ((int) $other->id === (int) $cm->id) {
                $found = true;
            }
        }
        return null;
    }

    /**
     * In-shell YouTube / Vimeo player HTML for showcase (not watch-% completion).
     */
    private static function video_embed_html(string $url): string {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $query = (string) (parse_url($url, PHP_URL_QUERY) ?? '');
        $src = '';

        if (str_contains($host, 'youtu.be')) {
            $id = trim($path, '/');
            if ($id !== '' && preg_match('/^[A-Za-z0-9_-]{6,}$/', $id)) {
                $src = 'https://www.youtube-nocookie.com/embed/' . rawurlencode($id);
            }
        } else if (str_contains($host, 'youtube.com')) {
            $id = '';
            if (preg_match('#/(?:embed|shorts)/([A-Za-z0-9_-]{6,})#', $path, $m)) {
                $id = $m[1];
            } else {
                parse_str($query, $params);
                $id = (string) ($params['v'] ?? '');
            }
            if ($id !== '' && preg_match('/^[A-Za-z0-9_-]{6,}$/', $id)) {
                $src = 'https://www.youtube-nocookie.com/embed/' . rawurlencode($id);
            }
        } else if (str_contains($host, 'vimeo.com')) {
            if (preg_match('#/(\d+)#', $path, $m)) {
                $src = 'https://player.vimeo.com/video/' . $m[1];
            }
        }

        if ($src === '') {
            return '';
        }
        // Trusted embed hosts only — built above, not from raw user HTML.
        return '<div class="lp-activity-video">'
            . '<iframe src="' . s($src) . '" title="Video" '
            . 'allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" '
            . 'allowfullscreen loading="lazy" referrerpolicy="strict-origin-when-cross-origin"></iframe>'
            . '</div>';
    }

    /**
     * Hugeicons name for activity type badge.
     */
    private static function mod_icon(string $modname): string {
        return match ($modname) {
            'page' => 'file-01',
            'url' => 'link-01',
            'resource' => 'file-02',
            'quiz' => 'help-circle',
            'assign' => 'task-01',
            'forum' => 'message-01',
            default => 'book-open-01',
        };
    }

    /**
     * Redirect Employer / Fellowship learners from stock mod views into LaunchPad.
     *
     * Site admins are excluded so Course builder / Moodle edit stays on native
     * mod pages. Fellows also get quiz/assign interstitial in the fellow shell;
     * attempt/submit still use Moodle engines from the CTA.
     */
    public static function maybe_redirect_from_mod(): void {
        global $PAGE, $USER;

        // Guard CLI/PHPUnit/AJAX before isloggedin() — matching persona_home — so
        // PHPUnit (failOnWarning=true) never sees session/user warnings from this hook.
        if (during_initial_install()
                || (defined('CLI_SCRIPT') && CLI_SCRIPT)
                || (defined('AJAX_SCRIPT') && AJAX_SCRIPT)
                || (defined('WS_SERVER') && WS_SERVER)
                || (defined('PHPUNIT_TEST') && PHPUNIT_TEST)) {
            return;
        }
        if (!isloggedin() || isguestuser() || is_siteadmin()) {
            return;
        }

        $userid = (int) $USER->id;
        $isemployee = account_persona::is_employee($userid);
        $isfellow = account_persona::is_fellowship($userid);
        if (!$isemployee && !$isfellow) {
            return;
        }

        $path = '';
        if ($PAGE->url) {
            try {
                $path = $PAGE->url->out_as_local_url(false);
            } catch (\Throwable $e) {
                $path = (string) $PAGE->url->get_path();
            }
        }
        if ($path === '') {
            $path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
        }
        $path = strtok($path, '?') ?: $path;

        // Wrap page/url/resource only. Quiz/assign stay on Moodle when opened from
        // "Continue in Moodle" (track still lands on the fellow interstitial first).
        if (!preg_match('#/mod/(page|url|resource)/view\.php$#', $path)) {
            return;
        }

        $cmid = optional_param('id', 0, PARAM_INT);
        if ($cmid <= 0) {
            // mod/page also accepts ?p={page instance id}.
            $pageid = optional_param('p', 0, PARAM_INT);
            if ($pageid > 0 && str_ends_with($path, '/mod/page/view.php')) {
                $cm = get_coursemodule_from_instance('page', $pageid, 0, false, IGNORE_MISSING);
                $cmid = $cm ? (int) $cm->id : 0;
            }
        }
        if ($cmid <= 0) {
            return;
        }

        // Only wrap LaunchPad (lp-*) courses — keep native Moodle chrome elsewhere.
        $cm = get_coursemodule_from_id('', $cmid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }
        $course = get_course((int) $cm->course);
        $idnumber = (string) ($course->idnumber ?? '');
        if (!str_starts_with($idnumber, account_persona::COURSE_IDNUMBER_PREFIX)) {
            return;
        }

        if ($isfellow) {
            redirect(new \moodle_url(persona_home::FELLOW_PATH, [
                'view' => 'activity',
                'cmid' => $cmid,
            ]));
        }

        redirect(new \moodle_url('/local/launchpad/activity.php', ['id' => $cmid]));
    }
}
