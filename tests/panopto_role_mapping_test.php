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

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../lib/panopto_data.php');

/**
 * Tests for converting effective Moodle course roles to Panopto groups.
 *
 * @package block_panopto
 * @copyright 2026 Panopto
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \panopto_data::get_role_from_context
 */
final class panopto_role_mapping_test extends \advanced_testcase {
    /**
     * Course mappings, not inherited capabilities or site administration, determine elevated groups.
     */
    public function test_course_role_mappings_are_authoritative(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $viewerroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $creatorroleid = create_role('Panopto creator', 'panoptocreator', '');
        $publisherroleid = create_role('Panopto publisher', 'panoptopublisher', '');

        $viewer = $this->getDataGenerator()->create_user();
        $siteadminviewer = $this->getDataGenerator()->create_user();
        $creator = $this->getDataGenerator()->create_user();
        $publisher = $this->getDataGenerator()->create_user();
        $creatorpublisher = $this->getDataGenerator()->create_user();

        $this->getDataGenerator()->enrol_user($viewer->id, $course->id, $viewerroleid);
        $this->getDataGenerator()->enrol_user($siteadminviewer->id, $course->id, $viewerroleid);
        $this->getDataGenerator()->enrol_user($creator->id, $course->id, $creatorroleid);
        $this->getDataGenerator()->enrol_user($publisher->id, $course->id, $publisherroleid);
        $this->getDataGenerator()->enrol_user($creatorpublisher->id, $course->id, $creatorroleid);
        role_assign($publisherroleid, $creatorpublisher->id, $context->id);

        set_config('siteadmins', (string) $siteadminviewer->id);
        set_config('creator_role_mapping', (string) $creatorroleid, 'block_panopto');
        set_config('publisher_role_mapping', (string) $publisherroleid, 'block_panopto');

        $DB->insert_record('block_panopto_creatormap', [
            'moodle_id' => $course->id,
            'role_id' => $creatorroleid,
        ]);
        $DB->insert_record('block_panopto_publishermap', [
            'moodle_id' => $course->id,
            'role_id' => $publisherroleid,
        ]);

        // Even mapped roles held at system level must not determine membership in this course.
        role_assign($creatorroleid, $siteadminviewer->id, \context_system::instance()->id);
        role_assign($publisherroleid, $siteadminviewer->id, \context_system::instance()->id);

        // Simulate stale inherited permissions which must never elevate course group membership.
        assign_capability('block/panopto:provision_asteacher', CAP_ALLOW, $viewerroleid, $context);
        assign_capability('block/panopto:provision_aspublisher', CAP_ALLOW, $viewerroleid, $context);

        self::assertTrue(is_siteadmin($siteadminviewer));
        self::assertSame('Viewer', \panopto_data::get_role_from_context($context, $viewer->id));
        self::assertSame('Viewer', \panopto_data::get_role_from_context($context, $siteadminviewer->id));
        role_assign($creatorroleid, $siteadminviewer->id, $context->id);
        self::assertSame('Creator', \panopto_data::get_role_from_context($context, $siteadminviewer->id));
        self::assertSame('Creator', \panopto_data::get_role_from_context($context, $creator->id));
        self::assertSame('Publisher', \panopto_data::get_role_from_context($context, $publisher->id));
        self::assertSame(
            'Creator/Publisher',
            \panopto_data::get_role_from_context($context, $creatorpublisher->id)
        );
    }
}
