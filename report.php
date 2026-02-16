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
$validation = optional_param('validation', '', PARAM_TEXT);
$tab = optional_param('tab', 'top', PARAM_ALPHA);        // top|issues|validations
$page = optional_param('page', 0, PARAM_INT);
$semester = optional_param('semester', '', PARAM_ALPHA);  // first|second|''

// Categorías permitidas.
$allowedcsv = trim((string)get_config('block_validacursos', 'allowedcategories'));
$allowedids = [];
if ($allowedcsv !== '') {
    $allowedids = array_filter(array_map('intval', explode(',', $allowedcsv)));
}

$pagetitle = get_string('issuesreport', 'block_validacursos');
$PAGE->set_context($systemcontext);

// Construimos URL incluyendo filtros activos.
$pageparams = ['show' => $show, 'tab' => $tab];
if ($categoryid) {
    $pageparams['category'] = $categoryid;
}
if ($validation !== '') {
    $pageparams['validation'] = $validation;
}
if ($semester !== '') {
    $pageparams['semester'] = $semester;
}
$PAGE->set_url(new moodle_url('/blocks/validacursos/report.php', $pageparams));
$PAGE->set_pagelayout('report');

// Cláusula SQL para filtro de semestre (requiere alias "c" para course).
// Segundo semestre: fullname contiene "Segundo" o "-2S-".
// Primer semestre: fullname NO contiene "Segundo" ni "-2S-".
$semestersql = '';
$semesterparams = [];
if ($semester === 'first') {
    $semestersql = ' AND ' . $DB->sql_like('c.fullname', ':sem1', false, true, true)
                 . ' AND ' . $DB->sql_like('c.fullname', ':sem2', false, true, true);
    $semesterparams['sem1'] = '%Segundo%';
    $semesterparams['sem2'] = '%-2S-%';
} else if ($semester === 'second') {
    $semestersql = ' AND (' . $DB->sql_like('c.fullname', ':sem1', false)
                 . ' OR ' . $DB->sql_like('c.fullname', ':sem2', false) . ')';
    $semesterparams['sem1'] = '%Segundo%';
    $semesterparams['sem2'] = '%-2S-%';
}

// Obtener IDs de cursos hijos meta-enlazados (una sola consulta).
$metachildids = $DB->get_fieldset_sql("SELECT DISTINCT customint1 FROM {enrol} WHERE enrol = 'meta' AND customint1 IS NOT NULL");
if (!empty($metachildids)) {
    $metaexclude_c = ' AND c.id NOT IN (' . implode(',', $metachildids) . ')';
    $metaexclude_i = ' AND i.courseid NOT IN (' . implode(',', $metachildids) . ')';
} else {
    $metaexclude_c = '';
    $metaexclude_i = '';
}

$PAGE->set_title($pagetitle);
$PAGE->set_heading($pagetitle);

// ===== Exportación pestaña ok (dataformat estándar Moodle) =====
if ($tab === 'ok' && $download !== '') {
    $okparams = [];
    $okwhere = 'c.id != ' . SITEID;
    if ($categoryid) {
        $okwhere .= ' AND c.category = :categoryid_ok';
        $okparams['categoryid_ok'] = $categoryid;
    } else if (!empty($allowedids)) {
        $okwhere .= ' AND c.category IN (' . implode(',', $allowedids) . ')';
    }
    $records = $DB->get_records_sql("
        SELECT c.id AS courseid, c.shortname, c.fullname AS coursename
          FROM {course} c
         WHERE $okwhere $semestersql $metaexclude_c
           AND c.id NOT IN (
               SELECT DISTINCT i.courseid
                 FROM {block_validacursos_issues} i
                WHERE i.resolvedat IS NULL
           )
      ORDER BY c.fullname ASC
    ", $okparams + $semesterparams);

    $columns = [
        'courseid' => get_string('courseid', 'block_validacursos'),
        'shortname' => get_string('shortname'),
        'coursename' => get_string('course'),
    ];

    \core\dataformat::download_data('courses_without_issues', $download, $columns, $records, function($rec) {
        return [
            'courseid' => $rec->courseid,
            'shortname' => $rec->shortname,
            'coursename' => $rec->coursename,
        ];
    });
    die();
}

// ===== Exportación pestaña issues (dataformat estándar Moodle) =====
if ($tab === 'issues' && $download !== '') {
    $issuescoursesparams = [];
    $issuescourseswhere = 'i.resolvedat IS NULL';
    $issuescoursesjoin = '';
    if ($categoryid) {
        $issuescoursesjoin = ' JOIN {course_categories} cc ON cc.id = c.category';
        $issuescourseswhere .= ' AND c.category = :categoryid_ic';
        $issuescoursesparams['categoryid_ic'] = $categoryid;
    } else if (!empty($allowedids)) {
        $issuescoursesjoin = ' JOIN {course_categories} cc ON cc.id = c.category';
        $issuescourseswhere .= ' AND c.category IN (' . implode(',', $allowedids) . ')';
    }
    if ($validation !== '') {
        $issuescourseswhere .= ' AND i.validation = :validation_ic';
        $issuescoursesparams['validation_ic'] = $validation;
    }
    $records = $DB->get_records_sql("
        SELECT i.courseid, c.shortname, c.fullname AS coursename, COUNT(1) AS issues
          FROM {block_validacursos_issues} i
          JOIN {course} c ON c.id = i.courseid
          $issuescoursesjoin
         WHERE $issuescourseswhere $semestersql $metaexclude_i
      GROUP BY i.courseid, c.shortname, c.fullname
      ORDER BY issues DESC, c.fullname ASC
    ", $issuescoursesparams + $semesterparams);

    $columns = [
        'courseid' => get_string('courseid', 'block_validacursos'),
        'shortname' => get_string('shortname'),
        'coursename' => get_string('course'),
        'issues' => get_string('issues', 'block_validacursos'),
    ];

    \core\dataformat::download_data('courses_with_issues', $download, $columns, $records, function($rec) {
        return [
            'courseid' => $rec->courseid,
            'shortname' => $rec->shortname,
            'coursename' => $rec->coursename,
            'issues' => (int)$rec->issues,
        ];
    });
    die();
}

// ===== Action: enviar email a profesores =====
$sendemailcourseid = optional_param('sendemail', 0, PARAM_INT);
if ($sendemailcourseid) {
    require_sesskey();
    $course = $DB->get_record('course', ['id' => $sendemailcourseid], '*', MUST_EXIST);
    $coursecontext = context_course::instance($course->id);

    // Obtener incidencias abiertas del curso.
    $openissues = $DB->get_records_select('block_validacursos_issues',
        'courseid = :courseid AND resolvedat IS NULL',
        ['courseid' => $sendemailcourseid],
        'validation ASC');

    // Obtener profesores (editingteacher + teacher).
    $teachers = [];
    $roles = $DB->get_records_list('role', 'archetype', ['editingteacher', 'teacher'], '', 'id');
    if ($roles) {
        $roleids = array_keys($roles);
        list($insql, $inparams) = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'role');
        $inparams['contextid'] = $coursecontext->id;
        $teachers = $DB->get_records_sql("
            SELECT DISTINCT u.*
              FROM {role_assignments} ra
              JOIN {user} u ON u.id = ra.userid
             WHERE ra.contextid = :contextid
               AND ra.roleid $insql
               AND u.deleted = 0
        ", $inparams);
    }

    if (!empty($teachers) && !empty($openissues)) {
        // Construir cuerpo del email.
        $courseurl = new moodle_url('/course/view.php', ['id' => $course->id]);
        $a = new stdClass();
        $a->coursename = format_string($course->fullname);
        $messagetext = get_string('emailbody', 'block_validacursos', $a) . "\n\n";
        foreach ($openissues as $issue) {
            $messagetext .= "- " . $issue->validation . "\n";
        }
        $messagetext .= "\n" . get_string('emailcourselink', 'block_validacursos') . ': ' . $courseurl->out(false) . "\n";

        $subject = get_string('emailsubject', 'block_validacursos', format_string($course->fullname));
        $noreplyuser = core_user::get_noreply_user();
        $sentcount = 0;
        foreach ($teachers as $teacher) {
            if (email_to_user($teacher, $noreplyuser, $subject, $messagetext)) {
                $sentcount++;
            }
        }

        $redirectparams = $pageparams;
        $redirectparams['tab'] = 'top';
        $redirecturl = new moodle_url('/blocks/validacursos/report.php', $redirectparams);
        if ($sentcount > 0) {
            redirect($redirecturl, get_string('emailsent', 'block_validacursos', $sentcount), null, \core\output\notification::NOTIFY_SUCCESS);
        } else {
            redirect($redirecturl, get_string('emailsentfail', 'block_validacursos'), null, \core\output\notification::NOTIFY_ERROR);
        }
    } else {
        $redirectparams = $pageparams;
        $redirectparams['tab'] = 'top';
        $redirecturl = new moodle_url('/blocks/validacursos/report.php', $redirectparams);
        redirect($redirecturl, get_string('emailsentfail', 'block_validacursos'), null, \core\output\notification::NOTIFY_ERROR);
    }
}

// ===== Tabla SQL (con descarga integrada) =====
$uniqueid = 'block_validacursos_issues';
$table = new \block_validacursos\output\issues_table($uniqueid);
$validationsdownload = ($tab === 'validations' && $download !== '') ? $download : '';
$table->is_downloading($validationsdownload, 'validacursos_issues', 'validacursos_issues');
$table->show_download_buttons_at([TABLE_P_BOTTOM]);
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

$fields = 'i.id, i.courseid, c.shortname AS courseshortname, c.fullname AS coursename, c.visible AS coursevisible, ' .
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
} else if (!empty($allowedids)) {
    $whereparts[] = 'c.category IN (' . implode(',', $allowedids) . ')';
}
if ($validation !== '') {
    $whereparts[] = 'i.validation = :validation';
    $params['validation'] = $validation;
}
if ($semester === 'first') {
    $whereparts[] = $DB->sql_like('c.fullname', ':sem1', false, true, true);
    $whereparts[] = $DB->sql_like('c.fullname', ':sem2', false, true, true);
    $params['sem1'] = '%Segundo%';
    $params['sem2'] = '%-2S-%';
} else if ($semester === 'second') {
    $whereparts[] = '(' . $DB->sql_like('c.fullname', ':sem1', false)
                  . ' OR ' . $DB->sql_like('c.fullname', ':sem2', false) . ')';
    $params['sem1'] = '%Segundo%';
    $params['sem2'] = '%-2S-%';
}
$where = $whereparts ? implode(' AND ', $whereparts) : '1=1';
$where .= $metaexclude_i;

$table->set_sql($fields, $from, $where, $params);
$table->set_count_sql("SELECT COUNT(1) FROM $from WHERE $where", $params);

// ===== Render =====
if (!$table->is_downloading()) {
    echo $OUTPUT->header();

    // Pestañas conservando filtros.
    $filterparams = ($categoryid ? ['category' => $categoryid] : [])
        + ($validation !== '' ? ['validation' => $validation] : [])
        + ($semester !== '' ? ['semester' => $semester] : []);
    $urlopen = new moodle_url('/blocks/validacursos/report.php', ['show' => 'open'] + $filterparams);
    $urlall  = new moodle_url('/blocks/validacursos/report.php', ['show' => 'all'] + $filterparams);
    echo html_writer::div(
        html_writer::link($urlopen, get_string('showopen', 'block_validacursos'), ['style' => $show !== 'open' ? '' : 'font-weight:bold'])
        . ' | ' .
        html_writer::link($urlall, get_string('showall', 'block_validacursos'), ['style' => $show !== 'all' ? '' : 'font-weight:bold']),
        'mb-2'
    );

    // Filtros: categoría y validación.
    $cats = core_course_category::make_categories_list();
    if (!empty($allowedids)) {
        $cats = array_intersect_key($cats, array_flip($allowedids));
    }
    $validationrows = $DB->get_records_sql("SELECT DISTINCT validation FROM {block_validacursos_issues} ORDER BY validation ASC");
    $validationoptions = [];
    foreach ($validationrows as $row) {
        $validationoptions[$row->validation] = $row->validation;
    }
    echo html_writer::start_tag('form', ['method' => 'get', 'action' => $PAGE->url->out(false)]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'show', 'value' => $show]);
    echo html_writer::select(['0' => get_string('allcategories')] + $cats, 'category', $categoryid, null,
        ['onchange' => 'this.form.submit()']);
    echo ' ';
    echo html_writer::select(['' => get_string('allvalidations', 'block_validacursos')] + $validationoptions,
        'validation', $validation, null, ['onchange' => 'this.form.submit()']);
    echo ' ';
    echo html_writer::select([
        '' => get_string('allsemesters', 'block_validacursos'),
        'first' => get_string('firstsemester', 'block_validacursos'),
        'second' => get_string('secondsemester', 'block_validacursos'),
    ], 'semester', $semester, null, ['onchange' => 'this.form.submit()']);
    echo html_writer::end_tag('form');

    // ===== Estadísticas =====
    // Total issues y open issues.
    $paramsall = [];
    $whereall = '1=1';
    $joinall = '';
    if ($categoryid || !empty($allowedids) || $semester !== '') {
        $joinall = ' JOIN {course} c ON c.id = i.courseid';
    }
    if ($categoryid) {
        $whereall .= ' AND c.category = :categoryid_all';
        $paramsall['categoryid_all'] = $categoryid;
    } else if (!empty($allowedids)) {
        $whereall .= ' AND c.category IN (' . implode(',', $allowedids) . ')';
    }
    if ($semester !== '') {
        $whereall .= ' ' . $semestersql;
        $paramsall = $paramsall + $semesterparams;
    }
    if ($validation !== '') {
        $whereall .= ' AND i.validation = :validation_all';
        $paramsall['validation_all'] = $validation;
    }
    $countall = $DB->get_field_sql("SELECT COUNT(1)
                                      FROM {block_validacursos_issues} i $joinall
                                     WHERE $whereall $metaexclude_i", $paramsall);

    $paramsopen = $paramsall;
    $whereopen = $whereall . ' AND i.resolvedat IS NULL';
    $countopen = $DB->get_field_sql("SELECT COUNT(1)
                                       FROM {block_validacursos_issues} i $joinall
                                      WHERE $whereopen $metaexclude_i", $paramsopen);

    // Total real de cursos en las categorías permitidas.
    $totalcoursesparams = [];
    $totalcourseswhere = 'c.id != ' . SITEID;
    if ($categoryid) {
        $totalcourseswhere .= ' AND c.category = :categoryid_total';
        $totalcoursesparams['categoryid_total'] = $categoryid;
    } else if (!empty($allowedids)) {
        $totalcourseswhere .= ' AND c.category IN (' . implode(',', $allowedids) . ')';
    }
    $totalcourses = $DB->get_field_sql("SELECT COUNT(1) FROM {course} c WHERE $totalcourseswhere $semestersql $metaexclude_c", $totalcoursesparams + $semesterparams);

    // Cursos con al menos una incidencia abierta.
    $courseswithissues = $DB->get_field_sql("SELECT COUNT(DISTINCT i.courseid)
                                              FROM {block_validacursos_issues} i $joinall
                                             WHERE $whereopen $metaexclude_i", $paramsopen);

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
    if ($categoryid || !empty($allowedids) || $semester !== '') {
        $joinByVal = ' JOIN {course} c ON c.id = i.courseid';
    }
    if ($categoryid) {
        $whereByVal .= ' AND c.category = :categoryid_byval';
        $paramsByVal['categoryid_byval'] = $categoryid;
    } else if (!empty($allowedids)) {
        $whereByVal .= ' AND c.category IN (' . implode(',', $allowedids) . ')';
    }
    if ($semester !== '') {
        $whereByVal .= ' ' . $semestersql;
        $paramsByVal = $paramsByVal + $semesterparams;
    }
    if ($validation !== '') {
        $whereByVal .= ' AND i.validation = :validation_byval';
        $paramsByVal['validation_byval'] = $validation;
    }
    $issuesByValidation = $DB->get_records_sql("
        SELECT i.validation, COUNT(1) AS total
          FROM {block_validacursos_issues} i $joinByVal
         WHERE $whereByVal $metaexclude_i
      GROUP BY i.validation
      ORDER BY total DESC
    ", $paramsByVal);

    echo html_writer::start_div('', ['style' => 'display:flex; flex-wrap:wrap; gap:2rem; margin-bottom:1.5rem; max-width:1000px; align-items:flex-start;']);

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

        echo html_writer::div($OUTPUT->render($piechart), '', ['style' => 'flex:0 1 auto; min-width:150px; max-width:250px;']);
    }

    echo html_writer::end_div();

    // ===== Pestañas =====
    $tabparams = $filterparams + ['show' => $show];
    $urltop = new moodle_url('/blocks/validacursos/report.php', ['tab' => 'top'] + $tabparams);
    $urlissues = new moodle_url('/blocks/validacursos/report.php', ['tab' => 'issues'] + $tabparams);
    $urlok = new moodle_url('/blocks/validacursos/report.php', ['tab' => 'ok'] + $tabparams);
    $urlvalidations = new moodle_url('/blocks/validacursos/report.php', ['tab' => 'validations'] + $tabparams);

    echo '<ul class="nav nav-tabs mb-3" role="tablist">';
    echo '<li class="nav-item"><a class="nav-link' . ($tab === 'top' ? ' active' : '') . '" href="' . $urltop . '">'
        . get_string('tabtopcoursesopen', 'block_validacursos') . '</a></li>';
    echo '<li class="nav-item"><a class="nav-link' . ($tab === 'issues' ? ' active' : '') . '" href="' . $urlissues . '">'
        . get_string('tabcourseswithissues', 'block_validacursos') . '</a></li>';
    echo '<li class="nav-item"><a class="nav-link' . ($tab === 'ok' ? ' active' : '') . '" href="' . $urlok . '">'
        . get_string('tabcoursesok', 'block_validacursos') . '</a></li>';
    echo '<li class="nav-item"><a class="nav-link' . ($tab === 'validations' ? ' active' : '') . '" href="' . $urlvalidations . '">'
        . get_string('tabvalidations', 'block_validacursos') . '</a></li>';
    echo '</ul>';

    if ($tab === 'top') {
        // Top cursos con más incidencias abiertas.
        $topparams = [];
        $topwhere = '1=1 AND i.resolvedat IS NULL';
        if ($categoryid) {
            $topwhere .= ' AND c.category = :categoryid_top';
            $topparams['categoryid_top'] = $categoryid;
        } else if (!empty($allowedids)) {
            $topwhere .= ' AND c.category IN (' . implode(',', $allowedids) . ')';
        }
        if ($validation !== '') {
            $topwhere .= ' AND i.validation = :validation_top';
            $topparams['validation_top'] = $validation;
        }
        if ($semester !== '') {
            $topwhere .= ' ' . $semestersql;
            $topparams = $topparams + $semesterparams;
        }
        $topcourses = $DB->get_records_sql("
            SELECT i.courseid, c.fullname AS coursename, COUNT(1) AS issues
              FROM {block_validacursos_issues} i
              JOIN {course} c ON c.id = i.courseid
             WHERE $topwhere $metaexclude_i
          GROUP BY i.courseid, c.fullname
          ORDER BY issues DESC, c.fullname ASC
             LIMIT 10
        ", $topparams);

        if ($topcourses) {
            $rows = [];
            $confirmstr = addslashes_js(get_string('sendemailconfirm', 'block_validacursos'));
            foreach ($topcourses as $tc) {
                $courseurl = new moodle_url('/course/view.php', ['id' => $tc->courseid]);
                $emailurl = new moodle_url('/blocks/validacursos/report.php',
                    ['sendemail' => $tc->courseid, 'sesskey' => sesskey()] + $pageparams + ['tab' => 'top']);
                $emailbtn = html_writer::link($emailurl->out(false),
                    '&#9993; ' . get_string('sendemail', 'block_validacursos'),
                    ['class' => 'btn btn-sm btn-outline-primary',
                     'onclick' => 'return confirm("' . $confirmstr . '")']);
                $rows[] = html_writer::tag('tr',
                    html_writer::tag('td', html_writer::link($courseurl, format_string($tc->coursename))) .
                    html_writer::tag('td', (int)$tc->issues, ['style' => 'text-align:right']) .
                    html_writer::tag('td', $emailbtn, ['style' => 'text-align:center'])
                );
            }
            $tablehtml = html_writer::tag('table',
                html_writer::tag('thead', html_writer::tag('tr',
                    html_writer::tag('th', get_string('course')) .
                    html_writer::tag('th', get_string('issues', 'block_validacursos'), ['style' => 'text-align:right']) .
                    html_writer::tag('th', '', ['style' => 'text-align:center'])
                )) .
                html_writer::tag('tbody', implode('', $rows)),
                ['class' => 'generaltable boxaligncenter', 'style' => 'margin-top: .5rem;']
            );
            echo html_writer::tag('div', $tablehtml, ['class' => 'box generalbox mb-3']);
        } else {
            echo html_writer::div(get_string('coursesnoissues', 'block_validacursos'), 'alert alert-success');
        }

    } else if ($tab === 'issues') {
        // Selector de descarga estándar Moodle.
        $downloadparamsissues = ['tab' => 'issues', 'show' => $show] + $filterparams;
        echo $OUTPUT->download_dataformat_selector(
            get_string('downloadas', 'table'),
            '/blocks/validacursos/report.php',
            'download',
            $downloadparamsissues
        );

        // Cursos con incidencias abiertas (listado paginado).
        $perpage = 50;
        $issuescoursesparams = [];
        $issuescourseswhere = 'i.resolvedat IS NULL';
        $issuescoursesjoin = '';
        if ($categoryid) {
            $issuescoursesjoin = ' JOIN {course_categories} cc ON cc.id = c.category';
            $issuescourseswhere .= ' AND c.category = :categoryid_ic';
            $issuescoursesparams['categoryid_ic'] = $categoryid;
        } else if (!empty($allowedids)) {
            $issuescoursesjoin = ' JOIN {course_categories} cc ON cc.id = c.category';
            $issuescourseswhere .= ' AND c.category IN (' . implode(',', $allowedids) . ')';
        }
        if ($validation !== '') {
            $issuescourseswhere .= ' AND i.validation = :validation_ic';
            $issuescoursesparams['validation_ic'] = $validation;
        }
        if ($semester !== '') {
            $issuescourseswhere .= ' ' . $semestersql;
            $issuescoursesparams = $issuescoursesparams + $semesterparams;
        }

        $totalcoursesWithIssues = $DB->get_field_sql("
            SELECT COUNT(1) FROM (
                SELECT i.courseid
                  FROM {block_validacursos_issues} i
                  JOIN {course} c ON c.id = i.courseid
                  $issuescoursesjoin
                 WHERE $issuescourseswhere $metaexclude_i
              GROUP BY i.courseid
            ) subq
        ", $issuescoursesparams);

        $courseswithissueslist = $DB->get_records_sql("
            SELECT i.courseid, c.fullname AS coursename, c.visible, COUNT(1) AS issues
              FROM {block_validacursos_issues} i
              JOIN {course} c ON c.id = i.courseid
              $issuescoursesjoin
             WHERE $issuescourseswhere $metaexclude_i
          GROUP BY i.courseid, c.fullname, c.visible
          ORDER BY issues DESC, c.fullname ASC
        ", $issuescoursesparams, $page * $perpage, $perpage);

        if ($courseswithissueslist) {
            $rows = [];
            foreach ($courseswithissueslist as $cw) {
                $courseurl = new moodle_url('/course/view.php', ['id' => $cw->courseid]);
                $attrs = empty($cw->visible) ? ['class' => 'dimmed_text'] : [];
                $rows[] = html_writer::tag('tr',
                    html_writer::tag('td', html_writer::link($courseurl, format_string($cw->coursename), $attrs)) .
                    html_writer::tag('td', (int)$cw->issues, ['style' => 'text-align:right'])
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
            echo html_writer::tag('div', $tablehtml, ['class' => 'box generalbox mb-3']);
            $pagingurl = new moodle_url('/blocks/validacursos/report.php', $tabparams + ['tab' => 'issues']);
            echo $OUTPUT->paging_bar($totalcoursesWithIssues, $page, $perpage, $pagingurl);
        } else {
            echo html_writer::div(get_string('coursesnoissues', 'block_validacursos'), 'alert alert-success');
        }

    } else if ($tab === 'ok') {
        // Selector de descarga estándar Moodle.
        $downloadparamsok = ['tab' => 'ok', 'show' => $show] + $filterparams;
        echo $OUTPUT->download_dataformat_selector(
            get_string('downloadas', 'table'),
            '/blocks/validacursos/report.php',
            'download',
            $downloadparamsok
        );

        // Cursos sin incidencias abiertas (listado paginado).
        $perpage = 50;
        $okparams = [];
        $okwhere = 'c.id != ' . SITEID;
        if ($categoryid) {
            $okwhere .= ' AND c.category = :categoryid_ok';
            $okparams['categoryid_ok'] = $categoryid;
        } else if (!empty($allowedids)) {
            $okwhere .= ' AND c.category IN (' . implode(',', $allowedids) . ')';
        }
        if ($semester !== '') {
            $okwhere .= ' ' . $semestersql;
            $okparams = $okparams + $semesterparams;
        }

        $totalcoursesok = $DB->get_field_sql("
            SELECT COUNT(1)
              FROM {course} c
             WHERE $okwhere $metaexclude_c
               AND c.id NOT IN (
                   SELECT DISTINCT i.courseid
                     FROM {block_validacursos_issues} i
                    WHERE i.resolvedat IS NULL
               )
        ", $okparams);

        $coursesok = $DB->get_records_sql("
            SELECT c.id AS courseid, c.fullname AS coursename, c.visible
              FROM {course} c
             WHERE $okwhere $metaexclude_c
               AND c.id NOT IN (
                   SELECT DISTINCT i.courseid
                     FROM {block_validacursos_issues} i
                    WHERE i.resolvedat IS NULL
               )
          ORDER BY c.fullname ASC
        ", $okparams, $page * $perpage, $perpage);

        if ($coursesok) {
            $rows = [];
            foreach ($coursesok as $co) {
                $courseurl = new moodle_url('/course/view.php', ['id' => $co->courseid]);
                $attrs = empty($co->visible) ? ['class' => 'dimmed_text'] : [];
                $rows[] = html_writer::tag('tr',
                    html_writer::tag('td', html_writer::link($courseurl, format_string($co->coursename), $attrs))
                );
            }
            $tablehtml = html_writer::tag('table',
                html_writer::tag('thead', html_writer::tag('tr',
                    html_writer::tag('th', get_string('course'))
                )) .
                html_writer::tag('tbody', implode('', $rows)),
                ['class' => 'generaltable boxaligncenter', 'style' => 'margin-top: .5rem;']
            );
            echo html_writer::tag('div', $tablehtml, ['class' => 'box generalbox mb-3']);
            $pagingurlok = new moodle_url('/blocks/validacursos/report.php', $tabparams + ['tab' => 'ok']);
            echo $OUTPUT->paging_bar($totalcoursesok, $page, $perpage, $pagingurlok);
        } else {
            echo html_writer::div(get_string('nocoursesok', 'block_validacursos'), 'alert alert-warning');
        }

    } else if ($tab === 'validations') {
        // La tabla SQL muestra su propio selector de descarga.
    }
}

// Tabla paginada (o CSV si download=csv) — solo en pestaña validations.
if ($tab === 'validations' || $table->is_downloading()) {
    $table->out(50, true);
}

if (!$table->is_downloading()) {
    echo $OUTPUT->footer();
}
