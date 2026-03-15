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
 * Description of obf_mock_curl
 *
 * @package    local_obf
 * @copyright  2013-2025, Open Badge Factory Oy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 *
 * @author jsuorsa
 */
class obf_mock_curl {
    public $info;
    public $rawresponse;
    public $error;
    public $oauth2;
    public static $emptypngdata = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAQAAAAEACAIAAADTED8xAAAACXBIWXMAAAsTAAALEwEAmpwYAAAAB3RJTUUH4QYCCiE56ohHkQAAABl0RVh0Q29tbWVudABDcmVhdGVkIHdpdGggR0lNUFeBDhcAAAH7SURBVHja7dNBDQAACMQwwL/n440GWglL1kkKvhoJMAAYAAwABgADgAHAAGAAMAAYAAwABgADgAHAAGAAMAAYAAwABgADgAHAAGAAMAAYAAwABgADgAHAAGAAMAAYAAwABgADgAHAAGAAMAAYAAwABgADgAHAAGAAMAAYAAyAAcAAYAAwABgADAAGAAOAAcAAYAAwABgADAAGAAOAAcAAYAAwABgADAAGAAOAAcAAYAAwABgADAAGAAOAAcAAYAAwABgADAAGAAOAAcAAYAAwABgADAAGAAOAAcAAYAAwAAYAA4ABwABgADAAGAAMAAYAA4ABwABgADAAGAAMAAYAA4ABwABgADAAGAAMAAYAA4ABwABgADAAGAAMAAYAA4ABwABgADAAGAAMAAYAA4ABwABgADAAGAAMAAYAA4ABMAAYAAwABgADgAHAAGAAMAAYAAwABgADgAHAAGAAMAAYAAwABgADgAHAAGAAMAAYAAwABgADgAHAAGAAMAAYAAwABgADgAHAAGAAMAAYAAwABgADgAHAAGAAMAAYAAwABsAAYAAwABgADAAGAAOAAcAAYAAwABgADAAGAAOAAcAAYAAwABgADAAGAAOAAcAAYAAwABgADAAGAAOAAcAAYAAwABgADAAGAAOAAcAAYAAwABgADAAGAAOAAeBaq+gE/QpErIgAAAAASUVORK5CYII=';

    private $testscenario = [];

    /**
     * New methods for future integration tests and fixtures.
     */
    public function set_test_scenario(array $scenario) {
        $this->testscenario = $scenario;
    }

    private function get_response($method, $url, $data = []) {
        foreach ($this->testscenario as $rule) {
            if (preg_match($rule['url'], $url) && $rule['method'] === mb_strtolower($method)) {
                $this->info['http_code'] = $rule['http_code'];
                $this->rawresponse = $rule['response'] ?? null;
                $this->error = $rule['error'] ?? null;
                return $rule['response'];
            }
        }
        $this->info['http_code'] = 200;
        $this->error = 'Mocked error: No matching rule found for URL ' . $url;
        return false;
    }

    public function get($url, $params = [], $options = []) {
        return $this->get_response('get', $url, $params);
    }

    public function post($url, $params = '', $options = []) {
        return $this->get_response('post', $url, $params);
    }

    public function put($url, $params = '', $options = []) {
        return $this->get_response('put', $url, $params);
    }

    public function delete($url, $params = [], $options = []) {
        return $this->get_response('delete', $url, $params);
    }
    public function get_info() {
        return $this->info ?? ['http_code' => 200];
    }

    /**
     * Create mock token for oauth2.
     */
    public static function mock_oauth2_db() {
        global $DB;

        $row = array_merge([
            'obf_url'       => 'https://example.test',
            'client_id'     => 'PHPUNIT',
            'client_secret' => 'SECRET',
            'access_token'  => 'MOCK_ACCESS_TOKEN',
            'refresh_token' => 'MOCK_REFRESH_TOKEN',
            'token_expires' => time() + 3600,
        ]);

        // Make sure there is only one row.
        $DB->delete_records('local_obf_oauth2', []);
        $DB->insert_record('local_obf_oauth2', $row);
    }

    /** 
     * Old methods refactored PHPUnit11 compatable
     **/
    public static function add_client_test_methods($self, &$curl) {
        $curl->info = ['http_code' => 200];
        // Mock HTTP POST.
        $curl->method('post')
            ->willReturnCallback(function($url, $params = null, $options = null) {
                if (str_ends_with($url, '/test/')) {
                    return json_encode(['post' => 'works!']);
                }
                return false;
            });

        // Mock HTTP GET.
        $curl->method('get')
            ->willReturnCallback(function($path, $arg1 = null, $arg2 = null) {
                if ($path === '/test/' || str_ends_with($path, '/test/')) {
                    return json_encode(['get' => 'works!']);
                }
                if ($path === '/doesnotexist/' || str_ends_with($path, '/doesnotexist/')) {
                    return false;
                }
                return false;
            });

        // Mock HTTP DELETE.
        $curl->method('delete')
            ->willReturnCallback(function($url, $params = null, $options = null) {
                if ($url === '/test/' || str_ends_with($url, '/test/')) {
                    return json_encode(['delete' => 'works!']);
                }
                return false;
            });
    }

    public static function add_get_badge($self, &$curl, $clientid, $badge) {
        $pathsuffix = '/badge/' . $clientid . '/' . $badge->get_id();

        $curl->method('get')
            ->willReturnCallback(function($url, $params = null, $options = null) use ($pathsuffix, $badge) {
                if (str_ends_with($url, $pathsuffix)) {
                    return json_encode([
                        'id' => $badge->get_id(),
                        'badge_id' => $badge->get_id(),
                        'description' => $badge->get_description(),
                        'image' => $badge->get_image(),
                        'name' => $badge->get_name(),
                    ]);
                }
                return false;
            });
    }

    public static function add_issue_badge($self, &$curl, $clientid) {
        // Mock HTTP POST.
        $curl->info = ['http_code' => 200];
        $curl->rawresponse = [
            'Location: https://localhost.localdomain/v1/event/' . $clientid . '/PHPUNITEVENTID'
        ];
        $curl->method('post')
            ->willReturn(json_encode(['post' => 'works!']));
    }
}
