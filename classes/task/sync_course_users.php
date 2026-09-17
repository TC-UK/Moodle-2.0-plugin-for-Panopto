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

namespace block_panopto\task;

use block_panopto\local\course_visibility_policy;

defined('MOODLE_INTERNAL') || die();

require_once(dirname(__FILE__) . '/../../lib/panopto_data.php');
require_once(dirname(__FILE__) . '/../../lib/panopto_throttling.php');

/**
 * Synchronise a bounded batch of active participants after a visibility or hidden-access policy change.
 *
 * @package block_panopto
 * @copyright 2026 Panopto
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_course_users extends \core\task\adhoc_task {
    /** Maximum users processed by one task execution. */
    private const BATCH_SIZE = 250;

    /** Course was made visible. */
    public const REASON_COURSE_SHOWN = 'course_shown';

    /** Course was hidden. */
    public const REASON_COURSE_HIDDEN = 'course_hidden';

    /** Effective hidden-access policy changed in block configuration. */
    public const REASON_HIDDEN_POLICY = 'hidden_policy';

    /**
     * Get the task component.
     *
     * @return string
     */
    public function get_component(): string {
        return 'block_panopto';
    }

    /**
     * Queue a participant sync after a course becomes visible.
     *
     * @param int $courseid Moodle course ID.
     * @param int $afteruserid Last processed user ID, or zero for the first batch.
     */
    public static function queue_course_shown_sync(int $courseid, int $afteruserid = 0): void {
        self::queue_course_sync($courseid, self::REASON_COURSE_SHOWN, $afteruserid);
    }

    /**
     * Queue a participant sync after a course becomes hidden.
     *
     * @param int $courseid Moodle course ID.
     * @param int $afteruserid Last processed user ID, or zero for the first batch.
     */
    public static function queue_course_hidden_sync(int $courseid, int $afteruserid = 0): void {
        self::queue_course_sync($courseid, self::REASON_COURSE_HIDDEN, $afteruserid);
    }

    /**
     * Queue reconciliation after the effective hidden-access policy changes.
     *
     * @param int $courseid Moodle course ID.
     * @param int $afteruserid Last processed user ID, or zero for the first batch.
     */
    public static function queue_hidden_policy_refresh(int $courseid, int $afteruserid = 0): void {
        self::queue_course_sync($courseid, self::REASON_HIDDEN_POLICY, $afteruserid);
    }

    /**
     * Queue the first or next participant batch while de-duplicating identical work.
     *
     * @param int $courseid Moodle course ID.
     * @param string $reason Reason represented by one of this class's REASON constants.
     * @param int $afteruserid Last processed user ID, or zero for the first batch.
     */
    private static function queue_course_sync(int $courseid, string $reason, int $afteruserid): void {
        $task = new self();
        $task->set_custom_data([
            'courseid' => $courseid,
            'targetvisible' => $reason === self::REASON_COURSE_SHOWN,
            'reason' => $reason,
            'afteruserid' => $afteruserid,
        ]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Process the next keyset-paginated participant batch.
     */
    public function execute(): void {
        $data = $this->normalise_custom_data();
        if (!$this->task_is_applicable($data->courseid, $data->reason)) {
            return;
        }

        $coursepanopto = new \panopto_data($data->courseid);
        if (!$coursepanopto->has_valid_panopto()) {
            return;
        }

        $users = $this->get_participant_batch($data->courseid, $data->afteruserid);
        $hasmore = count($users) > self::BATCH_SIZE;
        if ($hasmore) {
            array_pop($users);
        }

        $afteruserid = $this->sync_users($coursepanopto, $users, $data->afteruserid);
        if ($hasmore && !empty($users)) {
            self::queue_course_sync($data->courseid, $data->reason, $afteruserid);
        }
    }

    /**
     * Normalise and type the task custom data.
     *
     * @return \stdClass
     */
    private function normalise_custom_data(): \stdClass {
        $rawdata = (array) $this->get_custom_data();
        $reason = isset($rawdata['reason']) ? (string) $rawdata['reason'] : '';
        if (!in_array($reason, self::get_reasons(), true)) {
            // Preserve compatibility with tasks queued before explicit reasons were introduced.
            $reason = !empty($rawdata['targetvisible'])
                ? self::REASON_COURSE_SHOWN
                : self::REASON_COURSE_HIDDEN;
        }
        return (object) [
            'courseid' => isset($rawdata['courseid']) ? (int) $rawdata['courseid'] : 0,
            'reason' => $reason,
            'afteruserid' => isset($rawdata['afteruserid']) ? (int) $rawdata['afteruserid'] : 0,
        ];
    }

    /**
     * Confirm that settings and current course state still match the queued task.
     *
     * @param int $courseid Moodle course ID.
     * @param string $reason Task reason represented by one of this class's REASON constants.
     * @return bool
     */
    private function task_is_applicable(int $courseid, string $reason): bool {
        global $DB;

        if ($courseid <= SITEID) {
            return false;
        }

        $visible = $DB->get_field('course', 'visible', ['id' => $courseid], IGNORE_MISSING);
        if ($visible === false) {
            return false;
        }

        if ($reason === self::REASON_HIDDEN_POLICY) {
            return empty($visible);
        }

        $targetvisible = $reason === self::REASON_COURSE_SHOWN;
        return (bool) $visible === $targetvisible
            && course_visibility_policy::should_process_visibility_change($courseid, $targetvisible);
    }

    /**
     * Return supported task reasons.
     *
     * @return string[]
     */
    private static function get_reasons(): array {
        return [
            self::REASON_COURSE_SHOWN,
            self::REASON_COURSE_HIDDEN,
            self::REASON_HIDDEN_POLICY,
        ];
    }

    /**
     * Get one ordered page plus a look-ahead record of active participants.
     *
     * @param int $courseid Moodle course ID.
     * @param int $afteruserid Last user ID processed by the preceding batch.
     * @return \stdClass[]
     */
    private function get_participant_batch(int $courseid, int $afteruserid): array {
        global $DB;

        $context = \context_course::instance($courseid);
        [$enrolledsql, $params] = get_enrolled_sql($context, '', 0, true);
        $params['afteruserid'] = $afteruserid;
        $sql = "SELECT u.id
                  FROM {user} u
                  JOIN ($enrolledsql) enrolled ON enrolled.id = u.id
                 WHERE u.deleted = 0
                       AND u.id > :afteruserid
              ORDER BY u.id ASC";
        return $DB->get_records_sql($sql, $params, 0, self::BATCH_SIZE + 1);
    }

    /**
     * Synchronise one page of users and return the last processed ID.
     *
     * @param \panopto_data $coursepanopto Provisioned course data.
     * @param \stdClass[] $users User records containing an ID.
     * @param int $afteruserid Previous last-processed user ID.
     * @return int
     */
    private function sync_users(\panopto_data $coursepanopto, array $users, int $afteruserid): int {
        foreach ($users as $user) {
            \panopto_throttling::execute_with_throttling(
                [$coursepanopto, 'sync_external_user'],
                [(int) $user->id],
                'usermanagement_sync',
                'sync_course_visibility_user',
                (int) $user->id
            );
            $afteruserid = (int) $user->id;
        }

        return $afteruserid;
    }
}
