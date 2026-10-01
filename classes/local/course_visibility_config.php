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
 * Resolves site defaults and preserved course-level visibility synchronisation overrides.
 *
 * @package block_panopto
 * @copyright 2026 Panopto
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class course_visibility_config {
    /** The course follows the current site-wide value. */
    public const INHERIT = -1;

    /** The setting is explicitly disabled for the course. */
    public const DISABLED = 0;

    /** The setting is explicitly enabled for the course. */
    public const ENABLED = 1;

    /** @var string[] Visibility setting names that support course overrides. */
    private const SETTING_NAMES = [
        'sync_hidden_courses',
        'sync_hidden_all_participants',
        'sync_hidden_creators',
        'sync_hidden_publishers',
        'sync_visible_course_participants',
        'remove_access_on_course_hidden',
    ];

    /** @var array<int, array<string, int>> Stored course overrides cached for this request. */
    private static array $overridecache = [];

    /**
     * Return the names of settings that can be overridden by a course.
     *
     * @return string[]
     */
    public static function get_setting_names(): array {
        return self::SETTING_NAMES;
    }

    /**
     * Return the block instance config property used for one setting.
     *
     * @param string $settingname Site-wide setting name.
     * @return string
     */
    public static function get_instance_property(string $settingname): string {
        return 'visibility_' . $settingname;
    }

    /**
     * Whether site administration currently permits course-level overrides.
     *
     * @return bool
     */
    public static function course_overrides_allowed(): bool {
        return !empty(get_config('block_panopto', 'allow_course_visibility_overrides'));
    }

    /**
     * Get the site-wide values without applying any course override.
     *
     * @return array<string, bool>
     */
    public static function get_site_settings(): array {
        $settings = [];
        foreach (self::SETTING_NAMES as $settingname) {
            $settings[$settingname] = !empty(get_config('block_panopto', $settingname));
        }
        return $settings;
    }

    /**
     * Get the stored tri-state overrides for a course, whether or not overrides are currently allowed.
     *
     * @param int $courseid Moodle course ID.
     * @return array<string, int>
     */
    public static function get_course_overrides(int $courseid): array {
        global $DB;

        if (isset(self::$overridecache[$courseid])) {
            return self::$overridecache[$courseid];
        }

        $overrides = array_fill_keys(self::SETTING_NAMES, self::INHERIT);
        if ($courseid <= SITEID) {
            self::$overridecache[$courseid] = $overrides;
            return $overrides;
        }

        $context = \context_course::instance($courseid, IGNORE_MISSING);
        if (!$context) {
            self::$overridecache[$courseid] = $overrides;
            return $overrides;
        }

        $instances = $DB->get_records(
            'block_instances',
            ['blockname' => 'panopto', 'parentcontextid' => $context->id],
            'id ASC',
            'id, configdata',
            0,
            1
        );
        $instance = reset($instances);
        if (!$instance || empty($instance->configdata)) {
            self::$overridecache[$courseid] = $overrides;
            return $overrides;
        }

        $decoded = base64_decode($instance->configdata, true);
        if ($decoded === false) {
            self::$overridecache[$courseid] = $overrides;
            return $overrides;
        }

        $config = unserialize_object($decoded);
        foreach (self::SETTING_NAMES as $settingname) {
            $property = self::get_instance_property($settingname);
            if (property_exists($config, $property)) {
                $overrides[$settingname] = self::normalise_override($config->{$property});
            }
        }

        self::$overridecache[$courseid] = $overrides;
        return $overrides;
    }

    /**
     * Get effective settings for a course.
     *
     * Stored overrides are deliberately ignored, but not deleted, while the site switch is disabled.
     *
     * @param int $courseid Moodle course ID.
     * @return array<string, bool>
     */
    public static function get_effective_settings(int $courseid): array {
        $settings = self::get_site_settings();
        if (self::course_overrides_allowed()) {
            foreach (self::get_course_overrides($courseid) as $settingname => $override) {
                if ($override !== self::INHERIT) {
                    $settings[$settingname] = $override === self::ENABLED;
                }
            }
        }

        return $settings;
    }

    /**
     * Determine the effective value of one setting for a course.
     *
     * @param int $courseid Moodle course ID.
     * @param string $settingname Site-wide setting name.
     * @return bool
     */
    public static function is_enabled(int $courseid, string $settingname): bool {
        return self::get_effective_settings($courseid)[$settingname];
    }

    /**
     * Normalise submitted or stored override data to a supported value.
     *
     * @param mixed $value Submitted value.
     * @return int
     */
    public static function normalise_override($value): int {
        $value = (int) $value;
        if (in_array($value, [self::INHERIT, self::DISABLED, self::ENABLED], true)) {
            return $value;
        }
        return self::INHERIT;
    }

    /**
     * Clear cached configuration after a block instance is saved or during tests.
     *
     * @param int|null $courseid Course to clear, or null to clear every course.
     */
    public static function reset_cache(?int $courseid = null): void {
        if ($courseid === null) {
            self::$overridecache = [];
            return;
        }

        unset(self::$overridecache[$courseid]);
    }
}
