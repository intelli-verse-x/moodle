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
 * Hook callbacks for local_launchpad.
 *
 * @package    local_launchpad
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_launchpad;

use core\hook\after_config;

defined('MOODLE_INTERNAL') || die();

/**
 * Enable Fellowship email self-registration when unset.
 *
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * After config load: turn on auth_email self-registration if empty.
     *
     * @param after_config $hook
     */
    public static function after_config(after_config $hook): void {
        if (during_initial_install()) {
            return;
        }
        // Enable Email self-registration when signup is not usable yet.
        registration::ensure_email_self_registration(true);
    }
}
