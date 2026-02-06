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
 * Validation issues report with statistics, charts and export.
 *
 * @package    block_validacursos
 * @copyright  2025 Sergio Comerón <info@sergiocomeron.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();
$systemcontext = context_system::instance();
require_capability('block/validacursos:viewissuesreport', $systemcontext);

$download = optional_param('download', '', PARAM_ALPHA); // csv|excel
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

// ===== Filtro SQL reutilizable (categoría) =====
$catjoin = '';
$catwhere = '1=1';
$catparams = [];
if ($categoryid) {
    $catjoin = ' JOIN {course} c ON c.id = i.courseid';
    $catwhere = 'c.category = :categoryid_f';
    $catparams['categoryid_f'] = $categoryid;
}

// ===== Exportación Excel =====
if ($download === 'excel') {
    global $CFG;
    require_once($CFG->libdir . '/excellib.class.php');

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
    } else {
        $teacherselect = "(SELECT GROUP_CONCAT(CONCAT(u.firstname, ' ', u.lastname) ORDER BY u.lastname SEPARATOR ', ')
                             FROM {role_assignments} ra
                             JOIN {context} ctx ON ctx.id = ra.contextid
                             JOIN {role} r ON r.id = ra.roleid
                             JOIN {user} u ON u.id = ra.userid
                            WHERE ctx.contextlevel = :ctxlevel AND ctx.instanceid = i.courseid
                              AND r.archetype IN ('editingteacher','teacher') AND u.deleted = 0)";
    }

    $sql = "SELECT i.id, i.courseid, c.shortname, c.fullname, cc.name AS categoryname,
                   i.validation, i.firstseen, i.lastseen, i.resolvedat, $teacherselect AS teachers
              FROM {block_validacursos_issues} i
              JOIN {course} c ON c.id = i.courseid
              JOIN {course_categories} cc ON cc.id = c.category";

    $whereparts = [];
    $params = ['ctxlevel' => $contextcourselevel];
    if ($show !== 'all') {
        $whereparts[] = 'i.resolvedat IS NULL';
    }
    if ($categoryid) {
        $whereparts[] = 'c.category = :categoryid';
        $params['categoryid'] = $categoryid;
    }
    $sql .= ' WHERE ' . ($whereparts ? implode(' AND ', $whereparts) : '1=1');
    $sql .= ' ORDER BY i.lastseen DESC';

    $records = $DB->get_records_sql($sql, $params);

    $workbook = new MoodleExcelWorkbook('-');
    $workbook->send('validacursos_issues.xlsx');
    $worksheet = $workbook->add_worksheet(get_string('issuesreport', 'block_validacursos'));

    $headerformat = $workbook->add_format();
    $headerformat->set_bold(1);
    $headerformat->set_bg_color('#CCCCCC');
    $headerformat->set_align('center');

    $headers = ['ID', 'Course ID', 'Short name', 'Course', 'Category',
                'Validation', 'First seen', 'Last seen', 'Resolved at', 'Teachers'];
    foreach ($headers as $col => $header) {
        $worksheet->write(0, $col, $header, $headerformat);
    }

    $row = 1;
    foreach ($records as $rec) {
        $worksheet->write($row, 0, $rec->id);
        $worksheet->write($row, 1, $rec->courseid);
        $worksheet->write($row, 2, $rec->shortname);
        $worksheet->write($row, 3, $rec->fullname);
        $worksheet->write($row, 4, $rec->categoryname);
        $worksheet->write($row, 5, $rec->validation);
        $worksheet->write($row, 6, $rec->firstseen ? userdate($rec->firstseen) : '-');
        $worksheet->write($row, 7, $rec->lastseen ? userdate($rec->lastseen) : '-');
        $worksheet->write($row, 8, $rec->resolvedat ? userdate($rec->resolvedat) : '-');
        $worksheet->write($row, 9, $rec->teachers ?? '');
        $row++;
    }

    $worksheet->set_column(0, 1, 10);
    $worksheet->set_column(2, 2, 15);
    $worksheet->set_column(3, 3, 30);
    $worksheet->set_column(4, 4, 20);
    $worksheet->set_column(5, 5, 40);
    $worksheet->set_column(6, 8, 20);
    $worksheet->set_column(9, 9, 30);

    $workbook->close();
    die();
}

// ===== Tabla SQL (CSV + HTML) =====
$uniqueid = 'block_validacursos_issues';
$table = new \block_validacursos\output\issues_table($uniqueid);
$table->is_downloading($download === 'csv' ? 'csv' : '', 'validacursos_issues', 'validacursos_issues');
$table->define_baseurl($PAGE->url);

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
} else {
    $teacherselect = "(SELECT GROUP_CONCAT(CONCAT(u.firstname, ' ', u.lastname) ORDER BY u.lastname SEPARATOR ', ')
                         FROM {role_assignments} ra
                         JOIN {context} ctx ON ctx.id = ra.contextid
                         JOIN {role} r ON r.id = ra.roleid
                         JOIN {user} u ON u.id = ra.userid
                        WHERE ctx.contextlevel = :ctxlevel AND ctx.instanceid = i.courseid
                          AND r.archetype IN ('editingteacher','teacher') AND u.deleted = 0)";
}

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

// ===== Render =====
if (!$table->is_downloading()) {
    echo $OUTPUT->header();

    // Pestañas conservando categoría.
    $urlopen = new moodle_url('/blocks/validacursos/report.php', ['show' => 'open'] + ($categoryid ? ['category' => $categoryid] : []));
    $urlall  = new moodle_url('/blocks/validacursos/report.php', ['show' => 'all'] + ($categoryid ? ['category' => $categoryid] : []));
    echo html_writer::div(
        html_writer::link($urlopen, get_string('showopen', 'block_validacursos'), ['style' => $show !== 'open' ? '' : 'font-weight:bold'])
        . ' | ' .
        html_writer::link($urlall, get_string('showall', 'block_validacursos'), ['style' => $show !== 'all' ? '' : 'font-weight:bold']),
        'mb-2'
    );

    // Filtro categoría.
    $cats = core_course_category::make_categories_list();
    echo html_writer::start_tag('form', ['method' => 'get', 'action' => $PAGE->url->out(false)]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'show', 'value' => $show]);
    echo html_writer::select(['0' => get_string('allcategories')] + $cats, 'category', $categoryid, null,
        ['onchange' => 'this.form.submit()']);
    echo html_writer::end_tag('form');

    // ===== Estadísticas =====
    // Total issues y open issues.
    $paramsall = [];
    $whereall = '1=1';
    $joinall = '';
    if ($categoryid) {
        $joinall = ' JOIN {course} c ON c.id = i.courseid';
        $whereall .= ' AND c.category = :categoryid_all';
        $paramsall['categoryid_all'] = $categoryid;
    }
    $countall = $DB->get_field_sql("SELECT COUNT(1)
                                      FROM {block_validacursos_issues} i $joinall
                                     WHERE $whereall", $paramsall);

    $paramsopen = $paramsall;
    $whereopen = $whereall . ' AND i.resolvedat IS NULL';
    $countopen = $DB->get_field_sql("SELECT COUNT(1)
                                       FROM {block_validacursos_issues} i $joinall
                                      WHERE $whereopen", $paramsopen);

    // Total de aulas (cursos distintos) validadas.
    $totalcourses = $DB->get_field_sql("SELECT COUNT(DISTINCT i.courseid)
                                          FROM {block_validacursos_issues} i $joinall
                                         WHERE $whereall", $paramsall);

    // Cursos con al menos una incidencia abierta.
    $courseswithissues = $DB->get_field_sql("SELECT COUNT(DISTINCT i.courseid)
                                              FROM {block_validacursos_issues} i $joinall
                                             WHERE $whereopen", $paramsopen);

    $coursesnoissues = (int)$totalcourses - (int)$courseswithissues;
    $compliancerate = (int)$totalcourses > 0
        ? round(($coursesnoissues / (int)$totalcourses) * 100, 1)
        : 0;

    // Render estadísticas.
    echo html_writer::start_div('box generalbox mb-3');
    echo html_writer::div(
        html_writer::tag('strong', get_string('totalissues', 'block_validacursos') . ': ') . (int)$countall
        . ' &nbsp; | &nbsp; ' .
        html_writer::tag('strong', get_string('openissues', 'block_validacursos') . ': ') . (int)$countopen
        . ' &nbsp; | &nbsp; ' .
        html_writer::tag('strong', get_string('totalcoursesvalidated', 'block_validacursos') . ': ') . (int)$totalcourses
        . ' &nbsp; | &nbsp; ' .
        html_writer::tag('strong', get_string('compliancerate', 'block_validacursos') . ': ') . $compliancerate . '%',
        'mb-2'
    );
    echo html_writer::end_div();

    // ===== Gráficos =====
    // Datos: incidencias abiertas por tipo de validación.
    $paramsByVal = [];
    $whereByVal = 'i.resolvedat IS NULL';
    $joinByVal = '';
    if ($categoryid) {
        $joinByVal = ' JOIN {course} c ON c.id = i.courseid';
        $whereByVal .= ' AND c.category = :categoryid_byval';
        $paramsByVal['categoryid_byval'] = $categoryid;
    }
    $issuesByValidation = $DB->get_records_sql("
        SELECT i.validation, COUNT(1) AS total
          FROM {block_validacursos_issues} i $joinByVal
         WHERE $whereByVal
      GROUP BY i.validation
      ORDER BY total DESC
    ", $paramsByVal);

    echo html_writer::start_div('', ['style' => 'display:flex; flex-wrap:wrap; gap:2rem; margin-bottom:1.5rem; max-width:900px;']);

    // Gráfico de barras: incidencias por validación.
    if ($issuesByValidation) {
        $labels = [];
        $values = [];
        foreach ($issuesByValidation as $row) {
            $labels[] = $row->validation;
            $values[] = (int)$row->total;
        }

        $barseries = new \core\chart_series(
            get_string('openissues', 'block_validacursos'),
            $values
        );
        $barseries->set_color('#d9534f');

        $barchart = new \core\chart_bar();
        $barchart->set_title(get_string('issuesbyvalidation', 'block_validacursos'));
        $barchart->add_series($barseries);
        $barchart->set_labels($labels);
        $barchart->set_horizontal(true);

        echo html_writer::div($OUTPUT->render($barchart), '', ['style' => 'flex:2; min-width:300px; max-width:500px;']);
    }

    // Gráfico donut: cumplimiento.
    if ((int)$totalcourses > 0) {
        $pieseries = new \core\chart_series(
            get_string('complianceoverview', 'block_validacursos'),
            [$coursesnoissues, (int)$courseswithissues]
        );
        $pieseries->set_colors(['#5cb85c', '#d9534f']);

        $piechart = new \core\chart_pie();
        $piechart->set_doughnut(true);
        $piechart->set_title(get_string('complianceoverview', 'block_validacursos'));
        $piechart->add_series($pieseries);
        $piechart->set_labels([
            get_string('coursesnoissues', 'block_validacursos') . ' (' . $coursesnoissues . ')',
            get_string('courseswithissues', 'block_validacursos') . ' (' . (int)$courseswithissues . ')',
        ]);

        echo html_writer::div($OUTPUT->render($piechart), '', ['style' => 'flex:1; min-width:200px; max-width:350px;']);
    }

    echo html_writer::end_div();

    // Top cursos con más incidencias abiertas.
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

    // Botones de descarga.
    $csvurl = new moodle_url('/blocks/validacursos/report.php',
        ['download' => 'csv', 'show' => $show] + ($categoryid ? ['category' => $categoryid] : []));
    $excelurl = new moodle_url('/blocks/validacursos/report.php',
        ['download' => 'excel', 'show' => $show] + ($categoryid ? ['category' => $categoryid] : []));
    echo html_writer::div(
        html_writer::link($csvurl, get_string('downloadcsv', 'block_validacursos'), ['class' => 'btn btn-secondary mr-2'])
        . ' ' .
        html_writer::link($excelurl, get_string('downloadexcel', 'block_validacursos'), ['class' => 'btn btn-secondary']),
        'mb-3'
    );
}

// Tabla paginada (o CSV si download=csv).
$table->out(50, true);

if (!$table->is_downloading()) {
    echo $OUTPUT->footer();
}
