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
 * Table for top courses with most open issues.
 *
 * @package    block_validacursos
 * @copyright  2025 Sergio Comerón <info@sergiocomeron.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class top_courses_table extends \table_sql {

    /** @var array Page params for action URLs. */
    private $pageparams;

    public function __construct(string $uniqueid, array $pageparams = []) {
        parent::__construct($uniqueid);
        $this->pageparams = $pageparams;

        $columns = ['coursename', 'issues', 'actions'];
        $headers = [
            get_string('course'),
            get_string('issues', 'block_validacursos'),
            '',
        ];
        $this->define_columns($columns);
        $this->define_headers($headers);
        $this->sortable(true, 'issues', SORT_DESC);
        $this->no_sorting('actions');
        $this->collapsible(false);
    }

    public function col_coursename($row) {
        $url = new \moodle_url('/course/view.php', ['id' => $row->courseid]);
        return \html_writer::link($url, \format_string($row->coursename));
    }

    public function col_actions($row) {
        if ($this->is_downloading()) {
            return '';
        }
        $confirmstr = addslashes_js(get_string('sendemailconfirm', 'block_validacursos'));
        $emailurl = new \moodle_url('/blocks/validacursos/report.php',
            ['sendemail' => $row->courseid, 'sesskey' => \sesskey()] + $this->pageparams + ['tab' => 'top']);
        return \html_writer::link($emailurl->out(false),
            '&#9993; ' . get_string('sendemail', 'block_validacursos'),
            ['class' => 'btn btn-sm btn-outline-primary',
             'onclick' => 'return confirm("' . $confirmstr . '")']);
    }
}
