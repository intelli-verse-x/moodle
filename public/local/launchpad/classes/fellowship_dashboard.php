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
 * Fellowship dashboard data (enrolments, activities, completion).
 *
 * Read-only helpers for the Fellow shell. Never invents XP, grades, or
 * unlocks — only Moodle enrolments, visibility, and completion_info.
 *
 * @package    local_launchpad
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class fellowship_dashboard {

    /** Marker for automated checks. */
    public const DATA_REGION = 'local-launchpad-fellowship';

    /**
     * LaunchPad courses where the user is an active student.
     *
     * @param int $userid
     * @return list<\stdClass> id, fullname, shortname, idnumber, summary
     */
    public static function list_courses(int $userid): array {
        global $CFG, $DB;

        if ($userid <= 0) {
            return [];
        }

        require_once($CFG->libdir . '/enrollib.php');

        // Match Moodle student access: hidden courses (visible=0) show "unavailable"
        // on /course/view.php even when the user is enrolled — do not list them here.
        $sql = "SELECT DISTINCT c.id, c.fullname, c.shortname, c.idnumber, c.summary, c.summaryformat
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                  JOIN {course} c ON c.id = e.courseid
                  JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = :ctxlevel
                  JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.userid = ue.userid
                  JOIN {role} r ON r.id = ra.roleid AND r.archetype = :arch
                 WHERE ue.userid = :userid
                   AND ue.status = :uestatus
                   AND e.status = :estatus
                   AND c.visible = 1
                   AND " . $DB->sql_like('c.idnumber', ':idn', false) . "
              ORDER BY c.fullname ASC";

        return array_values($DB->get_records_sql($sql, [
            'userid' => $userid,
            'uestatus' => ENROL_USER_ACTIVE,
            'estatus' => ENROL_INSTANCE_ENABLED,
            'ctxlevel' => CONTEXT_COURSE,
            'arch' => 'student',
            'idn' => $DB->sql_like_escape(account_persona::COURSE_IDNUMBER_PREFIX) . '%',
        ]));
    }

    /**
     * True when the course is Shared orientation (lp-orient-*), not a track.
     */
    public static function is_orientation_course(\stdClass $course): bool {
        $idn = \core_text::strtolower(trim((string) ($course->idnumber ?? '')));
        return str_starts_with($idn, 'lp-orient-');
    }

    /**
     * Prefer a track course over Shared orientation when auto-selecting.
     *
     * @param list<\stdClass> $courses
     * @return \stdClass|null
     */
    public static function prefer_course(array $courses): ?\stdClass {
        if ($courses === []) {
            return null;
        }
        foreach ($courses as $course) {
            if (!self::is_orientation_course($course)) {
                return $course;
            }
        }
        return $courses[0];
    }

    /**
     * Track-specific My Track copy (blurbs, tools, capstone) from course idnumber.
     *
     * @return array{
     *   blurbs: array<int,string>,
     *   lead: string,
     *   prerequisite: string,
     *   device: string,
     *   tools: string,
     *   capstone_body: string
     * }
     */
    public static function track_copy_for_course(\stdClass $course): array {
        $idn = \core_text::strtolower(trim((string) ($course->idnumber ?? '')));
        $key = 'default';
        if (str_starts_with($idn, 'lp-orient-')) {
            $key = 'orient';
        } else if (str_contains($idn, '-ai-') || str_starts_with($idn, 'lp-ai-')) {
            $key = 'ai';
        } else if (str_contains($idn, 'fullstack') || str_contains($idn, 'full-stack')) {
            $key = 'fullstack';
        } else if (str_contains($idn, '-pm-') || str_contains($idn, 'product')) {
            $key = 'pm';
        } else if (str_contains($idn, 'marketing')) {
            $key = 'marketing';
        } else if (str_contains($idn, 'startup')) {
            $key = 'startup';
        }

        $blurbs = [
            1 => self::str('fellowship_track_blurb_1', null, 'Evidence versus assumptions, problem framing and prioritisation.'),
            2 => self::str('fellowship_track_blurb_2', null, 'Brief, backlog, acceptance scenarios and prototype.'),
            3 => self::str('fellowship_track_blurb_3', null, 'A simulated stakeholder adds capacity and accessibility constraints.'),
            4 => self::str('fellowship_track_blurb_4', null, 'Defend the revised roadmap; submit prototype, change log and decision pack.'),
        ];
        $capstone = self::str(
            'fellowship_track_capstone_body',
            null,
            'Brief, backlog, acceptance scenarios, prototype, change log and an evidence-linked decision memo. Stakeholder and data are <b>simulated</b> and labelled.'
        );
        $tools = self::str('fellowship_track_tools', null, 'No paid tools required.');
        $lead = self::str(
            'fellowship_track_lead_default',
            null,
            'Work through the phases on your assigned Moodle track. Activities open in Moodle. Later phases appear when the programme releases them.'
        );

        if ($key === 'ai') {
            $blurbs = [
                1 => self::str('fellowship_track_blurb_ai_1', null, 'Prompt patterns, evaluation basics, and honest limits of automation.'),
                2 => self::str('fellowship_track_blurb_ai_2', null, 'Build a guided automation with clear inputs, checks, and failure modes.'),
                3 => self::str('fellowship_track_blurb_ai_3', null, 'A simulated ops change adds volume, latency, and review constraints.'),
                4 => self::str('fellowship_track_blurb_ai_4', null, 'Defend the workflow, evaluation notes, and a labelled decision memo.'),
            ];
            $capstone = self::str(
                'fellowship_track_capstone_ai',
                null,
                'Runnable workflow notes, evaluation checklist, change log, and an evidence-linked memo. Constraints are <b>simulated</b> and labelled.'
            );
            $tools = self::str('fellowship_track_tools_ai', null, 'Browser plus any free automation / LLM sandbox your mentor names.');
            $lead = self::str(
                'fellowship_track_lead_ai',
                null,
                'AI & Automation track — Foundations through Demo day. Activities open in Moodle; later phases unlock when the programme releases them.'
            );
        } else if ($key === 'fullstack') {
            $blurbs = [
                1 => self::str('fellowship_track_blurb_fs_1', null, 'Stack basics, readability, and shipping a small vertical slice.'),
                2 => self::str('fellowship_track_blurb_fs_2', null, 'Guided feature with acceptance checks and a reviewable PR-style write-up.'),
                3 => self::str('fellowship_track_blurb_fs_3', null, 'A simulated product change adds load, a11y, and deadline pressure.'),
                4 => self::str('fellowship_track_blurb_fs_4', null, 'Demo the slice, walk the change log, and defend trade-offs.'),
            ];
            $capstone = self::str(
                'fellowship_track_capstone_fs',
                null,
                'Working slice, acceptance notes, change log, and decision memo. Stakeholder pressure is <b>simulated</b> and labelled.'
            );
            $tools = self::str('fellowship_track_tools_fs', null, 'Laptop recommended; free editor and Git hosting only.');
            $lead = self::str(
                'fellowship_track_lead_fs',
                null,
                'Full Stack track — Foundations through Demo day. Activities open in Moodle; later phases unlock when the programme releases them.'
            );
        } else if ($key === 'marketing') {
            $blurbs = [
                1 => self::str('fellowship_track_blurb_mkt_1', null, 'Audience evidence, channel fit, and honest measurement basics.'),
                2 => self::str('fellowship_track_blurb_mkt_2', null, 'Guided campaign brief with creatives, funnel, and success metrics.'),
                3 => self::str('fellowship_track_blurb_mkt_3', null, 'A simulated budget cut forces prioritisation and message tests.'),
                4 => self::str('fellowship_track_blurb_mkt_4', null, 'Defend the plan, results pack, and next-experiment notes.'),
            ];
            $capstone = self::str(
                'fellowship_track_capstone_mkt',
                null,
                'Campaign brief, creatives, metrics sheet, and decision memo. Market responses are <b>simulated</b> and labelled.'
            );
            $lead = self::str(
                'fellowship_track_lead_mkt',
                null,
                'Digital Marketing track — Foundations through Demo day. Activities open in Moodle; later phases unlock when the programme releases them.'
            );
        } else if ($key === 'startup') {
            $blurbs = [
                1 => self::str('fellowship_track_blurb_su_1', null, 'Problem evidence, beachhead customer, and riskiest assumption.'),
                2 => self::str('fellowship_track_blurb_su_2', null, 'Guided experiment plan with prototype and learning goals.'),
                3 => self::str('fellowship_track_blurb_su_3', null, 'A simulated pivot request changes scope, budget, or channel.'),
                4 => self::str('fellowship_track_blurb_su_4', null, 'Defend the learning, next bet, and decision pack.'),
            ];
            $capstone = self::str(
                'fellowship_track_capstone_su',
                null,
                'Experiment plan, prototype evidence, change log, and decision memo. Market feedback is <b>simulated</b> and labelled.'
            );
            $lead = self::str(
                'fellowship_track_lead_su',
                null,
                'Startup Building track — Foundations through Demo day. Activities open in Moodle; later phases unlock when the programme releases them.'
            );
        } else if ($key === 'orient') {
            $blurbs = [
                1 => self::str('fellowship_track_blurb_orient_1', null, 'How LaunchPad works, portals, and what “done” means here.'),
                2 => self::str('fellowship_track_blurb_orient_2', null, 'Reserved — orientation uses the overview activities above.'),
                3 => self::str('fellowship_track_blurb_orient_3', null, 'Reserved — orientation uses the overview activities above.'),
                4 => self::str('fellowship_track_blurb_orient_4', null, 'Reserved — orientation uses the overview activities above.'),
            ];
            $capstone = self::str(
                'fellowship_track_capstone_orient',
                null,
                'Complete Shared orientation before your assigned track gates count toward certification.'
            );
            $lead = self::str(
                'fellowship_track_lead_orient',
                null,
                'Shared orientation — finish the overview and check, then continue on your assigned track.'
            );
        } else if ($key === 'pm') {
            $lead = self::str(
                'fellowship_track_lead_pm',
                null,
                'Product Management track — Foundations through Demo day. Activities open in Moodle; later phases unlock when the programme releases them.'
            );
        }

        return [
            'blurbs' => $blurbs,
            'lead' => $lead,
            'prerequisite' => self::str('fellowship_track_prerequisite', null, 'Shared orientation ✓'),
            'device' => self::str(
                'fellowship_track_device',
                null,
                'Phone or laptop to read and submit; a laptop helps for the prototype.'
            ),
            'tools' => $tools,
            'capstone_body' => $capstone,
        ];
    }

    /**
     * Visible course modules for a fellow, grouped by section, with completion.
     *
     * @param \stdClass $course
     * @param int $userid
     * @return array{
     *   sections: list<array{name: string, activities: list<array>}>,
     *   completion_enabled: bool,
     *   completed: int,
     *   trackable: int,
     *   percent: int|null,
     *   next: ?array
     * }
     */
    public static function course_outline(\stdClass $course, int $userid): array {
        global $CFG;

        require_once($CFG->libdir . '/completionlib.php');

        $modinfo = get_fast_modinfo($course, $userid);
        $completion = new \completion_info($course);
        $completionenabled = $completion->is_enabled();

        $sections = [];
        $completed = 0;
        $trackable = 0;
        $next = null;

        foreach ($modinfo->get_section_info_all() as $section) {
            if ($section->section <= 0 && empty($section->sequence)) {
                continue;
            }
            $cms = $modinfo->get_cms();
            $activities = [];
            $sectioncms = $modinfo->get_sections()[$section->section] ?? [];
            foreach ($sectioncms as $cmid) {
                if (!isset($cms[$cmid])) {
                    continue;
                }
                $cm = $cms[$cmid];
                if (!$cm->uservisible || $cm->deletioninprogress) {
                    continue;
                }
                // Skip labels (no dedicated learner destination).
                if ($cm->modname === 'label') {
                    continue;
                }
                // News/forums stay visible in outlines but never become the hero "next" quest.
                $skipnext = ($cm->modname === 'forum');

                $iscomplete = false;
                $tracked = false;
                if ($completionenabled && $completion->is_enabled($cm) != COMPLETION_TRACKING_NONE) {
                    $tracked = true;
                    if (!$skipnext) {
                        $trackable++;
                    }
                    $data = $completion->get_data($cm, false, $userid);
                    $state = (int) $data->completionstate;
                    $iscomplete = ($state === COMPLETION_COMPLETE || $state === COMPLETION_COMPLETE_PASS);
                    if ($iscomplete && !$skipnext) {
                        $completed++;
                    }
                }

                $url = $cm->url ? $cm->url->out(false) : (new \moodle_url('/mod/' . $cm->modname . '/view.php', [
                    'id' => $cm->id,
                ]))->out(false);

                $item = [
                    'cmid' => (int) $cm->id,
                    'name' => self::display_label($cm->get_formatted_name()),
                    'modname' => $cm->modname,
                    'modname_display' => get_string('modulename', 'mod_' . $cm->modname),
                    'url' => $url,
                    'tracked' => $tracked,
                    'complete' => $iscomplete,
                    'icon_class' => self::mod_icon_class($cm->modname),
                ];
                // Evidence-first chips so phase Met never sits next to activity "Not tracked".
                $item = array_merge($item, self::activity_row_status($cm, $userid, $tracked, $iscomplete));
                // Open in Fellowship activity shell (page/file in-shell; quiz/assign CTA → Moodle).
                $item = activity_portal::rewrite_fellow_row($item);
                $activities[] = $item;

                if (!$skipnext && $next === null && empty($item['complete'])) {
                    $next = $item;
                }
            }

            if ($activities === [] && $section->section === 0) {
                continue;
            }

            if ($activities === []) {
                continue;
            }

            $secname = get_section_name($course, $section);
            if (trim((string) $secname) === '') {
                $secname = $section->section === 0
                    ? get_string('fellowship_section_general', 'local_launchpad')
                    : get_string('fellowship_section_numbered', 'local_launchpad', $section->section);
            }

            $sections[] = [
                'name' => self::display_label($secname),
                'sectionnum' => (int) $section->section,
                'activities' => $activities,
                'has_activities' => true,
            ];
        }

        // Drop empty trailing sections with no activities.
        $sections = array_values(array_filter($sections, static function (array $s): bool {
            return !empty($s['has_activities']);
        }));

        $percent = null;
        if ($completionenabled && $trackable > 0) {
            $percent = (int) round(($completed / $trackable) * 100);
        }

        return [
            'sections' => $sections,
            'completion_enabled' => $completionenabled,
            'completed' => $completed,
            'trackable' => $trackable,
            'percent' => $percent,
            'next' => $next,
        ];
    }

    /**
     * Simple icon hint by module name (CSS class suffix).
     */
    public static function mod_icon_class(string $modname): string {
        return match ($modname) {
            'quiz' => 'quiz',
            'assign' => 'assign',
            'page' => 'page',
            'resource', 'folder', 'url' => 'resource',
            'forum' => 'forum',
            default => 'activity',
        };
    }

    /**
     * Activity list chip for My track / home — assigns use mentor evidence; others use completion.
     *
     * Never returns the confusing "Not tracked" chip beside a phase that can show Met.
     *
     * @return array{
     *   show_status:bool,
     *   status_label:string,
     *   status_chip:string,
     *   tracked:bool,
     *   complete:bool
     * }
     */
    public static function activity_row_status(
        \cm_info $cm,
        int $userid,
        bool $completiontracked,
        bool $completioncomplete
    ): array {
        global $DB;

        if ($cm->modname === 'assign') {
            $instanceid = (int) $cm->instance;
            $latest = $DB->get_record('assign_submission', [
                'assignment' => $instanceid,
                'userid' => $userid,
                'latest' => 1,
            ], '*', IGNORE_MISSING);
            $substatus = $latest ? (string) $latest->status : '';
            $attemptnumber = $latest ? (int) $latest->attemptnumber : -1;
            $submittedcount = (int) $DB->count_records('assign_submission', [
                'assignment' => $instanceid,
                'userid' => $userid,
                'status' => 'submitted',
            ]);

            $agrade = false;
            if ($attemptnumber >= 0) {
                $agrade = $DB->get_record('assign_grades', [
                    'assignment' => $instanceid,
                    'userid' => $userid,
                    'attemptnumber' => $attemptnumber,
                ], '*', IGNORE_MISSING);
                if (!$agrade || $agrade->grade === null || (float) $agrade->grade < 0) {
                    $agrade = false;
                }
            }
            if (!$agrade && $latest && in_array($substatus, ['reopened', 'draft', 'new'], true)) {
                $prior = $DB->get_records_select(
                    'assign_grades',
                    'assignment = :assignment AND userid = :userid AND grade >= 0 AND attemptnumber < :attempt',
                    [
                        'assignment' => $instanceid,
                        'userid' => $userid,
                        'attempt' => max(0, $attemptnumber),
                    ],
                    'attemptnumber DESC, timemodified DESC',
                    '*',
                    0,
                    1
                );
                $agrade = $prior ? reset($prior) : false;
            }

            $gradepct = null;
            if ($agrade && $agrade->grade !== null && (float) $agrade->grade >= 0) {
                $assignmax = (float) ($DB->get_field('assign', 'grade', ['id' => $instanceid]) ?: 0);
                if ($assignmax > 0) {
                    $gradepct = (((float) $agrade->grade) / $assignmax) * 100;
                }
            }

            if ($gradepct !== null && $gradepct >= 70 && !in_array($substatus, ['reopened', 'draft', 'new'], true)) {
                return [
                    'show_status' => true,
                    'status_label' => get_string('fellowship_act_met', 'local_launchpad'),
                    'status_chip' => 'ok',
                    'tracked' => true,
                    'complete' => true,
                ];
            }
            if ($gradepct !== null && in_array($substatus, ['reopened', 'draft', 'new'], true)) {
                return [
                    'show_status' => true,
                    'status_label' => get_string('fellowship_act_changes', 'local_launchpad'),
                    'status_chip' => 'warn',
                    'tracked' => true,
                    'complete' => false,
                ];
            }
            if ($gradepct !== null) {
                // Graded below 70, still submitted — not Met yet.
                return [
                    'show_status' => true,
                    'status_label' => get_string('fellowship_act_not_met', 'local_launchpad'),
                    'status_chip' => 'warn',
                    'tracked' => true,
                    'complete' => false,
                ];
            }
            if ($substatus === 'submitted' || $submittedcount > 0) {
                return [
                    'show_status' => true,
                    'status_label' => get_string('fellowship_act_pending', 'local_launchpad'),
                    'status_chip' => '',
                    'tracked' => true,
                    'complete' => false,
                ];
            }
            return [
                'show_status' => true,
                'status_label' => get_string('fellowship_act_todo', 'local_launchpad'),
                'status_chip' => 'line',
                'tracked' => true,
                'complete' => false,
            ];
        }

        if ($completiontracked) {
            return [
                'show_status' => true,
                'status_label' => $completioncomplete
                    ? get_string('fellowship_complete', 'local_launchpad')
                    : get_string('fellowship_incomplete', 'local_launchpad'),
                'status_chip' => $completioncomplete ? 'ok' : 'line',
                'tracked' => true,
                'complete' => $completioncomplete,
            ];
        }

        // Content without completion: no misleading "Not tracked" chip.
        return [
            'show_status' => false,
            'status_label' => '',
            'status_chip' => '',
            'tracked' => false,
            'complete' => false,
        ];
    }

    /**
     * Programme phase weights (HTML PHW[]) — share of result per gate.
     *
     * @return array<int, int> sectionnum => weight percent
     */
    public static function phase_weights(): array {
        return [
            1 => 20,
            2 => 25,
            3 => 20,
            4 => 35,
        ];
    }

    /**
     * "Your journey" — same four gates / path as the HTML portal.
     *
     * Always renders Foundations → Guided project → Simulation → Demo day
     * (programme spine). Moodle section completion only drives status/progress;
     * empty later sections are still shown (locked), not dropped.
     *
     * @param array $outline Return value of {@see course_outline()}
     * @param bool $compact Home card uses compact; Phase progress uses full height + sublabels
     * @param array<int, string>|null $phasestatus Optional sectionnum => status label for sublabels
     * @return array{
     *   has_gates: bool,
     *   compact: bool,
     *   show_sublabels: bool,
     *   gates: list<array>,
     *   path_d: string,
     *   dash_offset: string,
     *   view_h: int,
     *   ring_r: int,
     *   core_r: int,
     *   mark_y: int,
     *   label_y: int,
     *   bot_left: string,
     *   bot_top: string
     * }
     */
    public static function journey_from_outline(array $outline, bool $compact = true, ?array $phasestatus = null): array {
        // Short labels match HTML PHS[]; full names match blueprint / HTML PH[].
        $spine = [
            1 => ['short' => 'Foundations', 'name' => 'Foundations'],
            2 => ['short' => 'Guided project', 'name' => 'Guided project'],
            3 => ['short' => 'Simulation', 'name' => 'Industry simulation'],
            4 => ['short' => 'Demo day', 'name' => 'Demo day and certification'],
        ];
        foreach (blueprint::phase_sections() as $num => $fullname) {
            if (isset($spine[$num])) {
                $spine[$num]['name'] = $fullname;
            }
        }
        $weights = self::phase_weights();

        $bysection = [];
        foreach ($outline['sections'] ?? [] as $section) {
            $num = (int) ($section['sectionnum'] ?? 0);
            if ($num > 0) {
                $bysection[$num] = $section;
            }
        }

        $phasedone = [];
        foreach ($spine as $num => $meta) {
            $section = $bysection[$num] ?? null;
            $tracked = 0;
            $done = 0;
            if ($section !== null) {
                foreach ($section['activities'] ?? [] as $activity) {
                    if (!empty($activity['tracked'])) {
                        $tracked++;
                        if (!empty($activity['complete'])) {
                            $done++;
                        }
                    }
                }
            }
            $phasedone[$num] = ($tracked > 0 && $done >= $tracked);
        }

        // HTML home journey: gate 0 is done|cur; later gates stay lock until earlier work is met.
        // We advance "current" when prior phases are complete (still show all four).
        $gates = [];
        $foundcurrent = false;
        $i = 0;
        foreach ($spine as $num => $meta) {
            $isdone = !empty($phasedone[$num]);
            $status = 'lock';
            $isdoneflag = false;
            $iscurrent = false;
            $islock = true;
            $mark = '🔒';
            $section = $bysection[$num] ?? null;
            $hasmoodlesection = $section !== null;
            // Prefer live Moodle section titles when the course has that section.
            $moodlename = trim((string) ($section['name'] ?? ''));
            $displayname = $moodlename !== '' ? $moodlename : $meta['name'];
            $displayshort = $moodlename !== '' ? $moodlename : $meta['short'];
            if ($isdone) {
                $status = 'done';
                $isdoneflag = true;
                $islock = false;
                $mark = '✓';
            } else if (!$foundcurrent) {
                $status = 'cur';
                $iscurrent = true;
                $islock = false;
                $mark = (string) ($i + 1);
                $foundcurrent = true;
            }
            if ($phasestatus !== null && isset($phasestatus[$num])) {
                $statustext = $phasestatus[$num];
            } else if ($isdone) {
                $statustext = 'Met';
            } else if ($iscurrent) {
                $statustext = 'In progress';
            } else if (!$hasmoodlesection) {
                $statustext = get_string('fellowship_journey_section_missing', 'local_launchpad');
            } else {
                $statustext = 'Not released';
            }
            $weight = (int) ($weights[$num] ?? 0);
            $gates[] = [
                'name' => $displayname,
                'short' => $displayshort,
                'spine_name' => $meta['name'],
                'spine_short' => $meta['short'],
                'has_moodle_section' => $hasmoodlesection,
                'sectionnum' => $num,
                'status' => $status,
                'is_done' => $isdoneflag,
                'is_current' => $iscurrent,
                'is_lock' => $islock,
                'mark' => $mark,
                'cx' => 0,
                'cy' => 0,
                'show_sublabel' => !$compact,
                'sublabel' => $weight . '% · ' . $statustext,
                // Duplicated on each gate so Mustache nest context can read sizes.
                'ring_r' => $compact ? 24 : 30,
                'core_r' => $compact ? 17 : 22,
                'mark_y' => $compact ? 6 : 7,
                'label_y' => $compact ? 44 : 54,
            ];
            $i++;
        }

        // Path geometry from ToBa-Learn-LaunchPad.html journey(compact).
        $viewh = $compact ? 150 : 210;
        $pts = [
            [90.0, $viewh * 0.62],
            [360.0, $viewh * 0.30],
            [640.0, $viewh * 0.66],
            [910.0, $viewh * 0.32],
        ];
        $pathd = sprintf(
            'M%s,%s C230,%s 240,%s %s,%s S520,%s %s,%s S800,%s %s,%s',
            $pts[0][0],
            $pts[0][1],
            $viewh * 0.05,
            $viewh * 0.85,
            $pts[1][0],
            $pts[1][1],
            $viewh * 0.98,
            $pts[2][0],
            $pts[2][1],
            $viewh * 0.02,
            $pts[3][0],
            $pts[3][1]
        );
        foreach ($gates as $idx => &$gate) {
            $gate['cx'] = (int) round($pts[$idx][0]);
            $gate['cy'] = (int) round($pts[$idx][1]);
        }
        unset($gate);

        // HTML: foundations Met → 0.36; else scale activity progress within first ~30% of path.
        $foundationsmet = !empty($phasedone[1]);
        $completed = (int) ($outline['completed'] ?? 0);
        $trackable = (int) ($outline['trackable'] ?? 0);
        if ($foundationsmet) {
            $pathprogress = 0.36;
            // Nudge further when later phases complete.
            $extradone = 0;
            foreach ([2, 3, 4] as $n) {
                if (!empty($phasedone[$n])) {
                    $extradone++;
                }
            }
            $pathprogress = min(1.0, 0.36 + ($extradone * 0.21));
        } else if ($trackable > 0) {
            $pathprogress = min(0.30, ($completed / $trackable) * 0.30);
        } else {
            $pathprogress = 0.0;
        }
        $dashoffset = number_format(1.0 - $pathprogress, 3, '.', '');

        // Bot sits on the first gate (HTML: offset 62 compact / 78 full).
        $botlift = $compact ? 62 : 78;
        $botleft = round(($pts[0][0] / 1000) * 100, 2) . '%';
        $bottop = round((($pts[0][1] - $botlift) / $viewh) * 100, 2) . '%';

        return [
            'has_gates' => true,
            'compact' => $compact,
            'show_sublabels' => !$compact,
            'gates' => $gates,
            'path_d' => $pathd,
            'dash_offset' => $dashoffset,
            'view_h' => $viewh,
            'ring_r' => $compact ? 24 : 30,
            'core_r' => $compact ? 17 : 22,
            'mark_y' => $compact ? 6 : 7,
            'label_y' => $compact ? 44 : 54,
            'bot_left' => $botleft,
            'bot_top' => $bottop,
        ];
    }

    /**
     * Phase progress tab: journey + per-gate weight/score/status from Moodle assigns.
     *
     * Pass at 70/100 on a released assign grade in that section (HTML rule). Empty
     * later sections stay "Not released". Never invents marks.
     *
     * @param list<\stdClass> $courses
     * @param \stdClass|null $selected Preferred lp-* course
     * @return array
     */
    public static function phase_progress_for_user(int $userid, array $courses, ?\stdClass $selected = null): array {
        global $CFG, $DB;

        require_once($CFG->libdir . '/gradelib.php');

        $course = $selected;
        if ($course === null && $courses !== []) {
            $course = $courses[0];
        }

        $weights = self::phase_weights();
        $hints = [
            1 => get_string('fellowship_phase_hint_foundations', 'local_launchpad'),
            2 => get_string('fellowship_phase_hint_submission', 'local_launchpad'),
            3 => get_string('fellowship_phase_hint_submission', 'local_launchpad'),
            4 => get_string('fellowship_phase_hint_capstone', 'local_launchpad'),
        ];
        $names = blueprint::phase_sections();
        $shorts = [
            1 => 'Foundations',
            2 => 'Guided project',
            3 => 'Simulation',
            4 => 'Demo day',
        ];

        $outline = [
            'sections' => [],
            'completion_enabled' => false,
            'completed' => 0,
            'trackable' => 0,
            'percent' => null,
            'next' => null,
        ];
        $modinfo = null;
        if ($course !== null) {
            $outline = self::course_outline($course, $userid);
            $modinfo = get_fast_modinfo($course, $userid);
        }

        $bysection = [];
        foreach ($outline['sections'] ?? [] as $section) {
            $num = (int) ($section['sectionnum'] ?? 0);
            if ($num > 0) {
                $bysection[$num] = $section;
            }
        }

        $phases = [];
        $statusmap = [];
        foreach ($names as $num => $fullname) {
            $weight = (int) ($weights[$num] ?? 0);
            $section = $bysection[$num] ?? null;
            $activities = $section['activities'] ?? [];
            $visiblecount = count($activities);

            $status = 'Not released';
            $chip = 'line';
            $score = null;
            $scored = false;
            $ico = (string) $num;
            $icodim = true;

            if ($visiblecount > 0 && $course !== null && $modinfo !== null) {
                $icodim = false;
                $bestpct = null;
                $hasgrade = false;
                $submitted = false;
                $revising = false;
                $tracked = 0;
                $done = 0;

                foreach ($activities as $activity) {
                    if (!empty($activity['tracked'])) {
                        $tracked++;
                        if (!empty($activity['complete'])) {
                            $done++;
                        }
                    }

                    $cmid = (int) ($activity['cmid'] ?? 0);
                    if ($cmid <= 0 || !isset($modinfo->cms[$cmid])) {
                        continue;
                    }
                    $cm = $modinfo->cms[$cmid];
                    if ($cm->modname !== 'assign' || !$cm->uservisible || $cm->deletioninprogress) {
                        continue;
                    }
                    $instanceid = (int) $cm->instance;

                    $latest = $DB->get_record('assign_submission', [
                        'assignment' => $instanceid,
                        'userid' => $userid,
                        'latest' => 1,
                    ], '*', IGNORE_MISSING);
                    if ($latest && $latest->status === 'submitted') {
                        $submitted = true;
                    } else if ($latest && in_array($latest->status, ['draft', 'reopened', 'new'], true)) {
                        $subcount = (int) $DB->count_records('assign_submission', [
                            'assignment' => $instanceid,
                            'userid' => $userid,
                            'status' => 'submitted',
                        ]);
                        if ($subcount > 0) {
                            $revising = true;
                        }
                    }

                    $gradeinfo = grade_get_grades($course->id, 'mod', 'assign', $instanceid, $userid);
                    $gg = $gradeinfo->items[0]->grades[$userid] ?? null;
                    $grademax = isset($gradeinfo->items[0]->grademax) ? (float) $gradeinfo->items[0]->grademax : 0.0;
                    if ($gg && $gg->grade !== null && $grademax > 0) {
                        $hasgrade = true;
                        $pct = (((float) $gg->grade) / $grademax) * 100;
                        if ($bestpct === null || $pct > $bestpct) {
                            $bestpct = $pct;
                        }
                    }
                }

                $allcomplete = ($tracked > 0 && $done >= $tracked);

                if ($hasgrade && $bestpct !== null) {
                    $score = (int) round($bestpct);
                    $scored = true;
                }
                if ($revising || ($hasgrade && $bestpct !== null && $bestpct < 70)) {
                    // Mentor asked for changes, or graded below the 70 Met bar.
                    $status = $revising ? 'Changes requested' : 'Not yet met';
                    $chip = 'warn';
                } else if ($hasgrade && $bestpct !== null && $bestpct >= 70) {
                    $status = 'Met';
                    $chip = 'ok';
                    $ico = '✓';
                } else if (!$allcomplete && $submitted) {
                    // Submission exists but phase activities are still open — keep "In progress".
                    $status = 'In progress';
                    $chip = 'line';
                } else if ($submitted) {
                    $status = 'Review pending';
                    $chip = '';
                } else if ($allcomplete) {
                    // Foundations-style phases (page/quiz only) finish via completion, not assign grades.
                    $status = 'Complete';
                    $chip = 'ok';
                    $ico = '✓';
                } else {
                    $status = 'In progress';
                    $chip = 'line';
                }
            }

            $statusmap[$num] = $status;
            $phases[] = [
                'sectionnum' => $num,
                'name' => $fullname,
                'short' => $shorts[$num] ?? $fullname,
                'weight' => $weight,
                'weight_label' => $weight . '%',
                'hint' => $hints[$num] ?? '',
                'status' => $status,
                'chip' => $chip,
                'has_chip_class' => $chip !== '',
                'score' => $score,
                'has_score' => $scored,
                'score_display' => $scored ? ($score . '/100') : '—',
                'ico' => $ico,
                'ico_dim' => $icodim,
                // Activity-complete phases (no 70+ grade yet) still clear "current" on My Track.
                'is_met' => ($status === 'Met'),
                'is_phase_done' => ($status === 'Met' || $status === 'Complete'),
                'is_not_released' => $status === 'Not released',
            ];
        }

        $journey = self::journey_from_outline($outline, false, $statusmap);

        $met = 0;
        foreach ($phases as $phase) {
            if (!empty($phase['is_met'])) {
                $met++;
            }
        }
        $gatestotal = count($phases);
        $allmet = $gatestotal > 0 && $met === $gatestotal;

        $strings = [
            'journey_heading' => self::str('fellowship_your_journey', null, 'Your journey'),
            'journey_sub' => self::str(
                'fellowship_journey_help_short',
                null,
                'Your Fellowship learning journey'
            ),
            'gates_heading' => self::str('fellowship_nav_phases', null, 'Phase progress'),
            'gates_sub' => self::str(
                'fellowship_phases_lead',
                null,
                'Each phase passes only after a released review at 70/100 or above.'
            ),
            'note' => self::str(
                'fellowship_phases_note',
                null,
                'A missing or unreviewed grade stays pending. A high capstone mark can’t make up for a phase that hasn’t passed.'
            ),
            'empty_title' => self::str('fellowship_no_courses_heading', null, 'No LaunchPad course yet'),
        ];

        return [
            'has_course' => $course !== null,
            'course_name' => $course ? format_string($course->fullname) : '',
            'course_idnumber' => $course ? s($course->idnumber) : '',
            'phases' => $phases,
            'has_phases' => $phases !== [],
            'journey' => $journey,
            'outline' => $outline,
            'gates_met' => $met,
            'gates_total' => $gatestotal,
            'gates_label' => get_string_manager()->string_exists('fellowship_completion_gates', 'local_launchpad')
                ? get_string('fellowship_completion_gates', 'local_launchpad', [
                    'met' => $met,
                    'total' => $gatestotal,
                ])
                : ($met . ' of ' . $gatestotal . ' gates passed'),
            'status_chip' => $allmet ? 'ok' : 'line',
            'status_label' => $allmet
                ? self::str('fellowship_completion_met', null, 'Requirements met')
                : self::str('fellowship_completion_outstanding', null, 'Requirements outstanding'),
            'empty_message' => self::str(
                'fellowship_no_courses',
                null,
                'You do not have a LaunchPad course yet. When your place is confirmed, your programme admin will enrol you and your track will appear here.'
            ),
            'strings' => $strings,
        ];
    }

    /**
     * My track page context — HTML layout with live Moodle sections/activities.
     *
     * Full journey + phase accordions + Capstone / meta / How completion works.
     * Capstone + device/tools copy is programme-honest (not demo track fiction).
     * Mentor names come from course teachers when enrolled.
     *
     * @param \stdClass $course
     * @return array
     */
    public static function track_for_user(int $userid, \stdClass $course): array {
        $progress = self::phase_progress_for_user($userid, [$course], $course);
        $outline = $progress['outline'] ?? self::course_outline($course, $userid);
        $copy = self::track_copy_for_course($course);
        $bysection = [];
        foreach ($outline['sections'] ?? [] as $section) {
            $num = (int) ($section['sectionnum'] ?? 0);
            // Orientation lives in section 0; track phases use 1–4.
            $bysection[$num] = $section;
        }

        $blurbs = $copy['blurbs'];
        $isorient = self::is_orientation_course($course);

        $phaseicons = [
            0 => ['icon' => 'student', 'tone' => 'blue'],
            1 => ['icon' => 'book-open-01', 'tone' => 'blue'],
            2 => ['icon' => 'task-01', 'tone' => 'teal'],
            3 => ['icon' => 'layers-01', 'tone' => 'violet'],
            4 => ['icon' => 'award-01', 'tone' => 'amber'],
        ];

        $trackphases = [];
        $firstopen = true;
        $currentset = false;

        // Shared orientation: show section-0 activities as one honest phase (not four locked gates).
        $sourcephases = $progress['phases'] ?? [];
        if ($isorient) {
            $orientacts = array_map(
                [self::class, 'decorate_home_activity'],
                array_values($bysection[0]['activities'] ?? [])
            );
            $orientdone = true;
            $orienttracked = 0;
            foreach ($orientacts as $act) {
                if (!empty($act['tracked'])) {
                    $orienttracked++;
                    if (empty($act['complete'])) {
                        $orientdone = false;
                    }
                }
            }
            if ($orienttracked === 0) {
                $orientdone = false;
            }
            $sourcephases = [[
                'sectionnum' => 0,
                'name' => self::str('fellowship_completion_orientation', null, 'Shared orientation'),
                'short' => 'Orientation',
                'weight' => 0,
                'weight_label' => '—',
                'hint' => '',
                'status' => $orientdone
                    ? self::str('fellowship_complete', null, 'Complete')
                    : self::str('fellowship_track_status_inprogress', null, 'In progress'),
                'chip' => $orientdone ? 'ok' : 'line',
                'has_chip_class' => true,
                'score' => null,
                'has_score' => false,
                'score_display' => '—',
                'ico' => $orientdone ? '✓' : '1',
                'ico_dim' => false,
                'is_met' => $orientdone,
                'is_phase_done' => $orientdone,
                'is_not_released' => false,
                '_orient_activities' => $orientacts,
            ]];
        }

        foreach ($sourcephases as $phase) {
            $num = (int) $phase['sectionnum'];
            if (!empty($phase['_orient_activities'])) {
                $activities = $phase['_orient_activities'];
            } else {
                $section = $bysection[$num] ?? null;
                $activities = array_map(
                    [self::class, 'decorate_home_activity'],
                    array_values($section['activities'] ?? [])
                );
            }
            $released = $activities !== [];
            $isopen = $released && $firstopen;
            if ($released) {
                $firstopen = false;
            }
            $phasedone = !empty($phase['is_met']) || !empty($phase['is_phase_done']);
            $iscurrent = false;
            if ($released && !$currentset && !$phasedone) {
                $iscurrent = true;
                $currentset = true;
            }
            $iconmeta = $phaseicons[$num] ?? ['icon' => 'book-open-01', 'tone' => 'blue'];
            if (!$released) {
                $iconmeta = ['icon' => 'square-lock-02', 'tone' => 'slate'];
            }
            unset($phase['_orient_activities']);
            $trackphases[] = array_merge($phase, [
                'index' => $num > 0 ? $num : 1,
                'anchor' => 'phase-' . ($num > 0 ? $num : 'orient'),
                'blurb' => $blurbs[$num] ?? ($blurbs[1] ?? ''),
                'released' => $released,
                'is_open' => $isopen,
                'is_current' => $iscurrent,
                'activities' => $activities,
                'has_activities' => $activities !== [],
                'locked_note' => get_string('fellowship_track_phase_locked', 'local_launchpad', $phase['weight']),
                'icon' => $iconmeta['icon'],
                'icon_tone' => $iconmeta['tone'],
                'show_lock' => !$released,
            ]);
        }

        $weights = [];
        if (!$isorient) {
            $maxw = max(1, max(self::phase_weights()));
            foreach (self::phase_weights() as $num => $weight) {
                $iconmeta = $phaseicons[$num] ?? ['icon' => 'book-open-01', 'tone' => 'blue'];
                $weights[] = [
                    'name' => blueprint::phase_sections()[$num] ?? ('Phase ' . $num),
                    'weight' => $weight,
                    'weight_label' => $weight . '%',
                    'bar_pct' => (int) round(($weight / $maxw) * 100),
                    'icon' => $iconmeta['icon'],
                    'icon_tone' => $iconmeta['tone'],
                ];
            }
        }

        $summary = trim(format_string($course->summary ?? ''));
        $hasrealsummary = $summary !== '' && stripos($summary, 'placeholder') === false;

        $mentors = self::course_mentors($course, $userid);
        $mentornames = array_map(static fn(array $m): string => (string) $m['name'], $mentors);
        $mentorlabel = $mentornames !== []
            ? implode(', ', $mentornames)
            : get_string('fellowship_track_mentor_tba', 'local_launchpad');

        $completed = (int) ($outline['completed'] ?? 0);
        $trackable = (int) ($outline['trackable'] ?? 0);
        $percent = $outline['percent'] ?? null;
        $haspercent = $percent !== null;
        $next = $outline['next'] ?? null;
        if (is_array($next)) {
            $next = self::decorate_home_activity($next);
        }

        if ($haspercent && (int) $percent >= 100) {
            $coursestatus = get_string('fellowship_complete', 'local_launchpad');
            $coursestatustype = 'ok';
        } else if ($haspercent || $trackable > 0) {
            $coursestatus = get_string('fellowship_track_status_inprogress', 'local_launchpad');
            $coursestatustype = 'line';
        } else {
            $coursestatus = get_string('fellowship_track_status_ready', 'local_launchpad');
            $coursestatustype = 'warn';
        }

        $firstanchor = $trackphases !== [] ? (string) ($trackphases[0]['anchor'] ?? '') : '';

        $started = ($haspercent && (int) $percent > 0) || $completed > 0;
        $ctalabel = $started
            ? get_string('fellowship_cta_resume', 'local_launchpad')
            : get_string('fellowship_cta_start', 'local_launchpad');
        $nextlabel = $started
            ? get_string('fellowship_next_activity', 'local_launchpad')
            : get_string('fellowship_next_activity_start', 'local_launchpad');
        if (is_array($next)) {
            $next['next_label'] = $nextlabel;
            $next['cta_label'] = $ctalabel;
        }

        $lead = $hasrealsummary
            ? self::display_label($summary)
            : $copy['lead'];

        // Orientation has no 1–4 journey gates — hide the journey strip.
        $journey = $progress['journey'] ?? ['has_gates' => false];
        if ($isorient) {
            $journey = ['has_gates' => false, 'gates' => []];
        }

        return [
            'course_name' => self::display_label(format_string($course->fullname)),
            'course_idnumber' => s($course->idnumber),
            'course_lead' => $lead,
            'course_badge' => $isorient
                ? self::str('fellowship_completion_orientation', null, 'Shared orientation')
                : get_string('fellowship_track_badge', 'local_launchpad'),
            'course_status' => $coursestatus,
            'course_statustype' => $coursestatustype,
            'journey' => $journey,
            'track_phases' => $trackphases,
            'has_track_phases' => $trackphases !== [],
            'first_section_anchor' => $firstanchor,
            'has_first_section' => $firstanchor !== '',
            'next' => $next,
            'has_next' => is_array($next),
            'next_label' => $nextlabel,
            'cta_label' => $ctalabel,
            'capstone_body' => $copy['capstone_body'],
            'prerequisite' => $copy['prerequisite'],
            'device' => $copy['device'],
            'tools' => $copy['tools'],
            'mentor' => $mentorlabel,
            'mentors' => $mentors,
            'has_mentors' => $mentors !== [],
            'weight_rows' => $weights,
            'completion_enabled' => !empty($outline['completion_enabled']),
            'completed' => $completed,
            'trackable' => $trackable,
            'percent' => $haspercent ? (int) $percent : null,
            'has_percent' => $haspercent,
            'activitypct' => $haspercent ? (int) $percent : ($trackable > 0
                ? (int) round(($completed / $trackable) * 100) : 0),
            'activitydone' => $completed,
            'activitytotal' => $trackable,
            'hasprogress' => $haspercent || $trackable > 0,
        ];
    }

    /**
     * Learner-facing label: plain text, no curriculum placeholders, soft punctuation.
     */
    public static function display_label(string $value): string {
        $text = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(strip_tags($text));
        // Blueprint activities ship as "Name [placeholder — content pending]".
        $text = preg_replace('/\s*\[[^\]]*placeholder[^\]]*\]\s*$/iu', '', $text) ?? $text;
        $text = preg_replace('/\s*\[[^\]]*content pending[^\]]*\]\s*$/iu', '', $text) ?? $text;
        $text = trim($text);
        // Soften em/en dashes used in seeded LaunchPad course titles.
        $text = str_replace(["\u{2014}", "\u{2013}"], ' · ', $text);
        $text = preg_replace('/\s+·\s+/u', ' · ', $text) ?? $text;
        return trim($text, " \t\n\r\0\x0B·");
    }

    /**
     * Course teachers / editing teachers as mentor contacts (Message CTAs for fellows).
     *
     * @return list<array{id:int,name:string,can_message:bool,message_url:string}>
     */
    public static function course_mentors(\stdClass $course, int $viewerid = 0): array {
        global $CFG, $DB;

        require_once($CFG->libdir . '/enrollib.php');

        $context = \context_course::instance((int) $course->id);
        $roleids = $DB->get_fieldset_select(
            'role',
            'id',
            'shortname IN (?, ?)',
            ['editingteacher', 'teacher']
        );
        if ($roleids === []) {
            return [];
        }

        list($insql, $params) = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED);
        $params['ctx'] = $context->id;
        $params['active'] = ENROL_USER_ACTIVE;
        $params['enabled'] = ENROL_INSTANCE_ENABLED;

        $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                       u.middlename, u.alternatename
                  FROM {role_assignments} ra
                  JOIN {user} u ON u.id = ra.userid AND u.deleted = 0
                  JOIN {user_enrolments} ue ON ue.userid = u.id AND ue.status = :active
                  JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :courseid AND e.status = :enabled
                 WHERE ra.contextid = :ctx AND ra.roleid {$insql}
              ORDER BY u.lastname ASC, u.firstname ASC";
        $params['courseid'] = (int) $course->id;

        $out = [];
        foreach ($DB->get_records_sql($sql, $params) as $user) {
            $mentorid = (int) $user->id;
            if ($viewerid > 0 && $mentorid === $viewerid) {
                continue;
            }
            $out[] = [
                'id' => $mentorid,
                'name' => fullname($user),
                'can_message' => $viewerid > 0 && messaging::can_message($viewerid, $mentorid, 'fellow'),
                'message_url' => messaging::messages_url($mentorid, 'fellow'),
            ];
        }
        return array_values($out);
    }

    /**
     * Display names of teachers / editing teachers on a course (Mentors card).
     *
     * @return list<string>
     */
    public static function course_mentor_names(\stdClass $course): array {
        $names = [];
        foreach (self::course_mentors($course) as $mentor) {
            $names[] = (string) $mentor['name'];
        }
        return $names;
    }

    /**
     * Completion tab: gates ring + requirements table + locked certificate.
     *
     * Reuses {@see phase_progress_for_user()} status. Weighted result only when
     * every phase has a released score. Shared orientation uses Moodle completion
     * on the enrolled lp-orient-* course when present. Certificate stays locked.
     *
     * @param list<\stdClass> $courses
     * @param \stdClass|null $selected
     * @return array
     */
    public static function completion_for_user(int $userid, array $courses, ?\stdClass $selected = null): array {
        $base = self::phase_progress_for_user($userid, $courses, $selected);
        $phases = $base['phases'] ?? [];
        $met = 0;
        $allscored = $phases !== [];
        $weightsum = 0.0;
        $phaseicons = [
            1 => 'book-open-01',
            2 => 'task-01',
            3 => 'layers-01',
            4 => 'award-01',
        ];
        $n = 0;

        foreach ($phases as &$phase) {
            $n++;
            $phase['result_display'] = !empty($phase['has_score']) ? (string) (int) $phase['score'] : '—';
            $phase['icon'] = $phaseicons[$n] ?? 'flag-02';
            $phase['bar_pct'] = !empty($phase['has_score'])
                ? max(0, min(100, (int) round((float) $phase['score'])))
                : 0;
            $phase['show_bar'] = !empty($phase['has_score']);
            if (!empty($phase['is_met'])) {
                $met++;
            }
            if (empty($phase['has_score'])) {
                $allscored = false;
            } else {
                $weightsum += ((float) $phase['score']) * ((int) $phase['weight']) / 100.0;
            }
        }
        unset($phase);

        $orientation = self::orientation_status_for_user($userid, $courses);

        $allmet = $phases !== [] && $met === count($phases);
        $gates = max(1, count($phases));
        $ringpct = (int) round(($met / $gates) * 100);

        // SVG ring geometry for the dedicated gates-passed card.
        $size = 168;
        $radius = ($size / 2) - 14;
        $circ = 2 * M_PI * $radius;
        $dashoffset = $circ * (1 - ($ringpct / 100));

        $gatestotal = count($phases);
        $strings = [
            'page_title' => self::str('fellowship_nav_progress', null, 'Progress'),
            'record_eyebrow' => self::str('fellowship_nav_record', null, 'My record'),
            'journey_heading' => self::str('fellowship_your_journey', null, 'Your journey'),
            'journey_sub' => self::str(
                'fellowship_journey_help_short',
                null,
                'Gates follow your Moodle course sections. Status updates from activity completion on this track.'
            ),
            'gates_heading' => self::str('fellowship_completion_gates_heading', null, 'Programme gates'),
            'gates_sub' => self::str(
                'fellowship_completion_gates_sub',
                null,
                'Phase reviews must reach 70/100. Practice quizzes carry no weight.'
            ),
            'gates_lead' => self::str(
                'fellowship_completion_gates_lead',
                null,
                'Track how each gate and Shared orientation stand against the published criteria.'
            ),
            'ring_label' => self::str('fellowship_completion_ring_label', null, 'gates passed'),
            'stats_heading' => self::str('fellowship_completion_stats_heading', null, 'At a glance'),
            'stat_gates' => self::str('fellowship_completion_stat_gates', null, 'Gates'),
            'stat_orientation' => self::str('fellowship_completion_stat_orientation', null, 'Orientation'),
            'stat_weighted' => self::str('fellowship_completion_stat_weighted', null, 'Weighted'),
            'req_heading' => self::str('fellowship_completion_req_heading', null, 'Requirements'),
            'col_req' => self::str('fellowship_completion_col_req', null, 'Requirement'),
            'col_weight' => self::str('fellowship_completion_col_weight', null, 'Weight'),
            'col_result' => self::str('fellowship_completion_col_result', null, 'Result'),
            'col_status' => self::str('fellowship_completion_col_status', null, 'Status'),
            'calc_note' => self::str(
                'fellowship_completion_calc_note',
                null,
                'Calculated only when every phase has a released review. Practice quizzes carry no weight.'
            ),
            'note' => self::str(
                'fellowship_phases_note',
                null,
                'A missing or unreviewed grade stays pending. A high capstone mark can’t make up for a phase that hasn’t passed.'
            ),
            'cert_heading' => self::str('fellowship_completion_cert_heading', null, 'Certificate'),
            'cert_title' => self::str('fellowship_completion_cert_title', null, 'Completion certificate'),
            'cert_na' => self::str('fellowship_completion_cert_na', null, 'Not available yet'),
            'cert_disclaimer' => self::str(
                'fellowship_completion_cert_disclaimer',
                null,
                'A certificate means the published criteria were met. It doesn’t imply a job, internship or placement.'
            ),
            'empty_title' => self::str('fellowship_no_courses_heading', null, 'No LaunchPad course yet'),
            'go_track' => self::str('fellowship_nav_track', null, 'My track'),
            'weighted' => self::str('fellowship_completion_weighted', null, 'Weighted result'),
            'orientation' => self::str('fellowship_completion_orientation', null, 'Shared orientation'),
        ];

        return array_merge($base, [
            'gates_met' => $met,
            'gates_total' => $gatestotal,
            'gates_label' => get_string_manager()->string_exists('fellowship_completion_gates', 'local_launchpad')
                ? get_string('fellowship_completion_gates', 'local_launchpad', [
                    'met' => $met,
                    'total' => $gatestotal,
                ])
                : ($met . ' of ' . $gatestotal . ' gates passed'),
            'gates_note' => $orientation['gates_note'],
            'all_met' => $allmet,
            'status_chip' => $allmet ? 'ok' : 'line',
            'status_label' => $allmet
                ? self::str('fellowship_completion_met', null, 'Requirements met')
                : self::str('fellowship_completion_outstanding', null, 'Requirements outstanding'),
            'ring_pct' => $ringpct,
            'ring_size' => $size,
            'ring_radius' => round($radius, 2),
            'ring_cx' => $size / 2,
            'ring_cy' => $size / 2,
            'ring_circ' => round($circ, 2),
            'ring_dashoffset' => number_format($dashoffset, 2, '.', ''),
            'orientation_done' => !empty($orientation['done']),
            'orientation_chip' => $orientation['chip'],
            'orientation_status' => $orientation['status'],
            'orientation_weight' => '—',
            'orientation_result' => '—',
            'phases' => $phases,
            'has_weighted' => $allscored,
            'weighted_display' => $allscored ? (string) (int) round($weightsum) : '—',
            'weighted_chip' => $allscored ? ($weightsum >= 70 ? 'ok' : 'warn') : 'line',
            'weighted_status' => $allscored
                ? ($weightsum >= 70
                    ? self::str('fellowship_completion_weighted_ok', null, 'On track')
                    : self::str('fellowship_completion_weighted_low', null, 'Below 70'))
                : self::str('fellowship_completion_weighted_pending', null, 'Pending'),
            'certificate_locked' => true,
            'cert_message' => self::str(
                'fellowship_completion_cert_body',
                null,
                '<b>Certificate issuance is not available</b> yet. Your results are your record until the programme confirms the tool and you meet the criteria.'
            ),
            'track_url' => (new \moodle_url(persona_home::FELLOW_PATH, ['view' => 'track']))->out(false),
            'empty_message' => self::str(
                'fellowship_no_courses',
                null,
                'You do not have a LaunchPad course yet. When your place is confirmed, your programme admin will enrol you and your track will appear here.'
            ),
            'strings' => $strings,
        ]);
    }

    /**
     * Shared orientation row for Completion — from enrolled lp-orient-* course.
     *
     * @param list<\stdClass> $courses
     * @return array{done:bool,chip:string,status:string,gates_note:string}
     */
    private static function orientation_status_for_user(int $userid, array $courses): array {
        $donelabel = self::str('fellowship_completion_orientation_done', null, 'Complete');
        $pendinglabel = self::str('fellowship_completion_orientation_pending', null, 'Pending');
        $progresslabel = self::str('fellowship_completion_orientation_progress', null, 'In progress');
        $notedone = self::str(
            'fellowship_completion_gates_note',
            null,
            'Plus Shared orientation ✓. Every gate must reach 70.'
        );
        $noteopen = self::str(
            'fellowship_completion_gates_note_open',
            null,
            'Shared orientation still open. Every gate must reach 70.'
        );

        $orientation = null;
        foreach ($courses as $course) {
            $idn = strtolower(trim((string) ($course->idnumber ?? '')));
            if (str_starts_with($idn, 'lp-orient-')) {
                $orientation = $course;
                break;
            }
        }

        if ($orientation === null) {
            return [
                'done' => false,
                'chip' => 'line',
                'status' => $pendinglabel,
                'gates_note' => $noteopen,
            ];
        }

        $outline = self::course_outline($orientation, $userid);
        $trackable = (int) ($outline['trackable'] ?? 0);
        $completed = (int) ($outline['completed'] ?? 0);
        $percent = $outline['percent'] ?? null;

        if ($trackable > 0) {
            $done = $completed >= $trackable;
            if ($done) {
                return [
                    'done' => true,
                    'chip' => 'ok',
                    'status' => $donelabel,
                    'gates_note' => $notedone,
                ];
            }
            return [
                'done' => false,
                'chip' => 'line',
                'status' => $completed > 0 ? $progresslabel : $pendinglabel,
                'gates_note' => $noteopen,
            ];
        }

        if ($percent !== null && (int) $percent >= 100) {
            return [
                'done' => true,
                'chip' => 'ok',
                'status' => $donelabel,
                'gates_note' => $notedone,
            ];
        }

        // Enrolled but nothing trackable yet — do not invent a Complete tick.
        return [
            'done' => false,
            'chip' => 'line',
            'status' => $pendinglabel,
            'gates_note' => $noteopen,
        ];
    }

    /**
     * Live XP total from Moodle activity records (same rules as Achievements log).
     *
     * @param list<\stdClass> $courses
     */
    public static function provisional_xp_total(array $courses, int $userid): int {
        $live = self::live_achievements($userid, $courses);
        return (int) $live['xp_total'];
    }

    /**
     * Level ladder from the HTML Fellow portal (thresholds + colours).
     *
     * @return list<array{name: string, xp: int, color: string}>
     */
    public static function achievement_levels(): array {
        return [
            ['name' => 'Explorer', 'xp' => 0, 'color' => '#8FB3FF'],
            ['name' => 'Builder', 'xp' => 200, 'color' => '#5B8DF6'],
            ['name' => 'Practitioner', 'xp' => 450, 'color' => '#1A61F2'],
            ['name' => 'Specialist', 'xp' => 800, 'color' => '#0F4FD6'],
            ['name' => 'Fellow', 'xp' => 1200, 'color' => '#0D2461'],
        ];
    }

    /**
     * Index into {@see achievement_levels()} for the given XP.
     */
    public static function level_index_for_xp(int $xp): int {
        $levels = self::achievement_levels();
        $index = 0;
        foreach ($levels as $i => $level) {
            if ($xp >= (int) $level['xp']) {
                $index = $i;
            }
        }
        return $index;
    }

    /**
     * Achievements template context from live Moodle progress for this fellow.
     *
     * @param list<\stdClass> $courses
     * @return array
     */
    public static function achievements_for_user(int $userid, array $courses): array {
        $live = self::live_achievements($userid, $courses);
        return self::achievements_context_from_live($live);
    }

    /**
     * @deprecated Use {@see achievements_for_user()}; kept for callers that only have XP.
     */
    public static function achievements_context(int $xp): array {
        return self::achievements_context_from_live([
            'xp_total' => $xp,
            'xp_log' => [],
            'badges' => self::badge_catalogue_empty(),
            'badges_earned' => 0,
        ]);
    }

    /**
     * Build Achievements view context from a {@see live_achievements()} result.
     *
     * @param array $live
     * @param string $tab all|earned badge collection filter
     * @return array
     */
    public static function achievements_context_from_live(array $live, string $tab = 'all'): array {
        $tab = ($tab === 'earned') ? 'earned' : 'all';
        $xp = (int) ($live['xp_total'] ?? 0);
        $levels = self::achievement_levels();
        $li = self::level_index_for_xp($xp);
        $currentlabel = self::str('fellowship_level_status_current', null, 'Current Level');
        $nextlabel = self::str('fellowship_level_status_next', null, 'Next Level');
        $lockedlabellevel = self::str('fellowship_level_status_locked', null, 'Locked');
        $donelabel = self::str('fellowship_level_status_done', null, 'Unlocked');

        $ladder = [];
        foreach ($levels as $i => $level) {
            $ispast = $i < $li;
            $isnow = $i === $li;
            $isnext = $i === ($li + 1);
            $islocked = $i > $li;
            if ($isnow) {
                $status = $currentlabel;
                $statusicon = 'checkmark-circle-02';
                $css = 'now';
            } else if ($ispast) {
                $status = $donelabel;
                $statusicon = 'checkmark-circle-02';
                $css = 'past';
            } else if ($isnext) {
                $status = $nextlabel;
                $statusicon = 'arrow-right-02';
                $css = 'next';
            } else {
                $status = $lockedlabellevel;
                $statusicon = 'square-lock-02';
                $css = 'locked';
            }
            $ladder[] = [
                'name' => $level['name'],
                'xp' => $level['xp'],
                'xp_label' => number_format((int) $level['xp']) . ' XP',
                'index' => $i + 1,
                'mark' => (string) ($i + 1),
                'is_past' => $ispast,
                'is_now' => $isnow,
                'is_next' => $isnext,
                'is_locked' => $islocked,
                'status_label' => $status,
                'status_icon' => $statusicon,
                'css' => $css,
                'badge_color' => $ispast ? '#2E9E4F' : ($isnow ? $level['color'] : ($isnext ? '#8FB3FF' : '#C5CDD8')),
            ];
        }

        $current = $levels[$li];
        $next = $levels[$li + 1] ?? null;
        $tonext = null;
        $pcttonext = 100;
        $progresscaption = '';
        $nextlevelxp = (int) $current['xp'];
        if ($next !== null) {
            $span = max(1, (int) $next['xp'] - (int) $current['xp']);
            $into = max(0, $xp - (int) $current['xp']);
            $tonext = max(0, (int) $next['xp'] - $xp);
            $pcttonext = (int) min(100, round(($into / $span) * 100));
            $nextlevelxp = (int) $next['xp'];
            $progresscaption = number_format($xp) . ' / ' . number_format($nextlevelxp)
                . ' XP to ' . $next['name'];
        } else {
            $progresscaption = self::str('fellowship_level_top', null, 'Top level reached.');
        }

        $badges = $live['badges'] ?? self::badge_catalogue_empty();
        $earnedlabel = self::str('fellowship_badge_pill_earned', null, 'Earned');
        $lockedlabel = self::str('fellowship_badge_pill_locked', null, 'Locked');
        foreach ($badges as &$badge) {
            $isearned = !empty($badge['earned']);
            $badge['status_pill'] = $isearned ? $earnedlabel : $lockedlabel;
            $badge['description'] = (string) ($badge['how'] ?? $badge['detail'] ?? '');
            $badge['has_image'] = !empty($badge['imageurl']);
            if (empty($badge['icon'])) {
                $badge['icon'] = 'square-lock-02';
            }
        }
        unset($badge);

        $earneditems = array_values(array_filter($badges, static fn(array $b): bool => !empty($b['earned'])));
        usort($earneditems, static function (array $a, array $b): int {
            return ((int) ($b['at'] ?? 0)) <=> ((int) ($a['at'] ?? 0));
        });
        $lockeditems = array_values(array_filter($badges, static fn(array $b): bool => empty($b['earned'])));
        $recent = array_slice($earneditems, 0, 4);
        $nextbadges = array_slice($lockeditems, 0, 3);

        $badgesearned = (int) ($live['badges_earned'] ?? count($earneditems));
        $badgestotal = count($badges);
        $badgespct = $badgestotal > 0 ? (int) round(($badgesearned / $badgestotal) * 100) : 0;

        $pageitems = $tab === 'earned' ? $earneditems : $badges;
        $xplog = $live['xp_log'] ?? [];

        $xprules = [
            [
                'label' => self::str('fellowship_xp_rule_lesson', null, 'Finish a lesson'),
                'xp' => 50,
                'icon' => 'book-open-01',
            ],
            [
                'label' => self::str('fellowship_xp_rule_quiz', null, 'Submit a quiz attempt'),
                'xp' => 40,
                'icon' => 'quiz-05',
            ],
            [
                'label' => self::str('fellowship_xp_rule_correct', null, 'Each correct answer'),
                'xp' => 10,
                'icon' => 'checkmark-circle-02',
            ],
            [
                'label' => self::str('fellowship_xp_rule_assign', null, 'Submit an assignment'),
                'xp' => 120,
                'icon' => 'file-upload',
            ],
            [
                'label' => self::str('fellowship_xp_rule_resubmit', null, 'Resubmit after feedback'),
                'xp' => 80,
                'icon' => 'refresh',
            ],
            [
                'label' => self::str('fellowship_xp_rule_gate', null, 'Pass a gate'),
                'xp' => 200,
                'icon' => 'flag-02',
            ],
            [
                'label' => self::str('fellowship_xp_rule_portfolio', null, 'Save a portfolio case study'),
                'xp' => 60,
                'icon' => 'folder-01',
            ],
        ];

        $achievementsurl = (new \moodle_url(persona_home::FELLOW_PATH, ['view' => 'achievements']))->out(false);
        $moodlebadges = (new \moodle_url('/badges/mybadges.php'))->out(false);

        // Pre-resolve with fallbacks so tabs/pills never show Moodle [[missing]] placeholders.
        $strings = [
            'level_path' => self::str('fellowship_level_path', null, 'Level path'),
            'progress_sub' => self::str(
                'fellowship_achievements_progress_sub',
                null,
                'Earn XP through lessons, quizzes, and submissions to unlock new levels.'
            ),
            'level_chip' => self::str('fellowship_level_chip', null, 'Level'),
            'level_now' => self::str('fellowship_level_now', null, 'Current level:'),
            'level_current_tag' => self::str('fellowship_level_current_tag', null, 'Current Level'),
            'xp_earned_label' => self::str('fellowship_xp_earned_label', null, 'XP earned'),
            'xp_to_level' => self::str('fellowship_xp_to_level', null, 'to'),
            'level_top' => self::str('fellowship_level_top', null, 'Top level reached.'),
            'xp_history_sub' => self::str(
                'fellowship_xp_history_sub',
                null,
                'Track your learning activity and XP earned from lessons, quizzes, and submissions.'
            ),
            'xp_empty_title' => self::str('fellowship_xp_empty_title', null, 'No XP Yet!'),
            'xp_empty_body' => self::str(
                'fellowship_xp_empty_body',
                null,
                'Finish a lesson, quiz, or assignment in Moodle to earn your first XP and start building your learning progress.'
            ),
            'xp_step_lessons' => self::str('fellowship_xp_step_lessons', null, 'Complete Lessons'),
            'xp_step_quizzes' => self::str('fellowship_xp_step_quizzes', null, 'Take Quizzes'),
            'xp_step_assigns' => self::str('fellowship_xp_step_assigns', null, 'Submit Assignments'),
            'xp_go_courses' => self::str('fellowship_xp_go_courses', null, 'Go to My Courses'),
            'xp_title' => self::str(
                'fellowship_xp_title',
                null,
                'XP from your Moodle activity record (lessons, quizzes, submissions). Does not affect grades.'
            ),
            'badges_heading' => self::str('fellowship_badges_heading', null, 'Badges'),
            'badges_of' => self::str('fellowship_badges_of', null, 'of'),
            'badges_earned' => self::str('fellowship_badges_earned', null, 'earned'),
            'badges_open_moodle' => self::str('fellowship_badges_open_moodle', null, 'Open Moodle badges'),
            'badges_tab_all' => self::str('fellowship_badges_tab_all', null, 'All'),
            'badges_tab_earned' => self::str('fellowship_badges_tab_earned', null, 'Earned'),
            'badges_empty_title' => self::str('fellowship_badges_empty_title', null, 'No badges in this view'),
            'badges_empty_body' => self::str(
                'fellowship_badges_empty_body',
                null,
                'Finish lessons, quizzes or assignments to unlock badges here.'
            ),
            'badges_recent_heading' => self::str('fellowship_badges_recent_heading', null, 'Recently earned'),
            'badges_recent_empty' => self::str(
                'fellowship_badges_recent_empty',
                null,
                'No badges earned yet — your first unlock will show here.'
            ),
            'badges_next_heading' => self::str('fellowship_badges_next_heading', null, 'Next up'),
            'badges_next_done' => self::str(
                'fellowship_badges_next_done',
                null,
                'You’ve unlocked every LaunchPad badge in this set.'
            ),
            'xp_history' => self::str('fellowship_xp_history', null, 'XP history'),
            'xp_history_empty' => self::str(
                'fellowship_xp_history_empty',
                null,
                'Nothing yet. Finish a lesson, quiz or assignment in Moodle to earn your first XP.'
            ),
            'xp_provisional_note' => self::str(
                'fellowship_xp_provisional_note',
                null,
                'XP and badges refresh from your Moodle completion, quiz and assignment records.'
            ),
            'xp_how' => self::str('fellowship_xp_how', null, 'How XP works'),
            'xp_how_note' => self::str(
                'fellowship_xp_how_note',
                null,
                'Each listed action counts once from your Moodle record. XP never affects your grade. Portfolio XP stays unavailable until that feature ships.'
            ),
            'xp_how_sub' => self::str(
                'fellowship_xp_how_sub',
                null,
                'Each action awards XP once from your Moodle activity.'
            ),
            'badge_pill_earned' => $earnedlabel,
            'badge_pill_locked' => $lockedlabel,
        ];

        return [
            'xp_total' => $xp,
            'xp_total_label' => number_format($xp),
            'level_name' => $current['name'],
            'level_index' => $li + 1,
            'level_color' => $current['color'],
            'has_next_level' => $next !== null,
            'next_level_name' => $next['name'] ?? '',
            'next_level_xp' => $nextlevelxp,
            'next_level_xp_label' => number_format($nextlevelxp),
            'xp_to_next' => $tonext,
            'pct_to_next' => $pcttonext,
            'progress_caption' => $progresscaption,
            'track_url' => (new \moodle_url(persona_home::FELLOW_PATH, ['view' => 'track']))->out(false),
            'ladder' => $ladder,
            'badges' => $badges,
            'page_items' => $pageitems,
            'has_items' => $pageitems !== [],
            'earned_items' => $earneditems,
            'has_earned_items' => $earneditems !== [],
            'recent_items' => $recent,
            'has_recent' => $recent !== [],
            'next_items' => $nextbadges,
            'has_next_badges' => $nextbadges !== [],
            'has_side_panels' => true,
            'badges_earned' => $badgesearned,
            'badges_total' => $badgestotal,
            'badges_pct' => $badgespct,
            'tab_all' => $tab === 'all',
            'tab_earned' => $tab === 'earned',
            'tab_all_url' => (new \moodle_url(persona_home::FELLOW_PATH, ['view' => 'achievements']))->out(false),
            'tab_earned_url' => (new \moodle_url(persona_home::FELLOW_PATH, [
                'view' => 'achievements',
                'tab' => 'earned',
            ]))->out(false),
            'moodle_badges_url' => $moodlebadges,
            'achievements_url' => $achievementsurl,
            'has_xp_log' => $xplog !== [],
            'xp_log' => $xplog,
            'xp_rules' => $xprules,
            'xp_empty_art_url' => (new \moodle_url('/local/launchpad/assets/img/fellowship-xp-empty.png'))->out(false),
            'strings' => $strings,
        ];
    }

    /**
     * Derive XP log + badge state from Moodle completion, quiz grades, and assign submissions.
     *
     * No local unlock tables — recomputed from Moodle records each request.
     *
     * @param list<\stdClass> $courses
     * @return array{xp_total: int, xp_log: list<array>, badges: list<array>, badges_earned: int}
     */
    public static function live_achievements(int $userid, array $courses): array {
        global $CFG, $DB, $USER;

        require_once($CFG->libdir . '/completionlib.php');
        require_once($CFG->libdir . '/gradelib.php');

        $xplog = [];
        $pagesdone = 0;
        $pagesdones1 = 0;
        $pagestotals1 = 0;
        $pagecompletebyname = [];
        $quizperfect = false;
        $quizperfectat = 0;
        $assignsubmitted = false;
        $assignsubmittedat = 0;
        $assignresubmit = false;
        $assignresubmitat = 0;
        $foundationscomplete = false;
        $foundationsgateat = 0;
        $foundationsgradeok = false;

        foreach ($courses as $course) {
            $modinfo = get_fast_modinfo($course, $userid);
            $completion = new \completion_info($course);
            $completionenabled = $completion->is_enabled();

            $s1tracked = 0;
            $s1done = 0;

            foreach ($modinfo->get_cms() as $cm) {
                if (!$cm->uservisible || $cm->deletioninprogress || $cm->modname === 'label') {
                    continue;
                }

                $sectionnum = 0;
                try {
                    $sectionnum = (int) $cm->get_section_info()->section;
                } catch (\Throwable $e) {
                    $sectionnum = 0;
                }

                $name = $cm->get_formatted_name();
                $namelower = \core_text::strtolower($name);
                $iscomplete = false;
                $completedtime = 0;
                if ($completionenabled && $completion->is_enabled($cm) != COMPLETION_TRACKING_NONE) {
                    $data = $completion->get_data($cm, false, $userid);
                    $state = (int) $data->completionstate;
                    $iscomplete = ($state === COMPLETION_COMPLETE || $state === COMPLETION_COMPLETE_PASS);
                    $completedtime = (int) ($data->timemodified ?? 0);
                }

                if ($cm->modname === 'page' || $cm->modname === 'resource' || $cm->modname === 'url') {
                    if ($sectionnum === 1) {
                        $pagestotals1++;
                        if ($iscomplete) {
                            $pagesdones1++;
                        }
                    }
                    if ($iscomplete) {
                        $pagesdone++;
                        $pagecompletebyname[] = $namelower;
                        $xplog[] = self::xp_log_entry(
                            $completedtime,
                            get_string('fellowship_xp_log_lesson', 'local_launchpad', $name),
                            50,
                            'page-' . $cm->id
                        );
                    }
                }

                if ($sectionnum === 1 && $completionenabled && $completion->is_enabled($cm) != COMPLETION_TRACKING_NONE) {
                    $s1tracked++;
                    if ($iscomplete) {
                        $s1done++;
                        $foundationsgateat = max($foundationsgateat, $completedtime);
                    }
                }

                if ($cm->modname === 'quiz') {
                    $attempts = $DB->get_records('quiz_attempts', [
                        'userid' => $userid,
                        'quiz' => (int) $cm->instance,
                        'state' => 'finished',
                    ], 'attempt DESC', '*', 0, 1);
                    $attempt = $attempts ? reset($attempts) : false;
                    if ($attempt) {
                        $at = (int) ($attempt->timefinish ?: $attempt->timestart);
                        $xplog[] = self::xp_log_entry(
                            $at,
                            get_string('fellowship_xp_log_quiz', 'local_launchpad', $name),
                            40,
                            'quiz-' . $cm->id
                        );
                        $quiz = $DB->get_record('quiz', ['id' => (int) $cm->instance], 'id,sumgrades,grade');
                        $fraction = null;
                        if ($quiz && (float) $quiz->sumgrades > 0 && $attempt->sumgrades !== null) {
                            $fraction = ((float) $attempt->sumgrades) / ((float) $quiz->sumgrades);
                        } else {
                            $grades = grade_get_grades($course->id, 'mod', 'quiz', (int) $cm->instance, $userid);
                            $gg = $grades->items[0]->grades[$userid] ?? null;
                            if ($gg && $gg->grade !== null && !empty($grades->items[0]->grademax)) {
                                $fraction = ((float) $gg->grade) / ((float) $grades->items[0]->grademax);
                            }
                        }
                        if ($fraction !== null && $fraction >= 0.999) {
                            $quizperfect = true;
                            $quizperfectat = max($quizperfectat, $at);
                            // HTML: +10 per correct (Foundations check = 4). Award 40 when perfect.
                            $xplog[] = self::xp_log_entry(
                                $at,
                                get_string('fellowship_xp_log_quiz_perfect', 'local_launchpad', $name),
                                40,
                                'quiz-perfect-' . $cm->id
                            );
                        } else if ($fraction !== null && $fraction > 0) {
                            $correctxp = (int) round($fraction * 4) * 10;
                            if ($correctxp > 0) {
                                $xplog[] = self::xp_log_entry(
                                    $at,
                                    get_string('fellowship_xp_log_quiz_correct', 'local_launchpad', $name),
                                    $correctxp,
                                    'quiz-correct-' . $cm->id
                                );
                            }
                        }
                    }
                }

                if ($cm->modname === 'assign') {
                    $allsubs = $DB->get_records('assign_submission', [
                        'assignment' => (int) $cm->instance,
                        'userid' => $userid,
                        'status' => 'submitted',
                    ], 'attemptnumber ASC');
                    if ($allsubs) {
                        $first = true;
                        foreach ($allsubs as $sub) {
                            $at = (int) ($sub->timemodified ?: $sub->timecreated);
                            if ($first) {
                                $assignsubmitted = true;
                                $assignsubmittedat = max($assignsubmittedat, $at);
                                $xplog[] = self::xp_log_entry(
                                    $at,
                                    get_string('fellowship_xp_log_assign', 'local_launchpad', $name),
                                    120,
                                    'assign-' . $cm->id
                                );
                                $first = false;
                            } else {
                                $assignresubmit = true;
                                $assignresubmitat = max($assignresubmitat, $at);
                                $xplog[] = self::xp_log_entry(
                                    $at,
                                    get_string('fellowship_xp_log_resubmit', 'local_launchpad', $name),
                                    80,
                                    'assign-re-' . $cm->id . '-' . $sub->attemptnumber
                                );
                            }
                        }
                    }

                    $grades = grade_get_grades($course->id, 'mod', 'assign', (int) $cm->instance, $userid);
                    $gg = $grades->items[0]->grades[$userid] ?? null;
                    if ($gg && $gg->grade !== null && !empty($grades->items[0]->grademax)) {
                        $pct = (((float) $gg->grade) / ((float) $grades->items[0]->grademax)) * 100;
                        if ($sectionnum === 1 && $pct >= 70) {
                            $foundationsgradeok = true;
                            $foundationsgateat = max($foundationsgateat, (int) ($gg->dategraded ?: time()));
                        }
                    }
                }
            }

            if ($s1tracked > 0 && $s1done >= $s1tracked) {
                $foundationscomplete = true;
            }
        }

        $gatecleared = $foundationscomplete || $foundationsgradeok;
        if ($gatecleared) {
            $xplog[] = self::xp_log_entry(
                $foundationsgateat ?: time(),
                get_string('fellowship_xp_log_gate', 'local_launchpad'),
                200,
                'gate-foundations'
            );
        }

        // Newest first for the history table.
        usort($xplog, static function (array $a, array $b): int {
            return ($b['ts'] <=> $a['ts']) ?: strcmp($a['key'], $b['key']);
        });
        // Dedupe by key (gate / activities).
        $seen = [];
        $deduped = [];
        foreach ($xplog as $row) {
            if (isset($seen[$row['key']])) {
                continue;
            }
            $seen[$row['key']] = true;
            $deduped[] = $row;
        }
        $xplog = $deduped;

        $xptotal = 0;
        foreach ($xplog as $row) {
            $xptotal += (int) $row['xp'];
        }

        $evidence = false;
        $evidenceat = 0;
        foreach ($pagecompletebyname as $n) {
            if (str_contains($n, 'evidence') || str_contains($n, 'assumption')) {
                $evidence = true;
            }
        }
        if (!$evidence && $pagesdone >= 2) {
            $evidence = true;
        }

        $alllessons = ($pagestotals1 > 0 && $pagesdones1 >= $pagestotals1)
            || ($pagestotals1 === 0 && $pagesdone >= 3);

        $profiledone = false;
        $profileat = 0;
        $user = ($USER && (int) $USER->id === $userid) ? $USER : \core_user::get_user($userid);
        if ($user) {
            $headline = trim(strip_tags((string) ($user->description ?? '')));
            $city = trim((string) ($user->city ?? ''));
            if ($headline !== '' || $city !== '') {
                $profiledone = true;
                $profileat = (int) ($user->timemodified ?? time());
            }
        }

        // Hugeicons slugs (iconify: hugeicons:<slug>) — no emoji.
        $badgedefs = [
            'first' => [
                'name' => 'First step',
                'icon' => 'plant-01',
                'how' => 'Finish your first lesson',
                'earned' => $pagesdone >= 1,
                'at' => $pagesdone >= 1 ? self::first_log_ts($xplog, 'page-') : 0,
            ],
            'evidence' => [
                'name' => 'Evidence hunter',
                'icon' => 'search-01',
                'how' => 'Finish Lesson 2: Evidence versus assumptions',
                'earned' => $evidence,
                'at' => $evidence ? self::first_log_ts($xplog, 'page-') : 0,
            ],
            'allLessons' => [
                'name' => 'Foundations reader',
                'icon' => 'books-01',
                'how' => 'Finish all Foundations lessons in your track',
                'earned' => $alllessons,
                'at' => $alllessons ? self::first_log_ts($xplog, 'page-') : 0,
            ],
            'ace' => [
                'name' => 'Quiz ace',
                'icon' => 'target-01',
                'how' => 'Score full marks on the Foundations check',
                'earned' => $quizperfect,
                'at' => $quizperfectat,
            ],
            'handin' => [
                'name' => 'Hand-in hero',
                'icon' => 'mail-send-01',
                'how' => 'Submit your first assignment',
                'earned' => $assignsubmitted,
                'at' => $assignsubmittedat,
            ],
            'loop' => [
                'name' => 'Feedback loop',
                'icon' => 'refresh',
                'how' => 'Resubmit after your mentor requests changes',
                'earned' => $assignresubmit,
                'at' => $assignresubmitat,
            ],
            'gate' => [
                'name' => 'Gate one cleared',
                'icon' => 'flag-02',
                'how' => 'Pass Foundations (completion or grade ≥ 70)',
                'earned' => $gatecleared,
                'at' => $foundationsgateat,
            ],
            'portfolio' => [
                'name' => 'Portfolio starter',
                'icon' => 'folder-01',
                'how' => 'Save your first portfolio case study',
                'earned' => false,
                'at' => 0,
            ],
            'profile' => [
                'name' => 'Introduced',
                'icon' => 'waving-hand-01',
                'how' => 'Add a city or description on your Moodle profile',
                'earned' => $profiledone,
                'at' => $profileat,
            ],
        ];

        $badges = [];
        $earnedcount = 0;
        foreach ($badgedefs as $id => $def) {
            $earned = !empty($def['earned']);
            if ($earned) {
                $earnedcount++;
            }
            $at = (int) ($def['at'] ?? 0);
            $badges[] = [
                'id' => $id,
                'name' => $def['name'],
                'icon' => $def['icon'],
                'how' => $def['how'],
                'earned' => $earned,
                'at' => $at,
                'has_iconify' => true,
                'detail' => $earned
                    ? get_string('fellowship_badge_earned_on', 'local_launchpad', $at ? userdate($at, get_string('strftimedate', 'langconfig')) : get_string('fellowship_badge_earned_recent', 'local_launchpad'))
                    : $def['how'],
                'css' => $earned ? 'on' : '',
            ];
        }

        return [
            'xp_total' => $xptotal,
            'xp_log' => array_map(static function (array $row): array {
                return [
                    'at' => $row['at'],
                    'title' => $row['title'],
                    'xp' => $row['xp'],
                ];
            }, $xplog),
            'badges' => $badges,
            'badges_earned' => $earnedcount,
        ];
    }

    /**
     * Home hero ring, KPI strip, and recent badges (newest earned first).
     *
     * @param array $outline From {@see course_outline()}
     * @param array $journey From {@see journey_from_outline()}
     * @param array $live From {@see live_achievements()}
     * @param int $limit Max recent badges to show
     * @return array Mustache extras for fellowship_home
     */
    public static function home_summary(array $outline, array $journey, array $live, int $limit = 4): array {
        $percent = $outline['percent'] ?? null;
        $completed = (int) ($outline['completed'] ?? 0);
        $trackable = (int) ($outline['trackable'] ?? 0);
        $hascoursepercent = $percent !== null;

        $currentgate = get_string('fellowship_gate_none', 'local_launchpad');
        $currentshort = $currentgate;
        $currentsection = 0;
        foreach ($journey['gates'] ?? [] as $gate) {
            // Ring caption stays compact (spine short); full Moodle section name is for cards/KPIs.
            $gateshort = (string) ($gate['spine_short'] ?? $gate['short'] ?? $gate['name'] ?? $currentgate);
            $gatename = (string) ($gate['name'] ?? $gateshort);
            if (!empty($gate['is_current'])) {
                $currentgate = $gatename;
                $currentshort = $gateshort;
                $currentsection = (int) ($gate['sectionnum'] ?? 0);
                break;
            }
            if (!empty($gate['is_done'])) {
                $currentgate = $gatename;
                $currentshort = $gateshort;
                $currentsection = (int) ($gate['sectionnum'] ?? 0);
            }
        }

        // Prefer course-wide activity completion %. If nothing is trackable yet,
        // fall back to the current gate section (still 0 when that section has
        // no completion criteria). Always show the hero ring when enrolled.
        $ringpct = $hascoursepercent ? (int) $percent : 0;
        $gatecompleted = 0;
        $gatetrackable = 0;
        if (!$hascoursepercent && $currentsection > 0) {
            foreach ($outline['sections'] ?? [] as $section) {
                if ((int) ($section['sectionnum'] ?? 0) !== $currentsection) {
                    continue;
                }
                foreach ($section['activities'] ?? [] as $activity) {
                    if (empty($activity['tracked'])) {
                        continue;
                    }
                    $gatetrackable++;
                    if (!empty($activity['complete'])) {
                        $gatecompleted++;
                    }
                }
            }
            if ($gatetrackable > 0) {
                $ringpct = (int) round(($gatecompleted / $gatetrackable) * 100);
            }
        }

        $alldone = $hascoursepercent && $trackable > 0 && $completed >= $trackable;
        if ($alldone) {
            $currentshort = get_string('fellowship_gate_complete', 'local_launchpad');
            $currentgate = $currentshort;
        }

        $size = 112;
        $radius = ($size / 2) - 10;
        $circ = 2 * M_PI * $radius;
        $dashoffset = $circ * (1 - ($ringpct / 100));
        $ring = [
            'pct' => $ringpct,
            'size' => $size,
            'radius' => round($radius, 2),
            'cx' => $size / 2,
            'cy' => $size / 2,
            'circ' => round($circ, 2),
            'dashoffset' => number_format($dashoffset, 2, '.', ''),
        ];

        $badges = $live['badges'] ?? [];
        $earned = [];
        foreach ($badges as $badge) {
            if (empty($badge['earned'])) {
                continue;
            }
            $earned[] = $badge;
        }
        usort($earned, static function (array $a, array $b): int {
            $at = ((int) ($b['at'] ?? 0)) <=> ((int) ($a['at'] ?? 0));
            if ($at !== 0) {
                return $at;
            }
            return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });
        $achievementsurl = (new \moodle_url(persona_home::FELLOW_PATH, ['view' => 'achievements']))->out(false);
        $recent = array_slice($earned, 0, max(1, $limit));
        foreach ($recent as &$badge) {
            $badge['url'] = $achievementsurl;
            $badge['has_image'] = !empty($badge['imageurl']);
            $badge['has_iconify'] = !empty($badge['icon']);
        }
        unset($badge);

        $badgesearned = (int) ($live['badges_earned'] ?? count($earned));
        $badgestotal = count($badges);

        $requiredvalue = get_string('fellowship_stat_required_na', 'local_launchpad');
        if ($hascoursepercent) {
            $requiredvalue = $completed . '/' . $trackable;
        } else if ($gatetrackable > 0) {
            $requiredvalue = $gatecompleted . '/' . $gatetrackable;
        }

        // Scoped list: current gate section (Employer pattern); keep Foundations for BC.
        $foundations = self::section_activities_from_outline($outline, 1);
        $scoped = [];
        $scopedname = $currentgate;
        if ($currentsection > 0) {
            $scoped = self::section_activities_from_outline($outline, $currentsection);
        }
        if ($scoped === [] && !empty($outline['sections'])) {
            foreach ($outline['sections'] as $section) {
                $acts = array_values($section['activities'] ?? []);
                if ($acts === []) {
                    continue;
                }
                $scoped = $acts;
                $scopedname = (string) ($section['name'] ?? $scopedname);
                break;
            }
        }
        if ($scoped === [] && $foundations !== []) {
            $scoped = $foundations;
            $scopedname = get_string('fellowship_foundations_heading', 'local_launchpad');
        }
        $scoped = array_slice($scoped, 0, 8);
        $scoped = array_map([self::class, 'decorate_home_activity'], $scoped);

        $seccounts = [];
        foreach ($outline['sections'] ?? [] as $section) {
            $sec = (int) ($section['sectionnum'] ?? 0);
            if ($sec <= 0) {
                continue;
            }
            $seccounts[$sec] = count($section['activities'] ?? []);
        }
        $gatelegend = [];
        foreach ($journey['gates'] ?? [] as $gate) {
            $sec = (int) ($gate['sectionnum'] ?? 0);
            $step = (string) ($gate['status'] ?? 'lock');
            $count = $seccounts[$sec] ?? 0;
            $hasmoodlesection = !empty($gate['has_moodle_section']) || isset($seccounts[$sec]);
            if ($count > 0) {
                $activitymeta = get_string('fellowship_activities_count', 'local_launchpad', $count);
            } else if (!$hasmoodlesection && empty($gate['is_current'])) {
                $activitymeta = get_string('fellowship_journey_section_missing', 'local_launchpad');
            } else {
                $activitymeta = '';
            }
            $gatelegend[] = array_merge($gate, [
                'step' => $step,
                'activity_count' => $count,
                'activity_meta' => $activitymeta,
                'has_activity_meta' => $activitymeta !== '' && empty($gate['is_current']),
                'legend_icon' => self::section_legend_icon($step),
            ]);
        }

        $phasesurl = (new \moodle_url(persona_home::FELLOW_PATH, ['view' => 'progress']))->out(false);
        $trackurl = (new \moodle_url(persona_home::FELLOW_PATH, ['view' => 'track']))->out(false);
        $hasscoped = $scoped !== [];
        $hasgates = $gatelegend !== [];
        $badgesvalue = $badgestotal > 0
            ? $badgesearned . '/' . $badgestotal
            : (string) $badgesearned;

        return [
            // Always render the hero ring for enrolled fellows (0% is honest).
            'show_hero_ring' => true,
            'has_percent' => $hascoursepercent || $trackable > 0 || !empty($outline['sections']),
            'percent' => $hascoursepercent ? (int) $percent : null,
            'completed' => $completed,
            'trackable' => $trackable,
            'ring' => $ring,
            'ring_pct' => $ringpct,
            'ring_label' => $currentshort,
            'ring_size' => $size,
            'ring_radius' => round($radius, 2),
            'ring_cx' => $size / 2,
            'ring_cy' => $size / 2,
            'ring_circ' => round($circ, 2),
            'ring_dashoffset' => number_format($dashoffset, 2, '.', ''),
            'stat_badges_value' => $badgesvalue,
            'stat_required_value' => $requiredvalue,
            'stat_gate_value' => $currentgate,
            'has_stats' => true,
            'stat_cards' => [
                [
                    'url' => $achievementsurl,
                    'icon' => 'champion',
                    'tone' => 'blue',
                    'value' => $badgesvalue,
                    'label' => get_string('fellowship_stat_badges', 'local_launchpad'),
                ],
                [
                    // Prefer in-page scoped list; fall back to Track when that block is absent.
                    'url' => $hasscoped ? '#fellowship-scoped' : $trackurl,
                    'icon' => 'checkmark-circle-02',
                    'tone' => 'violet',
                    'value' => $requiredvalue,
                    'label' => get_string('fellowship_stat_required', 'local_launchpad'),
                ],
                [
                    // Prefer journey card; fall back to Phase progress when gates are absent.
                    'url' => $hasgates ? '#fellowship-journey' : $phasesurl,
                    'icon' => 'flag-01',
                    'tone' => 'green',
                    'value' => $currentgate,
                    'label' => get_string('fellowship_stat_gate', 'local_launchpad'),
                    'is_text' => true,
                ],
            ],
            'scoped_activities' => $scoped,
            'has_scoped_activities' => $hasscoped,
            'scoped_section_name' => $scopedname,
            'scoped_section_help' => get_string('fellowship_scoped_help', 'local_launchpad'),
            'gate_legend' => $gatelegend,
            'has_gate_legend' => $hasgates,
            'recent_badges' => $recent,
            'has_recent_badges' => $recent !== [],
            'achievements_url' => $achievementsurl,
            'phases_url' => $phasesurl,
            'track_url_default' => $trackurl,
            'foundations_activities' => $foundations,
            'has_foundations_activities' => $foundations !== [],
            'progresspct' => $ringpct,
            'all_tracked_done' => $alldone,
        ];
    }

    /**
     * Moodle course overview image URL when an image is uploaded (empty string otherwise).
     *
     * @param \stdClass $course
     * @return string
     */
    public static function course_image_url(\stdClass $course): string {
        $image = \core_course\external\course_summary_exporter::get_course_image($course);
        return is_string($image) && $image !== '' ? $image : '';
    }

    /**
     * Legend icon for a journey gate step (Hugeicons name).
     */
    public static function section_legend_icon(string $step): string {
        return match ($step) {
            'done' => 'checkmark-circle-02',
            'cur' => 'flag-01',
            'lock' => 'square-lock-02',
            default => 'book-open-01',
        };
    }

    /**
     * Iconify row chrome for home activity list (Moodle modname only).
     *
     * @param array<string,mixed> $activity
     * @return array<string,mixed>
     */
    public static function decorate_home_activity(array $activity): array {
        $mod = (string) ($activity['modname'] ?? '');
        $icon = 'book-open-01';
        $tone = 'blue';
        if ($mod === 'quiz') {
            $icon = 'help-circle';
            $tone = 'violet';
        } else if (in_array($mod, ['assign', 'workshop'], true)) {
            $icon = 'task-01';
            $tone = 'teal';
        } else if (in_array($mod, ['page', 'resource', 'url', 'book', 'folder', 'file'], true)) {
            $icon = 'file-01';
            $tone = 'blue';
        } else if ($mod === 'forum') {
            $icon = 'message-01';
            $tone = 'amber';
        }
        $tracked = !empty($activity['tracked']);
        $complete = !empty($activity['complete']);
        if ($tracked && $complete) {
            $status = get_string('fellowship_complete', 'local_launchpad');
            $statustone = 'ok';
        } else if ($tracked) {
            $status = get_string('fellowship_incomplete', 'local_launchpad');
            $statustone = 'line';
        } else {
            $status = get_string('fellowship_not_tracked', 'local_launchpad');
            $statustone = 'line';
        }
        $meta = (string) ($activity['modname_display'] ?? '');
        $activity['icon'] = $icon;
        $activity['tone'] = $tone;
        $activity['status_label'] = $status;
        $activity['statustone'] = $statustone;
        $activity['meta'] = $meta !== '' ? \core_text::strtoupper($meta) : $meta;
        return $activity;
    }

    /**
     * Activities for one course section from a course_outline() result.
     *
     * @param array $outline
     * @param int $sectionnum
     * @return list<array>
     */
    public static function section_activities_from_outline(array $outline, int $sectionnum): array {
        foreach ($outline['sections'] ?? [] as $section) {
            if ((int) ($section['sectionnum'] ?? -1) === $sectionnum) {
                return array_values($section['activities'] ?? []);
            }
        }
        return [];
    }

    /**
     * Course news-forum posts for enrolled lp-* courses (newest first).
     *
     * @param list<\stdClass> $courses
     * @return array{
     *   items: list<array>,
     *   has_items: bool,
     *   has_posts: bool,
     *   count_posts: int,
     *   count_courses: int,
     *   moodle_news_url: string,
     *   has_moodle_news: bool
     * }
     */
    public static function list_announcements(int $userid, array $courses): array {
        global $DB;

        // Read-only: never call forum_get_course_forum() — it creates a news forum if missing.
        $items = [];
        $firstforumurl = '';
        $ctalabel = self::str('fellowship_news_open', null, 'Open');

        foreach ($courses as $course) {
            $modinfo = get_fast_modinfo($course, $userid);
            foreach ($modinfo->get_cms() as $cm) {
                if ($cm->modname !== 'forum' || !$cm->uservisible || $cm->deletioninprogress) {
                    continue;
                }
                $forum = $DB->get_record('forum', ['id' => (int) $cm->instance], 'id,type', IGNORE_MISSING);
                if (!$forum || $forum->type !== 'news') {
                    continue;
                }

                $forumurl = (new \moodle_url('/mod/forum/view.php', ['id' => $cm->id]))->out(false);
                if ($firstforumurl === '') {
                    $firstforumurl = $forumurl;
                }

                $discussions = $DB->get_records('forum_discussions', [
                    'forum' => (int) $forum->id,
                ], 'timemodified DESC', '*', 0, 20);
                foreach ($discussions as $discussion) {
                    $post = $DB->get_record('forum_posts', [
                        'discussion' => (int) $discussion->id,
                        'parent' => 0,
                    ]);
                    if (!$post) {
                        continue;
                    }
                    $author = $DB->get_record('user', ['id' => (int) $post->userid], 'id,firstname,lastname');
                    $authorname = $author ? fullname($author) : '';
                    $tagrows = announcement_service::tag_chips_for_post((int) $post->id);
                    $items[] = [
                        'id' => (int) $discussion->id,
                        'subject' => format_string($discussion->name),
                        'excerpt' => shorten_text(format_text(
                            $post->message,
                            (int) $post->messageformat,
                            ['context' => \context_module::instance((int) $cm->id), 'para' => false]
                        ), 180),
                        'author' => $authorname,
                        'has_author' => $authorname !== '',
                        'when' => userdate((int) $discussion->timemodified, get_string('strftimedatetimeshort', 'langconfig')),
                        'timemodified' => (int) $discussion->timemodified,
                        'url' => (new \moodle_url('/mod/forum/discuss.php', ['d' => $discussion->id]))->out(false),
                        'course_name' => format_string($course->fullname),
                        'cta_label' => $ctalabel,
                        'icon' => 'news',
                        'tags' => $tagrows,
                        'has_tags' => $tagrows !== [],
                    ];
                }
            }
        }

        usort($items, static fn(array $a, array $b): int => ($b['timemodified'] <=> $a['timemodified']));
        $items = array_slice($items, 0, 15);

        $coursenames = [];
        foreach ($items as $item) {
            $name = (string) ($item['course_name'] ?? '');
            if ($name !== '') {
                $coursenames[$name] = true;
            }
        }

        $hasitems = $items !== [];
        $hasmoodlenews = $firstforumurl !== '';

        return [
            'items' => $items,
            'has_items' => $hasitems,
            'has_posts' => $hasitems,
            'count_posts' => count($items),
            'count_courses' => count($coursenames),
            'moodle_news_url' => $firstforumurl,
            'has_moodle_news' => $hasmoodlenews,
        ];
    }

    /**
     * Upcoming calendar events for the fellow across enrolled courses + user events.
     *
     * @param list<\stdClass> $courses
     * @return array{items: list<array>, has_items: bool, moodle_calendar_url: string}
     */
    public static function list_calendar_events(int $userid, array $courses): array {
        return calendar_service::upcoming($userid, $courses);
    }

    /**
     * Portfolio = Moodle assign evidence for the selected track (submissions + released reviews).
     *
     * Reuses list_assignment_feedback() visibility rules. Not-submitted assigns are counted
     * in stats only — they are not portfolio evidence yet.
     *
     * @param list<\stdClass> $courses Courses to scan (pass the selected track for page scope)
     * @param int|null $courseid Selected course id (preserved for callers / switch URLs)
     * @return array{
     *   items: list<array>,
     *   has_items: bool,
     *   count_submitted: int,
     *   count_reviewed: int,
     *   count_awaiting: int,
     *   count_open: int,
     *   has_attention: bool,
     *   has_awaiting: bool,
     *   has_submitted: bool
     * }
     */
    public static function list_portfolio_evidence(int $userid, array $courses, ?int $courseid = null): array {
        $feedback = self::list_assignment_feedback($userid, $courses, 'all', $courseid);
        $attention = [];
        $pending = [];
        $reviewed = [];
        $countopen = 0;
        $counttotal = 0;
        $coursename = '';

        foreach ($feedback['items'] ?? [] as $row) {
            $counttotal++;
            $status = (string) ($row['status'] ?? 'not_submitted');
            if ($coursename === '' && !empty($row['course_name'])) {
                $coursename = (string) $row['course_name'];
            }
            if ($status === 'not_submitted') {
                $countopen++;
                continue;
            }

            $when = (string) ($row['graded_at'] ?? '');
            $whenlabel = '';
            if ($when !== '') {
                $whenlabel = self::str('fellowship_portfolio_when_graded', $when, 'Graded {$a}');
            } else {
                $when = (string) ($row['submitted_at'] ?? '');
                if ($when !== '') {
                    $whenlabel = self::str('fellowship_portfolio_when_submitted', $when, 'Submitted {$a}');
                }
            }
            $attempt = (int) ($row['attempt'] ?? 0);
            $showattempt = !empty($row['has_attempt']);
            $icon = match ($status) {
                'pending' => 'time-04',
                'changes' => 'alert-02',
                'reviewed' => 'checkmark-circle-02',
                default => 'file-02',
            };
            $item = [
                'cmid' => (int) ($row['cmid'] ?? 0),
                'course_id' => (int) ($row['course_id'] ?? 0),
                'name' => $row['name'],
                // Single-track page already has the course switch — skip repeating the title.
                'course_name' => '',
                'status_key' => $status,
                'status' => $row['status_label'] ?? '',
                'chip' => $row['chip'] ?? 'line',
                'icon' => $icon,
                'summary' => $row['summary'] ?? '',
                'when' => $when,
                'when_label' => $whenlabel,
                'has_when' => $whenlabel !== '',
                'url' => $row['url'],
                'has_comment' => !empty($row['has_comment']),
                'has_grade' => !empty($row['has_grade']),
                'grade_display' => $row['grade_display'] ?? '',
                'has_attempt' => $showattempt,
                'attempt' => $attempt,
                'attempt_label' => $showattempt
                    ? self::str('fellowship_portfolio_attempt', (string) $attempt, 'Attempt {$a}')
                    : '',
                'needs_attention' => !empty($row['needs_attention']),
                'cta_label' => $row['cta_label'] ?? '',
                'is_pending' => $status === 'pending',
                'is_reviewed' => $status === 'reviewed',
                'is_changes' => $status === 'changes',
                'is_open' => false,
            ];

            if (!empty($item['needs_attention'])) {
                $attention[] = $item;
            } else if ($status === 'pending') {
                $pending[] = $item;
            } else {
                $reviewed[] = $item;
            }
        }

        // Portfolio order: released evidence first, then revise, then waiting.
        $items = array_values(array_merge($reviewed, $attention, $pending));
        $countattention = count($attention);
        $countreviewed = count($reviewed);
        $countawaiting = count($pending);
        $countevidence = $countreviewed + $countattention + $countawaiting;

        // Mutually exclusive At a glance buckets (reviewed + awaiting + attention + open = total).
        $evidencechip = $countevidence > 0
            ? ($countawaiting + $countattention === 0 ? 'ok' : 'line')
            : 'line';
        $evidencestatus = $counttotal <= 0
            ? self::str('fellowship_portfolio_status_none', null, 'No assignments yet')
            : ($countevidence >= $counttotal
                ? self::str('fellowship_portfolio_status_complete', null, 'All have evidence')
                : self::str('fellowship_portfolio_status_partial', null, 'In progress'));

        if ($counttotal <= 0) {
            $herosummary = self::str(
                'fellowship_portfolio_hero_empty',
                null,
                'When this track has Moodle assignments, submitted work and released mentor reviews appear here.'
            );
            $evidenceheadline = self::str('fellowship_portfolio_evidence_none', null, 'No assignments on this track');
        } else {
            $heroparts = [
                self::str(
                    'fellowship_portfolio_hero_evidence',
                    (object) ['evidence' => $countevidence, 'total' => $counttotal],
                    "{$countevidence} of {$counttotal} track assignments have Moodle evidence"
                ),
            ];
            if (str_contains($heroparts[0], '{$a')) {
                $heroparts[0] = "{$countevidence} of {$counttotal} track assignments have Moodle evidence";
            }
            if ($countreviewed > 0) {
                $heroparts[] = self::str(
                    'fellowship_portfolio_hero_reviewed',
                    (string) $countreviewed,
                    "{$countreviewed} reviewed"
                );
            }
            if ($countawaiting > 0) {
                $heroparts[] = self::str(
                    'fellowship_portfolio_hero_awaiting',
                    (string) $countawaiting,
                    "{$countawaiting} awaiting release"
                );
            }
            if ($countattention > 0) {
                $heroparts[] = self::str(
                    'fellowship_portfolio_hero_attention',
                    (string) $countattention,
                    "{$countattention} need changes"
                );
            }
            if ($countopen > 0) {
                $heroparts[] = self::str(
                    'fellowship_portfolio_hero_open',
                    (string) $countopen,
                    "{$countopen} still open"
                );
            }
            $herosummary = implode(' · ', $heroparts) . '.';
            $evidenceheadline = self::str(
                'fellowship_portfolio_evidence_label',
                (object) ['evidence' => $countevidence, 'total' => $counttotal],
                "{$countevidence} of {$counttotal} with evidence"
            );
            if (str_contains($evidenceheadline, '{$a')) {
                $evidenceheadline = "{$countevidence} of {$counttotal} with evidence";
            }
        }

        return [
            'items' => $items,
            'has_items' => $items !== [],
            'count_total' => $counttotal,
            'count_evidence' => $countevidence,
            'count_submitted' => $countevidence,
            'count_reviewed' => $countreviewed,
            'count_awaiting' => $countawaiting,
            'count_attention' => $countattention,
            'count_open' => $countopen,
            'has_attention' => $countattention > 0,
            'has_awaiting' => $countawaiting > 0,
            'has_reviewed' => $countreviewed > 0,
            'has_submitted' => $countevidence > 0,
            'has_evidence' => $countevidence > 0,
            'has_open' => $countopen > 0,
            'has_total' => $counttotal > 0,
            'evidence_headline' => $evidenceheadline,
            'evidence_chip' => $evidencechip,
            'evidence_status' => $evidencestatus,
            'hero_summary' => $herosummary,
            'selected_course_name' => $coursename,
            'list_heading' => self::str('fellowship_portfolio_list_heading', null, 'Evidence'),
        ];
    }

    /**
     * Resolve a Moodle lang key with a hard fallback (never emit [[missing]] markers).
     *
     * Production: prefer lang/en/local_launchpad.php via get_string; fallback only when
     * the string cache is stale or the key is not installed yet.
     *
     * @param string $identifier
     * @param mixed $a
     * @param string $fallback
     */
    public static function str(string $identifier, $a = null, string $fallback = ''): string {
        if (get_string_manager()->string_exists($identifier, 'local_launchpad')) {
            $out = get_string($identifier, 'local_launchpad', $a);
            if (is_string($out) && $out !== '' && strpos($out, '[[') === false) {
                return $out;
            }
        }
        if ($fallback !== '' && $a !== null && is_string($a) && strpos($fallback, '{$a}') !== false) {
            return str_replace('{$a}', $a, $fallback);
        }
        return $fallback !== '' ? $fallback : $identifier;
    }

    /**
     * @return list<array>
     */
    private static function badge_catalogue_empty(): array {
        $live = [
            'badges' => [],
            'badges_earned' => 0,
        ];
        // Reuse definitions with nothing earned via a zero-user path is heavy; inline locked catalogue.
        foreach ([
            ['first', 'First step', 'plant-01', 'Finish your first lesson'],
            ['evidence', 'Evidence hunter', 'search-01', 'Finish Lesson 2: Evidence versus assumptions'],
            ['allLessons', 'Foundations reader', 'books-01', 'Finish all Foundations lessons in your track'],
            ['ace', 'Quiz ace', 'target-01', 'Score full marks on the Foundations check'],
            ['handin', 'Hand-in hero', 'mail-send-01', 'Submit your first assignment'],
            ['loop', 'Feedback loop', 'refresh', 'Resubmit after your mentor requests changes'],
            ['gate', 'Gate one cleared', 'flag-02', 'Pass Foundations (completion or grade ≥ 70)'],
            ['portfolio', 'Portfolio starter', 'folder-01', 'Save your first portfolio case study'],
            ['profile', 'Introduced', 'waving-hand-01', 'Add a city or description on your Moodle profile'],
        ] as $row) {
            $live['badges'][] = [
                'id' => $row[0],
                'name' => $row[1],
                'icon' => $row[2],
                'how' => $row[3],
                'earned' => false,
                'has_iconify' => true,
                'detail' => $row[3],
                'css' => '',
            ];
        }
        return $live['badges'];
    }

    /**
     * @return array{ts: int, at: string, title: string, xp: int, key: string}
     */
    private static function xp_log_entry(int $ts, string $title, int $xp, string $key): array {
        if ($ts <= 0) {
            $ts = time();
        }
        return [
            'ts' => $ts,
            'at' => userdate($ts, get_string('strftimedatetimeshort', 'langconfig')),
            'title' => $title,
            'xp' => $xp,
            'key' => $key,
        ];
    }

    /**
     * @param list<array{key: string, ts: int}> $xplog
     */
    private static function first_log_ts(array $xplog, string $prefix): int {
        $best = 0;
        foreach ($xplog as $row) {
            if (str_starts_with((string) $row['key'], $prefix)) {
                $best = max($best, (int) $row['ts']);
            }
        }
        return $best;
    }

    /**
     * Assignment feedback cards for the Fellowship Feedback tab.
     *
     * Uses Moodle assign APIs so grades/comments only appear when a student
     * would see them on mod/assign/view.php (marking workflow released,
     * gradebook not hidden, non-empty feedback plugins).
     *
     * Statuses: not_submitted | pending | reviewed | changes
     * "changes" = Moodle-visible feedback and attempt reopened/draft for resubmit.
     *
     * @param list<\stdClass> $courses Courses to scan (pass the selected track for page scope)
     * @param string $ftab all|attention|reviewed|pending|not_submitted
     * @param int|null $courseid Course id preserved on tab URLs (selected track)
     * @return array{
     *   items: list<array>,
     *   has_items: bool,
     *   has_assigns: bool,
     *   attention_count: int,
     *   has_attention: bool
     * }
     */
    public static function list_assignment_feedback(
        int $userid,
        array $courses,
        string $ftab = 'all',
        ?int $courseid = null
    ): array {
        global $CFG, $DB;

        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        $items = [];
        $attention = 0;
        $assigncount = 0;

        foreach ($courses as $course) {
            $modinfo = get_fast_modinfo($course, $userid);
            foreach ($modinfo->get_cms() as $cm) {
                if ($cm->modname !== 'assign' || !$cm->uservisible || $cm->deletioninprogress) {
                    continue;
                }
                $assigncount++;
                $name = self::display_label($cm->get_formatted_name());
                $url = activity_portal::fellow_url((int) $cm->id);
                $context = \context_module::instance($cm->id);
                $assign = new \assign($context, $cm, $course);
                $instance = $assign->get_instance($userid);
                $instanceid = (int) $instance->id;

                $latest = $assign->get_user_submission($userid, false);
                $substatus = $latest ? (string) $latest->status : '';
                $attemptnumber = $latest ? (int) $latest->attemptnumber : -1;
                $submittedcount = (int) $DB->count_records('assign_submission', [
                    'assignment' => $instanceid,
                    'userid' => $userid,
                    'status' => 'submitted',
                ]);

                // Grade for the current attempt (do not create records).
                $agrade = false;
                if ($attemptnumber >= 0) {
                    $agrade = $assign->get_user_grade($userid, false, $attemptnumber);
                    $usable = $agrade && (
                        ($agrade->grade !== null && (float) $agrade->grade >= 0)
                        || self::assign_feedback_plugins_have_content($assign, $agrade)
                    );
                    if (!$usable) {
                        $agrade = false;
                    }
                }
                // Reopened/draft attempt: fall back to prior graded attempt so released
                // comments still show (Moodle attempt history), without inheriting into a
                // fresh submitted attempt that has no grade yet.
                if (!$agrade && $latest && in_array($substatus, ['reopened', 'draft', 'new'], true)) {
                    $prior = $DB->get_records_select(
                        'assign_grades',
                        'assignment = :assignment AND userid = :userid AND attemptnumber < :attempt',
                        [
                            'assignment' => $instanceid,
                            'userid' => $userid,
                            'attempt' => max(0, $attemptnumber),
                        ],
                        'attemptnumber DESC, timemodified DESC',
                        '*',
                        0,
                        1
                    );
                    $candidate = $prior ? reset($prior) : false;
                    if ($candidate && (
                        ((float) $candidate->grade >= 0)
                        || self::assign_feedback_plugins_have_content($assign, $candidate)
                    )) {
                        $agrade = $candidate;
                    }
                }

                $gradingstatus = $assign->get_grading_status($userid);
                $workflowblocks = !empty($instance->markingworkflow)
                    && $gradingstatus !== ASSIGN_MARKING_WORKFLOW_STATE_RELEASED;

                $gradinginfo = grade_get_grades($course->id, 'mod', 'assign', $instanceid, $userid);
                $gradingitem = $gradinginfo->items[0] ?? null;
                $gradebookgrade = ($gradingitem && isset($gradingitem->grades[$userid]))
                    ? $gradingitem->grades[$userid]
                    : null;

                $pluginfeedback = false;
                $otherpluginfeedback = false;
                if ($agrade && !$workflowblocks) {
                    foreach ($assign->get_feedback_plugins() as $plugin) {
                        if (!$plugin->is_visible() || !$plugin->is_enabled()) {
                            continue;
                        }
                        if ($plugin->is_empty($agrade)) {
                            continue;
                        }
                        $pluginfeedback = true;
                        if ($plugin->get_type() !== 'comments') {
                            $otherpluginfeedback = true;
                        }
                    }
                }

                $cangrade = has_capability('mod/assign:grade', $context, $userid);
                $hasgradebookmark = (float) $instance->grade != GRADE_TYPE_NONE
                    && $gradebookgrade
                    && $gradebookgrade->grade !== null;
                $gradevisible = $cangrade
                    || (float) $instance->grade == GRADE_TYPE_NONE
                    || ($gradebookgrade !== null && empty($gradebookgrade->hidden));

                // Mirror assign::get_assign_feedback_status_renderable visibility.
                $feedbackvisible = ($hasgradebookmark || $pluginfeedback) && $gradevisible && !$workflowblocks;

                $commenttext = '';
                $nextsteptext = '';
                $gradedat = 0;
                $gradepct = null;
                $gradestr = '';
                $shownumeric = false;

                if ($feedbackvisible) {
                    if ($agrade) {
                        $gradedat = (int) ($agrade->timemodified ?: 0);
                    }
                    if ($hasgradebookmark && $gradebookgrade) {
                        $shownumeric = true;
                        $grademax = isset($gradingitem->grademax) ? (float) $gradingitem->grademax : 0.0;
                        if ($grademax > 0) {
                            $gradepct = (((float) $gradebookgrade->grade) / $grademax) * 100;
                        }
                        $gradestr = trim((string) ($gradebookgrade->str_long_grade ?? ''));
                        if ($gradestr === '' && $gradepct !== null) {
                            $gradestr = format_float($gradepct, 0) . '%';
                        }
                        if (!empty($gradebookgrade->dategraded)) {
                            $gradedat = max($gradedat, (int) $gradebookgrade->dategraded);
                        }
                        if (!empty($gradebookgrade->feedback)) {
                            $parts = mentor_dashboard::unpack_review_comment((string) $gradebookgrade->feedback);
                            if ($parts['next_step'] !== '') {
                                $nextsteptext = $parts['next_step'];
                            }
                            $body = $parts['comment'];
                            if ($body === '' && $parts['next_step'] === '') {
                                $body = (string) $gradebookgrade->feedback;
                            }
                            if ($body !== '') {
                                $commenttext = format_text(
                                    $body,
                                    (int) ($gradebookgrade->feedbackformat ?? FORMAT_MOODLE),
                                    ['context' => $context, 'para' => false]
                                );
                            }
                        }
                    } else if ($agrade && $agrade->grade !== null && (float) $agrade->grade >= 0
                            && (float) $instance->grade > 0) {
                        // Visible plugin feedback without gradebook push yet — show assign mark.
                        $shownumeric = true;
                        $gradepct = (((float) $agrade->grade) / (float) $instance->grade) * 100;
                        $gradestr = format_float($gradepct, 0) . '%';
                    }

                    if ($agrade && $commenttext === '' && $nextsteptext === '') {
                        $commentsplugin = $assign->get_feedback_plugin_by_type('comments');
                        if ($commentsplugin && $commentsplugin->is_enabled()
                                && method_exists($commentsplugin, 'get_feedback_comments')) {
                            $fc = $commentsplugin->get_feedback_comments((int) $agrade->id);
                            if ($fc && trim((string) $fc->commenttext) !== '') {
                                $parts = mentor_dashboard::unpack_review_comment((string) $fc->commenttext);
                                if ($parts['next_step'] !== '') {
                                    $nextsteptext = $parts['next_step'];
                                }
                                $body = $parts['comment'];
                                if ($body === '' && $parts['next_step'] === '') {
                                    $body = (string) $fc->commenttext;
                                }
                                if ($body !== '') {
                                    $commenttext = format_text(
                                        $body,
                                        (int) $fc->commentformat,
                                        ['context' => $context, 'para' => false]
                                    );
                                }
                            }
                        }
                    }
                }

                $attempt = $latest ? ((int) $latest->attemptnumber + 1) : 0;
                $submittedat = 0;
                if ($latest && ($substatus === 'submitted' || $substatus === 'reopened')) {
                    $submittedat = (int) ($latest->timemodified ?: $latest->timecreated);
                } else if ($submittedcount > 0) {
                    $lastsub = $DB->get_records('assign_submission', [
                        'assignment' => $instanceid,
                        'userid' => $userid,
                        'status' => 'submitted',
                    ], 'timemodified DESC', '*', 0, 1);
                    if ($lastsub) {
                        $s = reset($lastsub);
                        $submittedat = (int) ($s->timemodified ?: $s->timecreated);
                        if ($attempt <= 0) {
                            $attempt = (int) $s->attemptnumber + 1;
                        }
                    }
                }

                // Classify from Moodle-visible feedback + submission state.
                // Attention = fellow must revise. ≥70 soft-warn only after Moodle release.
                $status = 'not_submitted';
                $chip = 'line';
                $needsattention = false;
                $passedgate = ($gradepct !== null && $gradepct >= 70);
                // ≥70 Met clears attention even if Moodle left the attempt reopened.
                if ($feedbackvisible && $passedgate) {
                    $status = 'reviewed';
                    $chip = 'ok';
                    $needsattention = false;
                } else if ($feedbackvisible
                        && in_array($substatus, ['reopened', 'draft', 'new'], true)) {
                    $status = 'changes';
                    $chip = 'warn';
                    $needsattention = true;
                } else if ($feedbackvisible) {
                    // Released below 70 without reopen — warn, no revise badge.
                    $status = 'reviewed';
                    $chip = 'warn';
                    $needsattention = false;
                } else if ($substatus === 'submitted' || $submittedcount > 0) {
                    $status = 'pending';
                    $chip = 'line';
                } else {
                    $status = 'not_submitted';
                    $chip = 'line';
                }

                if ($needsattention) {
                    $attention++;
                }

                $statuslabel = match ($status) {
                    'pending' => get_string('fellowship_feedback_status_pending', 'local_launchpad'),
                    'reviewed' => get_string('fellowship_feedback_status_reviewed', 'local_launchpad'),
                    'changes' => get_string('fellowship_feedback_status_changes', 'local_launchpad'),
                    default => get_string('fellowship_feedback_status_none', 'local_launchpad'),
                };

                $summary = match ($status) {
                    'pending' => get_string('fellowship_feedback_summary_pending', 'local_launchpad'),
                    'reviewed' => get_string('fellowship_feedback_summary_reviewed', 'local_launchpad'),
                    'changes' => get_string('fellowship_feedback_summary_changes', 'local_launchpad'),
                    default => get_string('fellowship_feedback_summary_none', 'local_launchpad'),
                };

                $moodlenote = '';
                if ($feedbackvisible && $commenttext === '' && $otherpluginfeedback) {
                    $moodlenote = get_string('fellowship_feedback_plugins_note', 'local_launchpad');
                }

                // Moodle creates empty "new" rows when a student opens an assign —
                // do not surface "Attempt 1" until there is a real submission or review.
                $showattempt = $attempt > 0 && (
                    in_array($status, ['pending', 'reviewed', 'changes'], true)
                    || in_array($substatus, ['submitted', 'reopened'], true)
                    || $submittedcount > 0
                );

                $items[] = [
                    'cmid' => (int) $cm->id,
                    'course_id' => (int) $course->id,
                    'name' => $name,
                    'course_name' => self::display_label(format_string($course->fullname)),
                    'course_idnumber' => s($course->idnumber),
                    'url' => $url,
                    'status' => $status,
                    'status_label' => $statuslabel,
                    'chip' => $chip,
                    'summary' => $summary,
                    'has_grade' => $shownumeric && $gradestr !== '',
                    'grade_display' => $gradestr,
                    'has_next_step' => $nextsteptext !== '',
                    'next_step' => $nextsteptext,
                    'has_comment' => $commenttext !== '',
                    'comment_html' => $commenttext,
                    'has_moodle_feedback_note' => $moodlenote !== '',
                    'moodle_feedback_note' => $moodlenote,
                    'has_attempt' => $showattempt,
                    'attempt' => $attempt,
                    'submitted_at' => $submittedat
                        ? userdate($submittedat, get_string('strftimedatetimeshort', 'langconfig'))
                        : '',
                    'graded_at' => $gradedat
                        ? userdate($gradedat, get_string('strftimedatetimeshort', 'langconfig'))
                        : '',
                    'needs_attention' => $needsattention,
                    '_sort' => max(
                        $gradedat,
                        $submittedat,
                        $latest ? (int) ($latest->timemodified ?? 0) : 0
                    ),
                    'cta_label' => match ($status) {
                        'not_submitted' => get_string('fellowship_feedback_cta_open', 'local_launchpad'),
                        'pending' => get_string('fellowship_feedback_cta_view', 'local_launchpad'),
                        'changes' => get_string('fellowship_feedback_cta_revise', 'local_launchpad'),
                        default => get_string('fellowship_feedback_cta_view', 'local_launchpad'),
                    },
                ];
            }
        }

        // Status categories: attention → released → waiting → not submitted.
        $attentionitems = [];
        $reviewed = [];
        $pending = [];
        $notsubmitted = [];
        foreach ($items as $item) {
            switch ($item['status']) {
                case 'changes':
                    $attentionitems[] = $item;
                    break;
                case 'reviewed':
                    $reviewed[] = $item;
                    break;
                case 'pending':
                    $pending[] = $item;
                    break;
                default:
                    $notsubmitted[] = $item;
                    break;
            }
        }

        $bysort = static function (array $a, array $b): int {
            return ((int) ($b['_sort'] ?? 0)) <=> ((int) ($a['_sort'] ?? 0));
        };
        usort($attentionitems, $bysort);
        usort($reviewed, $bysort);
        usort($pending, $bysort);
        // Not submitted: keep Moodle section order (no activity timestamps).
        usort($notsubmitted, static function (array $a, array $b): int {
            return ((int) ($a['cmid'] ?? 0)) <=> ((int) ($b['cmid'] ?? 0));
        });

        // Empty banner only when Moodle-visible feedback (reviewed or changes) is absent.
        $hasmoodlereleased = ($attentionitems !== [] || $reviewed !== []);
        $emptyctaurl = '';
        $emptyctalabel = get_string('fellowship_feedback_cta_open', 'local_launchpad');
        $emptybody = get_string('fellowship_feedback_empty_submit', 'local_launchpad');
        if ($pending !== []) {
            $emptybody = get_string('fellowship_feedback_empty_waiting', 'local_launchpad');
            $emptyctaurl = $pending[0]['url'];
            $emptyctalabel = get_string('fellowship_feedback_cta_receipt', 'local_launchpad');
        } else if ($notsubmitted !== []) {
            $emptybody = get_string('fellowship_feedback_empty_submit', 'local_launchpad');
            $emptyctaurl = $notsubmitted[0]['url'];
            $emptyctalabel = get_string('fellowship_feedback_cta_open', 'local_launchpad');
        } else if ($items !== []) {
            $emptyctaurl = $items[0]['url'];
        }

        $allowedtabs = ['all', 'attention', 'reviewed', 'pending', 'not_submitted'];
        $ftab = in_array($ftab, $allowedtabs, true) ? $ftab : 'all';
        if ($courseid === null && count($courses) === 1) {
            $courseid = (int) ($courses[0]->id ?? 0) ?: null;
        }

        $taburls = [];
        foreach ($allowedtabs as $key) {
            $params = array_filter([
                'view' => 'feedback',
                'id' => ($courseid !== null && $courseid > 0) ? $courseid : null,
                'ftab' => $key !== 'all' ? $key : null,
            ]);
            $taburls[$key] = (new \moodle_url(persona_home::FELLOW_PATH, $params))->out(false);
        }

        $showattention = ($ftab === 'all' || $ftab === 'attention') && $attentionitems !== [];
        $showreviewed = ($ftab === 'all' || $ftab === 'reviewed') && $reviewed !== [];
        $showpending = ($ftab === 'all' || $ftab === 'pending') && $pending !== [];
        $shownotsubmitted = ($ftab === 'all' || $ftab === 'not_submitted') && $notsubmitted !== [];

        $filterempty = false;
        if ($ftab === 'attention') {
            $filterempty = $attentionitems === [];
        } else if ($ftab === 'reviewed') {
            $filterempty = $reviewed === [];
        } else if ($ftab === 'pending') {
            $filterempty = $pending === [];
        } else if ($ftab === 'not_submitted') {
            $filterempty = $notsubmitted === [];
        }

        return [
            'items' => $items,
            'attention_items' => $attentionitems,
            'reviewed_items' => $reviewed,
            'pending_items' => $pending,
            'not_submitted_items' => $notsubmitted,
            // Legacy aliases for older callers/templates.
            'released_items' => array_values(array_merge($attentionitems, $reviewed)),
            'open_items' => array_values(array_merge($pending, $notsubmitted)),
            'has_attention_items' => $showattention,
            'has_reviewed_items' => $showreviewed,
            'has_pending_items' => $showpending,
            'has_not_submitted_items' => $shownotsubmitted,
            'has_released' => $hasmoodlereleased,
            'has_open_items' => ($pending !== [] || $notsubmitted !== []),
            'has_items' => $items !== [],
            'has_assigns' => $assigncount > 0,
            'attention_count' => $attention,
            'has_attention' => $attention > 0,
            'all_count' => count($items),
            'attention_tab_count' => count($attentionitems),
            'reviewed_count' => count($reviewed),
            'pending_count' => count($pending),
            'not_submitted_count' => count($notsubmitted),
            'ftab' => $ftab,
            'ftab_all' => $ftab === 'all',
            'ftab_attention' => $ftab === 'attention',
            'ftab_reviewed' => $ftab === 'reviewed',
            'ftab_pending' => $ftab === 'pending',
            'ftab_not_submitted' => $ftab === 'not_submitted',
            'tab_all_url' => $taburls['all'],
            'tab_attention_url' => $taburls['attention'],
            'tab_reviewed_url' => $taburls['reviewed'],
            'tab_pending_url' => $taburls['pending'],
            'tab_not_submitted_url' => $taburls['not_submitted'],
            'show_tabs' => $assigncount > 0,
            'show_filter_empty' => $assigncount > 0 && $filterempty,
            'show_empty_released' => $assigncount > 0 && !$hasmoodlereleased && $ftab === 'all',
            'empty_title' => self::str('fellowship_feedback_empty_title', null, 'No released feedback yet'),
            'empty_title_lead' => self::str('fellowship_feedback_empty_title_lead', null, 'No released'),
            'empty_title_accent' => self::str('fellowship_feedback_empty_title_accent', null, 'feedback'),
            'empty_title_trail' => self::str('fellowship_feedback_empty_title_trail', null, 'yet'),
            'empty_body' => $emptybody,
            'empty_cta_url' => $emptyctaurl,
            'empty_cta_label' => $emptyctalabel,
            'has_empty_cta' => $emptyctaurl !== '',
        ];
    }

    /**
     * Whether any enabled assign feedback plugin has content for this grade row.
     *
     * @param \assign $assign
     * @param \stdClass $grade assign_grades record
     */
    private static function assign_feedback_plugins_have_content(\assign $assign, \stdClass $grade): bool {
        foreach ($assign->get_feedback_plugins() as $plugin) {
            if ($plugin->is_visible() && $plugin->is_enabled() && !$plugin->is_empty($grade)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the user may open the Fellowship shell.
     */
    public static function can_access(int $userid): bool {
        if ($userid <= 0) {
            return false;
        }
        // Site admins use admin.php — do not linger on the Fellowship shell.
        return account_persona::is_fellowship($userid);
    }

    /**
     * Two-letter initials for the avatar chip.
     *
     * @param \stdClass $user
     */
    public static function initials(\stdClass $user): string {
        $a = self::first_letter((string) ($user->firstname ?? ''));
        $b = self::first_letter((string) ($user->lastname ?? ''));
        $out = $a . $b;
        return $out !== '' ? $out : '?';
    }

    /**
     * Uppercase first character without requiring Moodle bootstrap in smoke tests.
     */
    private static function first_letter(string $value): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (class_exists(\core_text::class, false)) {
            return \core_text::strtoupper(\core_text::substr($value, 0, 1));
        }
        if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
            return mb_strtoupper(mb_substr($value, 0, 1));
        }
        return strtoupper(substr($value, 0, 1));
    }
}
