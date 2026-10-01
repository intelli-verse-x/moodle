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
// along with Moodle.  If not, see <http://www.gnu.org/copyleft/gpl.html>.

namespace local_requirelogin;

/**
 * Installs the shipped ToBa logo when the site has no logo yet.
 *
 * The image is a file setting in the database, so committing CSS alone never
 * shows it. This copies the plugin pix file into the admin logo areas.
 *
 * @package    local_requirelogin
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class logo {
    /**
     * Store the bundled logo as the site logo and the compact navbar logo.
     *
     * An admin who already chose a logo is left alone.
     */
    public static function install(): void {
        global $CFG;

        if (environment::is_automated_test()) {
            return;
        }

        $source = $CFG->dirroot . '/local/requirelogin/pix/toba-logo.png';
        if (!is_readable($source)) {
            return;
        }

        $fs = get_file_storage();
        $contextid = \context_system::instance()->id;
        $changed = false;
        foreach (['logo', 'logocompact'] as $area) {
            if (get_config('core_admin', $area)) {
                continue;
            }
            $fs->delete_area_files($contextid, 'core_admin', $area);
            $file = $fs->create_file_from_pathname([
                'contextid' => $contextid,
                'component' => 'core_admin',
                'filearea' => $area,
                'itemid' => 0,
                'filepath' => '/',
                'filename' => 'toba-logo.png',
            ], $source);
            set_config($area, $file->get_filepath() . $file->get_filename(), 'core_admin');
            $changed = true;
        }
        if ($changed) {
            theme_reset_all_caches();
        }
    }
}
