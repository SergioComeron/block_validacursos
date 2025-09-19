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

require_once($CFG->libdir . '/tablelib.php'); // Necesario para \table_sql

/**
 * Class issues_table
 *
 * @package    block_validacursos
 * @copyright  2025 Sergio Comerón <info@sergiocomeron.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class issues_table extends \table_sql {

    public function __construct(string $uniqueid) {
        parent::__construct($uniqueid);

        $columns = ['id', 'courseid', 'course', 'courseshortname', 'validation', 'state', 'firstseen', 'lastseen', 'resolvedat', 'teachers'];

        $headers = [
            get_string('id', 'block_validacursos'),
            get_string('courseid', 'block_validacursos'),
            get_string('course'),
            get_string('shortname'),
            get_string('validation', 'block_validacursos'),
            get_string('state', 'block_validacursos'),
            get_string('firstseen', 'block_validacursos'),
            get_string('lastseen', 'block_validacursos'),
            get_string('resolvedat', 'block_validacursos'),
            get_string('teachers', 'block_validacursos')
        ];
        $this->define_columns($columns);
        $this->define_headers($headers);
        $this->sortable(true, 'lastseen', SORT_DESC);
        $this->no_sorting('teachers');
        $this->collapsible(true);
    }

    public function col_course($row) {
        $url = new \moodle_url('/course/view.php', ['id' => $row->courseid]);
        return \html_writer::link($url, \format_string($row->coursename));
    }

    public function col_state($row) {
        $isopen = is_null($row->resolvedat);
        $label = $isopen ? get_string('open', 'block_validacursos') : get_string('resolved', 'block_validacursos');
        $color = $isopen ? '#d9534f' : '#5cb85c';
        return \html_writer::span($label, '', ['style' => "color:{$color}"]);
    }

    public function col_firstseen($row) {
        return $row->firstseen ? \userdate($row->firstseen) : '-';
    }

    public function col_lastseen($row) {
        return $row->lastseen ? \userdate($row->lastseen) : '-';
    }

    public function col_resolvedat($row) {
        return $row->resolvedat ? \userdate($row->resolvedat) : '-';
    }

    protected function col_courseshortname($row) {
        return isset($row->courseshortname) ? format_string($row->courseshortname) : '';
    }

    protected function col_teachers($row) {
        $list = isset($row->teachers) ? trim((string)$row->teachers) : '';
        if ($list === '') {
            return \html_writer::span('—', 'dimmed_text');
        }
        return s($list);
    }

}
