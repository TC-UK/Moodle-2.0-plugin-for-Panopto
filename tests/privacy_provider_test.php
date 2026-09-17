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

namespace block_panopto\privacy;

use core_privacy\local\metadata\collection;

/**
 * Tests for the Panopto Privacy API provider.
 *
 * @package block_panopto
 * @copyright 2026 Panopto
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \block_panopto\privacy\provider
 */
final class privacy_provider_test extends \advanced_testcase {
    /**
     * The external location declares every user field sent to Panopto.
     */
    public function test_metadata_declares_username_and_contact_fields(): void {
        $metadata = provider::get_metadata(new collection('block_panopto'))->get_collection();

        self::assertCount(1, $metadata);
        self::assertSame('block_panopto', $metadata[0]->get_name());
        self::assertSame([
            'username' => 'privacy:metadata:block_panopto:username',
            'firstname' => 'privacy:metadata:block_panopto:firstname',
            'lastname' => 'privacy:metadata:block_panopto:lastname',
            'email' => 'privacy:metadata:block_panopto:email',
        ], $metadata[0]->get_privacy_fields());
    }

    /**
     * A provisioned hidden course is included even after the participant is suspended.
     */
    public function test_hidden_suspended_course_is_reported_as_a_user_context(): void {
        global $DB;

        $this->resetAfterTest();
        set_config('server_number', 0, 'block_panopto');
        set_config('server_name1', 'example.panopto.invalid', 'block_panopto');
        set_config('application_key1', 'test-key', 'block_panopto');

        $course = $this->getDataGenerator()->create_course(['visible' => 0]);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $DB->set_field('user_enrolments', 'status', ENROL_USER_SUSPENDED, ['userid' => $user->id]);
        $DB->insert_record('block_panopto_foldermap', [
            'moodleid' => $course->id,
            'panopto_id' => '00000000-0000-0000-0000-000000000001',
            'panopto_server' => 'example.panopto.invalid',
            'panopto_app_key' => 'test-key',
        ]);

        $contextlist = provider::get_contexts_for_userid($user->id);

        self::assertContains(\context_course::instance($course->id)->id, $contextlist->get_contextids());
    }
}
