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

namespace block_panopto\local;

/**
 * Tests for the course visibility synchronisation policy.
 *
 * @package block_panopto
 * @copyright 2026 Panopto
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversDefaultClass \block_panopto\local\course_visibility_policy
 */
final class course_visibility_policy_test extends \advanced_testcase {
    /**
     * Verify participant and mapped-role policy combinations.
     *
     * @covers ::should_sync_course
     * @covers ::should_sync_hidden_course
     * @covers ::all_hidden_participants_enabled
     * @covers ::get_hidden_access_signature
     */
    public function test_hidden_course_policy_uses_effective_course_mappings(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['visible' => 0]);
        $creatorroleid = create_role('Panopto creator', 'panoptocreator', '');
        $publisherroleid = create_role('Panopto publisher', 'panoptopublisher', '');
        $viewerroleid = create_role('Panopto viewer', 'panoptoviewer', '');
        $creator = $this->getDataGenerator()->create_user();
        $publisher = $this->getDataGenerator()->create_user();
        $viewer = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($creator->id, $course->id, $creatorroleid);
        $this->getDataGenerator()->enrol_user($publisher->id, $course->id, $publisherroleid);
        $this->getDataGenerator()->enrol_user($viewer->id, $course->id, $viewerroleid);
        $DB->insert_record('block_panopto_creatormap', [
            'moodle_id' => $course->id,
            'role_id' => $creatorroleid,
        ]);
        $DB->insert_record('block_panopto_publishermap', [
            'moodle_id' => $course->id,
            'role_id' => $publisherroleid,
        ]);
        role_assign($creatorroleid, $viewer->id, \context_system::instance()->id);

        self::assertFalse(course_visibility_policy::should_sync_hidden_course($course->id, $creator->id));
        self::assertFalse(course_visibility_policy::should_sync_hidden_course($course->id, $publisher->id));
        self::assertFalse(course_visibility_policy::should_sync_hidden_course($course->id, $viewer->id));

        set_config('sync_hidden_courses', 1, 'block_panopto');
        set_config('sync_hidden_creators', 1, 'block_panopto');
        self::assertSame([
            'mode' => 'roles',
            'creatorselection' => [$creatorroleid],
            'publisherselection' => [],
            'creatorroleids' => [$creatorroleid],
            'publisherroleids' => [$publisherroleid],
        ], course_visibility_policy::get_hidden_access_signature($course->id));
        self::assertTrue(course_visibility_policy::should_sync_hidden_course($course->id, $creator->id));
        self::assertFalse(course_visibility_policy::should_sync_hidden_course($course->id, $publisher->id));
        self::assertFalse(course_visibility_policy::should_sync_hidden_course($course->id, $viewer->id));

        set_config('sync_hidden_creators', 0, 'block_panopto');
        set_config('sync_hidden_publishers', 1, 'block_panopto');
        self::assertSame([
            'mode' => 'roles',
            'creatorselection' => [],
            'publisherselection' => [$publisherroleid],
            'creatorroleids' => [$creatorroleid],
            'publisherroleids' => [$publisherroleid],
        ], course_visibility_policy::get_hidden_access_signature($course->id));
        self::assertFalse(course_visibility_policy::should_sync_hidden_course($course->id, $creator->id));
        self::assertTrue(course_visibility_policy::should_sync_hidden_course($course->id, $publisher->id));
        self::assertFalse(course_visibility_policy::should_sync_hidden_course($course->id, $viewer->id));

        set_config('sync_hidden_all_participants', 1, 'block_panopto');
        self::assertSame([
            'mode' => 'all',
            'creatorroleids' => [$creatorroleid],
            'publisherroleids' => [$publisherroleid],
        ], course_visibility_policy::get_hidden_access_signature($course->id));
        self::assertTrue(course_visibility_policy::all_hidden_participants_enabled($course->id));
        self::assertTrue(course_visibility_policy::should_sync_hidden_course($course->id, $creator->id));
        self::assertTrue(course_visibility_policy::should_sync_hidden_course($course->id, $publisher->id));
        self::assertTrue(course_visibility_policy::should_sync_hidden_course($course->id, $viewer->id));

        $course->visible = 1;
        set_config('sync_hidden_courses', 0, 'block_panopto');
        self::assertTrue(course_visibility_policy::should_sync_course($course, $viewer->id));
    }

    /**
     * Verify role-change matching uses the enabled role mappings.
     *
     * @covers ::should_sync_hidden_role
     */
    public function test_hidden_role_change_policy(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['visible' => 0]);
        $creatorroleid = create_role('Panopto creator', 'panoptocreator', '');
        $publisherroleid = create_role('Panopto publisher', 'panoptopublisher', '');
        $DB->insert_record('block_panopto_creatormap', [
            'moodle_id' => $course->id,
            'role_id' => $creatorroleid,
        ]);
        $DB->insert_record('block_panopto_publishermap', [
            'moodle_id' => $course->id,
            'role_id' => $publisherroleid,
        ]);

        set_config('sync_hidden_courses', 1, 'block_panopto');
        set_config('sync_hidden_creators', 1, 'block_panopto');
        self::assertTrue(course_visibility_policy::should_sync_hidden_role($course->id, $creatorroleid));
        self::assertFalse(course_visibility_policy::should_sync_hidden_role($course->id, $publisherroleid));

        set_config('sync_hidden_all_participants', 1, 'block_panopto');
        self::assertTrue(course_visibility_policy::should_sync_hidden_role($course->id, $publisherroleid));
    }

    /**
     * Verify re-hide removal cannot contradict all-participant hidden sync.
     *
     * @covers ::should_process_visibility_change
     */
    public function test_visibility_change_policy_resolves_conflicting_settings(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();

        self::assertFalse(course_visibility_policy::should_process_visibility_change($course->id, true));
        self::assertFalse(course_visibility_policy::should_process_visibility_change($course->id, false));

        set_config('sync_visible_course_participants', 1, 'block_panopto');
        self::assertTrue(course_visibility_policy::should_process_visibility_change($course->id, true));
        self::assertFalse(course_visibility_policy::should_process_visibility_change($course->id, false));

        set_config('remove_access_on_course_hidden', 1, 'block_panopto');
        self::assertTrue(course_visibility_policy::should_process_visibility_change($course->id, false));

        set_config('sync_visible_course_participants', 0, 'block_panopto');
        self::assertFalse(course_visibility_policy::should_process_visibility_change($course->id, true));
        self::assertTrue(course_visibility_policy::should_process_visibility_change($course->id, false));

        set_config('sync_hidden_courses', 1, 'block_panopto');
        set_config('sync_hidden_all_participants', 1, 'block_panopto');
        self::assertFalse(course_visibility_policy::should_process_visibility_change($course->id, true));
        self::assertFalse(course_visibility_policy::should_process_visibility_change($course->id, false));
    }
}
