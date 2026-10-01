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
 * Resolves whether Moodle course visibility permits Panopto role synchronisation.
 *
 * @package block_panopto
 * @copyright 2026 Panopto
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class course_visibility_policy {
    /**
     * Determine whether a course should contribute Panopto groups for a user.
     *
     * @param \stdClass $course Moodle course record containing id and visible.
     * @param int $userid Moodle user ID.
     * @return bool
     */
    public static function should_sync_course(\stdClass $course, int $userid): bool {
        if (!empty($course->visible)) {
            return true;
        }

        return self::should_sync_hidden_course((int) $course->id, $userid);
    }

    /**
     * Determine whether a hidden course should contribute Panopto groups for a user.
     *
     * @param int $courseid Moodle course ID.
     * @param int $userid Moodle user ID.
     * @return bool
     */
    public static function should_sync_hidden_course(int $courseid, int $userid): bool {
        if (!self::hidden_sync_enabled($courseid)) {
            return false;
        }

        if (self::all_hidden_participants_enabled($courseid)) {
            return true;
        }

        $mappedroles = self::get_enabled_hidden_role_ids($courseid);
        if (empty($mappedroles)) {
            return false;
        }

        $context = \context_course::instance($courseid);
        foreach (get_user_roles($context, $userid, false) as $role) {
            if (in_array((int) $role->roleid, $mappedroles, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether a role change in a hidden course requires a user sync.
     *
     * @param int $courseid Moodle course ID.
     * @param int $roleid Moodle role ID.
     * @return bool
     */
    public static function should_sync_hidden_role(int $courseid, int $roleid): bool {
        if (!self::hidden_sync_enabled($courseid)) {
            return false;
        }

        if (self::all_hidden_participants_enabled($courseid)) {
            return true;
        }

        return in_array($roleid, self::get_enabled_hidden_role_ids($courseid), true);
    }

    /**
     * Whether all active participants are allowed to sync while a course is hidden.
     *
     * @param int $courseid Moodle course ID.
     * @return bool
     */
    public static function all_hidden_participants_enabled(int $courseid): bool {
        return self::hidden_sync_enabled($courseid)
            && course_visibility_config::is_enabled($courseid, 'sync_hidden_all_participants');
    }

    /**
     * Return a canonical representation of the access retained while a course is hidden.
     *
     * Irrelevant subordinate values are deliberately excluded so saving an equivalent policy does not queue work.
     *
     * @param int $courseid Moodle course ID.
     * @return array<string, mixed>
     */
    public static function get_hidden_access_signature(int $courseid): array {
        if (!self::hidden_sync_enabled($courseid)) {
            return ['mode' => 'disabled'];
        }

        $mappings = \panopto_data::get_course_role_mappings($courseid);
        $creatorroleids = self::normalise_role_ids($mappings['creator']);
        $publisherroleids = self::normalise_role_ids($mappings['publisher']);

        if (self::all_hidden_participants_enabled($courseid)) {
            return [
                'mode' => 'all',
                'creatorroleids' => $creatorroleids,
                'publisherroleids' => $publisherroleids,
            ];
        }

        $creatorselection = course_visibility_config::is_enabled($courseid, 'sync_hidden_creators')
            ? $creatorroleids
            : [];
        $publisherselection = course_visibility_config::is_enabled($courseid, 'sync_hidden_publishers')
            ? $publisherroleids
            : [];

        if (empty($creatorselection) && empty($publisherselection)) {
            return ['mode' => 'disabled'];
        }

        return [
            'mode' => 'roles',
            'creatorselection' => $creatorselection,
            'publisherselection' => $publisherselection,
            'creatorroleids' => $creatorroleids,
            'publisherroleids' => $publisherroleids,
        ];
    }

    /**
     * Determine whether a bulk visibility-change task remains applicable.
     *
     * @param int $courseid Moodle course ID.
     * @param bool $targetvisible Visibility state that triggered the task.
     * @return bool
     */
    public static function should_process_visibility_change(int $courseid, bool $targetvisible): bool {
        if ($targetvisible) {
            return !self::all_hidden_participants_enabled($courseid)
                && course_visibility_config::is_enabled($courseid, 'sync_visible_course_participants');
        }

        // Retention wins if contradictory values are stored, but the removal option remains configurable.
        return !self::all_hidden_participants_enabled($courseid)
            && course_visibility_config::is_enabled($courseid, 'remove_access_on_course_hidden');
    }

    /**
     * Whether any hidden-course role synchronisation is enabled.
     *
     * @param int $courseid Moodle course ID.
     * @return bool
     */
    private static function hidden_sync_enabled(int $courseid): bool {
        return course_visibility_config::is_enabled($courseid, 'sync_hidden_courses');
    }

    /**
     * Get role IDs whose users may be synchronised while a course is hidden.
     *
     * Course mappings are authoritative because they begin with the global defaults but can be
     * deliberately overridden on an individual Panopto block instance.
     *
     * @param int $courseid Moodle course ID.
     * @return int[]
     */
    private static function get_enabled_hidden_role_ids(int $courseid): array {
        $mappings = \panopto_data::get_course_role_mappings($courseid);
        $roleids = [];

        if (course_visibility_config::is_enabled($courseid, 'sync_hidden_creators')) {
            $roleids = array_merge($roleids, $mappings['creator']);
        }

        if (course_visibility_config::is_enabled($courseid, 'sync_hidden_publishers')) {
            $roleids = array_merge($roleids, $mappings['publisher']);
        }

        return array_values(array_unique(array_map('intval', $roleids)));
    }

    /**
     * Normalise mapped role IDs for stable policy comparisons.
     *
     * @param array<int, int|string> $roleids Role IDs from mapping storage.
     * @return int[]
     */
    private static function normalise_role_ids(array $roleids): array {
        $roleids = array_values(array_unique(array_map('intval', $roleids)));
        sort($roleids, SORT_NUMERIC);
        return $roleids;
    }
}
