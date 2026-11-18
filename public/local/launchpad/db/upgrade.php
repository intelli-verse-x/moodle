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

    return true;
}
