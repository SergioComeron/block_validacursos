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
     * Crea un foro en la sección 0 y lo añade a la secuencia del curso.
     *
     * @param stdClass $course
     * @param string $type Tipo de foro Moodle (general, news, …).
     * @param string $name
     * @param string $intro
     * @return int Id del foro creado.
     */
    private function crear_foro_seccion0($course, $type, $name, $intro) {
        global $DB;

        $section0 = $DB->get_record('course_sections', ['course' => $course->id, 'section' => 0], '*', MUST_EXIST);

        $forum = new stdClass();
        $forum->course = $course->id;
        $forum->type = $type;
        $forum->name = $name;
        $forum->intro = $intro;
        $forum->introformat = FORMAT_HTML;
        $forum->assessed = 0;
        $forum->forcesubscribe = 1;
        $forum->trackingtype = 1;
        $forum->timemodified = time();
        $forum->timecreated = time();
        $forumid = $DB->insert_record('forum', $forum);

        $forummoduleid = $DB->get_field('modules', 'id', ['name' => 'forum'], MUST_EXIST);

        $cm = new stdClass();
        $cm->course = $course->id;
        $cm->module = $forummoduleid;
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

        $sequence = trim($section0->sequence);
        $sequence = $sequence ? $sequence . ',' . $cmid : $cmid;
        $DB->set_field('course_sections', 'sequence', $sequence, ['id' => $section0->id]);
        rebuild_course_cache($course->id, true);

        return $forumid;
    }

    /**
     * Crea un foro de tutorías de la asignatura en la sección 0 del curso si no existe.
     *
     * @param object $course
     * @return bool|int Devuelve el id del foro creado o false si falla.
     */
    private function crear_foro_tutorias($course) {
        return $this->crear_foro_seccion0(
            $course,
            'general',
            'Foro de tutorías de la asignatura',
            'Foro para tutorías de la asignatura.'
        );
    }

    /**
     * Crea un foro de comunicación entre estudiantes en la sección 0 del curso si no existe.
     *
     * @param object $course
     * @return bool|int Devuelve el id del foro creado o false si falla.
     */
    private function crear_foro_estudiantes($course) {
        return $this->crear_foro_seccion0(
            $course,
            'general',
            'Foro de comunicación entre estudiantes',
            'Foro para la comunicación entre estudiantes.'
        );
    }

    /**
     * Establece un foro en la configuración del bloque cero (claves forumid / forumestudiantesid / forumtutoriasid).
     *
     * @param int $blockinstanceid Id de la instancia del bloque bloquecero.
     * @param string $configkey Clave de configuración a establecer.
     * @param int $forumid Id del foro a seleccionar.
     */
    private function configurar_foro_bloquecero($blockinstanceid, $configkey, $forumid) {
        global $DB;

        $bi = $DB->get_record('block_instances',
            ['id' => $blockinstanceid, 'blockname' => 'bloquecero'], '*', MUST_EXIST);

        $config = !empty($bi->configdata) ? unserialize(base64_decode($bi->configdata)) : new stdClass();
        if (!is_object($config)) {
            $config = new stdClass();
        }

        $config->$configkey = $forumid;

        $bi->configdata = base64_encode(serialize($config));
        $bi->timemodified = time();
        $DB->update_record('block_instances', $bi);
    }

    /**
     * Añade una instancia del bloque bloquecero al curso en la región content-upper.
     *
     * @param stdClass $course
     */
    private function crear_bloquecero($course) {
        global $DB;

        $coursecontext = context_course::instance($course->id);

        // No crear un segundo bloquecero si ya existe uno.
        if ($DB->record_exists('block_instances', ['blockname' => 'bloquecero', 'parentcontextid' => $coursecontext->id])) {
            return;
        }

        $bi = new stdClass();
        $bi->blockname = 'bloquecero';
        $bi->parentcontextid = $coursecontext->id;
        $bi->showinsubcontexts = 0;
        $bi->requiredbytheme = 0;
        $bi->pagetypepattern = 'course-view-*';
        $bi->subpagepattern = null;
        $bi->defaultregion = 'content-upper'; // Región extra de Boost Union exigida por la validación.
        $bi->defaultweight = 0;
        $bi->configdata = '';
        $bi->timecreated = time();
        $bi->timemodified = time();
        $bi->id = $DB->insert_record('block_instances', $bi);
        context_block::instance($bi->id);
    }

    /**
     * Mueve la instancia del bloque cero a la región content-upper.
     *
     * Fija la región por defecto y alinea cualquier sobreescritura por página (block_positions)
     * a la misma región, de modo que la región efectiva sea content-upper en todas las páginas.
     *
     * @param int $blockinstanceid Id de la instancia del bloque bloquecero.
     */
    private function mover_bloquecero($blockinstanceid) {
        global $DB;

        // Comprobar que la instancia existe y es un bloquecero.
        if (!$DB->record_exists('block_instances', ['id' => $blockinstanceid, 'blockname' => 'bloquecero'])) {
            return;
        }

        $DB->set_field('block_instances', 'defaultregion', 'content-upper', ['id' => $blockinstanceid]);
        $DB->set_field('block_instances', 'timemodified', time(), ['id' => $blockinstanceid]);
        // Alinear las posiciones por página existentes para que no anulen la región por defecto.
        $DB->set_field('block_positions', 'region', 'content-upper', ['blockinstanceid' => $blockinstanceid]);
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

        // Añadir el bloque cero al curso si se solicita y el usuario tiene permisos.
        if (optional_param('addbloquecero', 0, PARAM_INT)) {
            require_capability('moodle/course:update', $context);
            $this->crear_bloquecero($COURSE);
            redirect(new moodle_url('/course/view.php', ['id' => $COURSE->id]), 'Bloque cero añadido', 2);
        }

        // Mover el bloque cero a la región content-upper si se solicita y el usuario tiene permisos.
        if (optional_param('movebloquecero', 0, PARAM_INT)) {
            require_capability('moodle/course:update', $context);
            $blockid = optional_param('blockid', 0, PARAM_INT);
            if ($blockid) {
                $this->mover_bloquecero($blockid);
                redirect(new moodle_url('/course/view.php', ['id' => $COURSE->id]), 'Bloque cero movido a la región correcta', 2);
            }
        }

        // Configurar un foro en el bloque cero si se solicita y el usuario tiene permisos.
        if (optional_param('configforobloquecero', 0, PARAM_INT)) {
            require_capability('moodle/course:update', $context);
            $blockid = optional_param('blockid', 0, PARAM_INT);
            $configkey = optional_param('configkey', '', PARAM_ALPHANUMEXT);
            $forumid = optional_param('forumid', 0, PARAM_INT);
            $allowedkeys = ['forumid', 'forumestudiantesid', 'forumtutoriasid'];
            if ($blockid && $forumid && in_array($configkey, $allowedkeys, true)) {
                $this->configurar_foro_bloquecero($blockid, $configkey, $forumid);
                redirect(new moodle_url('/course/view.php', ['id' => $COURSE->id]), 'Foro configurado en el bloque cero', 2);
            }
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
                // Botones para el bloque cero: añadir (+) si no existe, o mover (➜) si está mal ubicado.
                if ($val['nombre'] === 'Bloque cero' && !$val['estado'] && $label === 'Estado'
                    && has_capability('moodle/course:update', $context)) {
                    if (empty($val['detalle']['_existe'])) {
                        $html .= ' <button title="Añadir el bloque cero al curso" style="border:none;background:none;padding:0;margin-left:6px;cursor:pointer;" onclick="if(confirm(\'¿Quieres añadir el bloque cero al curso?\')){window.location.href=\'?addbloquecero=1&id=' . $COURSE->id . '\';}"><span style="font-size:1.1em;color:#28a745;">&#10133;</span></button>';
                    } else if (!empty($val['detalle']['_blockinstanceid'])) {
                        $bcid = (int)$val['detalle']['_blockinstanceid'];
                        $html .= ' <button title="Mover el bloque cero a la región correcta" style="border:none;background:none;padding:0;margin-left:6px;cursor:pointer;" onclick="if(confirm(\'¿Quieres mover el bloque cero a la región correcta?\')){window.location.href=\'?movebloquecero=1&blockid=' . $bcid . '&id=' . $COURSE->id . '\';}"><span style="font-size:1.1em;color:#007bff;">&#10138;</span></button>';
                    }
                }
                // Botones para foros: configurar en bloque cero (⚙), cambiar tipo (✏) y crear (+).
                $foros_con_boton = [
                    'Foro de tutorías de la asignatura' => 'createforotutorias',
                    'Foro de comunicación entre estudiantes' => 'createforoestudiantes',
                ];
                if (!$val['estado'] && $label === 'Estado' && isset($val['detalle']['_configkey'])) {
                    $foro_existe = !empty($val['detalle']['_foro_existe']);
                    $blockid = (int)($val['detalle']['_bloquecero_instanceid'] ?? 0);
                    if ($foro_existe && $blockid > 0 && has_capability('moodle/course:update', $context)) {
                        // El foro ya existe: ofrecer seleccionarlo en la configuración del bloque cero.
                        $cfgkey = $val['detalle']['_configkey'];
                        $fidvalido = (int)($val['detalle']['_foro_id_valido'] ?? 0);
                        $html .= ' <button title="Configurar este foro en el bloque cero" style="border:none;background:none;padding:0;margin-left:6px;cursor:pointer;" onclick="if(confirm(\'¿Configurar este foro en el bloque cero?\')){window.location.href=\'?configforobloquecero=1&blockid=' . $blockid . '&configkey=' . $cfgkey . '&forumid=' . $fidvalido . '&id=' . $COURSE->id . '\';}"><span style="font-size:1.1em;color:#007bff;">&#9881;</span></button>';
                    } else if (!$foro_existe && isset($foros_con_boton[$val['nombre']]) && has_capability('moodle/course:manageactivities', $context)) {
                        // El foro no existe: ofrecer cambiar el tipo (si hay uno con el nombre y tipo erróneo) o crearlo.
                        if (!empty($val['detalle']['_forumid']) && !empty($val['detalle']['Tipo requerido'])) {
                            $fid = (int)$val['detalle']['_forumid'];
                            $reqtype = $val['detalle']['Tipo requerido'];
                            $html .= ' <button title="Cambiar el tipo del foro existente a ' . s($reqtype) . '" style="border:none;background:none;padding:0;margin-left:6px;cursor:pointer;" onclick="if(confirm(\'¿Quieres cambiar el tipo del foro existente a ' . s($reqtype) . '?\')){window.location.href=\'?changeforumtype=' . $fid . '&newtype=' . urlencode($reqtype) . '&id=' . $COURSE->id . '\';}"><span style="font-size:1.1em;color:#007bff;">&#9998;</span></button>';
                        }
                        $action = $foros_con_boton[$val['nombre']];
                        $html .= ' <button title="Crear ' . s($val['nombre']) . ' en la sección 0" style="border:none;background:none;padding:0;margin-left:6px;cursor:pointer;" onclick="if(confirm(\'¿Quieres crear ' . addslashes($val['nombre']) . ' en la sección 0?\')){window.location.href=\'?' . $action . '=1&id=' . $COURSE->id . '\';}"><span style="font-size:1.1em;color:#28a745;">&#10133;</span></button>';
                    }
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
