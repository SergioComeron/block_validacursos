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

namespace block_validacursos\output;
defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/tablelib.php');

/**
 * Table for courses with open issues.
 *
 * @package    block_validacursos
 * @copyright  2025 Sergio Comerón <info@sergiocomeron.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class issues_courses_table extends \table_sql {

    public function __construct(string $uniqueid) {
        parent::__construct($uniqueid);

        $columns = ['coursename', 'categoryname', 'teachers', 'issues', 'failedvalidations'];
        $headers = [
            get_string('course'),
            get_string('category'),
            get_string('teachers', 'block_validacursos'),
            get_string('issues', 'block_validacursos'),
            get_string('failedvalidations', 'block_validacursos'),
        ];
        $this->define_columns($columns);
        $this->define_headers($headers);
        $this->sortable(true, 'issues', SORT_DESC);
        $this->no_sorting('teachers');
        $this->no_sorting('failedvalidations');
        $this->collapsible(true);
    }

    public function col_coursename($row) {
        $url = new \moodle_url('/course/view.php', ['id' => $row->courseid]);
        $attrs = empty($row->coursevisible) ? ['class' => 'dimmed_text'] : [];
        return \html_writer::link($url, \format_string($row->coursename), $attrs);
    }

    public function col_teachers($row) {
        $list = isset($row->teachers) ? trim((string)$row->teachers) : '';
        if ($list === '') {
            return $this->is_downloading() ? '' : \html_writer::span('—', 'dimmed_text');
        }
        return s($list);
    }

    public function col_failedvalidations($row) {
        return isset($row->failedvalidations) ? s($row->failedvalidations) : '';
    }
}
