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
 * Block Validacursos
 *
 * Documentation: {@link https://moodledev.io/docs/apis/plugintypes/blocks}
 *
 * @package    block_validacursos
 * @copyright  2025 Sergio Comerón <info@sergiocomeron.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class block_validacursos extends block_base {

    /**
     * Block initialisation
     */
    public function init() {
        $this->title = get_string('pluginname', 'block_validacursos');
    }

    /**
     * Ejecuta todas las validaciones y devuelve un array con los resultados.
     *
     * @param object $course
     * @param object $config
     * @return array
     */
    private function obtener_validaciones($course, $config) {
        return \block_validacursos\local\validator::get_validaciones($course, $config);
    }

    /**
     * Crea un foro de tutorías de la asignatura en la sección 0 del curso si no existe.
     *
     * @param object $course
     * @return bool|int Devuelve el id del foro creado o false si falla.
     */
    private function crear_foro_tutorias($course) {
        global $DB, $USER;

        // Obtener la sección 0
        $section0 = $DB->get_record('course_sections', ['course' => $course->id, 'section' => 0], '*', MUST_EXIST);

        // Crear el foro
        $forum = new stdClass();
        $forum->course = $course->id;
        $forum->type = 'general';
        $forum->name = 'Foro de tutorías de la asignatura';
        $forum->intro = 'Foro para tutorías de la asignatura.';
        $forum->introformat = FORMAT_HTML;
        $forum->assessed = 0;
        $forum->forcesubscribe = 1;
        $forum->trackingtype = 1;
        $forum->timemodified = time();
        $forum->timecreated = time();

        $forumid = $DB->insert_record('forum', $forum);

        // Obtener el id del módulo forum
        $forum_module_id = $DB->get_field('modules', 'id', ['name' => 'forum'], MUST_EXIST);

        // Crear el course_module
        $cm = new stdClass();
        $cm->course = $course->id;
        $cm->module = $forum_module_id;
        $cm->instance = $forumid;
        $cm->section = $section0->id;
        $cm->added = time();
        $cm->visible = 1;
        $cm->visibleold = 1;
        $cm->groupmode = 0;
        $cm->groupingid = 0;
        $cm->completion = 0;
        $cm->completiongradeitemnumber = null;
        $cm->completionview = 0;
        $cm->completionexpected = 0;
        $cm->showdescription = 0;
        $cmid = $DB->insert_record('course_modules', $cm);

        // Añadir el módulo a la sección 0
        $sequence = trim($section0->sequence);
        $sequence = $sequence ? $sequence . ',' . $cmid : $cmid;
        $DB->set_field('course_sections', 'sequence', $sequence, ['id' => $section0->id]);

        // Actualizar el campo modinfo del curso
        rebuild_course_cache($course->id, true);

        return $forumid;
    }

    /**
     * Crea un foro de comunicación entre estudiantes en la sección 0 del curso si no existe.
     *
     * @param object $course
     * @return bool|int Devuelve el id del foro creado o false si falla.
     */
    private function crear_foro_estudiantes($course) {
        global $DB, $USER;

        // Obtener la sección 0
        $section0 = $DB->get_record('course_sections', ['course' => $course->id, 'section' => 0], '*', MUST_EXIST);

        // Crear el foro
        $forum = new stdClass();
        $forum->course = $course->id;
        $forum->type = 'general';
        $forum->name = 'Foro de comunicación entre estudiantes';
        $forum->intro = 'Foro para la comunicación entre estudiantes.';
        $forum->introformat = FORMAT_HTML;
        $forum->assessed = 0;
        $forum->forcesubscribe = 1;
        $forum->trackingtype = 1;
        $forum->timemodified = time();
        $forum->timecreated = time();

        $forumid = $DB->insert_record('forum', $forum);

        // Obtener el id del módulo forum
        $forum_module_id = $DB->get_field('modules', 'id', ['name' => 'forum'], MUST_EXIST);

        // Crear el course_module
        $cm = new stdClass();
        $cm->course = $course->id;
        $cm->module = $forum_module_id;
        $cm->instance = $forumid;
        $cm->section = $section0->id;
        $cm->added = time();
        $cm->visible = 1;
        $cm->visibleold = 1;
        $cm->groupmode = 0;
        $cm->groupingid = 0;
        $cm->completion = 0;
        $cm->completiongradeitemnumber = null;
        $cm->completionview = 0;
        $cm->completionexpected = 0;
        $cm->showdescription = 0;
        $cmid = $DB->insert_record('course_modules', $cm);

        // Añadir el módulo a la sección 0
        $sequence = trim($section0->sequence);
        $sequence = $sequence ? $sequence . ',' . $cmid : $cmid;
        $DB->set_field('course_sections', 'sequence', $sequence, ['id' => $section0->id]);

        // Actualizar el campo modinfo del curso
        rebuild_course_cache($course->id, true);

        return $forumid;
    }

    /**
     * Get content
     *
     * @return stdClass
     */
    public function get_content() {
        global $COURSE, $PAGE, $DB;

        // Solo mostrar el bloque si el usuario tiene la capacidad block/validacursos:view
        $context = context_course::instance($COURSE->id);
        if (!has_capability('block/validacursos:view', $context)) {
            return null;
        }

        if ($this->content !== null) {
            return $this->content;
        }

        $config = get_config('block_validacursos');

        // Filtro por categorías permitidas (simple: coincidencia exacta).
        $config = get_config('block_validacursos');
        $allowedcsv = isset($config->allowedcategories) ? trim((string)$config->allowedcategories) : '';
        if ($allowedcsv !== '') {
            $allowed = array_filter(array_map('intval', explode(',', $allowedcsv)));
            if (!in_array((int)$COURSE->category, $allowed, true)) {
                return null; // Fuera de las categorías permitidas: no mostrar el bloque.
            }
        }

        $validaciones = $this->obtener_validaciones($COURSE, $config);

        // Registrar incidencias negativas del curso en la tabla del bloque.
        try {
            \block_validacursos\local\logger::save_course_results_history($COURSE->id, $validaciones);
        } catch (\Throwable $e) {
            // No interrumpir el render del bloque si falla el guardado.
        }

        // Procesar cambio de fecha si se solicita y el usuario tiene permisos
        if (optional_param('changestartdate', 0, PARAM_INT)) {
            require_capability('moodle/course:update', $context);
            if (!empty($config->fechainiciovalidacion)) {
                // Recarga el objeto curso para asegurar el ID
                $courseid = $COURSE->id ?? optional_param('id', 0, PARAM_INT);
                if ($courseid) {
                    $DB->set_field('course', 'startdate', $config->fechainiciovalidacion, ['id' => $courseid]);
                    redirect(new moodle_url('/course/view.php', ['id' => $courseid]), 'Fecha de inicio actualizada', 2);
                } else {
                    print_error('missingcourseid', 'block_validacursos');
                }
            }
        }

        if (optional_param('createcronogramaactividades', 0, PARAM_INT)) {
            require_capability('moodle/course:manageactivities', $context);
            global $CFG;
            require_once($CFG->dirroot . '/course/lib.php');
            require_once($CFG->dirroot . '/course/modlib.php');

            $plugindir = \core_component::get_plugin_directory('block', 'validacursos');
            $tmplfile = $plugindir . '/content/cronograma_actividades.html';
            $introhtml = is_readable($tmplfile) ? file_get_contents($tmplfile) : '<p>Cronograma de actividades calificables</p>';

            $data = new stdClass();
            $data->course = $COURSE->id;
            $data->modulename = 'label';
            $data->section = 0;
            $data->visible = 1;
            $data->visibleoncoursepage = 1;
            $data->showdescription = 0;
            $data->completion = 0;
            $data->intro = $introhtml;
            $data->introformat = FORMAT_HTML;
            $data->introeditor = [
                'text' => $introhtml,
                'format' => FORMAT_HTML,
                'itemid' => 0
            ];
            $data->name = 'Cronograma de actividades calificables';

            create_module($data);
            rebuild_course_cache($COURSE->id, true);
            redirect(new moodle_url('/course/view.php', ['id' => $COURSE->id]), 'Cronograma de actividades calificables creado', 2);
        }

        if (optional_param('createcronogramasesiones', 0, PARAM_INT)) {
            require_capability('moodle/course:manageactivities', $context);
            global $CFG;
            require_once($CFG->dirroot . '/course/lib.php');
            require_once($CFG->dirroot . '/course/modlib.php');

            $tmplfile = $CFG->dirroot . '/blocks/validacursos/content/cronograma_sesiones.html';
            $introhtml = '';
            if (is_readable($tmplfile)) {
                $introhtml = file_get_contents($tmplfile);
            } else {
                // Traza para depurar si falla la ruta.
                debugging('No se puede leer la plantilla: '.$tmplfile, DEBUG_DEVELOPER);
                $introhtml = '<p>Cronograma de sesiones síncronas</p>';
            }

            $data = new stdClass();
            $data->course = $COURSE->id;
            $data->modulename = 'label';
            $data->section = 0;
            $data->visible = 1;
            $data->visibleoncoursepage = 1;
            $data->showdescription = 0;
            $data->completion = 0;

            // Redundancia segura: poner intro e introeditor.
            $data->intro = $introhtml;
            $data->introformat = FORMAT_HTML;
            $data->introeditor = [
                'text' => $introhtml,
                'format' => FORMAT_HTML,
                'itemid' => 0
            ];

            // (Opcional) nombre interno para identificar el recurso en informes.
            $data->name = 'Cronograma de sesiones síncronas';

            create_module($data);
            rebuild_course_cache($COURSE->id, true);
            redirect(new moodle_url('/course/view.php', ['id' => $COURSE->id]), 'Cronograma de sesiones síncronas creado', 2);
        }

        // Procesar cambio de fecha de fin si se solicita y el usuario tiene permisos
        if (optional_param('changeenddate', 0, PARAM_INT)) {
            require_capability('moodle/course:update', $context);
            if (!empty($config->fechafinvalidacion)) {
                $courseid = $COURSE->id ?? optional_param('id', 0, PARAM_INT);
                if ($courseid) {
                    $DB->set_field('course', 'enddate', $config->fechafinvalidacion, ['id' => $courseid]);
                    redirect(new moodle_url('/course/view.php', ['id' => $courseid]), 'Fecha de fin actualizada', 2);
                } else {
                    print_error('missingcourseid', 'block_validacursos');
                }
            }
        }

        // Procesar creación de foro de tutorías si se solicita y el usuario tiene permisos
        if (optional_param('createforotutorias', 0, PARAM_INT)) {
            require_capability('moodle/course:manageactivities', $context);
            $this->crear_foro_tutorias($COURSE);
            redirect(new moodle_url('/course/view.php', ['id' => $COURSE->id]), 'Foro de tutorías creado', 2);
        }

        // Procesar creación de foro de comunicación entre estudiantes si se solicita y el usuario tiene permisos
        if (optional_param('createforoestudiantes', 0, PARAM_INT)) {
            require_capability('moodle/course:manageactivities', $context);
            $this->crear_foro_estudiantes($COURSE);
            redirect(new moodle_url('/course/view.php', ['id' => $COURSE->id]), 'Foro de comunicación entre estudiantes creado', 2);
        }

        // Activar finalización del curso si se solicita
        if (optional_param('enablecompletion', 0, PARAM_INT)) {
            require_capability('moodle/course:update', $context);
            $DB->set_field('course', 'enablecompletion', 1, ['id' => $COURSE->id]);
            rebuild_course_cache($COURSE->id, true);
            redirect(new moodle_url('/course/view.php', ['id' => $COURSE->id]), 'Finalización de curso activada', 2);
        }

        // Activar mostrar condiciones de finalización si se solicita
        if (optional_param('enableshowcompletionconditions', 0, PARAM_INT)) {
            require_capability('moodle/course:update', $context);
            $DB->set_field('course', 'showcompletionconditions', 1, ['id' => $COURSE->id]);
            rebuild_course_cache($COURSE->id, true);
            redirect(new moodle_url('/course/view.php', ['id' => $COURSE->id]), 'Mostrar condiciones de finalización activado', 2);
        }

        // Activar mostrar fechas de actividad si se solicita
        if (optional_param('enableshowactivitydates', 0, PARAM_INT)) {
            require_capability('moodle/course:update', $context);
            $DB->set_field('course', 'showactivitydates', 1, ['id' => $COURSE->id]);
            rebuild_course_cache($COURSE->id, true);
            redirect(new moodle_url('/course/view.php', ['id' => $COURSE->id]), 'Mostrar fechas de actividad activado', 2);
        }

        // Ocultar curso si se solicita
        if (optional_param('hidecourse', 0, PARAM_INT)) {
            require_capability('moodle/course:visibility', $context);
            $DB->set_field('course', 'visible', 0, ['id' => $COURSE->id]);
            rebuild_course_cache($COURSE->id, true);
            redirect(new moodle_url('/course/view.php', ['id' => $COURSE->id]), 'Curso ocultado', 2);
        }

        // Cambiar tipo de foro si se solicita
        $changeforumtype_id = optional_param('changeforumtype', 0, PARAM_INT);
        if ($changeforumtype_id) {
            require_capability('moodle/course:manageactivities', $context);
            $newtype = optional_param('newtype', '', PARAM_ALPHA);
            if ($newtype !== '' && $DB->record_exists('forum', ['id' => $changeforumtype_id, 'course' => $COURSE->id])) {
                $DB->set_field('forum', 'type', $newtype, ['id' => $changeforumtype_id]);
                rebuild_course_cache($COURSE->id, true);
                redirect(new moodle_url('/course/view.php', ['id' => $COURSE->id]), 'Tipo de foro actualizado', 2);
            }
        }

        // Crear categoría del calificador si se solicita
        if ($catname = optional_param('creategradecat', '', PARAM_TEXT)) {
            require_capability('moodle/grade:manage', $context);
            global $CFG; // <-- Añade esta línea
            require_once($CFG->libdir . '/gradelib.php');
            require_once($CFG->dirroot . '/grade/lib.php');
            $catname = trim($catname);
            if ($catname !== '') {
                $category = new grade_category([
                    'courseid' => $COURSE->id,
                    'fullname' => $catname,
                    'aggregation' => 13, // Promedio simple de calificaciones
                    'aggregationcoef' => ($catname === 'Actividades no evaluables') ? 0 : 1,
                ]);
                $category->insert();
                redirect(new moodle_url('/course/view.php', ['id' => $COURSE->id]), 'Categoría "' . $catname . '" creada', 2);
            }
        }

        $html = '<h4>Valida Cursos</h4>';
        foreach ($validaciones as $i => $val) {
            $iconoid = uniqid('validacursos_icono_' . $i . '_');
            $color = $val['estado'] ? 'green' : 'red';
            $html .= '<div style="margin-bottom:6px;">';
            $html .= '<span style="cursor:pointer;color:' . $color . ';font-size:1.2em;vertical-align:middle;" onclick="var d=document.getElementById(\'' . $iconoid . '\');d.style.display=d.style.display==\'none\'?\'block\':\'none\';">&#9679;</span> ';
            $html .= '<span style="cursor:pointer;vertical-align:middle;" onclick="var d=document.getElementById(\'' . $iconoid . '\');d.style.display=d.style.display==\'none\'?\'block\':\'none\';">' . $val['nombre'] . '</span>';
            $html .= '<div id="' . $iconoid . '" style="display:none;margin-top:4px;padding:6px;border:1px solid #ccc;background:#f9f9f9;font-size:0.95em;">';
            foreach ($val['detalle'] as $label => $valor) {
                // No mostrar campos internos (prefijo _).
                if (strpos($label, '_') === 0) {
                    continue;
                }
                $html .= '<strong>' . $label . ':</strong> ' . $valor;
                // Mostrar botón solo si es la validación de fecha, no está validada y es el campo "Curso"
                if ($val['nombre'] === 'Fecha de inicio' && !$val['estado'] && $label === 'Curso' && has_capability('moodle/course:update', $context)) {
                    // Icono de "corregir": lápiz ✏️
                    $html .= ' <button title="Corregir la fecha por la configurada" style="border:none;background:none;padding:0;margin-left:6px;cursor:pointer;" onclick="if(confirm(\'¿Quieres corregir la fecha de inicio del curso por la configurada?\')){window.location.href=\'?changestartdate=1&id=' . $COURSE->id . '\';}"><span style="font-size:1.1em;color:#007bff;">&#9998;</span></button>';
                }
                // Mostrar botón solo si es la validación de fecha de fin, no está validada y es el campo "Curso"
                if ($val['nombre'] === 'Fecha de fin' && !$val['estado'] && $label === 'Curso' && has_capability('moodle/course:update', $context)) {
                    $html .= ' <button title="Corregir la fecha por la configurada" style="border:none;background:none;padding:0;margin-left:6px;cursor:pointer;" onclick="if(confirm(\'¿Quieres corregir la fecha de fin del curso por la configurada?\')){window.location.href=\'?changeenddate=1&id=' . $COURSE->id . '\';}"><span style="font-size:1.1em;color:#007bff;">&#9998;</span></button>';
                }
                // Botones para foros: cambiar tipo (↻) y crear (+).
                $foros_con_boton = [
                    'Foro de tutorías de la asignatura' => 'createforotutorias',
                    'Foro de comunicación entre estudiantes' => 'createforoestudiantes',
                ];
                if (isset($foros_con_boton[$val['nombre']]) && !$val['estado'] && $label === 'Estado' && has_capability('moodle/course:manageactivities', $context)) {
                    if (!empty($val['detalle']['_forumid']) && !empty($val['detalle']['Tipo requerido'])) {
                        $fid = (int)$val['detalle']['_forumid'];
                        $reqtype = $val['detalle']['Tipo requerido'];
                        $html .= ' <button title="Cambiar el tipo del foro existente a ' . s($reqtype) . '" style="border:none;background:none;padding:0;margin-left:6px;cursor:pointer;" onclick="if(confirm(\'¿Quieres cambiar el tipo del foro existente a ' . s($reqtype) . '?\')){window.location.href=\'?changeforumtype=' . $fid . '&newtype=' . urlencode($reqtype) . '&id=' . $COURSE->id . '\';}"><span style="font-size:1.1em;color:#007bff;">&#9998;</span></button>';
                    }
                    $action = $foros_con_boton[$val['nombre']];
                    $html .= ' <button title="Crear ' . s($val['nombre']) . ' en la sección 0" style="border:none;background:none;padding:0;margin-left:6px;cursor:pointer;" onclick="if(confirm(\'¿Quieres crear ' . addslashes($val['nombre']) . ' en la sección 0?\')){window.location.href=\'?' . $action . '=1&id=' . $COURSE->id . '\';}"><span style="font-size:1.1em;color:#28a745;">&#10133;</span></button>';
                }
                // Mostrar botón solo si es la validación del cronograma de sesiones síncronas, no está validada y el usuario tiene permisos
                if ($val['nombre'] === 'Cronograma de sesiones síncronas en bloque cero'
                    && !$val['estado']
                    && has_capability('moodle/course:manageactivities', $context)) {
                    $html .= ' <button title="Crear cronograma de sesiones síncronas" style="border:none;background:none;padding:0;margin-left:6px;cursor:pointer;" onclick="if(confirm(\'¿Crear el cronograma de sesiones síncronas en la sección 0?\')){window.location.href=\'?createcronogramasesiones=1&id=' . $COURSE->id . '\';}"><span style="font-size:1.1em;color:#28a745;">&#10133;</span></button>';
                }

                if ($val['nombre'] === 'Cronograma de actividades calificables en bloque cero'
                    && !$val['estado']
                    && has_capability('moodle/course:manageactivities', $context)) {
                    $html .= ' <button title="Crear cronograma de actividades calificables" style="border:none;background:none;padding:0;margin-left:6px;cursor:pointer;" onclick="if(confirm(\'¿Crear el cronograma de actividades calificables en la sección 0?\')){window.location.href=\'?createcronogramaactividades=1&id=' . $COURSE->id . '\';}"><span style="font-size:1.1em;color:#28a745;">&#10133;</span></button>';
                }
                // Botón para activar finalización del curso
                if ($val['nombre'] === 'Finalización de curso activada' && !$val['estado'] && $label === 'Estado' && has_capability('moodle/course:update', $context)) {
                    $html .= ' <button title="Activar finalización de curso" style="border:none;background:none;padding:0;margin-left:6px;cursor:pointer;" onclick="if(confirm(\'¿Quieres activar la finalización de curso?\')){window.location.href=\'?enablecompletion=1&id=' . $COURSE->id . '\';}"><span style="font-size:1.1em;color:#007bff;">&#9998;</span></button>';
                }
                // Botón para activar mostrar condiciones de finalización
                if ($val['nombre'] === 'Mostrar condiciones de finalización de actividad' && !$val['estado'] && $label === 'Estado' && has_capability('moodle/course:update', $context)) {
                    $html .= ' <button title="Activar mostrar condiciones de finalización" style="border:none;background:none;padding:0;margin-left:6px;cursor:pointer;" onclick="if(confirm(\'¿Quieres activar mostrar condiciones de finalización de actividad?\')){window.location.href=\'?enableshowcompletionconditions=1&id=' . $COURSE->id . '\';}"><span style="font-size:1.1em;color:#007bff;">&#9998;</span></button>';
                }
                // Botón para activar mostrar fechas de actividad
                if ($val['nombre'] === 'Mostrar fechas de actividad' && !$val['estado'] && $label === 'Estado' && has_capability('moodle/course:update', $context)) {
                    $html .= ' <button title="Activar mostrar fechas de actividad" style="border:none;background:none;padding:0;margin-left:6px;cursor:pointer;" onclick="if(confirm(\'¿Quieres activar mostrar fechas de actividad?\')){window.location.href=\'?enableshowactivitydates=1&id=' . $COURSE->id . '\';}"><span style="font-size:1.1em;color:#007bff;">&#9998;</span></button>';
                }
                // Botón para ocultar el curso
                if ($val['nombre'] === 'Curso oculto' && !$val['estado'] && $label === 'Estado' && has_capability('moodle/course:visibility', $context)) {
                    $html .= ' <button title="Ocultar curso" style="border:none;background:none;padding:0;margin-left:6px;cursor:pointer;" onclick="if(confirm(\'¿Quieres ocultar este curso?\')){window.location.href=\'?hidecourse=1&id=' . $COURSE->id . '\';}"><span style="font-size:1.1em;color:#007bff;">&#9998;</span></button>';
                }
                // Botones para crear categorías del calificador si faltan
                if ($val['nombre'] === 'Categorías del calificador' && !$val['estado'] && $label === 'Faltan' && $valor !== '-') {
                    $faltantes = explode(', ', $valor);
                    foreach ($faltantes as $catfaltante) {
                        $catfaltante = trim($catfaltante);
                        if ($catfaltante !== '') {
                            $catfaltante_esc = htmlspecialchars($catfaltante, ENT_QUOTES);
                            $catfaltante_js = addslashes($catfaltante);
                            $html .= '<br><button title="Crear categoría \'' . $catfaltante_esc . '" style="border:none;background:none;padding:0;margin-right:6px;cursor:pointer;" onclick="if(confirm(\'¿Quieres crear la categoría \\\'' . $catfaltante_js . '\\\' en el calificador?\')){window.location.href=\'?creategradecat=' . urlencode($catfaltante) . '&id=' . $COURSE->id . '\';}"><span style="font-size:1.1em;color:#28a745;">&#10133;</span></button> ' . $catfaltante_esc;
                        }
                    }
                }
                $html .= '<br>';
            }
            $html .= '</div></div>';
        }

        $this->content = (object)[
            'footer' => '',
            'text' => $html,
        ];
        return $this->content;
    }

    /**
     * Indica que el bloque tiene un archivo settings.php.
     *
     * @return bool
     */
    public function has_config() {
        return true;
    }

    public function hide_header() {
        return true;
    }

    /**
     * Limita el bloque solo al contexto de curso.
     *
     * @return array
     */
    public function applicable_formats() {
        return [
            'course-view' => true, // Solo disponible en cursos.
            'site' => true,       // Disponible en la página principal.
            'my' => false,         // No disponible en "Mi área".
        ];
    }


}
