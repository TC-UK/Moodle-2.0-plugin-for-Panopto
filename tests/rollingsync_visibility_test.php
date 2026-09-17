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

namespace block_panopto;

use block_panopto\task\sync_course_users;
use block_panopto\task\sync_user;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../classes/rollingsync.php');

/**
 * Tests for course visibility event handling.
 *
 * @package block_panopto
 * @copyright 2026 Panopto
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \block_panopto_rollingsync::courseupdated
 */
final class rollingsync_visibility_test extends \advanced_testcase {
    /**
     * A visible transition queues a background participant sync.
     */
    public function test_course_made_visible_queues_sync(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_plugin();
        set_config('sync_visible_course_participants', 1, 'block_panopto');
        $course = $this->getDataGenerator()->create_course(['visible' => 0]);
        $DB->set_field('course', 'visible', 1, ['id' => $course->id]);

        \block_panopto_rollingsync::courseupdated($this->visibility_event($course, 1));

        $tasks = \core\task\manager::get_adhoc_tasks(sync_course_users::class);
        self::assertCount(1, $tasks);
        $data = $tasks[0]->get_custom_data();
        self::assertSame($course->id, (int) $data->courseid);
        self::assertTrue((bool) $data->targetvisible);
        self::assertSame(sync_course_users::REASON_COURSE_SHOWN, $data->reason);
        self::assertSame(0, (int) $data->afteruserid);
    }

    /**
     * A hidden transition queues selective access recalculation.
     */
    public function test_course_hidden_queues_selective_access_sync(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_plugin();
        set_config('sync_visible_course_participants', 1, 'block_panopto');
        set_config('remove_access_on_course_hidden', 1, 'block_panopto');
        set_config('sync_hidden_courses', 1, 'block_panopto');
        set_config('sync_hidden_creators', 1, 'block_panopto');
        $course = $this->getDataGenerator()->create_course(['visible' => 1]);
        $DB->set_field('course', 'visible', 0, ['id' => $course->id]);

        \block_panopto_rollingsync::courseupdated($this->visibility_event($course, 0));

        $tasks = \core\task\manager::get_adhoc_tasks(sync_course_users::class);
        self::assertCount(1, $tasks);
        self::assertFalse((bool) $tasks[0]->get_custom_data()->targetvisible);
    }

    /**
     * Re-hiding does not queue removal when every hidden participant retains access.
     */
    public function test_course_hidden_does_not_queue_conflicting_removal(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_plugin();
        set_config('sync_visible_course_participants', 1, 'block_panopto');
        set_config('remove_access_on_course_hidden', 1, 'block_panopto');
        set_config('sync_hidden_courses', 1, 'block_panopto');
        set_config('sync_hidden_all_participants', 1, 'block_panopto');
        $course = $this->getDataGenerator()->create_course(['visible' => 1]);
        $DB->set_field('course', 'visible', 0, ['id' => $course->id]);

        \block_panopto_rollingsync::courseupdated($this->visibility_event($course, 0));

        self::assertEmpty(\core\task\manager::get_adhoc_tasks(sync_course_users::class));
    }

    /**
     * Showing a course does not queue redundant work when hidden participants are already synchronised.
     */
    public function test_course_made_visible_does_not_queue_when_hidden_all_sync_is_enabled(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_plugin();
        set_config('sync_visible_course_participants', 1, 'block_panopto');
        set_config('sync_hidden_courses', 1, 'block_panopto');
        set_config('sync_hidden_all_participants', 1, 'block_panopto');
        $course = $this->getDataGenerator()->create_course(['visible' => 0]);
        $DB->set_field('course', 'visible', 1, ['id' => $course->id]);

        \block_panopto_rollingsync::courseupdated($this->visibility_event($course, 1));

        self::assertEmpty(\core\task\manager::get_adhoc_tasks(sync_course_users::class));
    }

    /**
     * A new enrolment in a hidden course queues a sync for the all-participant policy.
     */
    public function test_hidden_course_enrolment_queues_sync_for_all_participants(): void {
        $this->resetAfterTest();
        $this->configure_plugin();
        set_config('async_tasks', 1, 'block_panopto');
        set_config('sync_hidden_courses', 1, 'block_panopto');
        set_config('sync_hidden_all_participants', 1, 'block_panopto');
        $course = $this->getDataGenerator()->create_course(['visible' => 0]);
        $user = $this->getDataGenerator()->create_user();
        $event = \core\event\user_enrolment_created::create([
            'objectid' => 1,
            'context' => \context_course::instance($course->id),
            'relateduserid' => $user->id,
            'other' => ['enrol' => 'manual'],
        ]);

        \block_panopto_rollingsync::userenrolmentcreated($event);

        $tasks = \core\task\manager::get_adhoc_tasks(sync_user::class);
        self::assertCount(1, $tasks);
        self::assertSame($course->id, (int) $tasks[0]->get_custom_data()->courseid);
        self::assertSame($user->id, (int) $tasks[0]->get_custom_data()->userid);
    }

    /**
     * A mapped role assignment in a hidden course queues a selective user sync.
     */
    public function test_hidden_course_mapped_role_assignment_queues_sync(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_plugin();
        set_config('async_tasks', 1, 'block_panopto');
        set_config('sync_hidden_courses', 1, 'block_panopto');
        set_config('sync_hidden_creators', 1, 'block_panopto');
        $course = $this->getDataGenerator()->create_course(['visible' => 0]);
        $user = $this->getDataGenerator()->create_user();
        $roleid = create_role('Panopto creator', 'panoptocreator', '');
        $DB->insert_record('block_panopto_creatormap', [
            'moodle_id' => $course->id,
            'role_id' => $roleid,
        ]);
        $event = \core\event\role_assigned::create([
            'objectid' => $roleid,
            'context' => \context_course::instance($course->id),
            'relateduserid' => $user->id,
            'other' => [
                'id' => 1,
                'component' => '',
                'itemid' => 0,
            ],
        ]);

        \block_panopto_rollingsync::roleassigned($event);

        $tasks = \core\task\manager::get_adhoc_tasks(sync_user::class);
        self::assertCount(1, $tasks);
        self::assertSame($course->id, (int) $tasks[0]->get_custom_data()->courseid);
        self::assertSame($user->id, (int) $tasks[0]->get_custom_data()->userid);
    }

    /**
     * Configure enough server data for event observers to run without external calls.
     */
    private function configure_plugin(): void {
        set_config('server_number', 0, 'block_panopto');
        set_config('server_name1', 'example.panopto.invalid', 'block_panopto');
        set_config('application_key1', 'test-key', 'block_panopto');
    }

    /**
     * Build a course-updated event without triggering the global event manager.
     *
     * @param \stdClass $course Moodle course record.
     * @param int $visible New visibility value.
     * @return \core\event\course_updated
     */
    private function visibility_event(\stdClass $course, int $visible): \core\event\course_updated {
        return \core\event\course_updated::create([
            'objectid' => $course->id,
            'context' => \context_course::instance($course->id),
            'other' => [
                'shortname' => $course->shortname,
                'fullname' => $course->fullname,
                'updatedfields' => ['visible' => $visible],
            ],
        ]);
    }
}
