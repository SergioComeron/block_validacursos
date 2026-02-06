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

namespace block_validacursos\task;

use block_validacursos\local\validator;
use block_validacursos\local\logger;

/**
 * Scheduled task to validate all courses and log issues.
 *
 * @package    block_validacursos
 * @copyright  2025 Sergio Comerón <info@sergiocomeron.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class validate_all_courses extends \core\task\scheduled_task {

    /**
     * Return the task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('validateallcourses', 'block_validacursos');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        global $DB;

        $config = get_config('block_validacursos');

        // Build allowed categories filter.
        $allowedcsv = isset($config->allowedcategories) ? trim((string)$config->allowedcategories) : '';
        $allowed = [];
        if ($allowedcsv !== '') {
            $allowed = array_filter(array_map('intval', explode(',', $allowedcsv)));
        }

        // Fetch visible courses using a recordset for memory efficiency.
        $rs = $DB->get_recordset('course', ['visible' => 1], 'id ASC');

        $processed = 0;
        $errors = 0;

        foreach ($rs as $course) {
            // Skip site-level course.
            if ($course->id == SITEID) {
                continue;
            }

            // Filter by allowed categories.
            if (!empty($allowed) && !in_array((int)$course->category, $allowed, true)) {
                continue;
            }

            try {
                $validaciones = validator::get_validaciones($course, $config);
                logger::save_course_results_history($course->id, $validaciones);
                $processed++;
            } catch (\Throwable $e) {
                $errors++;
                mtrace("  Error validating course {$course->id} ({$course->shortname}): " . $e->getMessage());
            }
        }

        $rs->close();

        mtrace("Validate all courses: {$processed} courses processed, {$errors} errors.");
    }
}
