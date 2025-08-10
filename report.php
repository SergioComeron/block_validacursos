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

/**
 * TODO describe file report
 *
 * @package    block_validacursos
 * @copyright  2025 Sergio Comerón <info@sergiocomeron.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();
$systemcontext = context_system::instance();
require_capability('block/validacursos:viewissuesreport', $systemcontext);

$download = optional_param('download', '', PARAM_ALPHA); // csv
$show = optional_param('show', 'open', PARAM_ALPHA);     // open|all

$pagetitle = get_string('issuesreport', 'block_validacursos');
$PAGE->set_context($systemcontext);
$PAGE->set_url(new moodle_url('/blocks/validacursos/report.php', ['show' => $show]));
$PAGE->set_pagelayout('report');
$PAGE->set_title($pagetitle);
$PAGE->set_heading($pagetitle);

$uniqueid = 'block_validacursos_issues';
$table = new \block_validacursos\output\issues_table($uniqueid);
$table->is_downloading($download, 'validacursos_issues', 'validacursos_issues'); // CSV si ?download=csv
$table->define_baseurl($PAGE->url);

// SQL
$fields = 'i.id, i.courseid, c.fullname AS coursename, i.validation, i.firstseen, i.lastseen, i.resolvedat';
$from   = '{block_validacursos_issues} i JOIN {course} c ON c.id = i.courseid';
// Usa 1=1 cuando no hay filtro específico.
$where  = ($show !== 'all') ? 'i.resolvedat IS NULL' : '1=1';
$params = [];

$table->set_sql($fields, $from, $where, $params);

// Conteo consistente con el WHERE anterior.
$table->set_count_sql("SELECT COUNT(1) FROM $from WHERE $where", $params);

// Render
if (!$table->is_downloading()) {
    echo $OUTPUT->header();

    // Filtros simples.
    $tabs = [];
    $urlopen = new moodle_url($PAGE->url, ['show' => 'open']);
    $urlall  = new moodle_url($PAGE->url, ['show' => 'all']);
    echo html_writer::div(
        html_writer::link($urlopen, get_string('showopen', 'block_validacursos'), ['style' => $show !== 'open' ? '' : 'font-weight:bold'])
        . ' | ' .
        html_writer::link($urlall, get_string('showall', 'block_validacursos'), ['style' => $show !== 'all' ? '' : 'font-weight:bold']),
        'mb-2'
    );
}

// Tamaño de página y salida (paginada si no es descarga).
$table->out(50, true);

if (!$table->is_downloading()) {
    echo $OUTPUT->footer();
}
