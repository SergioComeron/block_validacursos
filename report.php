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
$categoryid = optional_param('category', 0, PARAM_INT);

$pagetitle = get_string('issuesreport', 'block_validacursos');
$PAGE->set_context($systemcontext);

// Construimos URL incluyendo filtros activos.
$pageparams = ['show' => $show];
if ($categoryid) {
    $pageparams['category'] = $categoryid;
}
$PAGE->set_url(new moodle_url('/blocks/validacursos/report.php', $pageparams));
$PAGE->set_pagelayout('report');
$PAGE->set_title($pagetitle);
$PAGE->set_heading($pagetitle);

$uniqueid = 'block_validacursos_issues';
$table = new \block_validacursos\output\issues_table($uniqueid);
$table->is_downloading($download, 'validacursos_issues', 'validacursos_issues');
$table->define_baseurl($PAGE->url);

// SQL
$fields = 'i.id, i.courseid, c.fullname AS coursename, i.validation, i.firstseen, i.lastseen, i.resolvedat';
$from   = '{block_validacursos_issues} i JOIN {course} c ON c.id = i.courseid';

$whereparts = [];
$params = [];
if ($show !== 'all') {
    $whereparts[] = 'i.resolvedat IS NULL';
}
if ($categoryid) {
    $whereparts[] = 'c.category = :categoryid';
    $params['categoryid'] = $categoryid;
}
$where = $whereparts ? implode(' AND ', $whereparts) : '1=1';

$table->set_sql($fields, $from, $where, $params);
$table->set_count_sql("SELECT COUNT(1) FROM $from WHERE $where", $params);

// Render
if (!$table->is_downloading()) {
    echo $OUTPUT->header();

    // Links de pestañas conservando categoría.
    $urlopen = new moodle_url('/blocks/validacursos/report.php', ['show' => 'open'] + ($categoryid ? ['category' => $categoryid] : []));
    $urlall  = new moodle_url('/blocks/validacursos/report.php', ['show' => 'all'] + ($categoryid ? ['category' => $categoryid] : []));
    echo html_writer::div(
        html_writer::link($urlopen, get_string('showopen', 'block_validacursos'), ['style' => $show !== 'open' ? '' : 'font-weight:bold'])
        . ' | ' .
        html_writer::link($urlall, get_string('showall', 'block_validacursos'), ['style' => $show !== 'all' ? '' : 'font-weight:bold']),
        'mb-2'
    );

    // Form categoría (mantiene show).
    $cats = core_course_category::make_categories_list();
    echo html_writer::start_tag('form', ['method' => 'get', 'action' => $PAGE->url->out(false)]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'show', 'value' => $show]);
    echo html_writer::select(['0' => get_string('allcategories')] + $cats, 'category', $categoryid, null,
        ['onchange' => 'this.form.submit()']);
    echo html_writer::end_tag('form');
}

// Tamaño de página y salida (paginada si no es descarga).
$table->out(50, true);

if (!$table->is_downloading()) {
    echo $OUTPUT->footer();
}
