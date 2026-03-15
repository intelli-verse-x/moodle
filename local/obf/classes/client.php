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
 * OBF Client.
 *
 * @package    local_obf
 * @copyright  2013-2025, Open Badge Factory Oy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace classes;

use context_course;
use context_coursecat;
use context_system;
use core\message\message;
use curl;
use dml_write_exception;
use Exception;
use html_writer;
use local_obf_html;
use moodle_url;
use stdClass;
use url;
use \Collator;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../lib.php');
require_once($CFG->libdir . '/filelib.php');

/**
 * Class for handling the communication to Open Badge Factory API v2.
 *
 * @copyright  2013-2021, Open Badge Factory Oy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class obf_client {
    /**
     * @var $client Static obf_client singleton
     */
    private static $client = null;

    /**
     * @var string Static current client id
     */
    private static $clientid = null;


    /**
     * @var curl|null Transport. Curl.
     */
    private $transport = null;

    /**
     * @var object local_obf_oauth2 table row
     */
    private $oauth2 = null;

    /**
     * @var int HTTP code for handling errors, such as deleted badges.
     */
    private $httpcode = null;
    /**
     * @var string Last error message.
     */
    private $error = '';
    /**
     * @var array Raw response.
     */
    private $rawresponse = null;
    /**
     * @var bool Store raw response?
     */
    private $enablerawresponse = false;

    /**
     * @var array event id => api details lookup table
     */
    private $eventlookup = null;

    /**
     * @var array Total badge issuing events.
     */
    private $total_assertions = null;

    const RETRIEVE_ALL = 'all';
    const RETRIEVE_LOCAL = 'local';

    /**
     * Returns the client instance.
     *
     * @param curl|null $transport
     * @return obf_client The client.
     */
    public static function get_instance($transport = null) {
        global $DB;
        if (is_null(self::$client)) {

            self::$client = new self();

            $oauth2 = $DB->get_records('local_obf_oauth2', null, 'client_name');
            if (count($oauth2) > 0) {
                // Use the first one by default.
                $o2 = current($oauth2);
                if (self::$clientid) {
                    foreach ($oauth2 as $o) {
                        if ($o->client_id === self::$clientid) {
                            $o2 = $o;
                            break;
                        }
                    }
                }
                self::$client->set_oauth2($o2);
            }

            if (!is_null($transport)) {
                self::$client->set_transport($transport);
            }
        }

        return self::$client;
    }

    /**
     * Set current active OAuth2 connection. Returns the client instance.
     *
     * @param string client_id in local_obf_oauth2 table row
     * @return obf_client The client.
     */
    public static function connect($id, $user = null, $transport = null) {
        self::$client = null;
        self::$clientid = $id;

        $ok = true;

        $available = self::get_available_clients($user);

        if (empty($available)) {
            // No connection available.
            $ok = false;
        } else if (!is_null($user)) {
            // OAuth2 connections.
            $ok = is_null($id) ? !empty($available) : isset($available[$id]);
        }

        if (!$ok) {
            throw new Exception(get_string('apierror0', 'local_obf'), 0);
        }

        return self::get_instance($transport);
    }

    /**
     * Get configured OAuth2 clients
     *
     * @return array id and name pairs
     */
    public static function get_available_clients($user = null) {
        global $CFG, $DB, $USER;

        if (is_null($user)) {
            $user = $USER;
        }

        if ($user === '*' || in_array($user->id, explode(',', $CFG->siteadmins))) {
            // Can see all connected clients.
            return $DB->get_records_menu('local_obf_oauth2', null, 'client_name', 'client_id, client_name');
        }

        // Get connected clients based on user role access (role can be in any context).
        $sql =
            "SELECT DISTINCT o.client_id, o.client_name FROM {local_obf_oauth2} o
            INNER JOIN {local_obf_oauth2_role} r ON o.id = r.oauth2_id
            INNER JOIN {role_assignments} ra ON r.role_id = ra.roleid
            WHERE ra.userid = ?
            ORDER BY o.client_name";

        return $DB->get_records_sql_menu($sql, array($user->id));
    }

    /**
     * Checks if there is at least one client_id stored in the database or if the obfclientid config is not empty.
     *
     * @return bool Returns true if at least one client_id is found, false otherwise.
     */
    public static function has_client_id() {
        global $DB;
        return $DB->count_records('local_obf_oauth2') > 0 || !empty(get_config('local_obf', 'obfclientid'));
    }

    /**
     * Checks that the OBF client id is stored to plugin settings.
     *
     * @throws Exception If the client id is missing.
     */
    public function require_client_id() {
        if (empty($this->oauth2->client_id) && empty(get_config('local_obf', 'obfclientid'))) {
            throw new Exception(get_string('apierror0', 'local_obf'), 0);
        }
    }

    /**
     * Get OBF api url
     *
     * @return string
     */
    private function obf_url() {
        if (isset($this->oauth2->obf_url)) {
            $url = $this->oauth2->obf_url;
        } else {
            $url = get_config('local_obf', 'apiurl');
        }
        return rtrim($url, '/');
    }

    /**
     * Get current active client id
     *
     * @return string
     */
    public function client_id() {
        if (isset($this->oauth2->client_id)) {
            return $this->oauth2->client_id;
        }
        return get_config('local_obf', 'obfclientid');
    }

    /**
     * Get current active client id
     *
     * @return string
     */
    public function local_events() {
        return get_config('local_obf', 'apidataretrieve') == self::RETRIEVE_LOCAL;
    }

    /**
     * Set current active API client credentials
     *
     * @param object $input Input row from local_obf_oauth2 table
     * @return null
     */
    public function set_oauth2($input) {

        if (!preg_match('/^https?:\/\/.+/', $input->obf_url)) {
            throw new Exception('Invalid parameter $obf_url');
        }
        if (!preg_match('/^\w+$/', $input->client_id)) {
            throw new Exception('Invalid parameter $clientid');
        }
        if (!preg_match('/^\w+$/', $input->client_secret)) {
            throw new Exception('Invalid parameter $clientsecret');
        }

        self::$clientid = $input->client_id;

        $input->obf_url = rtrim($input->obf_url, '/');

        $this->oauth2 = $input;
    }

    /**
     * Get access token. Request a new access token using client credentials if needed.
     *
     * @return array access token and expiration timestamp
     */
    public function oauth2_access_token() {
        global $DB;

        $this->require_client_id();

        if (!isset($this->oauth2->access_token) || $this->oauth2->token_expires < time()) {

            $url = $this->obf_url() . '/v2/client/oauth2/token';

            $params = array(
                'grant_type' => 'client_credentials',
                'client_id' => $this->oauth2->client_id,
                'client_secret' => $this->oauth2->client_secret
            );

            $curl = $this->get_transport();
            $options = $this->get_curl_options(false);

            // Add HTTPHEADER option for this special request.
            $options['HTTPHEADER'][] = 'Content-Type: application/x-www-form-urlencoded';

            // Make sure http_build_query() $arg_separator is '&' as required by OBF API.
            $res = $curl->post($url, http_build_query($params, '', '&'), $options);
            $res = json_decode($res);

            if (!isset($res)) {
                $res = new stdClass(); // Create a default object when the response is missing.
                $res->error = get_string('apierror503', 'local_obf');
            }

            if (isset($res->error)) {
                throw new Exception(get_string('failedtogetaccesstoken', 'local_obf') . $res->error);
            }

            $this->oauth2->access_token = $res->access_token;
            $this->oauth2->token_expires = time() + $res->expires_in;

            $sql = "UPDATE {local_obf_oauth2} SET access_token = ?, token_expires = ? WHERE client_id = ?";
            $DB->execute($sql, array($this->oauth2->access_token, $this->oauth2->token_expires, $this->oauth2->client_id));
        }

        return array(
            'access_token' => $this->oauth2->access_token,
            'token_expires' => $this->oauth2->token_expires
        );
    }

    /**
     * Returns a new curl-instance.
     *
     * @return \curl
     */
    public function get_transport() {
        if (!is_null($this->transport)) {
            return $this->transport;
        }

        // Use Moodle's curl-object if no transport is defined.
        return new curl();
    }

    /**
     * Set object transport.
     *
     * @param curl $transport
     */
    public function set_transport($transport) {
        $this->transport = $transport;
    }

    /**
     * Returns the default CURL-settings for a request.
     *
     * @return array
     */
    private function get_curl_options($auth = true) {

        // Don't verify localhost dev server.
        $url = $this->obf_url();
        $secure = strpos($url, 'https://localhost/') !== 0;

        $options = [
            'RETURNTRANSFER' => true,
            'FOLLOWLOCATION' => false,
            'SSL_VERIFYHOST' => $secure ? 2 : 0,
            'SSL_VERIFYPEER' => $secure ? 1 : 0
        ];

        if ($auth) {
            if (isset($this->oauth2)) {
                $token = $this->oauth2_access_token();
                $options['HTTPHEADER'][] = "Authorization: Bearer " . $token['access_token'];
            } else {
                throw new Exception("OAuth2 not configured");
            }
        }

        return $options;
    }

    /**
     * Get raw response.
     *
     * @return string[] Raw response.
     */
    public function get_raw_response() {
        return $this->rawresponse;
    }

    /**
     * Enable/disable storing raw response.
     *
     * @param bool $enable
     * @return obf_client This object.
     */
    public function set_enable_raw_response($enable) {
        $this->enablerawresponse = $enable;
        $this->rawresponse = null;
        return $this;
    }

    /**
     * Makes a CURL-request to OBF API (new style).
     *
     * @param string $method The HTTP method.
     * @param string $url The API path.
     * @param array $params The params of the request.
     * @return string The response string.
     * @throws Exception In case something goes wrong.
     */
    public function request($method, $url = '', $params = array(), $retry = true, $otheroauth2 = null) {
        global $DB;

        $curl = $this->get_transport();
        $options = $this->get_curl_options();

        if ($method === 'get') {
            $response = $curl->get($url, $params, $options);
        } else if ($method === 'post') {
            // Add Content-Type without overwriting get_curl_options() Authorization.
            $options['HTTPHEADER'][] = 'Content-Type: application/json';
            $response = $curl->post($url, json_encode($params), $options);
        } else if ($method === 'put') {
            // Add Content-Type without overwriting get_curl_options() Authorization.
            $options['HTTPHEADER'][] = 'Content-Type: application/json';
            $response = $curl->put($url, json_encode($params), $options);
        } else if ($method === 'delete') {
            $response = $curl->delete($url, $params, $options);
        } else {
            throw new Exception('unknown method ' . $method);
        }

        $this->rawresponse = null;
        if ($this->enablerawresponse) {
            $this->rawresponse = $curl->get_raw_response();
        }

        $info = $curl->get_info();

        if ($info['http_code'] === 403 && empty(get_config('local_obf', 'obfclientid'))) {
            if ($retry) {
                // Try again one time.
                return $this->request($method, $url, $params, false, $otheroauth2);
            }
            // Try with all other available connections.
            if (isset($this->oauth2)) {
                if (is_null($otheroauth2)) {
                    $otheroauth2 = $DB->get_records_select('local_obf_oauth2', 'client_id != ?', array($this->oauth2->client_id));
                }
                if (!empty($otheroauth2)) {
                    $this->set_oauth2(array_shift($otheroauth2));
                    return $this->request($method, $url, $params, true, $otheroauth2);
                }
            }
        }

        $this->httpcode = $info['http_code'];
        $this->error = '';

        // Codes 2xx should be ok.
        if (is_numeric($this->httpcode) && ($this->httpcode < 200 || $this->httpcode >= 300)) {
            $this->error = isset($response['error']) ? $response['error'] : '';
            $appendtoerror = defined('PHPUNIT_TEST') && PHPUNIT_TEST ? ' ' . $method . ' ' . $url : '';
            throw new Exception(get_string(
                'apierror' . $this->httpcode,
                'local_obf',
                $this->error
            ) . $appendtoerror, $this->httpcode);
        }

        return $response;
    }

    /* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */

    /**
     * Tests the connection to OBF API.
     *
     * @return int Returns the error code on failure and -1 on success.
     */
    public function test_connection() {
        if (!self::has_client_id()) {
            return 0;
        }
        try {
            $url = $this->obf_url() . '/v2/client/' . $this->client_id() . '/ping';
            $this->request('get', $url);
            return -1;
        } catch (Exception $exc) {
            return $exc->getCode();
        }
    }

    /**
     * Get all the badges from the API.
     *
     * @param string[] $categories Filter badges by these categories.
     * @return array The badges data.
     */
    public function get_badges(array $categories = array(), $query = '') {
        global $DB;

        $params = array('draft' => 0, 'external' => 1);

        // Checks rules.
        // Add categories to request if special rules are set.
        $courseid = optional_param('courseid', null, PARAM_INT);
        if ($courseid) {
            $course = get_course($courseid);

            $categoryid = $course->category;

            // Get the category path.
            $categorypath = $DB->get_field('course_categories', 'path', ['id' => $categoryid]);

            // Split the category path into an array of category IDs.
            $categoryids = explode('/', trim($categorypath, '/'));

            // Add the current category ID to the array.
            $categoryids[] = $categoryid;

            // Prepare the placeholders for the SQL query.
            $placeholders = implode(',', array_fill(0, count($categoryids), '?'));

            // If any rules are define on site we will prevent display categories in case there is no rule for current categ.
            $anyrulesdefinesql = "SELECT * FROM {local_obf_rulescateg}";
            $anyrules = $DB->get_records_sql($anyrulesdefinesql);

            // Construct the SQL query.
            $sql = "SELECT * FROM {local_obf_rulescateg} WHERE oauth2_id = ? AND (coursecategorieid IN ($placeholders))";
            $args = [obf_client::get_instance()->oauth2->id];
            $args = array_merge($args, $categoryids);

            $rules = $DB->get_records_sql($sql, $args);

            $haszero = false; // Variable to track if at least one occurrence of zero is found.

            foreach ($rules as $rule) {
                if ($rule->badgecategoriename === '0' || $rule->badgecategoriename === null) {
                    $haszero = true; // An occurrence of zero is found.
                    break; // Exit the loop as soon as an occurrence is found.
                }

                if (!in_array($rule->badgecategoriename, $categories)) {
                    $categories[] = $rule->badgecategoriename;
                }
            }

            if ($haszero) {
                $categories = []; // Reset $categories to null if an occurrence of zero is found.
            }
        }

        if (empty($rules) && !empty($anyrules)) {
            return [];
        }

        if (!empty($query)) {
            $query = mb_strtolower($query);
        }

        $badges = []; // Initialize badges array that'll be combined from batches.
        $limit = 1000; // Limit for each batch request (OBF v2 API max 1000).
        $offset = 0; // Offset for each batch request, iteratively increased.

        /** Loop, if necessary, enough of times to get all badges (max 5000) */
        do {
            $params['limit'] = $limit;
            $params['offset'] = $offset;

            $url = $this->obf_url() . '/v2/badge/' . $this->client_id();
            $res = $this->request('get', $url, $params);
            $out = json_decode($res, true);
            $batch = []; // Initialize batch array, will be filled with the response data.

            if (is_array($out) && isset($out['result'])) {
                $batch = $out['result'];
            } else {
                $batch = [];
            }

            // Handle the response data to align with badge.php expectations.
            foreach ($batch as $badge) {
                // Filter badges by categories if categories are provided.
                if (!empty($categories)) {
                    if (
                        !isset($badge['category']) || !is_array($badge['category']) ||
                        !array_intersect($badge['category'], $categories)
                    ) {
                        continue;
                    }
                }

                // Filter badges by query if query is provided.
                if (!empty($query)) {
                    // Filter badges by query, mb_ to support letters with diacritics.
                    $badge_name = mb_strtolower($badge['name'] ?? '');
                    if (mb_strpos($badge_name, $query) === false) {
                        continue;
                    }
                }

                // Remove primary language from alt_language list.
                $primary = $badge['primary_language'] ?? '';
                $languages = $badge['language'] ?? $badge['languages'] ?? [];
                $alt_languages = array_values(array_filter($languages, function ($lang) use ($primary) {
                    return $lang !== $primary && $lang !== '';
                }));

                $badges[] = [
                    'id' => $badge['id'] ?? '',
                    'name' => $badge['name'] ?? '',
                    'description' => $badge['description'] ?? '',
                    'image' => $badge['image'] ?? '',
                    'primary_language' => $primary,
                    'alt_language' => $alt_languages,
                    'category' => $badge['category'] ?? [],
                    'tag' => $badge['tag'] ?? [],
                    'client_alias_id' => $badge['client_alias_id'] ?? [],
                    'creator_id' => $badge['creator_id'] ?? null,
                    'draft' => $badge['draft'] ?? true,
                    'ctime' => $badge['ctime'] ?? 0,
                    'mtime' => $badge['mtime'] ?? 0,
                    'readyforissuing' => isset($badge['draft']) ? !$badge['draft'] : false,
                    'expires' => $badge['expires'] ?? 0, // Probably legacy API keys stuff, remove later.
                    'client_id' => $this->client_id(),
                ];
            }

            $offset += $limit;
            // Stop if batch has less badges than the limit or if we have reached the arbitrary offset limit of 5000.
        } while (count($batch) === $limit && $offset < 5000);

        /** Sort the badges alphabetically */
        $coll = new Collator('root');
        $coll->setStrength( Collator::PRIMARY );
        usort($badges, function ($a, $b) use ($coll) {
            return $coll->compare($a['name'], $b['name']);
        });

        return $badges;
    }

    /**
     * Get all the badges from the API for all configured OAuth2 clients.
     *
     * @param string[] $categories Filter badges by these categories.
     * @return array The badges data.
     */
    public function get_badges_all(array $categories = array(), $query = '') {
        global $DB;

        $oauth2 = $DB->get_records('local_obf_oauth2', null, 'client_name');

        if (empty($oauth2)) {
            return $this->get_badges($categories, $query);
        }

        $prevoauth2 = $this->oauth2;

        $out = [];
        foreach ($oauth2 as $o2) {
            $this->set_oauth2($o2);
            $out = array_merge($out, $this->get_badges($categories, $query));
            usleep(100000); // Sleep 0.1s to avoid hitting rate limits.
        }
        $this->set_oauth2($prevoauth2);

        return $out;
    }

    /**
     * Get all client aliases.
     *
     * @return array The alias data.
     * @throws Exception If the request fails
     */
    public function get_aliases() {
        $url = $this->obf_url() . '/v2/client/' . $this->client_id() . '/alias';
        $res = $this->request('get', $url);
        $data = json_decode($res, true);
        return $data['result'] ?? [];
    }

    /**
     * Get a single badge from the API.
     *
     * @param string $badgeid
     * @return array The badge data.
     * @throws Exception If the request fails
     */
    public function get_badge($badgeid) {
        $url = $this->obf_url() . '/v2/badge/' . $this->client_id() . '/' . $badgeid;
        $res = $this->request('get', $url);

        $badge = json_decode($res, true);
        if (!is_array($badge)) {
            throw new Exception("Invalid badge response");
        }

        // Get all alias objects for the client.
        $result_aliases = $this->get_aliases();

        // Filter alias objects that are available for this badge.
        $available_aliases = [];

        foreach ($result_aliases as $alias) {
            if (in_array($alias['id'], $badge['client_alias_id'] ?? [])) {
                $available_aliases[] = $alias;
            }
        }

        $content = $badge['content'][0] ?? [];

        return [
            'id' => $badge['id'] ?? '',
            'name' => $content['name'] ?? '',
            'description' => $content['description'] ?? '',
            'image' => $badge['image'] ?? '',
            'language' => $badge['primary_language'] ?? '',
            'tags' => $content['tag'] ?? [],
            'alignment' => $content['alignment'] ?? [],
            'creator_id' => $badge['creator']['id'] ?? null, // Not used yet.
            'intent' => $badge['intent'] ?? '',
            'email_subject' => $badge['email_message']['subject'] ?? '',
            'email_body' => $badge['email_message']['body'] ?? '',
            'email_link_text' => $badge['email_message']['link_text'] ?? '',
            'email_footer' => $badge['email_message']['footer'] ?? '',
            'draft' => $badge['draft'] ?? true,
            'ctime' => $badge['ctime'] ?? 0,
            'mtime' => $badge['mtime'] ?? 0,
            'expires' => $badge['expires'] ?? 0,
            'client_id' => $this->client_id(),
            'criteria_html' => $content['criteria'] ?? '',
            // New Badge v3 fields start.
            'achievement_type' => $content['achievement_type'] ?? '',
            'credits_available' => $content['credits_available'] ?? 0.0,
            'field_of_study' => $content['field_of_study'] ?? '',
            'human_code' => $content['human_code'] ?? '',
            'specialization' => $content['specialization'] ?? '',
            // New Badge v3 fields end.
            // API v1 fields set to null.
            'metadata' => null,
            'evidence_definition' => null,
            'css' => null,
            'copy_of' => null,
            'image_small' => null,
            'deleted' => 0,
            'lastmodifiedby' => null,
            'client_aliases' => $available_aliases,
            'client_alias_id' => $badge['client_alias_id'] ?? [], // Not used, client_alias_id's are part of client_aliases objects.
        ];
    }

    /**
     * Get issuer data from the API.
     *
     * @return array The issuer data.
     * @throws Exception If the request fails
     */
    public function get_issuer() {
        $url = $this->obf_url() . '/v2/client/' . $this->client_id();
        $res = $this->request('get', $url);

        $issuer = json_decode($res, true);
        if (!is_array($issuer)) {
            throw new Exception("Invalid issuer response");
        }

        return [
            'id' => $issuer['id'] ?? '',
            'reply_to' => $issuer['reply_to'] ?? '',
            'url' => $issuer['url'] ?? '',
            'vat_id' => $issuer['vat_id'] ?? '',
            'name' => $issuer['name'] ?? '',
            'mtime' => $issuer['mtime'] ?? 0,
            'ctime' => $issuer['ctime'] ?? 0,
            'email' => $issuer['email'] ?? '',
            'billing_email' => $issuer['billing_email'] ?? '',
            'verified' => $issuer['verified'] ? 1 : 0,
            'issuing_limit' => $issuer['issuing_limit'] ?? 0,
            'tier' => $issuer['tier'] ?? '',
            'country' => $issuer['country'] ?? '',
            'image' => $issuer['image'] ?? '',
            'description' => $issuer['description'] ?? '',
            'type' => $issuer['type'] ?? '',
            'aliases' => $issuer['aliases'] ?? 0,
            'searchable' => $issuer['searchable'] ? 1 : 0,
            'paid_until' => $issuer['paid_until'] ?? 0,
            // API v1 fields not present in v2, remove later.
            'is_active' => 1, // Old API v1 field is_active is always set true to ensure potential is_active checks in other classes.
            'deleted' => 0, // Old API v1 field deleted is always set to 0 to ensure potential deleted checks in other classes.
            'suspended' => 0, // Old API v1 field suspended is always set to 0 to ensure potential suspended checks in other classes.
            'enterprise_features' => 0, // Old API v1 field enterprise_features is always set to 0 to ensure potential enterprise features checks in other classes.
            'partner' => [],
            'client_config' => [],
            'lastmodifiedby' => null,
        ];
    }

    public function get_client_info() {
        return $this->get_issuer();
    }

    /**
     * Get badge issuing events from the API.
     *
     * @param string $badgeid The id of the badge.
     * @param string $email The email address of the recipient.
     * @param array $params Optional extra params for the query.
     * @return array The event data.
     */
    public function get_assertions($badgeid = null, $email = null, $params = array(), $include_recipients = true) {

        if ($this->local_events()) {
            $params['api_consumer_id'] = OBF_API_CONSUMER_ID;
        }
        if (!empty($badgeid)) {
            $params['badge_id'] = $badgeid;
        }
        if (!empty($email)) {
            $params['email'] = $email;
        }

        /** Build the output array from the two requests to match the V1 output. */
        $rec_begin = time();
        $rec_end = 0;
        $out = [];

        $paginated = false;
        $max_get = 1000;
        if (isset($params['limit']) || isset($params['offset'])) {
            $max_get = 1;
            $paginated = true;
        }
        else {
            $params['limit'] = 1000;
            $params['offset'] = 0;
        }

        $count = 0;

        for ($i=0; $i < $max_get; $i++) {

            /** Get badge issuing data. */
            $url = $this->obf_url() . '/v2/event/' . $this->client_id();
            $res = $this->request('get', $url, $params);
            $data = json_decode($res, true);

            if (!isset($data['result'])) {
                break;
            }

            $params['offset'] += $params['limit'];

            foreach ($data['result'] ?? [] as $event) {
                $log_entry = [];
                if (!empty($event['log_entry'])) {
                    $log_entry = json_decode($event['log_entry'], true);
                }


                $out[] = array(
                    'id' => $event['id'],
                    'name' => $event['name'] ?? '',
                    'recipient' => [],
                    'recipient_count' => $event['recipient_count'],
                    'issued_on' => $event['issued_on'] ?? null,
                    'badge_id' => $event['badge_id'] ?? null,
                    'expires' => $event['expires_on'] ?? null,
                    'revoked' => [],
                    'log_entry' => $log_entry ?? [],
                    'timestamp' => $event['ctime'] ?? null,
                    '_total' => $data['total'],
                    'client_alias_id' => $event['client_alias_id'] ?? null
                );

                $rec_begin = min($rec_begin, $event['ctime']);
                $rec_end = max($rec_end, $event['ctime']);
            }

            $count += count($data['result']);

            if ($count >= $data['total'] || count($data['result']) < $params['limit']) {
                break;
            }

            usleep(100000);
        }

        if (!empty($out) && $include_recipients) {
            $recipients = [];
            $revoked = [];
            $rec_params = $params;
            if ($paginated) {
                // Event results are paginated, limit recipient list by time range.
                $rec_params['begin'] = $rec_begin - 1;
                $rec_params['end'] = $rec_end + 1;
            }
            $rec_params['limit'] = 1000;
            $rec_params['offset'] = 0;

            $count = 0;

            $url = $this->obf_url() . '/v2/event/' . $this->client_id() . '/recipient';
            for ($i=0; $i < 1000; $i++) {

                $res = $this->request('get', $url, $rec_params);
                $data = json_decode($res, true);

                if (!isset($data['result'])) {
                    break;
                }

                $rec_params['offset'] += $rec_params['limit'];

                foreach ($data['result'] as $r) {
                    if (!isset($recipients[$r['event_id']])) {
                        $recipients[$r['event_id']] = [];
                    }
                    if (!isset($revoked[$r['event_id']])) {
                        $revoked[$r['event_id']] = [];
                    }

                    $recipients[$r['event_id']][] = $r['email'];

                    if ($r['revoked']) {
                        $revoked[$r['event_id']][$r['email']] = 1;
                    }
                }

                $count += count($data['result']);

                if ($count >= $data['total'] || count($data['result']) < $rec_params['limit']) {
                    break;
                }

                usleep(100000);
            }

            foreach ($out as &$o) {
                $o['recipient'] = $recipients[$o['id']] ?? [];
                $o['revoked'] = $revoked[$o['id']] ?? [];
            }
        }

        return $out;
    }

    /**
     * Get badge issuing events from the API, count only.
     *
     * @param string $badgeid The id of the badge.
     * @param string $email The email address of the recipient.
     * @param array $params Optional extra params for the query.
     * @return int result count.
     */
    public function get_assertions_count($badgeid = null, $email = null, $params = array()) {
        if ($this->local_events()) {
            $params['api_consumer_id'] = OBF_API_CONSUMER_ID;
        }
        if (!empty($badgeid)) {
            $params['badge_id'] = $badgeid;
        }
        if (!empty($email)) {
            $params['email'] = $email;
        }

        $params['limit'] = 1;
        $params['offset'] = 0;

        $url = $this->obf_url() . '/v2/event/' . $this->client_id();
        $res = $this->request('get', $url, $params);
        $data = json_decode($res, true);

        return $data['total'] ?? 0;
    }


    /**
     * Get single recipient's all badge issuing events from the API for all connections.
     *
     * @param string $email The email address of the recipient.
     * @param array $params Optional extra params for the query.
     * @return array The event data.
     */
    public function get_recipient_assertions($email, $params = array()) {
        global $DB;

        if (empty($email)) {
            return array();
        }

        $this->eventlookup = [];

        $prevo2 = $this->oauth2;

        $oauth2 = $DB->get_records('local_obf_oauth2');

        $out = [];
        if (!empty($oauth2)) {
            // Iterate through all OAuth2 clients.
            foreach ($oauth2 as $o2) {
                $this->set_oauth2($o2);
                $host = $this->obf_url();
                $res = $this->get_assertions(null, $email, $params);
                foreach ($res as $r) {
                    // Saving host for pub_get_badge to use.
                    $this->eventlookup[$r['id']] = ['host' => $host];
                    $out[] = $r;
                }
            }
        }
        $this->set_oauth2($prevo2);

        return $out;
    }

    /**
     * Get single issuing event from the API.
     *
     * @param string $eventid The id of the event.
     * @return array The event data.
     */
    // TODO: Re-enabled offset parameter later when load-more functionality is integrated.
    public function get_event($eventid, $offset = 0) {

        $url = $this->obf_url() . '/v2/event/' . $this->client_id() . '/' . $eventid;
        $res = $this->request('get', $url);
        $event = json_decode($res, true);

        $recipients = [];
        $offset = 0;
        $limit = 1000;
        // $total = 0;
        // $offset = optional_param('offset', 0, PARAM_INT);

        /**
         * We cannot get all the recipients at once,
         * currently we'll get first 1000,
         * we can easily raise this limit later if needed for example ten times.
         */
        // do { // Get recipients for the event.
        $recipients_url = $this->obf_url() . '/v2/event/' . $this->client_id() . '/recipient';
        $query = ['event_id' => $eventid, 'offset' => $offset, 'limit' => $limit];
        $recipients_res = $this->request('get', $recipients_url, $query);
        $recipients_data = json_decode($recipients_res, true);
        $recipient_list = $recipients_data['result'] ?? [];
        $emails = array_map(fn($recipient) => $recipient['email'], $recipient_list);
        $recipients = array_merge($recipients, $recipients_data['result']);
        $offset += $limit;
        // $total = $recipients_data['total'] ?? 0;
        // } while (count($recipients) < $total);

        $log_entry = [];
        if (!empty($event['log_entry'])) {
            $log_entry = json_decode($event['log_entry'], true);
        }

        /** Handle data to respond v1 specs */
        return [
            'id' => $event['id'] ?? '',
            'name' => $event['name'] ?? '',
            'badge_id' => $event['badge']['id'] ?? '',
            'issued_on' => $event['issued_on'] ?? 0,
            'expires' => $event['expires_on'] ?? 0,
            'recipient' => $emails,
            // For now set false, but could be used in the future to get more recipients.
            'more_recipients_available' => false, // count($recipients) + $offset < $total,
            'next_offset' => $offset + $limit,
            'mtime' => $event['mtime'] ?? 0,
            'timestamp' => $event['mtime'] ?? $event['issued_on'] ?? 0,
            'client_id' => $this->client_id(),
            'client_alias_id' => $event['client_alias_id'] ?? '',
            'api_consumer_id' => $event['api_consumer_id'] ?? '',
            'log_entry' => $log_entry ?? [],
            'earnable_application_id' => $event['earnable_application_id'] ?? '',
            'email_subject' => $event['email_message']['subject'] ?? '',
            'email_body' => $event['email_message']['body'] ?? '',
            'email_pdf_link_text' => $event['email_message']['link_text'] ?? '',
            'email_footer' => $event['email_message']['footer'] ?? '',
            // Legacy API v1 fields. 
            'lastmodifiedby' => null,
            'token' => null,
            'json_cache_id' => '',
            'deleted' => 0, // Old API v1 field deleted is always set to 0 to ensure potential deleted checks in other classes.
            'show_report' => 1, // Always set to 1 to ensure potential show_report checks in other classes.
        ];
    }

    /**
     * Get event's revoked assertions from the API.
     *
     * @param string $eventid The id of the event.
     * @return array The revoked data.
     */
    public function get_revoked($eventid) {
        $url = $this->obf_url() . '/v2/event/' . $this->client_id() . '/recipient';

        $offset = 0; 
        $limit = 1000;
        $map = [];

        do {
            $res  = $this->request('get', $url, ['event_id' => $eventid, 'offset' => $offset, 'limit' => $limit]);
            $data = json_decode($res, true) ?: [];
            $rows = $data['result'] ?? [];
            $total = $data['total'] ?? null;

            foreach ($rows as $r) {
                $isrevoked = false;
                if (array_key_exists('revoked', $r)) {
                    $isrevoked = (bool)$r['revoked'];
                } 
                if (!$isrevoked) { continue; }

                $email = $r['email'] ?? null;
                if (!$email) { continue; }
                $email = strtolower($email);

                $ts = null;
                if (!empty($r['issued_on'])) {
                    $ts = (int)$r['issued_on'];
                } 
                $map[$email] = $ts ?: time();
            }

            $offset += $limit;
            usleep(100000); // Sleep 0.1s to avoid hitting rate limits.
        } while ($total !== null && $offset < (int)$total);

        return ['revoked' => $map];
    }

    /**
     * Get badge categories from Badges data.
     *
     * @return array The category data.
     */
    public function get_categories() {
        $badges = $this->get_badges();
        $categories = [];
        // Collect categories from all badges.
        foreach ($badges as $badge) {
            if (!empty($badge['category']) && is_array($badge['category'])) {
                $categories = array_merge($categories, $badge['category']);
            }
        }
        // Return unique categories.
        return array_values(array_unique($categories));
    }

    /**
     * Delete a badge. Use with caution.
     */
    public function delete_badge($badgeid) {
        $url = $this->obf_url() . '/v2/badge/' . $this->client_id() . '/' . $badgeid;
        $this->request('delete', $url);
    }

    /**
     * Deletes all client badges. Use with caution.
     */
    public function delete_badges() {

        // Get all badges.
        $badges = $this->get_badges();

        // Loop through badges and delete them.
        foreach ($badges as $badge) {
            if (isset($badge['id']) && !empty($badge['id'])) {
                try {
                    $this->delete_badge($badge['id']);
                    usleep(100000); // Sleep 0.1s to avoid hitting rate limits.
                } catch (Exception $e) {
                    error_log("Failed to delete badge with ID {$badge['id']}: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * Exports a badge to Open Badge Factory
     *
     * @param obf_badge $badge The badge.
     */
    public function export_badge(obf_badge $badge) {
        $img = $badge->get_image();
        // These methods provide no data atm.
        $criteriaMd = $badge->get_criteria_html();
        $categories = $badge->get_categories();
        $tags = $badge->get_tags();

        // If criteria is empty, but badge has criteria URL, use that.
        if ($criteriaMd === '' && $badge->has_criteria_url()) {
            $criteriaMd = 'Criteria: ' . $badge->get_criteria_url(); 
        }

        // If image header is not valid, add a default PNG header and remove whitespace.
        if (!preg_match('#^data:image/(png|svg\+xml);base64,#', $img)) {
            $img = 'data:image/png;base64,' . preg_replace('/\s+/', '', $img);
        }

        $params = array(
            'category' => $categories,
            'image' => $img,
            'primary_language' => $badge->get_primary_language(),
            'content' => array(array(
                'language' => $badge->get_primary_language(),
                'name' => $badge->get_name(),
                'description' => $badge->get_description(),
                'criteria' => $criteriaMd,
                'tag' => $tags,
            )),
            'email_message' => array(
                'subject' => $badge->get_email()->get_subject(),
                'body' => $badge->get_email()->get_body(),
                'link_text' => $badge->get_email()->get_link_text(),
                'footer' => $badge->get_email()->get_footer(),
            ),
            'expires' => 0,
            'draft' => $badge->is_draft()
        );

        $url = $this->obf_url() . '/v2/badge/' . $this->client_id();

        try {
            $this->request('post', $url, $params);
        } catch (Exception $e) {
            error_log('Export failed: HTTP=' . $e->getCode() . ' msg=' . $e->getMessage());
        }
    }

    /**
     * Issues a badge.
     *
     * @param obf_badge $badge The badge to be issued.
     * @param string[] $recipients The recipient list, array of emails.
     * @param int $issuedon The issuance date as a Unix timestamp
     * @param string $email The email to send (template).
     * @param string $criteriaaddendum The criteria addendum.
     * @param string $course The course name.
     * @param string $activity The activity name.
     * @param string|null $client_alias_id
     */

    public function issue_badge(obf_badge $badge, $recipients, $issuedon, $email, $criteriaaddendum, $course, $activity, $client_alias_id = null) {
        global $CFG, $DB;

        // Before doing anything we test connection.
        // If test_connection failed we throw an Error.
        $httpcode = $this->test_connection();
        if ($httpcode !== -1) {
            throw new Exception(get_string('connectionerror', 'local_obf'), $httpcode);
        }

        $recipientsnameemail = [];

        $userfields = 'id, email, ' . implode(', ',
            ['firstnamephonetic', 'lastnamephonetic', 'middlename', 'alternatename', 'firstname', 'lastname']
        );

        $users = $DB->get_records_list('user', 'email', $recipients, '', $userfields);
        $now = time();
        $sql = "INSERT INTO {local_obf_history_emails} (user_id, email, timestamp) VALUES (?,?,?)";

        $courseid = $badge->get_course_id(); // ID cours.

        // Declare message content for managers notifications.
        $managerfullmessage = '';
        $managerfullmessagehtml = '';

        foreach ($users as $user) {

            try {
                $DB->execute($sql, array($user->id, $user->email, $now));
            } catch (dml_write_exception $e) {
                // Ignore duplicate entry errors.
                if (!$DB->record_exists('local_obf_history_emails', array('user_id' => $user->id, 'email' => $user->email))) {
                    throw $e;
                }
            }
            // Add username, if available.
            if ($user->firstname && $user->lastname && $user->email && !preg_match('/[><]/', $user->email)) {
                $recipientsnameemail[] = [
                    'email' => $user->email,
                    'name' => fullname($user),
                ];
            } else {
                $recipientsnameemail[] = [
                    'email' => $user->email,
                    'name' => ''
                ];
            }

            // Sending notification.
            // Get the user ID who is receiving the badge.
            $userid = $user->id;

            // Compose the message.
            $message = new message();
            $message->component = 'local_obf'; // The component triggering the message.
            $message->name = 'issued'; // The name of your custom message.
            // The user sending the message (can be an admin or system).
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = \core_user::get_user($userid); // The user receiving the message.
            $badgename = $badge->get_name();
            $message->subject = get_string('congratsbadgeearned', 'local_obf', $badgename);
            $courseurl = new moodle_url('/course/view.php', array('id' => $courseid));
            if (empty($courseid)) {
                $courseid = 1;
            }
            $coursename = get_course($courseid)->fullname;

            $courselink = html_writer::link($courseurl, $coursename);
            $badgelink = $badge->get_name();

            // We need both.
            $message->fullmessage = get_string(
                'newbadgeearned',
                'local_obf',
                array('courselink' => $courselink, 'badgelink' => $badgelink)
            );
            $message->fullmessagehtml = get_string(
                'newbadgeearned',
                'local_obf',
                array('courselink' => $courselink, 'badgelink' => $badgelink)
            );
            $message->fullmessageformat = FORMAT_MARKDOWN;

            // Send the message.
            $usermessages[] = $message;

            $courseurl = new moodle_url('/course/view.php', array('id' => $courseid));
            $courselink = html_writer::link($courseurl, $coursename);

            // Prepare message for managers.
            $managerfullmessage = $managerfullmessage . '<br>'
                . get_string('badgeissuedbody', 'local_obf', [
                    'badgename' => $badgename,
                    'firstname' => $user->firstname,
                    'lastname' => $user->lastname,
                    'courselink' => $courselink,
                ]) . '<br>';
            $managerfullmessagehtml = $managerfullmessagehtml . '<br>'
                . get_string('badgeissuedbody', 'local_obf', [
                    'badgename' => $badgename,
                    'firstname' => $user->firstname,
                    'lastname' => $user->lastname,
                    'courselink' => $courselink,
                ]) . '<br>';

        }

        // Send notification to teachers.
        $capability = 'local/obf:viewspecialnotif'; // Capability name.
        if (get_course($courseid)->category) {
            $contextrole = context_coursecat::instance(get_course($courseid)->category);
        } else {
            $contextrole = context_system::instance();
        }

        // Get the roles matching the capability.
        $roles = get_roles_with_cap_in_context($contextrole, $capability);

        // Get the users with the matching roles in the course.
        $roleids = array_keys($roles[0]);
        $managerusers = array();
        foreach ($roleids as $roleid) {
            $roleusers = get_role_users($roleid, context_course::instance($courseid), false, 'u.*');
            // Add role users to $managerusers only if they don't already exist.
            foreach ($roleusers as $roleuser) {
                $userexists = false;

                foreach ($managerusers as $manageruser) {
                    if ($manageruser->id == $roleuser->id) {
                        $userexists = true;
                        break;
                    }
                }

                if (!$userexists) {
                    $managerusers[] = $roleuser;
                }
            }
        }

        // If no users found, send the notification to platform admins.
        if (empty($managerusers) && $courseid == 1) {
            $managerusers = get_admins();
        }

        // Loop Users list.
        foreach ($managerusers as $manageruser) {
            // Access Users data.
            // Compose the message.
            $messagemanagerbadgeissue = new message();

            $userid = $manageruser->id;

            $messagemanagerbadgeissue->component = 'local_obf'; // The component triggering the message.
            $messagemanagerbadgeissue->name = 'issuedbadgetostudent'; // The name of your custom message.
            // The user sending the message (can be an admin or system).
            $messagemanagerbadgeissue->userfrom = \core_user::get_noreply_user();
            $messagemanagerbadgeissue->userto = \core_user::get_user($userid); // The user receiving the message.
            if (empty($courseid)) {
                $courseid = 1;
            }

            $messagemanagerbadgeissue->subject = get_string('badgeissuedsubject', 'local_obf', [
                'badgename' => $badgename
            ]);

            $messagemanagerbadgeissue->fullmessage = $managerfullmessage;
            $messagemanagerbadgeissue->fullmessagehtml = $managerfullmessagehtml;

            $messagemanagerbadgeissue->fullmessageformat = FORMAT_MARKDOWN;

            // Send the message.
            $managermessages[] = $messagemanagerbadgeissue;
        }

        $coursename = $badge->get_course_name($course);

        // Always add wwwroot to log entry.
        $logentry = ['wwwroot' => $CFG->wwwroot];

        // Add course and activity info to log entry if provided.
        if (!empty($course)) {
            $logentry['course_id'] = (string)$course;
            $logentry['course_name'] = $coursename;
            if (!empty($activity)) {
                $logentry['activity_name'] = $activity;
            }
        }

        $params = [
            'recipient' => $recipientsnameemail,
            'issued_on' => $issuedon,
            'api_consumer_id' => OBF_API_CONSUMER_ID,
            'send_email' => true,
            'show_report' => true,
            'log_entry' => $logentry
        ];

        // Add client alias id to the params if provided
        if (!empty($client_alias_id)) {
            $params['client_alias_id'] = $client_alias_id;
        }

        if (!is_null($email)) {
            $params['email_message'] = [
                'subject' => $email->get_subject(),
                'body' => $email->get_body(),
                'link_text' => $email->get_link_text(),
                'footer' => $email->get_footer()
            ];
        }

        if (!empty($criteriaaddendum)) {
            $params['criteria_add'] = $criteriaaddendum;
        }

        if (!is_null($badge->get_expires()) && $badge->get_expires() > 0) {
            $params['expires_on'] = $badge->get_expires();
        }

        $url = $this->obf_url() . '/v2/event/' . $this->client_id() . '/' . $badge->get_id() . '/issue';
        $this->request('post', $url, $params);

        // Sending notifications messages only if request is done with no error.
        // At this point we assume that error are handle in the request method.
        if (isset($usermessages)) {
            foreach ($usermessages as $message) {
                message_send($message);
            }
        }

        if (isset($managermessages)) {
            foreach ($managermessages as $message) {
                message_send($message);
            }
        }
    }

    /**
     * Revoke an issued event.
     *
     * @param string $eventid
     * @param string[] $emails Array of emails to revoke the event for.
     */
    public function revoke_event($eventid, $emails) {
        $url = $this->obf_url() . '/v2/event/' . $this->client_id() . '/' . $eventid . '/revoke';
        $query = ['recipient' => $emails];
        $this->request('put', $url, $query);
    }

    /**
     * Get badge details for an issued badge.
     *
     * @param string $badgeid
     * @param string $eventid
     * @return array|null V1 compatible BadgeClass
     */
    public function pub_get_badge($badgeid, $eventid) {
        if ($this->eventlookup && isset($this->eventlookup[$eventid]['host'])) {
            $host = $this->eventlookup[$eventid]['host'];
        }
        else {
            $host = $this->obf_url();
        }

        $url = $host . '/v1/badge/_/' . $badgeid . '.json';
        $params = array('v' => '1.1', 'event' => $eventid);
        try {
            $res = $this->request('get', $url, $params);
            return json_decode($res, true);
        } catch (Exception $e) {
            debugging('request failed: GET ' . $url);
        }
    }


    /**
     * Get absolute path of certificate directory.
     *
     * @return object
     */
    public function get_oauth2id() {
        return $this->oauth2;
    }

    /**
     * Get the total number of assertions issued.
     */
    public function get_total_assertions()
    {
        return $this->total_assertions ?? 0;
    }
}
