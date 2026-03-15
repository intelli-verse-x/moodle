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
 * Client tests.
 *
 * @package    local_obf
 * @copyright  2013-2020, Open Badge Factory Oy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../classes/client.php');

/**
 * OBF Client testcase.
 *
 * @copyright  2013-2020, Open Badge Factory Oy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_obf_client_test extends advanced_testcase {
    /**
     * Test API request.
     */
    public function testrequest() {
        $this->resetAfterTest();

        require_once(__DIR__ . '/lib/obf_mock_curl.php');
        $curl = $this->getMockBuilder(curl::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['post', 'get', 'delete'])
            ->getMock();
        obf_mock_curl::add_client_test_methods($this, $curl);
        obf_mock_curl::mock_oauth2_db();

        $client = \classes\obf_client::get_instance($curl);

        // Test HTTP POST.
        $response = json_decode($client->request('post', '/test/'), true);
        $this->assertArrayHasKey('post', $response);

        // Test HTTP GET.
        $response = json_decode($client->request('get', '/test/'), true);
        $this->assertArrayHasKey('get', $response);

        // Test HTTP DELETE.
        $response = json_decode($client->request('delete', '/test/'), true);
        $this->assertArrayHasKey('delete', $response);

        // Test invalid url.
        $curl->info = array('http_code' => 404);

        try {
            $client->request('/doesnotexist/');
            $this->fail('An expected exception has not been raised.');
        } catch (Exception $e) {
            // We should end up here.
            0 + 0; // Suppressing PHP_CodeSniffer error messages.
        }
    }

    /**
     * Test missing clinet id.
     */
    public function test_missing_client_id() {
        $this->resetAfterTest();

        set_config('obfclientid', 'test', 'local_obf');

        $client = \classes\obf_client::get_instance();

        try {
            $client->require_client_id();
        } catch (Exception $ex) {
            $this->fail('Client id required but not found.');
        }

        unset_config('obfclientid', 'local_obf');

        try {
            $client->require_client_id();
            $this->fail('Missing client id should throw an exception.');
        } catch (Exception $ex) {
            // We should end up here.
            0 + 0; // Suppressing PHP_CodeSniffer error messages.
        }
    }
}
