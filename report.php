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
$tab = optional_param('tab', 'top', PARAM_ALPHA);        // top|issues|ok|validations
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

// ===== Configuración común =====
$dbfamily = $DB->get_dbfamily();
$contextcourselevel = CONTEXT_COURSE;

// Subconsulta de profesores (reutilizable).
if ($dbfamily === 'postgres') {
    $teachersubquery = "(SELECT string_agg(COALESCE(u.firstname,'') || ' ' || COALESCE(u.lastname,''), ', ' ORDER BY u.lastname, u.firstname)
                         FROM {role_assignments} ra
                         JOIN {context} ctx ON ctx.id = ra.contextid
                         JOIN {role} r ON r.id = ra.roleid
                         JOIN {user} u ON u.id = ra.userid
                        WHERE ctx.contextlevel = :ctxlevel AND ctx.instanceid = i.courseid
                          AND r.archetype IN ('editingteacher','teacher') AND u.deleted = 0)";
} else {
    $teachersubquery = "(SELECT GROUP_CONCAT(CONCAT(u.firstname, ' ', u.lastname) ORDER BY u.lastname SEPARATOR ', ')
                         FROM {role_assignments} ra
                         JOIN {context} ctx ON ctx.id = ra.contextid
                         JOIN {role} r ON r.id = ra.roleid
                         JOIN {user} u ON u.id = ra.userid
                        WHERE ctx.contextlevel = :ctxlevel AND ctx.instanceid = i.courseid
                          AND r.archetype IN ('editingteacher','teacher') AND u.deleted = 0)";
}

// Filtros comunes para issues (reutilizables en top/issues).
$issueswhere_parts = ['i.resolvedat IS NULL'];
$issuesparams_base = [];
if ($categoryid) {
    $issueswhere_parts[] = 'c.category = :categoryid';
    $issuesparams_base['categoryid'] = $categoryid;
} else if (!empty($allowedids)) {
    $issueswhere_parts[] = 'c.category IN (' . implode(',', $allowedids) . ')';
}
if ($validation !== '') {
    $issueswhere_parts[] = 'i.validation = :validation';
    $issuesparams_base['validation'] = $validation;
}
if ($semester === 'first') {
    $issueswhere_parts[] = $DB->sql_like('c.fullname', ':sem1', false, true, true);
    $issueswhere_parts[] = $DB->sql_like('c.fullname', ':sem2', false, true, true);
    $issuesparams_base['sem1'] = '%Segundo%';
    $issuesparams_base['sem2'] = '%-2S-%';
} else if ($semester === 'second') {
    $issueswhere_parts[] = '(' . $DB->sql_like('c.fullname', ':sem1', false)
                          . ' OR ' . $DB->sql_like('c.fullname', ':sem2', false) . ')';
    $issuesparams_base['sem1'] = '%Segundo%';
    $issuesparams_base['sem2'] = '%-2S-%';
}
$issueswhere_base = implode(' AND ', $issueswhere_parts) . $metaexclude_i;

// ===== Configurar tabla activa según pestaña =====
$activetable = null;
$downloading = false;

if ($tab === 'top') {
    $activetable = new \block_validacursos\output\top_courses_table('block_validacursos_top', $pageparams);
    $topdl = $download !== '' ? $download : '';
    $activetable->is_downloading($topdl, 'top_courses_issues', 'top_courses_issues');
    $activetable->show_download_buttons_at([TABLE_P_BOTTOM]);
    $activetable->define_baseurl($PAGE->url);

    $topsubquery = "(SELECT i.courseid, c.fullname AS coursename, COUNT(1) AS issues
                       FROM {block_validacursos_issues} i
                       JOIN {course} c ON c.id = i.courseid
                      WHERE $issueswhere_base
                   GROUP BY i.courseid, c.fullname)";
    $activetable->set_sql('*', "$topsubquery subq", '1=1', $issuesparams_base);
    $activetable->set_count_sql("SELECT COUNT(*) FROM $topsubquery subq_count", $issuesparams_base);
    $downloading = $activetable->is_downloading();

} else if ($tab === 'issues') {
    $activetable = new \block_validacursos\output\issues_courses_table('block_validacursos_issues_courses');
    $issuesdl = $download !== '' ? $download : '';
    $activetable->is_downloading($issuesdl, 'courses_with_issues', 'courses_with_issues');
    $activetable->show_download_buttons_at([TABLE_P_BOTTOM]);
    $activetable->define_baseurl($PAGE->url);

    // Agregación de validaciones fallidas.
    if ($dbfamily === 'postgres') {
        $validationsagg = "string_agg(DISTINCT i.validation, ', ' ORDER BY i.validation)";
    } else {
        $validationsagg = "GROUP_CONCAT(DISTINCT i.validation ORDER BY i.validation SEPARATOR ', ')";
    }

    $issuessubquery = "(SELECT i.courseid, c.fullname AS coursename, c.visible AS coursevisible,
                               cc.name AS categoryname, COUNT(1) AS issues,
                               $teachersubquery AS teachers,
                               $validationsagg AS failedvalidations
                          FROM {block_validacursos_issues} i
                          JOIN {course} c ON c.id = i.courseid
                          JOIN {course_categories} cc ON cc.id = c.category
                         WHERE $issueswhere_base
                      GROUP BY i.courseid, c.fullname, c.visible, cc.name)";
    $issuessubparams = $issuesparams_base + ['ctxlevel' => $contextcourselevel];
    $activetable->set_sql('*', "$issuessubquery subq", '1=1', $issuessubparams);
    $activetable->set_count_sql("SELECT COUNT(*) FROM (SELECT i.courseid
                          FROM {block_validacursos_issues} i
                          JOIN {course} c ON c.id = i.courseid
                          JOIN {course_categories} cc ON cc.id = c.category
                         WHERE $issueswhere_base
                      GROUP BY i.courseid) subq_count", $issuesparams_base);
    $downloading = $activetable->is_downloading();

} else if ($tab === 'ok') {
    $activetable = new \block_validacursos\output\ok_courses_table('block_validacursos_ok');
    $okdl = $download !== '' ? $download : '';
    $activetable->is_downloading($okdl, 'courses_without_issues', 'courses_without_issues');
    $activetable->show_download_buttons_at([TABLE_P_BOTTOM]);
    $activetable->define_baseurl($PAGE->url);

    $okwhere_parts = ['c.id != ' . SITEID];
    $okparams = [];
    if ($categoryid) {
        $okwhere_parts[] = 'c.category = :categoryid_ok';
        $okparams['categoryid_ok'] = $categoryid;
    } else if (!empty($allowedids)) {
        $okwhere_parts[] = 'c.category IN (' . implode(',', $allowedids) . ')';
    }
    if ($semester === 'first') {
        $okwhere_parts[] = $DB->sql_like('c.fullname', ':sem1', false, true, true);
        $okwhere_parts[] = $DB->sql_like('c.fullname', ':sem2', false, true, true);
        $okparams['sem1'] = '%Segundo%';
        $okparams['sem2'] = '%-2S-%';
    } else if ($semester === 'second') {
        $okwhere_parts[] = '(' . $DB->sql_like('c.fullname', ':sem1', false)
                          . ' OR ' . $DB->sql_like('c.fullname', ':sem2', false) . ')';
        $okparams['sem1'] = '%Segundo%';
        $okparams['sem2'] = '%-2S-%';
    }
    $okwhere = implode(' AND ', $okwhere_parts) . $metaexclude_c
             . ' AND c.id NOT IN (SELECT DISTINCT i.courseid FROM {block_validacursos_issues} i WHERE i.resolvedat IS NULL)';

    $activetable->set_sql('c.id AS courseid, c.fullname AS coursename, c.visible AS coursevisible',
                          '{course} c', $okwhere, $okparams);
    $activetable->set_count_sql("SELECT COUNT(1) FROM {course} c WHERE $okwhere", $okparams);
    $downloading = $activetable->is_downloading();

} else {
    // Tab: validations.
    $activetable = new \block_validacursos\output\issues_table('block_validacursos_issues');
    $validationsdl = $download !== '' ? $download : '';
    $activetable->is_downloading($validationsdl, 'validacursos_issues', 'validacursos_issues');
    $activetable->show_download_buttons_at([TABLE_P_BOTTOM]);
    $activetable->define_baseurl($PAGE->url);

    $fields = 'i.id, i.courseid, c.shortname AS courseshortname, c.fullname AS coursename, c.visible AS coursevisible, ' .
              'cc.name AS categoryname, ' .
              'i.validation, i.firstseen, i.lastseen, i.resolvedat, ' . $teachersubquery . ' AS teachers';

    $from = '{block_validacursos_issues} i
             JOIN {course} c ON c.id = i.courseid
             JOIN {course_categories} cc ON cc.id = c.category';

    $vwhereparts = [];
    $vparams = [];
    $vparams['ctxlevel'] = $contextcourselevel;
    if ($show !== 'all') {
        $vwhereparts[] = 'i.resolvedat IS NULL';
    }
    if ($categoryid) {
        $vwhereparts[] = 'c.category = :categoryid';
        $vparams['categoryid'] = $categoryid;
    } else if (!empty($allowedids)) {
        $vwhereparts[] = 'c.category IN (' . implode(',', $allowedids) . ')';
    }
    if ($validation !== '') {
        $vwhereparts[] = 'i.validation = :validation';
        $vparams['validation'] = $validation;
    }
    if ($semester === 'first') {
        $vwhereparts[] = $DB->sql_like('c.fullname', ':sem1', false, true, true);
        $vwhereparts[] = $DB->sql_like('c.fullname', ':sem2', false, true, true);
        $vparams['sem1'] = '%Segundo%';
        $vparams['sem2'] = '%-2S-%';
    } else if ($semester === 'second') {
        $vwhereparts[] = '(' . $DB->sql_like('c.fullname', ':sem1', false)
                        . ' OR ' . $DB->sql_like('c.fullname', ':sem2', false) . ')';
        $vparams['sem1'] = '%Segundo%';
        $vparams['sem2'] = '%-2S-%';
    }
    $vwhere = $vwhereparts ? implode(' AND ', $vwhereparts) : '1=1';
    $vwhere .= $metaexclude_i;

    $activetable->set_sql($fields, $from, $vwhere, $vparams);
    $activetable->set_count_sql("SELECT COUNT(1) FROM $from WHERE $vwhere", $vparams);
    $downloading = $activetable->is_downloading();
}

// ===== Render =====
if (!$downloading) {
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

    $totalcoursesparams = [];
    $totalcourseswhere = 'c.id != ' . SITEID;
    if ($categoryid) {
        $totalcourseswhere .= ' AND c.category = :categoryid_total';
        $totalcoursesparams['categoryid_total'] = $categoryid;
    } else if (!empty($allowedids)) {
        $totalcourseswhere .= ' AND c.category IN (' . implode(',', $allowedids) . ')';
    }
    $totalcourses = $DB->get_field_sql("SELECT COUNT(1) FROM {course} c WHERE $totalcourseswhere $semestersql $metaexclude_c", $totalcoursesparams + $semesterparams);

    $courseswithissues = $DB->get_field_sql("SELECT COUNT(DISTINCT i.courseid)
                                              FROM {block_validacursos_issues} i $joinall
                                             WHERE $whereopen $metaexclude_i", $paramsopen);

    $coursesnoissues = (int)$totalcourses - (int)$courseswithissues;
    $compliancerate = (int)$totalcourses > 0
        ? round(($coursesnoissues / (int)$totalcourses) * 100, 1)
        : 0;

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
}

// Renderizar la tabla activa (funciona tanto para descarga como para visualización).
$activetable->out(50, true);

if (!$downloading) {
    echo $OUTPUT->footer();
}
