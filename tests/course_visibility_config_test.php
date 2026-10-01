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

use block_panopto\task\sync_course_users;

/**
 * Tests for inheritable course visibility configuration.
 *
 * @package block_panopto
 * @copyright 2026 Panopto
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversDefaultClass \block_panopto\local\course_visibility_config
 */
final class course_visibility_config_test extends \advanced_testcase {
    /**
     * Clear the request-level resolver cache between tests.
     */
    protected function setUp(): void {
        parent::setUp();
        course_visibility_config::reset_cache();
    }

    /**
     * Course values override site defaults only while the site switch allows them.
     *
     * @covers ::course_overrides_allowed
     * @covers ::get_course_overrides
     * @covers ::get_effective_settings
     * @covers ::get_site_settings
     * @covers ::is_enabled
     */
    public function test_course_overrides_are_ignored_but_preserved_while_disabled(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $this->set_site_settings();
        set_config('allow_course_visibility_overrides', 1, 'block_panopto');

        $overrides = [
            'sync_hidden_courses' => course_visibility_config::INHERIT,
            'sync_hidden_all_participants' => course_visibility_config::ENABLED,
            'sync_hidden_creators' => course_visibility_config::DISABLED,
            'sync_hidden_publishers' => course_visibility_config::INHERIT,
            'sync_visible_course_participants' => course_visibility_config::DISABLED,
            'remove_access_on_course_hidden' => course_visibility_config::ENABLED,
        ];
        $this->create_panopto_block($course->id, $overrides);

        self::assertSame($overrides, course_visibility_config::get_course_overrides($course->id));
        self::assertSame([
            'sync_hidden_courses' => true,
            'sync_hidden_all_participants' => true,
            'sync_hidden_creators' => false,
            'sync_hidden_publishers' => false,
            'sync_visible_course_participants' => false,
            'remove_access_on_course_hidden' => true,
        ], course_visibility_config::get_effective_settings($course->id));

        set_config('allow_course_visibility_overrides', 0, 'block_panopto');
        course_visibility_config::reset_cache($course->id);
        self::assertSame([
            'sync_hidden_courses' => true,
            'sync_hidden_all_participants' => false,
            'sync_hidden_creators' => true,
            'sync_hidden_publishers' => false,
            'sync_visible_course_participants' => true,
            'remove_access_on_course_hidden' => false,
        ], course_visibility_config::get_effective_settings($course->id));
        self::assertSame($overrides, course_visibility_config::get_course_overrides($course->id));

        set_config('allow_course_visibility_overrides', 1, 'block_panopto');
        course_visibility_config::reset_cache($course->id);
        self::assertTrue(course_visibility_config::is_enabled($course->id, 'remove_access_on_course_hidden'));
        self::assertFalse(course_visibility_config::is_enabled($course->id, 'sync_visible_course_participants'));
    }

    /**
     * Course-specific hidden-all retention suppresses contradictory transition work.
     *
     * @covers ::get_effective_settings
     */
    public function test_course_overrides_drive_visibility_policy(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $this->set_site_settings();
        set_config('allow_course_visibility_overrides', 1, 'block_panopto');
        $this->create_panopto_block($course->id, [
            'sync_hidden_courses' => course_visibility_config::ENABLED,
            'sync_hidden_all_participants' => course_visibility_config::ENABLED,
            'sync_hidden_creators' => course_visibility_config::INHERIT,
            'sync_hidden_publishers' => course_visibility_config::INHERIT,
            'sync_visible_course_participants' => course_visibility_config::ENABLED,
            'remove_access_on_course_hidden' => course_visibility_config::ENABLED,
        ]);

        self::assertTrue(course_visibility_policy::all_hidden_participants_enabled($course->id));
        self::assertFalse(course_visibility_policy::should_process_visibility_change($course->id, true));
        self::assertFalse(course_visibility_policy::should_process_visibility_change($course->id, false));
    }

    /**
     * Saving other block configuration cannot erase or replace overrides while their controls are disabled.
     *
     * @covers \block_panopto::instance_config_save
     */
    public function test_disabled_course_configuration_is_preserved_during_block_save(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $overrides = array_fill_keys(
            course_visibility_config::get_setting_names(),
            course_visibility_config::INHERIT
        );
        $overrides['remove_access_on_course_hidden'] = course_visibility_config::ENABLED;
        $record = $this->create_panopto_block($course->id, $overrides);
        set_config('allow_course_visibility_overrides', 0, 'block_panopto');

        $page = new \moodle_page();
        $page->set_context(\context_course::instance($course->id));
        $page->set_course($course);
        $page->set_pagelayout('standard');
        $page->set_pagetype('course-view');
        $page->blocks->load_blocks();
        $blocks = $page->blocks->get_blocks_for_region($page->blocks->get_default_region());
        $block = reset($blocks);
        self::assertInstanceOf(\block_panopto::class, $block);

        $block->instance_config_save((object) [
            'course' => '',
            'creator' => [],
            'publisher' => [],
            'visibility_remove_access_on_course_hidden' => course_visibility_config::DISABLED,
        ]);

        $configdata = $DB->get_field('block_instances', 'configdata', ['id' => $record->id], MUST_EXIST);
        $config = unserialize_object(base64_decode($configdata));
        self::assertSame(
            course_visibility_config::ENABLED,
            $config->visibility_remove_access_on_course_hidden
        );
    }

    /**
     * Saving a changed hidden-access policy queues an immediate full participant reconciliation.
     *
     * Transition-only settings do not alter current hidden access and therefore do not queue unnecessary work.
     *
     * @covers \block_panopto::instance_config_save
     * @covers \block_panopto\task\sync_course_users::queue_course_sync
     */
    public function test_hidden_policy_change_queues_course_reconciliation(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['visible' => 0]);
        foreach (course_visibility_config::get_setting_names() as $settingname) {
            set_config($settingname, 0, 'block_panopto');
        }
        set_config('allow_course_visibility_overrides', 1, 'block_panopto');
        $overrides = array_fill_keys(
            course_visibility_config::get_setting_names(),
            course_visibility_config::INHERIT
        );
        $this->create_panopto_block($course->id, $overrides);
        $block = $this->get_panopto_block($course);

        $data = $this->get_block_form_data($overrides);
        $data->visibility_remove_access_on_course_hidden = course_visibility_config::ENABLED;
        $block->instance_config_save($data);
        self::assertEmpty(\core\task\manager::get_adhoc_tasks(sync_course_users::class));

        $data->visibility_sync_hidden_courses = course_visibility_config::ENABLED;
        $data->visibility_sync_hidden_all_participants = course_visibility_config::ENABLED;
        $block->instance_config_save($data);

        $tasks = \core\task\manager::get_adhoc_tasks(sync_course_users::class);
        self::assertCount(1, $tasks);
        $taskdata = $tasks[0]->get_custom_data();
        self::assertSame($course->id, (int) $taskdata->courseid);
        self::assertSame(sync_course_users::REASON_HIDDEN_POLICY, $taskdata->reason);
        self::assertSame(0, (int) $taskdata->afteruserid);
    }

    /**
     * Set a representative mix of site-wide defaults.
     */
    private function set_site_settings(): void {
        set_config('sync_hidden_courses', 1, 'block_panopto');
        set_config('sync_hidden_all_participants', 0, 'block_panopto');
        set_config('sync_hidden_creators', 1, 'block_panopto');
        set_config('sync_hidden_publishers', 0, 'block_panopto');
        set_config('sync_visible_course_participants', 1, 'block_panopto');
        set_config('remove_access_on_course_hidden', 0, 'block_panopto');
    }

    /**
     * Create the single Panopto block instance and persist tri-state settings in its standard config data.
     *
     * @param int $courseid Moodle course ID.
     * @param array<string, int> $overrides Visibility setting overrides.
     */
    private function create_panopto_block(int $courseid, array $overrides): \stdClass {
        $config = new \stdClass();
        foreach ($overrides as $settingname => $value) {
            $property = course_visibility_config::get_instance_property($settingname);
            $config->{$property} = $value;
        }

        $context = \context_course::instance($courseid);
        $record = $this->getDataGenerator()->create_block('panopto', [
            'parentcontextid' => $context->id,
            'configdata' => base64_encode(serialize($config)),
        ]);
        course_visibility_config::reset_cache($courseid);
        return $record;
    }

    /**
     * Load the Panopto block from a course page.
     *
     * @param \stdClass $course Moodle course record.
     * @return \block_panopto
     */
    private function get_panopto_block(\stdClass $course): \block_panopto {
        $page = new \moodle_page();
        $page->set_context(\context_course::instance($course->id));
        $page->set_course($course);
        $page->set_pagelayout('standard');
        $page->set_pagetype('course-view');
        $page->blocks->load_blocks();
        $blocks = $page->blocks->get_blocks_for_region($page->blocks->get_default_region());
        $block = reset($blocks);
        self::assertInstanceOf(\block_panopto::class, $block);
        return $block;
    }

    /**
     * Build submitted block data containing every course visibility override.
     *
     * @param array<string, int> $overrides Visibility setting overrides.
     * @return \stdClass
     */
    private function get_block_form_data(array $overrides): \stdClass {
        $data = (object) [
            'course' => '',
            'creator' => [],
            'publisher' => [],
        ];
        foreach ($overrides as $settingname => $value) {
            $property = course_visibility_config::get_instance_property($settingname);
            $data->{$property} = $value;
        }
        return $data;
    }
}
