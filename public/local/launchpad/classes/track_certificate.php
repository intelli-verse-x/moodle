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

/**
 * One customcert activity association per LaunchPad track course.
 *
 * LaunchPad owns attach / detach / association; Moodle mod_customcert owns design + PDF.
 *
 * @package    local_launchpad
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class track_certificate {

    /** Config key prefix: track_cert_cmid_{courseid}. */
    public const CONFIG_PREFIX = 'track_cert_cmid_';

    /**
     * True when mod_customcert is installed and enabled.
     */
    public static function is_plugin_available(): bool {
        $info = \core_plugin_manager::instance()->get_plugin_info('mod_customcert');
        if ($info === null) {
            return false;
        }
        // versiondb is set after a successful install/upgrade.
        return (int) ($info->versiondb ?? 0) > 0 && $info->is_enabled() !== false;
    }

    /**
     * Stored course-module id for a track, or 0.
     */
    public static function get_stored_cmid(int $courseid): int {
        if ($courseid <= 0) {
            return 0;
        }
        return (int) get_config('local_launchpad', self::CONFIG_PREFIX . $courseid);
    }

    /**
     * Persist association (0 clears).
     */
    public static function set_stored_cmid(int $courseid, int $cmid): void {
        if ($courseid <= 0) {
            return;
        }
        $key = self::CONFIG_PREFIX . $courseid;
        if ($cmid <= 0) {
            unset_config($key, 'local_launchpad');
            return;
        }
        set_config($key, (string) $cmid, 'local_launchpad');
    }

    /**
     * Resolve the associated CM if it still exists, is customcert, and belongs to the course.
     *
     * Clears a stale config value automatically.
     *
     * @return \cm_info|null
     */
    public static function resolve_cm(int $courseid): ?\cm_info {
        global $DB;

        $cmid = self::get_stored_cmid($courseid);
        if ($cmid <= 0 || $courseid <= 0) {
            return null;
        }

        $modinfo = get_fast_modinfo($courseid);
        try {
            $cm = $modinfo->get_cm($cmid);
        } catch (\Throwable $e) {
            self::set_stored_cmid($courseid, 0);
            return null;
        }

        if ($cm->modname !== 'customcert' || (int) $cm->course !== $courseid) {
            self::set_stored_cmid($courseid, 0);
            return null;
        }

        // Deleted modules stay in cache briefly — check DB row.
        if (!$DB->record_exists('course_modules', ['id' => $cmid, 'deletioninprogress' => 0])) {
            self::set_stored_cmid($courseid, 0);
            return null;
        }

        return $cm;
    }

    /**
     * Count issued certificates for the associated activity (0 if none / missing).
     */
    public static function issue_count(int $courseid): int {
        global $DB;

        $cm = self::resolve_cm($courseid);
        if ($cm === null) {
            return 0;
        }
        $instanceid = (int) $cm->instance;
        if ($instanceid <= 0 || !$DB->get_manager()->table_exists('customcert_issues')) {
            return 0;
        }
        return (int) $DB->count_records('customcert_issues', ['customcertid' => $instanceid]);
    }

    /**
     * Deep-link to Moodle customcert template editor (PNG / elements).
     */
    public static function design_url(int $courseid): ?\moodle_url {
        global $DB;

        $cm = self::resolve_cm($courseid);
        if ($cm === null) {
            return null;
        }
        $templateid = (int) $DB->get_field('customcert', 'templateid', ['id' => (int) $cm->instance]);
        if ($templateid <= 0) {
            return null;
        }
        return new \moodle_url('/mod/customcert/edit.php', ['tid' => $templateid]);
    }

    /**
     * Preferred section for a new cert: Demo day / certification (4), else highest track phase, else 0.
     */
    public static function preferred_section(int $courseid): int {
        $phases = admin_dashboard::track_phase_titles($courseid);
        if (isset($phases[4])) {
            return 4;
        }
        if ($phases !== []) {
            return (int) max(array_keys($phases));
        }
        return 0;
    }

    /**
     * Attach exactly one customcert to a track course.
     *
     * @return array{ok:string,cmid:int}|array{error:string}
     */
    public static function attach(\stdClass $actor, int $courseid): array {
        global $CFG;

        unset($actor);
        if (!self::is_plugin_available()) {
            return ['error' => get_string('admin_cert_err_plugin', 'local_launchpad')];
        }
        if ($courseid <= 0) {
            return ['error' => get_string('admin_courseops_err_track', 'local_launchpad')];
        }
        if (self::resolve_cm($courseid) !== null) {
            return ['error' => get_string('admin_cert_err_already', 'local_launchpad')];
        }

        try {
            $course = account_provision::require_launchpad_course($courseid);
        } catch (account_provision_exception $e) {
            return ['error' => $e->getMessage()];
        }
        if (!admin_dashboard::is_track_idnumber((string) $course->idnumber)) {
            return ['error' => get_string('admin_mentors_err_not_track', 'local_launchpad')];
        }

        $context = \context_course::instance($courseid);
        try {
            require_capability('moodle/course:manageactivities', $context);
        } catch (\required_capability_exception $e) {
            return ['error' => get_string('admin_courseops_err_cap', 'local_launchpad')];
        }

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->libdir . '/completionlib.php');
        require_once($CFG->dirroot . '/mod/customcert/lib.php');

        $sectionnum = self::preferred_section($courseid);
        $name = get_string('admin_cert_default_name', 'local_launchpad', format_string($course->fullname));

        $moduleinfo = new \stdClass();
        $moduleinfo->modulename = 'customcert';
        $moduleinfo->course = $courseid;
        $moduleinfo->section = $sectionnum;
        // Hidden until Step 3 eligibility unlock — never in fellow outline by default.
        $moduleinfo->visible = 0;
        $moduleinfo->visibleoncoursepage = 0;
        $moduleinfo->cmidnumber = '';
        $moduleinfo->name = $name;
        $moduleinfo->introeditor = [
            'text' => '<p>' . get_string('admin_cert_default_intro', 'local_launchpad') . '</p>',
            'format' => FORMAT_HTML,
            'itemid' => 0,
        ];
        $moduleinfo->completion = COMPLETION_TRACKING_NONE;
        $moduleinfo->completionview = 0;
        $moduleinfo->completionexpected = 0;
        $moduleinfo->requiredtime = 0;
        $moduleinfo->verifyany = 0;
        $moduleinfo->emailstudents = 0;
        $moduleinfo->emailteachers = 0;
        $moduleinfo->emailothers = '';
        $moduleinfo->protection_print = 0;
        $moduleinfo->protection_modify = 0;
        $moduleinfo->protection_copy = 0;
        $moduleinfo->deliveryoption = \mod_customcert\certificate::DELIVERY_OPTION_DOWNLOAD;
        $moduleinfo->usecustomfilename = 0;

        try {
            $created = create_module($moduleinfo);
        } catch (\Throwable $e) {
            return ['error' => get_string('admin_cert_err_create', 'local_launchpad', $e->getMessage())];
        }

        $cmid = (int) ($created->coursemodule ?? 0);
        if ($cmid <= 0) {
            return ['error' => get_string('admin_cert_err_create', 'local_launchpad', 'missing cmid')];
        }

        self::set_stored_cmid($courseid, $cmid);
        rebuild_course_cache($courseid, true);

        return [
            'ok' => get_string('admin_cert_ok_attached', 'local_launchpad'),
            'cmid' => $cmid,
        ];
    }

    /**
     * Detach certificate from LaunchPad association.
     *
     * Always clears config + hides the CM. Hard-deletes the activity only when
     * zero certificates have been issued (audit-safe).
     *
     * @return array{ok:string}|array{error:string}
     */
    public static function detach(\stdClass $actor, int $courseid): array {
        global $CFG;

        unset($actor);
        if ($courseid <= 0) {
            return ['error' => get_string('admin_courseops_err_track', 'local_launchpad')];
        }

        try {
            $course = account_provision::require_launchpad_course($courseid);
        } catch (account_provision_exception $e) {
            return ['error' => $e->getMessage()];
        }
        if (!admin_dashboard::is_track_idnumber((string) $course->idnumber)) {
            return ['error' => get_string('admin_mentors_err_not_track', 'local_launchpad')];
        }

        $context = \context_course::instance($courseid);
        try {
            require_capability('moodle/course:manageactivities', $context);
        } catch (\required_capability_exception $e) {
            return ['error' => get_string('admin_courseops_err_cap', 'local_launchpad')];
        }

        $cm = self::resolve_cm($courseid);
        if ($cm === null) {
            self::set_stored_cmid($courseid, 0);
            return ['ok' => get_string('admin_cert_ok_detached', 'local_launchpad')];
        }

        $issues = self::issue_count($courseid);
        require_once($CFG->dirroot . '/course/lib.php');

        if ($issues > 0) {
            // Preserve issued records — hide only + clear LaunchPad association.
            set_coursemodule_visible($cm->id, 0, 0);
            self::set_stored_cmid($courseid, 0);
            rebuild_course_cache($courseid, true);
            return [
                'ok' => get_string('admin_cert_ok_detached_kept', 'local_launchpad', $issues),
            ];
        }

        // No issues — safe to hard-delete the unused activity.
        try {
            course_delete_module($cm->id, true);
        } catch (\Throwable $e) {
            // Still clear association + hide if delete fails.
            set_coursemodule_visible($cm->id, 0, 0);
            self::set_stored_cmid($courseid, 0);
            rebuild_course_cache($courseid, true);
            return ['error' => get_string('admin_cert_err_delete', 'local_launchpad', $e->getMessage())];
        }

        self::set_stored_cmid($courseid, 0);
        rebuild_course_cache($courseid, true);
        return ['ok' => get_string('admin_cert_ok_detached', 'local_launchpad')];
    }

    /**
     * Mustache context for Course ops Settings certificate panel.
     *
     * @param string|\moodle_url $formaction Settings form POST target
     * @return array<string, mixed>
     */
    public static function panel_context(int $courseid, string|\moodle_url $formaction): array {
        $pluginok = self::is_plugin_available();
        $cm = $pluginok ? self::resolve_cm($courseid) : null;
        $attached = $cm !== null;
        $issues = $attached ? self::issue_count($courseid) : 0;
        $design = $attached ? self::design_url($courseid) : null;
        $actionurl = $formaction instanceof \moodle_url ? $formaction->out(false) : (string) $formaction;

        return [
            'show_cert_panel' => true,
            'cert_title' => get_string('admin_cert_title', 'local_launchpad'),
            'cert_lead' => get_string('admin_cert_lead', 'local_launchpad'),
            'cert_plugin_missing' => !$pluginok,
            'cert_plugin_missing_msg' => get_string('admin_cert_err_plugin', 'local_launchpad'),
            'cert_attached' => $attached,
            'cert_status_label' => $attached
                ? get_string('admin_cert_status_attached', 'local_launchpad')
                : get_string('admin_cert_status_none', 'local_launchpad'),
            'cert_name' => $attached ? format_string($cm->name) : '',
            'cert_issues' => $issues,
            'cert_has_issues' => $issues > 0,
            'cert_issues_label' => get_string('admin_cert_issues_count', 'local_launchpad', $issues),
            'cert_already_msg' => get_string('admin_cert_err_already', 'local_launchpad'),
            'cert_form_action' => $actionurl,
            'cert_can_attach' => $pluginok && !$attached,
            'cert_attach_label' => get_string('admin_cert_attach', 'local_launchpad'),
            'cert_can_detach' => $attached,
            'cert_detach_label' => get_string('admin_cert_detach', 'local_launchpad'),
            'cert_detach_confirm' => $issues > 0
                ? get_string('admin_cert_detach_confirm_kept', 'local_launchpad', $issues)
                : get_string('admin_cert_detach_confirm', 'local_launchpad'),
            'cert_has_design_url' => $design !== null,
            'cert_design_url' => $design !== null ? $design->out(false) : '',
            'cert_design_label' => get_string('admin_cert_edit_design', 'local_launchpad'),
            'cert_design_help' => get_string('admin_cert_edit_design_help', 'local_launchpad'),
        ];
    }

    /**
     * Course id that owns this CM association, or 0.
     */
    public static function courseid_for_cmid(int $cmid): int {
        global $DB;

        if ($cmid <= 0) {
            return 0;
        }
        // Fast path: CM course + stored config match.
        $cm = get_coursemodule_from_id('customcert', $cmid, 0, false, IGNORE_MISSING);
        if ($cm && self::get_stored_cmid((int) $cm->course) === $cmid) {
            return (int) $cm->course;
        }
        // Slow path: scan plugin config keys (small N of tracks).
        $like = $DB->sql_like('name', ':prefix');
        $rows = $DB->get_records_select(
            'config_plugins',
            "plugin = :plugin AND {$like}",
            ['plugin' => 'local_launchpad', 'prefix' => self::CONFIG_PREFIX . '%'],
            '',
            'name, value'
        );
        foreach ($rows as $row) {
            if ((int) $row->value === $cmid) {
                $courseid = (int) substr((string) $row->name, strlen(self::CONFIG_PREFIX));
                return $courseid > 0 ? $courseid : 0;
            }
        }
        return 0;
    }

    /**
     * Criteria-only check against a Progress/Completion snapshot (no re-entry).
     *
     * @param array<string, mixed> $completion From {@see fellowship_dashboard::completion_for_user()}
     * @param list<\stdClass> $courses Fellow LaunchPad courses
     */
    public static function criteria_met_from_completion(array $completion, array $courses): bool {
        $allmet = !empty($completion['all_met']);
        $orientationdone = !empty($completion['orientation_done']);
        $hasorient = false;
        foreach ($courses as $c) {
            $idn = strtolower(trim((string) ($c->idnumber ?? '')));
            if (str_starts_with($idn, 'lp-orient-')) {
                $hasorient = true;
                break;
            }
        }
        // Orientation required only when the fellow is enrolled in Shared orientation.
        $orientok = !$hasorient || $orientationdone;
        $weightedok = !empty($completion['has_weighted'])
            && (int) ($completion['weighted_display'] ?? 0) >= 70;
        return $allmet && $orientok && $weightedok;
    }

    /**
     * Criteria-only eligibility for a fellow on a track.
     *
     * @return array{
     *   eligible:bool,
     *   attached:bool,
     *   plugin:bool,
     *   issued:bool,
     *   reason:string,
     *   message:string
     * }
     */
    public static function eligibility_for_user(int $userid, int $courseid): array {
        $base = self::availability_base($userid, $courseid);
        if ($base['reason'] !== 'ok') {
            return $base;
        }

        $courses = fellowship_dashboard::list_courses($userid);
        $selected = null;
        foreach ($courses as $c) {
            if ((int) $c->id === $courseid) {
                $selected = $c;
                break;
            }
        }
        if ($selected === null) {
            $base['reason'] = 'not_enrolled';
            $base['message'] = get_string('fellowship_cert_msg_criteria', 'local_launchpad');
            return $base;
        }

        // completion_for_user embeds the cert card via fellow_card_from_completion —
        // do not call fellow_card_context from inside that path.
        $completion = fellowship_dashboard::completion_for_user($userid, $courses, $selected);
        return self::finalize_eligibility($base, $completion, $courses, $userid, $courseid);
    }

    /**
     * Plugin / attach / issued base (before criteria).
     *
     * @return array{
     *   eligible:bool,
     *   attached:bool,
     *   plugin:bool,
     *   issued:bool,
     *   reason:string,
     *   message:string
     * }
     */
    private static function availability_base(int $userid, int $courseid): array {
        $base = [
            'eligible' => false,
            'attached' => false,
            'plugin' => self::is_plugin_available(),
            'issued' => false,
            'reason' => 'locked',
            'message' => get_string('fellowship_completion_cert_body', 'local_launchpad'),
        ];
        if ($userid <= 0 || $courseid <= 0) {
            return $base;
        }
        if (!$base['plugin']) {
            $base['reason'] = 'plugin';
            $base['message'] = get_string('fellowship_cert_msg_plugin', 'local_launchpad');
            return $base;
        }
        $cm = self::resolve_cm($courseid);
        if ($cm === null) {
            $base['reason'] = 'not_attached';
            $base['message'] = get_string('fellowship_cert_msg_not_attached', 'local_launchpad');
            return $base;
        }
        $base['attached'] = true;
        $base['issued'] = self::user_has_issue($userid, $courseid);
        $base['reason'] = 'ok';
        return $base;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $completion
     * @param list<\stdClass> $courses
     * @return array<string, mixed>
     */
    private static function finalize_eligibility(
        array $base,
        array $completion,
        array $courses,
        int $userid,
        int $courseid
    ): array {
        unset($userid, $courseid);
        if (self::criteria_met_from_completion($completion, $courses)) {
            $base['eligible'] = true;
            $base['reason'] = 'eligible';
            $base['message'] = !empty($base['issued'])
                ? get_string('fellowship_cert_msg_ready_again', 'local_launchpad')
                : get_string('fellowship_cert_msg_ready', 'local_launchpad');
            return $base;
        }
        $base['reason'] = 'criteria';
        $base['message'] = get_string('fellowship_cert_msg_criteria', 'local_launchpad');
        return $base;
    }

    /**
     * True when this user already has a customcert_issues row for the track cert.
     */
    public static function user_has_issue(int $userid, int $courseid): bool {
        global $DB;

        $cm = self::resolve_cm($courseid);
        if ($cm === null || $userid <= 0) {
            return false;
        }
        if (!$DB->get_manager()->table_exists('customcert_issues')) {
            return false;
        }
        return $DB->record_exists('customcert_issues', [
            'userid' => $userid,
            'customcertid' => (int) $cm->instance,
        ]);
    }

    /**
     * Progress / Completion mustache fields (safe to call from completion_for_user).
     *
     * @param array<string, mixed> $completion Snapshot without cert fields yet
     * @param list<\stdClass> $courses
     * @return array<string, mixed>
     */
    public static function fellow_card_from_completion(
        int $userid,
        int $courseid,
        array $completion,
        array $courses
    ): array {
        $base = self::availability_base($userid, $courseid);
        $el = ($base['reason'] === 'ok')
            ? self::finalize_eligibility($base, $completion, $courses, $userid, $courseid)
            : $base;
        $downloadurl = (new \moodle_url(persona_home::FELLOW_PATH, [
            'view' => 'progress',
            'id' => $courseid,
            'downloadcert' => 1,
            'sesskey' => sesskey(),
        ]))->out(false);

        return [
            'certificate_locked' => !$el['eligible'],
            'certificate_ready' => $el['eligible'],
            'cert_has_download' => $el['eligible'],
            'cert_download_url' => $downloadurl,
            'cert_download_label' => get_string('fellowship_cert_download', 'local_launchpad'),
            'cert_na_label' => get_string('fellowship_completion_cert_na', 'local_launchpad'),
            'cert_ready_label' => get_string('fellowship_cert_ready_label', 'local_launchpad'),
            'cert_message' => $el['message'],
        ];
    }

    /**
     * Server-side gate: LaunchPad-associated customcert URLs never open for fellows
     * (or other non-managers). Redirect to Progress — eligibility is enforced there.
     */
    public static function maybe_gate_customcert_view(): void {
        global $PAGE, $USER;

        if (during_initial_install()
                || (defined('CLI_SCRIPT') && CLI_SCRIPT)
                || (defined('AJAX_SCRIPT') && AJAX_SCRIPT)
                || (defined('WS_SERVER') && WS_SERVER)
                || (defined('PHPUNIT_TEST') && PHPUNIT_TEST)) {
            return;
        }
        if (!isloggedin() || isguestuser()) {
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
        if (!preg_match('#/mod/customcert/view\.php$#', $path)) {
            return;
        }

        $cmid = optional_param('id', 0, PARAM_INT);
        if ($cmid <= 0) {
            return;
        }
        $courseid = self::courseid_for_cmid($cmid);
        if ($courseid <= 0) {
            return;
        }

        // Managers / report viewers keep Moodle chrome (design QA, issue report).
        $cmcontext = \context_module::instance($cmid);
        if (has_capability('mod/customcert:manage', $cmcontext)
                || has_capability('mod/customcert:viewreport', $cmcontext)
                || is_siteadmin()) {
            return;
        }

        redirect(new \moodle_url(persona_home::FELLOW_PATH, [
            'view' => 'progress',
            'id' => $courseid,
        ]));
    }

    /**
     * Eligible Download: auto-issue if needed, then stream the real PDF (exits).
     *
     * @throws \moodle_exception when not eligible / missing plugin
     */
    public static function serve_pdf_download(int $userid, int $courseid): void {
        global $CFG, $DB;

        $el = self::eligibility_for_user($userid, $courseid);
        if (!$el['eligible']) {
            throw new \moodle_exception('fellowship_cert_err_not_eligible', 'local_launchpad');
        }
        $cm = self::resolve_cm($courseid);
        if ($cm === null) {
            throw new \moodle_exception('fellowship_cert_err_not_eligible', 'local_launchpad');
        }

        require_once($CFG->dirroot . '/mod/customcert/lib.php');

        $customcert = $DB->get_record('customcert', ['id' => (int) $cm->instance], '*', MUST_EXIST);
        if (!$DB->record_exists('customcert_issues', [
            'userid' => $userid,
            'customcertid' => (int) $customcert->id,
        ])) {
            \mod_customcert\certificate::issue_certificate((int) $customcert->id, $userid);
        }

        $templaterec = $DB->get_record('customcert_templates', ['id' => (int) $customcert->templateid], '*', MUST_EXIST);
        $template = new \mod_customcert\template($templaterec);
        // Stream PDF for this user (same path as Moodle downloadown).
        $template->generate_pdf(false, $userid, false);
        exit;
    }
}
