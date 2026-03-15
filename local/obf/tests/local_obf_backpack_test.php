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
 * Backpack testcase.
 *
 * @package    local_obf
 * @copyright  2013-2020, Open Badge Factory Oy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../classes/backpack.php');

/**
 * Backpack testcase.
 *
 * @group obf
 * @copyright  2013-2020, Open Badge Factory Oy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_obf_backpack_test extends advanced_testcase {
    /**
     * Test backpack.
     */
    public function test_backpack() {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $backpack = \classes\obf_backpack::get_instance($user);
        $this->assertFalse($backpack);

        $userids = \classes\obf_backpack::get_user_ids_with_backpack();
        $this->assertCount(0, $userids);
    }

    /**
     * Test valid backpack connection.
     */
    public function test_valid_connection() {
        $this->resetAfterTest();

        $email = 'existing@example.com';
        $stub = $this->getMockBuilder('classes\\obf_backpack')
            ->onlyMethods(['connect_to_backpack'])
            ->setConstructorArgs([new curl()])
            ->getMock();

        $stub->expects($this->once())
            ->method('connect_to_backpack')
            ->with($this->equalTo($email))
            ->willReturn(69);

        $stub->connect($email);
        $this->assertTrue($stub->is_connected());

        $userids = 'classes\\obf_backpack'::get_user_ids_with_backpack();
        $this->assertCount(1, $userids);

        $stub->disconnect();
        $this->assertFalse(\classes\obf_backpack::get_instance_by_backpack_email($email));
    }

    /**
     * Test invalid backpack connection.
     */
    public function test_invalid_connection() {
        $this->resetAfterTest();
        $stub = $this->getMockBuilder('classes\\obf_backpack')
            ->onlyMethods(['connect_to_backpack'])
            ->getMock();
        $stub->expects($this->any())->method(
            $this->equalTo('connect_to_backpack'))->will($this->returnValue(false));

        try {
            $email = 'doesnotexist@example.com';
            $stub->connect($email);
            $this->fail('There shouldn\'t exist an account with email "' . $email . '"');
        } catch (Exception $e) {
            // We should end up here.
            0 + 0; // Suppressing PHP_CodeSniffer error messages.
        }

        $this->assertFalse($stub->is_connected());
    }

    /**
     * Test email verification on backpack.
     */
    public function test_verification() {
        $assertion = 'valid_assertion';
        $invalidassertion = 'invalid_assertion';
        $email = 'existing@example.com';

        $mock = $this->getMockBuilder(curl::class)
                    ->onlyMethods(['post'])
                    ->getMock();
        $mock->expects($this->any())->method('post')->will($this->returnCallback(
            function($url, $params) use ($email, $assertion) {
                $obj = json_decode($params);

                if ($obj->assertion == $assertion) {
                    return json_encode(array('email' => $email, 'status' => 'okay'));
                }

                return json_encode(array('status' => 'failure', 'reason' => 'You failed.'));
            }));

        $backpack = new \classes\obf_backpack($mock);
        $this->assertEquals($email, $backpack->verify($assertion));

        try {
            $backpack->verify($invalidassertion);
            $this->fail('Verification should have failed.');
        } catch (Exception $e) {
            // We should end up here.
            0 + 0; // Suppressing PHP_CodeSniffer error messages.
        }
    }

}
