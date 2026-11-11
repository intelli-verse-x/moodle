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
 * AJAX: LaunchPad notification dropdown + inbox detail (Moodle popup APIs).
 *
 * @package    local_launchpad
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require(__DIR__ . '/../../config.php');

require_once(__DIR__ . '/classes/messaging.php');
require_once(__DIR__ . '/classes/fellowship_dashboard.php');
require_once(__DIR__ . '/classes/mentor_dashboard.php');
require_once(__DIR__ . '/classes/admin_dashboard.php');
require_once(__DIR__ . '/classes/account_persona.php');

use local_launchpad\account_persona;
use local_launchpad\admin_dashboard;
use local_launchpad\fellowship_dashboard;
use local_launchpad\mentor_dashboard;
use local_launchpad\messaging;

require_login();
require_sesskey();

header('Content-Type: application/json; charset=utf-8');

$userid = (int) $USER->id;
$allowed = fellowship_dashboard::can_access($userid)
    || mentor_dashboard::can_access($userid)
    || admin_dashboard::can_access($userid)
    || account_persona::is_employee($userid)
    || is_siteadmin();
if (!$allowed) {
    echo json_encode(['ok' => false, 'error' => 'permission', 'notifications' => [], 'unreadcount' => 0]);
    exit;
}

$action = required_param('action', PARAM_ALPHAEXT);

if ($action === 'list') {
    $limit = optional_param('limit', 20, PARAM_INT);
    $offset = optional_param('offset', 0, PARAM_INT);
    $portal = optional_param('portal', 'fellow', PARAM_ALPHA);
    if (!in_array($portal, ['fellow', 'mentor', 'admin', 'employer'], true)) {
        $portal = 'fellow';
    }
    echo json_encode(messaging::list_popup_notifications($userid, $limit, $offset, $portal));
    exit;
}

if ($action === 'markall') {
    $timecreatedto = optional_param('timecreatedto', 0, PARAM_INT);
    echo json_encode(messaging::mark_all_popup_notifications_read($userid, $timecreatedto));
    exit;
}

if ($action === 'detail') {
    $notifid = required_param('notifid', PARAM_INT);
    $portal = optional_param('portal', 'fellow', PARAM_ALPHA);
    $ntab = optional_param('ntab', 'all', PARAM_ALPHA);
    if (!in_array($portal, ['fellow', 'mentor', 'admin', 'employer'], true)) {
        $portal = 'fellow';
    }
    if (!in_array($ntab, ['all', 'unread'], true)) {
        $ntab = 'all';
    }
    echo json_encode(messaging::notification_detail_for_inbox($userid, $notifid, $portal, $ntab));
    exit;
}

echo json_encode(['ok' => false, 'error' => 'unknownaction', 'notifications' => [], 'unreadcount' => 0]);
