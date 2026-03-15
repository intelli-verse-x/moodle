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
 * Cron task for failed issue badge to try again.
 *
 * @package     local_obf
 * @author      Sylvain Revenu | Pimenko 2024
 * @copyright   2013-2020, Open Badge Factory Oy
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_obf\task;

use cache_helper;
use classes\criterion\obf_criterion;
use classes\obf_badge;
use classes\obf_client;
use classes\obf_email;
use classes\obf_issue_event;
use classes\obf_issuefailedrecord;
use Exception;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../obf_issuefailedrecord.php');
require_once(__DIR__ . '/../criterion/obf_criterion.php');
require_once(__DIR__ . '/../client.php');
require_once(__DIR__ . '/../email.php');
require_once(__DIR__ . '/../event.php');

/**
 * Cron task for processing failed issue records.
 *
 * This class extends \core\task\scheduled_task and implements methods for retrieving
 * the task name and executing the task to handle failed issue records.
 */
class obf_issuefailedrecord_task extends \core\task\scheduled_task {

    /**
     * Retrieve the name from the 'local_obf' language file for the 'processobfissuefailedrecord' key.
     *
     * @return string The name retrieved from the language file.
     */
    public function get_name() {
        return get_string(
            'processobfissuefailedrecord',
            'local_obf',
        );
    }

    /**
     * Execute the logic for processing failed records from the 'local_obf' database table.
     *
     * Retrieves records from the database table and processes each record based on its status. If the status is 'pending',
     * 'error', or null, it attempts to resolve the issue by issuing a badge to the recipient. If the recipient already
     * received the badge, it deletes the record. If the status is 'success', it simply deletes the record.
     *
     * @return void
     */
    public function execute() {
        global $DB;

        $records = $DB->get_records('local_obf_issuefailedrecord');

        foreach ($records as $record) {
            if ($record->status == "pending" || $record->status == "error" || $record->status == null) {
                // Try to issue if the record's status is 'pending', 'error', or null.
                try {
                    $issuefailed = new obf_issuefailedrecord($record);

                    $informations = $issuefailed->getinformations();
                    $badge = $informations['badge'] ?? null;

                    // Skip processing if there is no associated badge.
                    if ($badge === null) {
                        mtrace(
                            get_string(
                                'processobfissuefailedrecordlog',
                                'local_obf',
                                $issuefailed->getemail()['badgeid'],
                            ),
                        );
                        continue;
                    }

                    $all_recipients = $issuefailed->getrecipients();
                    $recipients = [];
                    foreach ($all_recipients as $recipient_email) {
                        $user = $DB->get_record(
                            'user',
                            [ 'email' => $recipient_email ],
                        );
                        if ($user) {
                            $recipients[$user->id] = $user->email;
                        }
                    }

                    if (empty($recipients)) {
                        // No recipients in the Moodle db, remove from queue.
                        $DB->delete_records(
                            'local_obf_issuefailedrecord',
                            [ 'id' => $record->id ],
                        );
                        continue;
                    }

                    // Regenerate the criterion and email content.
                    $criterion = $informations['criteriondata'];

                    $email = new obf_email();
                    $email->set_id($issuefailed->getemail()['id']);
                    $email->set_body($issuefailed->getemail()['body']);
                    $email->set_subject($issuefailed->getemail()['subject']);
                    $email->set_badge_id($issuefailed->getemail()['badgeid']);
                    $email->set_footer($issuefailed->getemail()['footer']);
                    $email->set_link_text($issuefailed->getemail()['linktext']);

                    // Get client alias id if used in criterion.
                    $items = $criterion->get_items();
                    $clientaliasid = $criterion->get_clientaliasid_from_items($items);
                    
                    // Attempt to issue the badge for the recipient.
                    $eventid = $badge->issue(
                        array_values($recipients),
                        $issuefailed->gettimestamp(),
                        $email,
                        $issuefailed->getcriteriaaddendum(),
                        $items,
                        $clientaliasid,
                    );

                    // Mark the criterion as met for the users.
                    foreach (array_keys($recipients) AS $user_id) {
                        $criterion->set_met_by_user($user_id);
                    }

                    // If badge issuance is valid, create a related event and save the criterion.
                    if ($eventid && !is_bool($eventid)) {
                        $issuevent = new obf_issue_event(
                            $eventid,
                            $DB,
                        );
                        $issuevent->set_criterionid($criterion->get_id());
                        $issuevent->save($DB);
                    }

                    // Update the cache and set the record's status to 'success' after successful badge issuance.
                    cache_helper::invalidate_by_event(
                        'new_obf_assertion',
                        [ $user->id ],
                    );
                    $DB->set_field(
                        'local_obf_issuefailedrecord',
                        'status',
                        "success",
                        [ 'id' => $record->id ],
                    );
                } catch (Exception $e) {
                    $timestamp = $issuefailed->gettimestamp();
                    // If the failed record is more than a day old, update its status to 'error'.
                    if ((time() - $timestamp) > 86400) {
                        $DB->set_field(
                            'local_obf_issuefailedrecord',
                            'status',
                            "error",
                            [ 'id' => $record->id ],
                        );
                    }
                    // If at least one recipient failed, we postpone the record.
                    continue;
                }

            } else if ($record->status == "success") {
                // Delete the record if its status is 'success'.
                $DB->delete_records(
                    'local_obf_issuefailedrecord',
                    [ 'id' => $record->id ],
                );
            }
        }
    }
}
