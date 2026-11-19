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
 * Upgrade steps for local_launchpad.
 *
 * @package    local_launchpad
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade plugin.
 *
 * @param int $oldversion Previous version.
 * @return bool
 */
function xmldb_local_launchpad_upgrade($oldversion) {
    if ($oldversion < 2026102812) {
        \local_launchpad\registration::ensure_email_self_registration();
        upgrade_plugin_savepoint(true, 2026102812, 'local', 'launchpad');
    }

    if ($oldversion < 2026102813) {
        \local_launchpad\registration::ensure_email_self_registration(true);
        upgrade_plugin_savepoint(true, 2026102813, 'local', 'launchpad');
    }

    if ($oldversion < 2026102814) {
        \local_launchpad\registration::ensure_email_self_registration(true);
        upgrade_plugin_savepoint(true, 2026102814, 'local', 'launchpad');
    }

    if ($oldversion < 2026111602) {
        // Reveal LaunchPad category folders for Moodle course management without
        // restoring course.visible (fellows stay gated by Show to fellows).
        \local_launchpad\admin_dashboard::reveal_launchpad_categories_for_management();
        upgrade_plugin_savepoint(true, 2026111602, 'local', 'launchpad');
    }

    if ($oldversion < 2026111603) {
        // Seed per-track mentor rubric packs for existing lp-* track courses.
        \local_launchpad\track_rubric::backfill_missing_packs();
        upgrade_plugin_savepoint(true, 2026111603, 'local', 'launchpad');
    }

    if ($oldversion < 2026111604) {
        // Manage tracks merged into Tracks & courses (nav + Danger zone UI only).
        upgrade_plugin_savepoint(true, 2026111604, 'local', 'launchpad');
    }

    if ($oldversion < 2026111605) {
        // Tracks & courses clean dashboard UI polish (template/CSS only).
        upgrade_plugin_savepoint(true, 2026111605, 'local', 'launchpad');
    }

    if ($oldversion < 2026111606) {
        // E8/E9 honesty: assign upload-fail blocks save/submit; clearer flashes; submit confirm.
        upgrade_plugin_savepoint(true, 2026111606, 'local', 'launchpad');
    }

    if ($oldversion < 2026111607) {
        // Employer staff admin Phase 1: create/list/revoke + persona exclusivity.
        upgrade_plugin_savepoint(true, 2026111607, 'local', 'launchpad');
    }

    if ($oldversion < 2026111608) {
        // Mentor in-shell profile/password; Employer staff Phase 2 course enrol/unenrol.
        upgrade_plugin_savepoint(true, 2026111608, 'local', 'launchpad');
    }

    if ($oldversion < 2026111609) {
        // Interim tip used briefly on wip/fellow-lesson-ux before cert tips were restored.
        upgrade_plugin_savepoint(true, 2026111609, 'local', 'launchpad');
    }

    if ($oldversion < 2026111700) {
        // Landing Sign-in handoff: clear is-leaving overlay on bfcache Back/Forward.
        upgrade_plugin_savepoint(true, 2026111700, 'local', 'launchpad');
    }

    if ($oldversion < 2026111701) {
        // Employer login/Access portal: restore pin in hook; skip testsession steal; persona Access portal.
        upgrade_plugin_savepoint(true, 2026111701, 'local', 'launchpad');
    }

    if ($oldversion < 2026111702) {
        // Cross-role portal: wrong login tab must not stamp/open another shell.
        upgrade_plugin_savepoint(true, 2026111702, 'local', 'launchpad');
    }

    if ($oldversion < 2026111703) {
        // Mentor tag must not be overwritten by fellow login / labelled as Fellow.
        upgrade_plugin_savepoint(true, 2026111703, 'local', 'launchpad');
    }

    if ($oldversion < 2026111704) {
        // Course ops Certificate panel: attach once / edit design / issue-safe detach.
        upgrade_plugin_savepoint(true, 2026111704, 'local', 'launchpad');
    }

    if ($oldversion < 2026111705) {
        // Cert Step 3: criteria gate, customcert URL → Progress, Download auto-issue PDF.
        upgrade_plugin_savepoint(true, 2026111705, 'local', 'launchpad');
    }

    if ($oldversion < 2026111706) {
        // Fellow lesson UX Slice 0–1: videowatch in LaunchPad shell + phase playlist / prev-next.
        upgrade_plugin_savepoint(true, 2026111706, 'local', 'launchpad');
    }

    if ($oldversion < 2026111707) {
        // Playlist/hero use Home fellow status labels; activity crumbs show track name.
        upgrade_plugin_savepoint(true, 2026111707, 'local', 'launchpad');
    }

    if ($oldversion < 2026111708) {
        // Fellow lesson UX Slice 2: live watch progress bar + Done without reload.
        upgrade_plugin_savepoint(true, 2026111708, 'local', 'launchpad');
    }

    if ($oldversion < 2026111709) {
        // Slice 2 tweak: stepped watch % in phase playlist; drop under-video notice.
        upgrade_plugin_savepoint(true, 2026111709, 'local', 'launchpad');
    }

    if ($oldversion < 2026111710) {
        // Fellow lesson UX Slice 3: Moodle VideoJS stage + downloads under player.
        upgrade_plugin_savepoint(true, 2026111710, 'local', 'launchpad');
    }

    if ($oldversion < 2026111711) {
        // Slice 3 fix: flush VideoJS AMD/CSS in custom Fellow/Employer shells.
        upgrade_plugin_savepoint(true, 2026111711, 'local', 'launchpad');
    }

    if ($oldversion < 2026111712) {
        // Slice 3 fix: also flush top-of-body JS (javascript-static / M.util.js_pending) so VideoJS boots.
        upgrade_plugin_savepoint(true, 2026111712, 'local', 'launchpad');
    }

    if ($oldversion < 2026111713) {
        // Slice 3: click-open speed menu, custom speed UI, quality gear for Moodle-stored videos.
        upgrade_plugin_savepoint(true, 2026111713, 'local', 'launchpad');
    }

    if ($oldversion < 2026111714) {
        // Cross-role: poisoned mentor tag must not override employee/fellow shells.
        upgrade_plugin_savepoint(true, 2026111714, 'local', 'launchpad');
    }

    if ($oldversion < 2026111715) {
        // Stitch-aligned Fellow lesson shell: playlist right, real phase progress, download cards.
        upgrade_plugin_savepoint(true, 2026111715, 'local', 'launchpad');
    }

    if ($oldversion < 2026111716) {
        // Fellow lesson UX Slice 4: readable text article stage; E7 only when real sections exist.
        upgrade_plugin_savepoint(true, 2026111716, 'local', 'launchpad');
    }

    if ($oldversion < 2026111717) {
        // Next stays visible but locked until fellow-access-complete / videowatch Done.
        upgrade_plugin_savepoint(true, 2026111717, 'local', 'launchpad');
    }

    if ($oldversion < 2026111718) {
        // Fix video length meta when lang cache is stale; drop duplicate bottom Next.
        upgrade_plugin_savepoint(true, 2026111718, 'local', 'launchpad');
    }

    if ($oldversion < 2026111719) {
        // Drop native VideoJS title tooltip over the player (title already in hero).
        upgrade_plugin_savepoint(true, 2026111719, 'local', 'launchpad');
    }

    if ($oldversion < 2026111720) {
        // Compact < > prev/next in activity topbar; remove long Next/Previous buttons.
        upgrade_plugin_savepoint(true, 2026111720, 'local', 'launchpad');
    }

    if ($oldversion < 2026111721) {
        // Assign file dropzone: PDF/DOCX only, 1 file, Moodle file plugin + size/type checks.
        upgrade_plugin_savepoint(true, 2026111721, 'local', 'launchpad');
    }

    if ($oldversion < 2026111722) {
        // Draft Remove file; honour assignsubmission_file max files/size/types; submitted stays locked.
        upgrade_plugin_savepoint(true, 2026111722, 'local', 'launchpad');
    }

    if ($oldversion < 2026111723) {
        // Fellow mobile topbar: Stitch two-tier brand + track/phase context (portal only).
        upgrade_plugin_savepoint(true, 2026111723, 'local', 'launchpad');
    }

    if ($oldversion < 2026111724) {
        // Fellow mobile V2: phase playlist as bottom-sheet drawer (desktop rail unchanged).
        upgrade_plugin_savepoint(true, 2026111724, 'local', 'launchpad');
    }

    if ($oldversion < 2026111725) {
        // Mobile polish: chevrons, downloads separator, no syllabus dim, control-bar align, syllabus string.
        upgrade_plugin_savepoint(true, 2026111725, 'local', 'launchpad');
    }

    if ($oldversion < 2026111726) {
        // VideoJS bar: progress-on-top ref layout, hide LIVE, responsive control row.
        upgrade_plugin_savepoint(true, 2026111726, 'local', 'launchpad');
    }

    if ($oldversion < 2026111727) {
        // Stitch video player: boot poster/loader → center play; white scrubber; no FOUC.
        upgrade_plugin_savepoint(true, 2026111727, 'local', 'launchpad');
    }

    if ($oldversion < 2026111728) {
        // Control bar: remove PiP; strict play·volume·time | 1x·CC·gear·FS order.
        upgrade_plugin_savepoint(true, 2026111728, 'local', 'launchpad');
    }

    if ($oldversion < 2026111729) {
        // Mobile control bar: single nowrap row; progress absolute; volume icon-only.
        upgrade_plugin_savepoint(true, 2026111729, 'local', 'launchpad');
    }

    if ($oldversion < 2026111730) {
        // Mobile: tap video to re-show controls; idle bar pointer-events none.
        upgrade_plugin_savepoint(true, 2026111730, 'local', 'launchpad');
    }

    if ($oldversion < 2026111731) {
        // Mobile: full-width 16:9 only (no full-height); Stitch compact control chrome.
        upgrade_plugin_savepoint(true, 2026111731, 'local', 'launchpad');
    }

    if ($oldversion < 2026111732) {
        // Fix playback-rate menu: explicit rate apply + unclip overflow for popups.
        upgrade_plugin_savepoint(true, 2026111732, 'local', 'launchpad');
    }

    if ($oldversion < 2026111737) {
        // Custom rate popover; stop videowatch forcing 1x; clip player corners.
        upgrade_plugin_savepoint(true, 2026111737, 'local', 'launchpad');
    }

    if ($oldversion < 2026111738) {
        // Video stage fill: absolute tech + object-fit cover (16:9 stage).
        upgrade_plugin_savepoint(true, 2026111738, 'local', 'launchpad');
    }

    if ($oldversion < 2026111739) {
        // Drop branded blue stage underlay; black boot → gone on play.
        upgrade_plugin_savepoint(true, 2026111739, 'local', 'launchpad');
    }

    return true;
}
