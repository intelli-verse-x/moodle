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
 * Fellow assignment submit / status / feedback inside LaunchPad (Moodle assign engine).
 *
 * @package    local_launchpad
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class assign_submit_ui {

    /**
     * Activity URL for an assign CM inside the fellow shell.
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
     * Extra Mustache fields for the assign activity page (no Moodle handoff).
     *
     * @return array<string,mixed>
     */
    public static function export(int $cmid, int $userid): array {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'assign');
        $context = \context_module::instance($cm->id);
        require_capability('mod/assign:view', $context);

        $assign = new \assign($context, $cm, $course);
        $instance = $assign->get_instance();
        $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);

        $submission = $assign->get_user_submission($userid, false);
        $substatus = $submission ? (string) $submission->status : '';
        $attemptnumber = $submission ? ((int) $submission->attemptnumber + 1) : 0;
        $statuskey = self::status_key($substatus);

        // Product rule (prototype): after Submit for review the attempt is locked.
        // Upload/edit returns only when Moodle reopens (REOPENED) or while still a draft.
        // Do not treat Moodle "submissiondrafts + can_edit submitted" as editable here.
        $canedit = false;
        if ($assign->submissions_open($userid) && $assign->is_any_submission_plugin_enabled()) {
            if (!$submission
                || $substatus === ASSIGN_SUBMISSION_STATUS_NEW
                || $substatus === ASSIGN_SUBMISSION_STATUS_DRAFT
                || $substatus === ASSIGN_SUBMISSION_STATUS_REOPENED) {
                $canedit = $assign->can_edit_submission($userid, $userid);
            }
        }

        $hastext = false;
        $hasfile = false;
        $textplugin = $assign->get_submission_plugin_by_type('onlinetext');
        if ($textplugin && $textplugin->is_enabled()) {
            $hastext = true;
        }
        $fileplugin = $assign->get_submission_plugin_by_type('file');
        if ($fileplugin && $fileplugin->is_enabled()) {
            $hasfile = true;
        }

        $drafttext = '';
        $drafthtml = '';
        $currentfiles = [];
        if ($submission && $hastext && $textplugin) {
            $raw = $textplugin->get_editor_text('onlinetext', (int) $submission->id);
            if (is_string($raw)) {
                // Textarea shows plain text; keep HTML only for read-only view.
                $drafttext = trim(html_to_text($raw, 0, false));
                if (trim(strip_tags($raw)) !== '') {
                    $drafthtml = format_text($raw, FORMAT_HTML, ['context' => $context]);
                }
            }
        }
        if ($submission && $hasfile && $fileplugin) {
            foreach ($fileplugin->get_files($submission, $user) as $storedfile) {
                /** @var \stored_file $storedfile */
                if ($storedfile->is_directory()) {
                    continue;
                }
                $fname = $storedfile->get_filename();
                $ext = strtoupper(pathinfo($fname, PATHINFO_EXTENSION) ?: 'FILE');
                $currentfiles[] = [
                    'filename' => $fname,
                    'ext' => $ext,
                    'filesize' => display_size($storedfile->get_filesize()),
                    'url' => \moodle_url::make_pluginfile_url(
                        $storedfile->get_contextid(),
                        $storedfile->get_component(),
                        $storedfile->get_filearea(),
                        $storedfile->get_itemid(),
                        $storedfile->get_filepath(),
                        $storedfile->get_filename()
                    )->out(false),
                ];
            }
        }

        $grademax = (float) ($instance->grade ?? 0);
        $all = $assign->get_all_submissions($userid);

        // Prefill empty reopened/new draft from the latest prior attempt (Moodle "based on previous").
        $prefilledfrom = 0;
        if ($canedit && $hastext && $textplugin && trim(strip_tags($drafttext)) === '') {
            $ordered = array_reverse($all);
            foreach ($ordered as $sub) {
                if ($submission && (int) $sub->id === (int) $submission->id) {
                    continue;
                }
                if ((string) $sub->status === ASSIGN_SUBMISSION_STATUS_NEW) {
                    continue;
                }
                $raw = $textplugin->get_editor_text('onlinetext', (int) $sub->id);
                if (is_string($raw) && trim(strip_tags($raw)) !== '') {
                    $drafttext = trim(html_to_text($raw, 0, false));
                    $prefilledfrom = (int) $sub->attemptnumber + 1;
                    break;
                }
            }
        }

        // Latest mentor feedback across attempts (reopened attempt often has no grade row yet).
        $hasgrade = false;
        $gradelabel = '';
        $feedbackhtml = '';
        $gradedon = '';
        $gradername = '';
        $feedbackattempt = 0;
        $grades = $DB->get_records(
            'assign_grades',
            ['assignment' => (int) $instance->id, 'userid' => $userid],
            'attemptnumber DESC, timemodified DESC'
        );
        foreach ($grades as $agrade) {
            if ($agrade->grade === null || (float) $agrade->grade < 0) {
                continue;
            }
            $hasgrade = true;
            $feedbackattempt = (int) $agrade->attemptnumber + 1;
            $gradelabel = format_float((float) $agrade->grade, 2)
                . ' / '
                . format_float($grademax > 0 ? $grademax : 100, 2);
            $gradedon = !empty($agrade->timemodified)
                ? userdate((int) $agrade->timemodified, get_string('strftimedatetimeshort', 'langconfig'))
                : '';
            if (!empty($agrade->grader)) {
                $grader = $DB->get_record('user', ['id' => (int) $agrade->grader, 'deleted' => 0]);
                if ($grader) {
                    $gradername = fullname($grader);
                }
            }
            $fc = $DB->get_record('assignfeedback_comments', [
                'assignment' => (int) $instance->id,
                'grade' => (int) $agrade->id,
            ]);
            if ($fc && trim(strip_tags((string) $fc->commenttext)) !== '') {
                $feedbackhtml = format_text((string) $fc->commenttext, FORMAT_HTML, ['context' => $context]);
            }
            break;
        }

        $priors = [];
        foreach ($all as $sub) {
            if ($submission && (int) $sub->id === (int) $submission->id) {
                continue;
            }
            if ((string) $sub->status === ASSIGN_SUBMISSION_STATUS_NEW) {
                continue;
            }
            $priortext = '';
            $priorraw = '';
            if ($hastext && $textplugin) {
                $raw = $textplugin->get_editor_text('onlinetext', (int) $sub->id);
                if (is_string($raw) && trim(strip_tags($raw)) !== '') {
                    $priorraw = $raw;
                    $priortext = format_text($raw, FORMAT_HTML, ['context' => $context]);
                }
            }
            $priorgrade = '';
            $priorfeedback = '';
            $agrade = $DB->get_record('assign_grades', [
                'assignment' => (int) $instance->id,
                'userid' => $userid,
                'attemptnumber' => (int) $sub->attemptnumber,
            ]);
            if ($agrade && $agrade->grade !== null && (float) $agrade->grade >= 0) {
                $priorgrade = format_float((float) $agrade->grade, 2)
                    . ' / '
                    . format_float($grademax > 0 ? $grademax : 100, 2);
                $fc = $DB->get_record('assignfeedback_comments', [
                    'assignment' => (int) $instance->id,
                    'grade' => (int) $agrade->id,
                ]);
                if ($fc && trim(strip_tags((string) $fc->commenttext)) !== '') {
                    $priorfeedback = format_text((string) $fc->commenttext, FORMAT_HTML, ['context' => $context]);
                }
            }
            $priorfiles = [];
            if ($hasfile && $fileplugin) {
                foreach ($fileplugin->get_files($sub, $user) as $storedfile) {
                    /** @var \stored_file $storedfile */
                    if ($storedfile->is_directory()) {
                        continue;
                    }
                    $priorfiles[] = [
                        'filename' => $storedfile->get_filename(),
                        'filesize' => display_size($storedfile->get_filesize()),
                        'url' => \moodle_url::make_pluginfile_url(
                            $storedfile->get_contextid(),
                            $storedfile->get_component(),
                            $storedfile->get_filearea(),
                            $storedfile->get_itemid(),
                            $storedfile->get_filepath(),
                            $storedfile->get_filename()
                        )->out(false),
                    ];
                }
            }
            $priors[] = [
                'attempt_label' => get_string(
                    'fellowship_assign_attempt_n',
                    'local_launchpad',
                    (int) $sub->attemptnumber + 1
                ),
                'status_label' => get_string(
                    'fellowship_assign_status_' . self::status_key((string) $sub->status),
                    'local_launchpad'
                ),
                'modified' => userdate(
                    (int) ($sub->timemodified ?: $sub->timecreated),
                    get_string('strftimedatetimeshort', 'langconfig')
                ),
                'has_text' => $priortext !== '',
                'text_html' => $priortext,
                'has_files' => $priorfiles !== [],
                'files' => $priorfiles,
                'has_grade' => $priorgrade !== '',
                'grade_label' => $priorgrade,
                'has_feedback' => $priorfeedback !== '',
                'feedback_html' => $priorfeedback,
            ];
        }
        $priors = array_reverse($priors);

        $hascontent = ($drafthtml !== '') || ($currentfiles !== []);
        $hasattempt = $submission
            && $substatus !== ''
            && $substatus !== ASSIGN_SUBMISSION_STATUS_NEW;
        $hasreceipt = $hasattempt || $hascontent;
        $modifiedts = $submission ? (int) ($submission->timemodified ?: $submission->timecreated) : 0;
        $receiptwhen = $modifiedts > 0
            ? userdate($modifiedts, get_string('strftimedatetimeshort', 'langconfig'))
            : '';
        $receiptwhenlabel = '';
        if ($receiptwhen !== '') {
            if ($substatus === ASSIGN_SUBMISSION_STATUS_SUBMITTED) {
                $receiptwhenlabel = get_string('fellowship_assign_submitted_at', 'local_launchpad');
            } else if ($substatus === ASSIGN_SUBMISSION_STATUS_DRAFT
                || $substatus === ASSIGN_SUBMISSION_STATUS_REOPENED) {
                $receiptwhenlabel = get_string('fellowship_assign_saved_at', 'local_launchpad');
            } else {
                $receiptwhenlabel = get_string('fellowship_assign_updated_at', 'local_launchpad');
            }
        }

        $flash = optional_param('assignmsg', '', PARAM_ALPHAEXT);
        $flashok = '';
        $flasherr = '';
        if ($flash === 'saved') {
            $flashok = get_string('fellowship_assign_saved_draft', 'local_launchpad');
        } else if ($flash === 'submitted') {
            $flashok = get_string('fellowship_assign_submitted_ok', 'local_launchpad');
        } else if ($flash === 'empty') {
            $flasherr = get_string('fellowship_assign_err_empty', 'local_launchpad');
        } else if ($flash === 'denied') {
            $flasherr = get_string('fellowship_assign_err_denied', 'local_launchpad');
        } else if ($flash === 'uploadsize') {
            $flasherr = get_string('fellowship_assign_err_uploadsize', 'local_launchpad');
        } else if ($flash === 'uploadpartial') {
            $flasherr = get_string('fellowship_assign_err_uploadpartial', 'local_launchpad');
        } else if ($flash === 'uploadfail') {
            $flasherr = get_string('fellowship_assign_err_uploadfail', 'local_launchpad');
        } else if ($flash === 'filetype') {
            $flasherr = get_string('fellowship_assign_err_filetype', 'local_launchpad');
        } else if ($flash === 'filemax') {
            $flasherr = get_string('fellowship_assign_err_filemax', 'local_launchpad');
        } else if ($flash === 'removed') {
            $flashok = get_string('fellowship_assign_file_removed', 'local_launchpad');
        } else if ($flash === 'error') {
            $flasherr = get_string('fellowship_assign_err_generic', 'local_launchpad');
        }

        $filerules = $hasfile && $fileplugin
            ? self::file_upload_rules($fileplugin)
            : [
                'extensions' => [],
                'maxbytes' => 0,
                'maxfiles' => 0,
                'accept' => '',
                'hint' => '',
            ];

        return [
            'assign_needsengine' => false,
            'assign_workspace' => true,
            'assign_can_edit' => $canedit,
            'assign_has_text' => $hastext,
            'assign_has_file' => $hasfile,
            'assign_file_accept' => $filerules['accept'],
            'assign_file_rules_hint' => $filerules['hint'],
            'assign_work_heading' => $attemptnumber > 0
                ? get_string('fellowship_assign_work_attempt', 'local_launchpad', $attemptnumber)
                : get_string('fellowship_assign_work_new', 'local_launchpad'),
            'assign_drop_title' => get_string('fellowship_assign_drop_title', 'local_launchpad'),
            'assign_choose_file' => get_string('fellowship_assign_choose_file', 'local_launchpad'),
            'assign_nothing_yet' => get_string('fellowship_assign_nothing_yet', 'local_launchpad'),
            'assign_has_attached' => $currentfiles !== [],
            'assign_attached_label' => get_string('fellowship_assign_attached', 'local_launchpad'),
            'assign_can_remove_file' => $canedit && $hasfile && $currentfiles !== []
                && in_array($statuskey, ['draft', 'reopened', 'none'], true),
            'assign_remove_file_label' => get_string('fellowship_assign_remove_file', 'local_launchpad'),
            'assign_file_max' => (int) ($filerules['maxfiles'] ?? 1),
            'assign_file_slots_left' => max(0, (int) ($filerules['maxfiles'] ?? 1) - count($currentfiles)),
            'assign_show_file_drop' => $hasfile && (
                count($currentfiles) < (int) ($filerules['maxfiles'] ?? 1)
                || (int) ($filerules['maxfiles'] ?? 1) === 1
            ),
            'assign_draft_text' => $drafttext,
            'assign_draft_html' => $drafthtml,
            'assign_has_draft_html' => $drafthtml !== '',
            'assign_current_files' => $currentfiles,
            'assign_has_current_files' => $currentfiles !== [],
            'assign_has_receipt' => $hasreceipt,
            'assign_has_receipt_content' => $hascontent,
            'assign_receipt_when' => $receiptwhen,
            'assign_has_receipt_when' => $receiptwhen !== '',
            'assign_receipt_when_label' => $receiptwhenlabel,
            'assign_receipt_honesty' => get_string('fellowship_assign_receipt_honesty', 'local_launchpad'),
            'assign_receipt_empty' => get_string('fellowship_assign_receipt_empty', 'local_launchpad'),
            'assign_is_draft' => $statuskey === 'draft',
            'assign_is_submitted' => $statuskey === 'submitted',
            'assign_attempt_n' => $attemptnumber,
            'assign_attempt_label' => $attemptnumber > 0
                ? get_string('fellowship_assign_attempt_n', 'local_launchpad', $attemptnumber)
                : get_string('fellowship_assign_attempt_none', 'local_launchpad'),
            'assign_has_feedback' => $hasgrade || $feedbackhtml !== '',
            'assign_feedback_grade' => $gradelabel,
            'assign_has_feedback_grade' => $hasgrade,
            'assign_feedback_html' => $feedbackhtml,
            'assign_has_feedback_html' => $feedbackhtml !== '',
            'assign_feedback_when' => $gradedon,
            'assign_feedback_by' => $gradername,
            'assign_has_feedback_by' => $gradername !== '',
            'assign_feedback_attempt_label' => $feedbackattempt > 0
                ? get_string('fellowship_assign_feedback_from', 'local_launchpad', $feedbackattempt)
                : '',
            'assign_has_feedback_attempt' => $feedbackattempt > 0,
            'assign_prefilled' => $prefilledfrom > 0,
            'assign_prefilled_label' => $prefilledfrom > 0
                ? get_string('fellowship_assign_prefilled', 'local_launchpad', $prefilledfrom)
                : '',
            'assign_completion_note' => get_string('fellowship_assign_completion_note', 'local_launchpad'),
            'assign_prior_attempts' => $priors,
            'assign_has_prior_attempts' => $priors !== [],
            'assign_form_action' => self::activity_url($cmid)->out(false),
            'assign_sesskey' => sesskey(),
            'assign_cmid' => $cmid,
            'assign_lastmodified' => $submission ? (int) $submission->timemodified : 0,
            'assign_flash_ok' => $flashok,
            'assign_has_flash_ok' => $flashok !== '',
            'assign_flash_err' => $flasherr,
            'assign_has_flash_err' => $flasherr !== '',
            'assign_require_statement' => !empty($instance->requiresubmissionstatement),
            'assign_statement_label' => !empty($instance->requiresubmissionstatement)
                ? get_string('fellowship_assign_statement', 'local_launchpad')
                : '',
        ];
    }

    /**
     * Map assign status string to lang key suffix.
     */
    private static function status_key(string $status): string {
        return match ($status) {
            ASSIGN_SUBMISSION_STATUS_SUBMITTED => 'submitted',
            ASSIGN_SUBMISSION_STATUS_DRAFT => 'draft',
            ASSIGN_SUBMISSION_STATUS_REOPENED => 'reopened',
            default => 'none',
        };
    }

    /**
     * If the fellow chose a file but PHP rejected the upload, return a flash code.
     * Does not invent a submit — caller must abort before save_submission.
     *
     * @return string|null flash code or null when no failed upload to report
     */
    private static function file_upload_flash_if_failed(): ?string {
        if (empty($_FILES['assignfile']) || !is_array($_FILES['assignfile'])) {
            return null;
        }
        $err = (int) ($_FILES['assignfile']['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($err === UPLOAD_ERR_OK) {
            $tmp = (string) ($_FILES['assignfile']['tmp_name'] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                return 'uploadfail';
            }
            return null;
        }
        return match ($err) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'uploadsize',
            UPLOAD_ERR_PARTIAL => 'uploadpartial',
            default => 'uploadfail',
        };
    }

    /**
     * Upload rules from Moodle assignsubmission_file (LaunchPad defaults: pdf/docx, 10MB, 1 file).
     *
     * @param \assign_submission_plugin $fileplugin
     * @return array{extensions:list<string>,maxbytes:int,maxfiles:int,accept:string,hint:string}
     */
    public static function file_upload_rules(\assign_submission_plugin $fileplugin): array {
        $extensions = self::parse_file_extensions((string) $fileplugin->get_config('filetypeslist'));
        if ($extensions === []) {
            $extensions = ['pdf', 'docx'];
        }
        // Honour Moodle assignsubmission_file admin settings (default 1 when unset).
        $maxfiles = (int) $fileplugin->get_config('maxfilesubmissions');
        if ($maxfiles < 1) {
            $maxfiles = 1;
        }
        $maxbytes = (int) $fileplugin->get_config('maxsubmissionsizebytes');
        if ($maxbytes <= 0) {
            $maxbytes = 10 * 1024 * 1024;
        }
        $accept = '.' . implode(',.', $extensions);
        $label = strtoupper(implode(' or ', $extensions));
        $hint = get_string('fellowship_assign_file_rules', 'local_launchpad', (object) [
            'types' => $label,
            'size' => display_size($maxbytes),
            'files' => $maxfiles,
        ]);
        return [
            'extensions' => $extensions,
            'maxbytes' => $maxbytes,
            'maxfiles' => $maxfiles,
            'accept' => $accept,
            'hint' => $hint,
        ];
    }

    /**
     * @return list<string> lowercase extensions without dots
     */
    public static function parse_file_extensions(string $filetypeslist): array {
        $filetypeslist = trim($filetypeslist);
        if ($filetypeslist === '') {
            return [];
        }
        $out = [];
        foreach (preg_split('/[\s,;]+/', $filetypeslist) ?: [] as $part) {
            $part = strtolower(trim($part));
            $part = ltrim($part, '.');
            if ($part === '' || str_contains($part, '/')) {
                // Skip MIME groups like "document" — LaunchPad keeps explicit extensions.
                continue;
            }
            $out[] = $part;
        }
        return array_values(array_unique($out));
    }

    /**
     * Validate $_FILES['assignfile'] against Moodle/plugin rules before draft save.
     *
     * @param array<string,mixed> $fileinfo
     * @param array{extensions:list<string>,maxbytes:int,maxfiles:int,accept:string,hint:string} $rules
     * @return string|null flash code
     */
    public static function validate_uploaded_assign_file(array $fileinfo, array $rules): ?string {
        $tmp = (string) ($fileinfo['tmp_name'] ?? '');
        $name = clean_filename((string) ($fileinfo['name'] ?? ''));
        $size = (int) ($fileinfo['size'] ?? 0);
        if ($tmp === '' || !is_uploaded_file($tmp) || $name === '') {
            return 'uploadfail';
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, $rules['extensions'], true)) {
            return 'filetype';
        }
        if ($size <= 0) {
            $size = (int) filesize($tmp);
        }
        if ($size > (int) $rules['maxbytes']) {
            return 'uploadsize';
        }
        return null;
    }

    /**
     * Save draft and/or submit for grading from LaunchPad POST.
     *
     * @return string flash code for redirect
     *         (saved|submitted|empty|denied|error|uploadsize|uploadpartial|uploadfail|filetype|filemax|removed)
     */
    public static function process_post(int $cmid, int $userid): string {
        global $CFG, $USER;

        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        require_sesskey();

        [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'assign');
        $context = \context_module::instance($cm->id);
        require_capability('mod/assign:submit', $context);

        if ((int) $USER->id !== $userid) {
            return 'denied';
        }

        $assign = new \assign($context, $cm, $course);
        if (!$assign->submissions_open($userid)) {
            return 'denied';
        }
        // Moodle truth: submitted/locked attempts cannot be rewritten from LaunchPad.
        if (!$assign->can_edit_submission($userid, $userid)) {
            return 'denied';
        }
        $existingsub = $assign->get_user_submission($userid, false);
        $existingstatus = $existingsub ? (string) $existingsub->status : '';
        // Handed-in attempts stay locked until mentor reopen (ignore Moodle draft-edit-on-submitted).
        if ($existingstatus === ASSIGN_SUBMISSION_STATUS_SUBMITTED) {
            return 'denied';
        }

        $textplugin = $assign->get_submission_plugin_by_type('onlinetext');
        $hastext = $textplugin && $textplugin->is_enabled();
        $fileplugin = $assign->get_submission_plugin_by_type('file');
        $hasfile = $fileplugin && $fileplugin->is_enabled();

        // Draft-only: clear attached files via Moodle assignsubmission_file::remove().
        if ((bool) optional_param('assign_remove_file', 0, PARAM_BOOL)) {
            if (!$hasfile || !$existingsub) {
                return 'denied';
            }
            if (!in_array($existingstatus, [
                ASSIGN_SUBMISSION_STATUS_DRAFT,
                ASSIGN_SUBMISSION_STATUS_NEW,
                ASSIGN_SUBMISSION_STATUS_REOPENED,
            ], true)) {
                return 'denied';
            }
            $fileplugin->remove($existingsub);
            return 'removed';
        }

        // Honesty: failed / oversized / partial upload must not look saved or submitted.
        $uploadflash = self::file_upload_flash_if_failed();
        if ($uploadflash !== null) {
            return $uploadflash;
        }

        $submit = (bool) optional_param('assign_submit', 0, PARAM_BOOL);
        $text = optional_param('onlinetext', '', PARAM_RAW);
        $statement = optional_param('submissionstatement', 0, PARAM_BOOL);

        $data = (object) [
            'userid' => $userid,
            'lastmodified' => optional_param('lastmodified', 0, PARAM_INT),
        ];
        if ($hastext) {
            $data->onlinetext_editor = [
                'text' => $text,
                'format' => FORMAT_HTML,
                'itemid' => file_get_unused_draft_itemid(),
            ];
        }

        // File plugin: validate against Moodle assignsubmission_file admin rules.
        $newfilechosen = $hasfile
            && !empty($_FILES['assignfile']['tmp_name'])
            && is_uploaded_file($_FILES['assignfile']['tmp_name']);
        if ($newfilechosen) {
            $rules = self::file_upload_rules($fileplugin);
            $check = self::validate_uploaded_assign_file($_FILES['assignfile'], $rules);
            if ($check !== null) {
                return $check;
            }
            $user = \core_user::get_user($userid, '*', MUST_EXIST);
            $existingfiles = [];
            if ($existingsub) {
                foreach ($fileplugin->get_files($existingsub, $user) as $storedfile) {
                    if (!$storedfile->is_directory()) {
                        $existingfiles[] = $storedfile;
                    }
                }
            }
            $maxfiles = (int) $rules['maxfiles'];
            // maxfiles=1 → replace; otherwise append until the admin cap.
            if ($maxfiles === 1) {
                $existingfiles = [];
            } else if (count($existingfiles) >= $maxfiles) {
                return 'filemax';
            }

            $draftid = file_get_unused_draft_itemid();
            $usercontext = \context_user::instance($userid);
            $fs = get_file_storage();
            $fs->delete_area_files($usercontext->id, 'user', 'draft', $draftid);
            foreach ($existingfiles as $storedfile) {
                $fs->create_file_from_storedfile([
                    'contextid' => $usercontext->id,
                    'component' => 'user',
                    'filearea' => 'draft',
                    'itemid' => $draftid,
                    'filepath' => $storedfile->get_filepath(),
                    'filename' => $storedfile->get_filename(),
                ], $storedfile);
            }
            $fs->create_file_from_pathname([
                'contextid' => $usercontext->id,
                'component' => 'user',
                'filearea' => 'draft',
                'itemid' => $draftid,
                'filepath' => '/',
                'filename' => clean_filename((string) $_FILES['assignfile']['name']),
            ], $_FILES['assignfile']['tmp_name']);
            $data->files_filemanager = $draftid;
        }

        $notices = [];
        $saved = $assign->save_submission($data, $notices);
        if (!$saved) {
            foreach ($notices as $n) {
                $low = core_text::strtolower((string) $n);
                if (str_contains($low, 'empty')) {
                    return 'empty';
                }
                if (str_contains($low, 'type') || str_contains($low, 'extension')
                        || str_contains($low, 'not accepted') || str_contains($low, 'filetype')) {
                    return 'filetype';
                }
                if (str_contains($low, 'size') || str_contains($low, 'large') || str_contains($low, 'bytes')) {
                    return 'uploadsize';
                }
            }
            return 'error';
        }

        $submission = $assign->get_user_submission($userid, false);
        $status = $submission ? (string) $submission->status : '';

        // Assignments without draft mode mark SUBMITTED inside save_submission already.
        // Always report that truth — never call a live submit a "draft saved".
        if ($status === ASSIGN_SUBMISSION_STATUS_SUBMITTED) {
            return 'submitted';
        }

        if (!$submit) {
            return 'saved';
        }

        $instance = $assign->get_instance();
        $submitdata = (object) ['userid' => $userid];
        if (!empty($instance->requiresubmissionstatement)) {
            if (!$statement) {
                return 'denied';
            }
            $submitdata->submissionstatement = 1;
        }
        $ok = $assign->submit_for_grading($submitdata, $notices);
        if ($ok) {
            return 'submitted';
        }
        // Race / already submitted: treat as success if Moodle shows submitted now.
        $submission = $assign->get_user_submission($userid, false);
        if ($submission && (string) $submission->status === ASSIGN_SUBMISSION_STATUS_SUBMITTED) {
            return 'submitted';
        }
        return 'error';
    }
}
