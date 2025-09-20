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

/*
 * Cadenas mínimas usadas en el reporte por si aún no están en lang.
 * (Opcional: elimina este bloque cuando añadas las cadenas al paquete de idioma).
 */
if (!function_exists('block_validacursos_temp_get_string')) {
    // No-op helper.
}

$uniqueid = 'block_validacursos_issues';
$table = new \block_validacursos\output\issues_table($uniqueid);
$table->is_downloading($download, 'validacursos_issues', 'validacursos_issues');
$table->define_baseurl($PAGE->url);

// SQL
$contextcourselevel = CONTEXT_COURSE;
$dbfamily = $DB->get_dbfamily();
if ($dbfamily === 'postgres') {
    $teacherselect = "(SELECT string_agg(COALESCE(u.firstname,'') || ' ' || COALESCE(u.lastname,''), ', ' ORDER BY u.lastname, u.firstname)
                         FROM {role_assignments} ra
                         JOIN {context} ctx ON ctx.id = ra.contextid
                         JOIN {role} r ON r.id = ra.roleid
                         JOIN {user} u ON u.id = ra.userid
                        WHERE ctx.contextlevel = :ctxlevel AND ctx.instanceid = i.courseid
                          AND r.archetype IN ('editingteacher','teacher') AND u.deleted = 0)";
} else { // mysql/mariadb fallback
    $teacherselect = "(SELECT GROUP_CONCAT(CONCAT(u.firstname, ' ', u.lastname) ORDER BY u.lastname SEPARATOR ', ')
                         FROM {role_assignments} ra
                         JOIN {context} ctx ON ctx.id = ra.contextid
                         JOIN {role} r ON r.id = ra.roleid
                         JOIN {user} u ON u.id = ra.userid
                        WHERE ctx.contextlevel = :ctxlevel AND ctx.instanceid = i.courseid
                          AND r.archetype IN ('editingteacher','teacher') AND u.deleted = 0)";
}

$shortwithcat = 'c.shortname';

$fields = 'i.id, i.courseid, c.shortname AS courseshortname, c.fullname AS coursename, ' .
          'cc.name AS categoryname, ' .
          'i.validation, i.firstseen, i.lastseen, i.resolvedat, ' . $teacherselect . ' AS teachers';

$from   = '{block_validacursos_issues} i
            JOIN {course} c ON c.id = i.courseid
            JOIN {course_categories} cc ON cc.id = c.category';

$whereparts = [];
$params = [];
$params['ctxlevel'] = $contextcourselevel;
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

    // ===== Métricas y agregados =====
    global $DB;

    // Totales (en la categoría seleccionada si procede).
    $paramsall = [];
    $whereall = '1=1';
    if ($categoryid) {
        $whereall .= ' AND c.category = :categoryid_all';
        $paramsall['categoryid_all'] = $categoryid;
    }
    $countall = $DB->get_field_sql("SELECT COUNT(1) 
                                      FROM {block_validacursos_issues} i 
                                      JOIN {course} c ON c.id = i.courseid
                                     WHERE $whereall", $paramsall);

    $paramsopen = $paramsall;
    $whereopen = $whereall . ' AND i.resolvedat IS NULL';
    $countopen = $DB->get_field_sql("SELECT COUNT(1) 
                                       FROM {block_validacursos_issues} i 
                                       JOIN {course} c ON c.id = i.courseid
                                      WHERE $whereopen", $paramsopen);

    // Render totales.
    $totalshtml = html_writer::div(
        html_writer::tag('strong', get_string('totalissues', 'block_validacursos') . ': ') . (int)$countall
        . ' &nbsp; | &nbsp; ' .
        html_writer::tag('strong', get_string('openissues', 'block_validacursos') . ': ') . (int)$countopen,
        'mb-3'
    );
    echo $totalshtml;

    // Top cursos con más incidencias abiertas (respetando el filtro de categoría si existe).
    $topparams = [];
    $topwhere = '1=1 AND i.resolvedat IS NULL';
    if ($categoryid) {
        $topwhere .= ' AND c.category = :categoryid_top';
        $topparams['categoryid_top'] = $categoryid;
    }
    $topcourses = $DB->get_records_sql("
        SELECT i.courseid, c.fullname AS coursename, COUNT(1) AS issues
          FROM {block_validacursos_issues} i
          JOIN {course} c ON c.id = i.courseid
         WHERE $topwhere
      GROUP BY i.courseid, c.fullname
      ORDER BY issues DESC, c.fullname ASC
         LIMIT 10
    ", $topparams);

    if ($topcourses) {
        $rows = [];
        foreach ($topcourses as $tc) {
            $courseurl = new moodle_url('/course/view.php', ['id' => $tc->courseid]);
            $rows[] = html_writer::tag('tr',
                html_writer::tag('td', html_writer::link($courseurl, format_string($tc->coursename))) .
                html_writer::tag('td', (int)$tc->issues, ['style' => 'text-align:right'])
            );
        }
        $tablehtml = html_writer::tag('table',
            html_writer::tag('thead', html_writer::tag('tr',
                html_writer::tag('th', get_string('course')) .
                html_writer::tag('th', get_string('issues', 'block_validacursos'), ['style' => 'text-align:right'])
            )) .
            html_writer::tag('tbody', implode('', $rows)),
            ['class' => 'generaltable boxaligncenter', 'style' => 'margin-top: .5rem;']
        );
        echo html_writer::tag('div',
            html_writer::tag('h3', get_string('topcoursesopen', 'block_validacursos')) . $tablehtml,
            ['class' => 'box generalbox mb-3']
        );
    }
}

// Tamaño de página y salida (paginada si no es descarga).
$table->out(50, true);

if (!$table->is_downloading()) {
    echo $OUTPUT->footer();
}
