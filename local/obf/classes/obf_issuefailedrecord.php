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

namespace classes;

use classes\criterion\obf_criterion;
use classes\criterion\obf_criterion_item;
use Exception;

require_once(__DIR__ . '/../classes/criterion/obf_criterion_activity.php');

/**
 * Unique identifier for a specific element
 */
class obf_issuefailedrecord {

    /**
     * Unique identifier for a specific element
     */
    protected $id;
    /**
     * Represents an array of recipients for a specific action or event.
     */
    protected $recipients;
    /**
     * Represents a timestamp in Unix epoch format.
     */
    protected $timestamp;
    /**
     * Variable representing the email address to send the message to.
     */
    protected $email;
    /**
     * Represents additional criteria to be added to a query.
     */
    protected $criteriaaddendum;
    /**
     * Represents the current status of the system.
     */
    protected $status;
    /**
     * Represents a collection of items.
     */
    protected $items;
    /**
     * The client id link to the badge.
     */
    protected $clientid;

    /**
     * The client id link to the badge.
     */
    protected $criterionid;

    /**
     * Constructor for initializing object properties from a given record.
     *
     * @param object $record An object containing properties for initialization.
     * @return void
     */
    public function __construct($record) {
        $this->id = $record->id;
        $this->recipients = $record->recipients;
        $this->timestamp = $record->time;
        $this->email = $record->email;
        $this->criteriaaddendum = $record->criteriaaddendum;
        $this->status = $record->status;
        $this->items = $record->items;
        $this->clientid = $record->clientid;
        $this->criterionid = $record->criterionid;
    }

    /**
     * Retrieves the ID of the current object.
     *
     * @return int The ID of the object.
     */
    public function getid() {
        return $this->id;
    }

    /**
     * Retrieves the recipients of the message as an array.
     *
     * Parses the recipients JSON string stored in the class property $recipients and
     * returns it as an array.
     *
     * @return array An array containing the recipients of the message.
     */
    public function getrecipients() {
        return json_decode($this->recipients);
    }

    /**
     * Retrieves the timestamp value of the object.
     *
     * Retrieves and returns the timestamp property of the object. This property represents the specific
     * date and time associated with the object instance or record.
     *
     * @return mixed The timestamp value of the object.
     */
    public function gettimestamp() {
        return $this->timestamp;
    }

    /**
     * Retrieves the email address stored in the object and decodes it from JSON format.
     *
     * @return mixed Returns the email address decoded as an associative array.
     */
    public function getemail() {
        $email = $this->email;
        return json_decode(
            $email,
            true,
        );
    }

    /**
     * Retrieves the criteria addendum property of the object.
     *
     * This method returns the criteria addendum property of the object, which contains additional details or criteria specified
     * for the object.
     *
     * @return mixed The criteria addendum property of the object.
     */
    public function getcriteriaaddendum() {
        return $this->criteriaaddendum;
    }

    /**
     * Get the status of the current object.
     *
     * This method returns the value of the status property of the object.
     *
     * @return mixed The current status of the object.
     */
    public function getstatus() {
        return $this->status;
    }

    /**
     * Get the client id of the current object.
     *
     * This method returns the value of the status property of the object.
     *
     * @return mixed The current status of the object.
     */
    public function getclientid() {
        return $this->clientid;
    }

    /**
     * Get the criterion id of the current object.
     *
     * This method returns the value of the status property of the object.
     *
     * @return mixed The current status of the object.
     */
    public function getcriterionid() {
        return $this->criterionid;
    }

    /**
     * Retrieve items property and decode it as JSON.
     *
     * This method returns the items property of the object instance and decodes it as a JSON string into an associative array.
     *
     * @return array|null Returns the items property decoded as an associative array, or null if items property is empty or
     *     invalid JSON.
     */
    public function getitems() {
        $itemsArray = unserialize($this->items);
        if (is_array($itemsArray)) {
            foreach ($itemsArray as &$item) {
                $item = obf_criterion_item::fromArray($item); // We need a way to reconstruct obf_criterion_item from an array
            }
            unset($item);
            // break the reference with the last element.
        }
        return $itemsArray;
    }

    /**
     * Deletes the current record from the database table 'local_obf_issuefailedrecord'.
     *
     * @return bool Returns true if the record was successfully deleted, false otherwise.
     * @global object $DB The global database object.
     *
     */
    public function delete() {
        global $DB;

        if ($DB->delete_records(
            'local_obf_issuefailedrecord',
            [ 'id' => $this->id ],
        )) {
            return true;
        }
        return false;
    }

    /**
     * Get a formatted string of recipients by joining them with a comma.
     *
     * @return string The formatted string of recipients separated by commas.
     */
    public function getformattedrecipients(): string {
        return implode(
            ',',
            $this->getrecipients(),
        );
    }

    /**
     * Retrieves information related to the badge and client for the current object.
     * Uses global $DB for database access.
     *
     * @return array Associative array containing 'criteriondata' with obf_criterion object and 'badge' object.
     *               Returns empty array if badge is not found.
     */
    public function getinformations() {
        global $DB;

        $badge = null;
        $client = null;

        if($this->clientid) {
            $client = obf_client::connect($this->clientid);
            try {
                $badge = $client->get_badge($this->getemail()['badgeid']);
                $badge = obf_badge::get_instance_from_array($badge);
                $badge->set_client($client);
            } catch (Exception $e) {
                // Ignore if badge data can't be retrieve.
            }
        } else {
            // Fallback if for some reason clientid was not saved.
            // Find the correct client.
            $clientavaible = $DB->get_records(
                'local_obf_oauth2',
                null,
                'client_name',
            );
            if (empty($clientavaible)) {
                // Fallback for legacy connect.
                $client = obf_client::get_instance();
                try {
                    $badge = $client->get_badge($this->getemail()['badgeid']);
                    $badge = obf_badge::get_instance_from_array($badge);
                } catch (Exception $e) {
                    // Ignore if badge data can't be retrieve.
                }
            } else {
                foreach ($clientavaible as $client) {
                    $client = obf_client::connect($client->client_id);
                    try {
                        $badge = $client->get_badge($this->getemail()['badgeid']);
                        $badge = obf_badge::get_instance_from_array($badge);
                        $badge->set_client($client);
                        break;
                    } catch (Exception $e) {
                        // If no records is find for the badge we continue searching for...
                        continue;
                    }
                }
            }
        }

        if ($client) {
            $criterion = new obf_criterion();
            if ($badge) {
                $criterion->set_badge($badge);
            } else {
                $criterion->set_badgeid($this->getemail()['badgeid']);
            }

            $criterion->set_id($this->getcriterionid());
            $criterion->set_clientid($client->client_id());
            $criterion->set_items($this->getitems());

            return [
                'criteriondata' => $criterion,
                'badge' => $badge,
                'client' => $client
            ];
        } else {
            // If badge is nowhere to be found we return empty data.
            return [];
        }
    }
}
