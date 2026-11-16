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
 * Per-track rubric packs for mentor Review (one 4-criterion pack per LaunchPad course).
 *
 * @package    local_launchpad
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class track_rubric {

    /** Config key prefix: track_rubric_{courseid}. */
    public const CONFIG_PREFIX = 'track_rubric_';

    /**
     * Shared default pack (former hard-coded M1).
     *
     * @return array{v:int,criteria:list<array{key:string,label:string,weight:int,descriptor:string}>}
     */
    public static function default_pack(): array {
        return [
            'v' => 1,
            'criteria' => [
                [
                    'key' => 'c1',
                    'label' => get_string('mentor_rubric_evidence', 'local_launchpad'),
                    'weight' => 30,
                    'descriptor' => get_string('mentor_rubric_desc_evidence', 'local_launchpad'),
                ],
                [
                    'key' => 'c2',
                    'label' => get_string('mentor_rubric_acceptance', 'local_launchpad'),
                    'weight' => 25,
                    'descriptor' => get_string('mentor_rubric_desc_acceptance', 'local_launchpad'),
                ],
                [
                    'key' => 'c3',
                    'label' => get_string('mentor_rubric_prioritisation', 'local_launchpad'),
                    'weight' => 25,
                    'descriptor' => get_string('mentor_rubric_desc_prioritisation', 'local_launchpad'),
                ],
                [
                    'key' => 'c4',
                    'label' => get_string('mentor_rubric_communication', 'local_launchpad'),
                    'weight' => 20,
                    'descriptor' => get_string('mentor_rubric_desc_communication', 'local_launchpad'),
                ],
            ],
        ];
    }

    /**
     * Seed pack for a known track slug, else shared default.
     *
     * @return array{v:int,criteria:list<array{key:string,label:string,weight:int,descriptor:string}>}
     */
    public static function default_pack_for_slug(string $slug): array {
        $slug = strtolower(trim($slug));
        $packs = self::plan_packs();
        return $packs[$slug] ?? self::default_pack();
    }

    /**
     * MVP plan packs keyed by track_code_slug / idnumber mid-segment.
     *
     * @return array<string,array{v:int,criteria:list<array{key:string,label:string,weight:int,descriptor:string}>}>
     */
    public static function plan_packs(): array {
        return [
            'ai' => self::pack_rows([
                ['Routing', 35, 'Strong: clear intent routing with guarded fallbacks.'],
                ['Reproducible evaluation', 30, 'Strong: eval steps others can re-run from the write-up.'],
                ['Safe review boundary', 20, 'Strong: human-in-the-loop / risk limits stated.'],
                ['Explanation/runbook', 15, 'Strong: operator can follow the runbook without you.'],
            ]),
            'fullstack' => self::pack_rows([
                ['Functional correctness', 35, 'Strong: acceptance paths work end-to-end.'],
                ['Tests/reproducibility', 25, 'Strong: tests or steps prove the build.'],
                ['Accessible recovery', 25, 'Strong: errors recover without trapping the user.'],
                ['Maintainability/explanation', 15, 'Strong: structure and notes another engineer can follow.'],
            ]),
            'pm' => self::default_pack(),
            'marketing' => self::pack_rows([
                ['Measurement correctness', 30, 'Strong: metrics and sources are defensible.'],
                ['Audience/message fit', 25, 'Strong: message matches the named audience.'],
                ['Claim discipline', 25, 'Strong: claims stay inside evidence.'],
                ['Recommendation/experiment', 20, 'Strong: next test is concrete and measurable.'],
            ]),
            'startup' => self::pack_rows([
                ['Hypothesis/research', 30, 'Strong: hypothesis and research are explicit.'],
                ['Economics/sensitivity', 25, 'Strong: unit economics and sensitivity are clear.'],
                ['Evidence discipline', 25, 'Strong: claims stay tied to evidence.'],
                ['Decision/communication', 20, 'Strong: decision and ask are crisp.'],
            ]),
        ];
    }

    /**
     * @param list<array{0:string,1:int,2:string}> $rows
     * @return array{v:int,criteria:list<array{key:string,label:string,weight:int,descriptor:string}>}
     */
    private static function pack_rows(array $rows): array {
        $criteria = [];
        $i = 1;
        foreach ($rows as $row) {
            $criteria[] = [
                'key' => 'c' . $i,
                'label' => (string) $row[0],
                'weight' => (int) $row[1],
                'descriptor' => (string) $row[2],
            ];
            $i++;
        }
        return ['v' => 1, 'criteria' => $criteria];
    }

    /**
     * Resolve slug from course idnumber (lp-{code}-{cohort}) or track fullname.
     */
    public static function slug_for_course(\stdClass $course): string {
        $idnumber = strtolower(trim((string) ($course->idnumber ?? '')));
        if (preg_match('/^lp-([a-z0-9]+)-/', $idnumber, $m)
                && $m[1] !== 'orient') {
            return $m[1];
        }
        $name = trim((string) ($course->fullname ?? ''));
        // Strip "LaunchPad · " / " · Cohort …" wrappers when present.
        if (preg_match('/LaunchPad\s*[·\-]\s*(.+?)\s*[·\-]\s*Cohort/i', $name, $m)) {
            $name = trim($m[1]);
        }
        return admin_dashboard::track_code_slug($name);
    }

    /**
     * Load pack for course; seed from slug/default if missing.
     *
     * @return array{v:int,criteria:list<array{key:string,label:string,weight:int,descriptor:string}>}
     */
    public static function ensure_course_pack(int $courseid, string $trackname = ''): array {
        global $DB;

        if ($courseid <= 0) {
            return self::default_pack();
        }
        $existing = self::read_config($courseid);
        if ($existing !== null) {
            return $existing;
        }

        $course = $DB->get_record('course', ['id' => $courseid], 'id,fullname,idnumber', IGNORE_MISSING);
        $slug = '';
        if ($course) {
            $slug = self::slug_for_course($course);
        } else if ($trackname !== '') {
            $slug = admin_dashboard::track_code_slug($trackname);
        }
        $pack = self::default_pack_for_slug($slug);
        self::write_config($courseid, $pack);
        return $pack;
    }

    /**
     * @return array{v:int,criteria:list<array{key:string,label:string,weight:int,descriptor:string}>}
     */
    public static function get_course_pack(int $courseid): array {
        return self::ensure_course_pack($courseid);
    }

    /**
     * Criteria list shaped like mentor_dashboard::rubric_criteria().
     *
     * @return list<array{key:string,label:string,weight:int,descriptor:string}>
     */
    public static function criteria_for_course(int $courseid): array {
        $pack = self::get_course_pack($courseid);
        return $pack['criteria'];
    }

    /**
     * Validate and save 4 criteria. Weights must sum to 100.
     *
     * @param list<array{key?:string,label:string,weight:int|string,descriptor?:string}> $criteria
     * @return array{ok?:string,error?:string,pack?:array}
     */
    public static function save_course_pack(int $courseid, array $criteria): array {
        if ($courseid <= 0) {
            return ['error' => get_string('admin_rubric_err_course', 'local_launchpad')];
        }
        if (count($criteria) !== 4) {
            return ['error' => get_string('admin_rubric_err_count', 'local_launchpad')];
        }

        $clean = [];
        $sum = 0;
        $i = 1;
        foreach ($criteria as $row) {
            $label = trim((string) ($row['label'] ?? ''));
            $weight = (int) ($row['weight'] ?? 0);
            $descriptor = trim((string) ($row['descriptor'] ?? ''));
            $key = trim((string) ($row['key'] ?? ''));
            if ($key === '') {
                $key = 'c' . $i;
            }
            if ($label === '') {
                return ['error' => get_string('admin_rubric_err_label', 'local_launchpad')];
            }
            if ($weight < 1 || $weight > 100) {
                return ['error' => get_string('admin_rubric_err_weight', 'local_launchpad')];
            }
            $sum += $weight;
            $clean[] = [
                'key' => \core_text::substr($key, 0, 32),
                'label' => \core_text::substr($label, 0, 120),
                'weight' => $weight,
                'descriptor' => \core_text::substr($descriptor, 0, 255),
            ];
            $i++;
        }
        if ($sum !== 100) {
            return ['error' => get_string('admin_rubric_err_sum', 'local_launchpad', $sum)];
        }

        $pack = ['v' => 1, 'criteria' => $clean];
        self::write_config($courseid, $pack);
        return [
            'ok' => get_string('admin_rubric_ok_saved', 'local_launchpad'),
            'pack' => $pack,
        ];
    }

    /**
     * Seed packs for all LaunchPad track courses missing config (upgrade / repair).
     *
     * @return int Number of courses seeded
     */
    public static function backfill_missing_packs(): int {
        $n = 0;
        foreach (admin_dashboard::list_track_courses() as $course) {
            $cid = (int) $course->id;
            if ($cid <= 0) {
                continue;
            }
            if (self::read_config($cid) !== null) {
                continue;
            }
            self::ensure_course_pack($cid);
            $n++;
        }
        return $n;
    }

    /**
     * @return array{v:int,criteria:list<array{key:string,label:string,weight:int,descriptor:string}>}|null
     */
    private static function read_config(int $courseid): ?array {
        $raw = get_config('local_launchpad', self::CONFIG_PREFIX . $courseid);
        if ($raw === false || $raw === null || $raw === '') {
            return null;
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded) || empty($decoded['criteria']) || !is_array($decoded['criteria'])) {
            return null;
        }
        if (count($decoded['criteria']) !== 4) {
            return null;
        }
        $criteria = [];
        foreach ($decoded['criteria'] as $i => $row) {
            if (!is_array($row)) {
                return null;
            }
            $label = trim((string) ($row['label'] ?? ''));
            $weight = (int) ($row['weight'] ?? 0);
            if ($label === '' || $weight < 1) {
                return null;
            }
            $criteria[] = [
                'key' => (string) ($row['key'] ?? ('c' . ($i + 1))),
                'label' => $label,
                'weight' => $weight,
                'descriptor' => (string) ($row['descriptor'] ?? ''),
            ];
        }
        return ['v' => (int) ($decoded['v'] ?? 1), 'criteria' => $criteria];
    }

    /**
     * @param array{v:int,criteria:list<array{key:string,label:string,weight:int,descriptor:string}>} $pack
     */
    private static function write_config(int $courseid, array $pack): void {
        set_config(self::CONFIG_PREFIX . $courseid, json_encode($pack, JSON_UNESCAPED_UNICODE), 'local_launchpad');
    }
}
