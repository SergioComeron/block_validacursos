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
        $this->title = '';
    }

    /**
     * Ejecuta todas las validaciones y devuelve un array con los resultados.
     *
     * @param object $course
     * @param object $config
     * @return array
     */
    private function obtener_validaciones($course, $config) {
        global $DB;
        $validaciones = [];

        // Validación fecha de inicio.
        $validafecha = !empty($course->startdate) && !empty($config->fechainiciovalidacion)
            && $this->fechas_son_iguales($course->startdate, $config->fechainiciovalidacion);

        $validaciones[] = [
            'nombre' => 'Fecha de inicio',
            'estado' => $validafecha,
            'mensaje' => $validafecha ? 'Fecha Inicio validada' : 'Fecha Inicio NO validada',
            'detalle' => [
                'Curso' => !empty($course->startdate) ? userdate($course->startdate) : get_string('notavailable', 'moodle'),
                'Configuración' => !empty($config->fechainiciovalidacion) ? userdate($config->fechainiciovalidacion) : get_string('notavailable', 'moodle')
            ]
        ];

        // Validación fecha de fin.
        $validafechafin = !empty($course->enddate) && !empty($config->fechafinvalidacion)
            && $this->fechas_son_iguales($course->enddate, $config->fechafinvalidacion);

        $validaciones[] = [
            'nombre' => 'Fecha de fin',
            'estado' => $validafechafin,
            'mensaje' => $validafechafin ? 'Fecha Fin validada' : 'Fecha Fin NO validada',
            'detalle' => [
                'Curso' => !empty($course->enddate) ? userdate($course->enddate) : get_string('notavailable', 'moodle'),
                'Configuración' => !empty($config->fechafinvalidacion) ? userdate($config->fechafinvalidacion) : get_string('notavailable', 'moodle')
            ]
        ];

        // Sección 0
        $section0 = $DB->get_record('course_sections', ['course' => $course->id, 'section' => 0]);
        $section0id = $section0 ? $section0->id : null;

        // ID módulo foro (una sola vez)
        $forum_module_id = $DB->get_field('modules', 'id', ['name' => 'forum'], MUST_EXIST);

        // Todos los foros del curso
        $foros = $DB->get_records('forum', ['course' => $course->id]);

        // Todos los course_modules de foros
        $cms = $DB->get_records('course_modules', ['course' => $course->id, 'module' => $forum_module_id]);
        $cms_by_instance = [];
        foreach ($cms as $cm) {
            $cms_by_instance[$cm->instance] = $cm;
        }

        // Lista de validaciones de foros
        $foros_a_validar = [
            [
                'nombre' => 'Tablón de anuncios',
                'type'   => 'news',
                'titulo' => 'Tablón de anuncios'
            ],
            [
                'nombre' => 'Foro de comunicación entre estudiantes',
                'type'   => 'general',
                'titulo' => 'Foro de comunicación entre estudiantes'
            ],
            [
                'nombre' => 'Foro de tutorías de la asignatura',
                'type'   => 'general',
                'titulo' => 'Foro de tutorías de la asignatura'
            ]
        ];

        foreach ($foros_a_validar as $finfo) {
            $foro_ok = false;
            foreach ($foros as $f) {
                if ($f->type === $finfo['type'] && $f->name === $finfo['titulo']) {
                    $cm = $cms_by_instance[$f->id] ?? null;
                    if ($cm && $cm->section == $section0id) {
                        $foro_ok = true;
                    }
                    break;
                }
            }

            $validaciones[] = [
                'nombre' => $finfo['nombre'],
                'estado' => $foro_ok,
                'mensaje' => $foro_ok
                    ? "Validado"
                    : "No validado",
                'detalle' => [
                    'Nombre buscado' => $finfo['titulo'],
                    'Estado' => $foro_ok ? 'Encontrado en la primera sección' : 'No encontrado o fuera de sección'
                ]
            ];
        }

        // Validación de la existencia de la URL "Guia Docente" en el bloque cero (sección 0)
        $guiadocente_ok = false;
        $guiaurl = '';
        $guiaurlok = false;
        // Obtener todos los módulos de la sección 0
        $section0mods = [];
        if ($section0id) {
            $section0mods = $DB->get_records('course_modules', ['course' => $course->id, 'section' => $section0id]);
        }
        if ($section0mods) {
            // Obtener todos los recursos url del curso
            $urls = $DB->get_records('url', ['course' => $course->id]);
            foreach ($section0mods as $cm) {
                if ($cm->module == $DB->get_field('modules', 'id', ['name' => 'url'])) {
                    if (isset($urls[$cm->instance])) {
                        $urlobj = $urls[$cm->instance];
                        if (trim($urlobj->name) === 'Guía Docente') {
                            $guiadocente_ok = true;
                            $guiaurl = (new moodle_url('/mod/url/view.php', ['id' => $cm->id]))->out();
                            // Comprobar que la URL empieza por https://www.udima.es
                            if (strpos(trim($urlobj->externalurl), 'https://www.udima.es') === 0) {
                                $guiaurlok = true;
                            }
                            break;
                        }
                    }
                }
            }
        }
        $validaciones[] = [
            'nombre' => 'Guía Docente en bloque cero',
            'estado' => $guiadocente_ok && $guiaurlok,
            'mensaje' => ($guiadocente_ok && $guiaurlok) ? 'Guía Docente encontrada y URL válida' :
                ($guiadocente_ok ? 'Guía Docente encontrada pero URL NO válida' : 'Guía Docente NO encontrada'),
            'detalle' => [
                'Nombre buscado' => 'Guia Docente',
                'Estado' => $guiadocente_ok
                    ? ('Encontrada en la sección 0 <a href="' . $guiaurl . '" target="_blank" style="margin-left:8px;">Ver</a>')
                    : 'No encontrada en la sección 0',
                'URL' => $guiadocente_ok
                    ? (isset($urlobj->externalurl) ? s($urlobj->externalurl) : '-')
                    : '-',
                'Validación URL' => $guiadocente_ok
                    ? ($guiaurlok ? 'La URL es válida' : 'La URL NO es válida (debe empezar por https://www.udima.es)')
                    : '-'
            ]
        ];

        // Validación de datos de tutoría en bloque cero (label con claves mínimas)
        $label_module_id = $DB->get_field('modules', 'id', ['name' => 'label'], MUST_EXIST);
        $label_encontrado = false;
        $claves = [
            'Profesor:',
            'Correo electrónico:',
            'Teléfono',
            'Extensión',
            'Horario de tutorías'
        ];
        $faltan = $claves;

        // Obtener todos los course_modules de sección 0 que sean label
        if ($section0id) {
            $section0mods = $DB->get_records('course_modules', [
                'course' => $course->id,
                'section' => $section0id,
                'module' => $label_module_id
            ]);
            if ($section0mods) {
                $label_instances = array_map(function($cm) { return $cm->instance; }, $section0mods);
                list($in_sql, $params) = $DB->get_in_or_equal($label_instances);
                $labels = $DB->get_records_select('label', "id $in_sql", $params);
                foreach ($labels as $label) {
                    $intro = strip_tags($label->intro);
                    $faltan_actual = [];
                    foreach ($claves as $clave) {
                        if (mb_stripos($intro, $clave) === false) {
                            $faltan_actual[] = $clave;
                        }
                    }
                    if (empty($faltan_actual)) {
                        $label_encontrado = true;
                        $faltan = [];
                        break;
                    } else {
                        // Si hay varias labels, nos quedamos con la que menos le falte
                        if (count($faltan_actual) < count($faltan)) {
                            $faltan = $faltan_actual;
                        }
                    }
                }
            }
        }

        $detalle_label = [
            'Claves buscadas' => implode(', ', $claves),
            'Estado' => $label_encontrado ? 'Encontrado' : 'No encontrado'
        ];
        if (!$label_encontrado && count($faltan) > 0) {
            $detalle_label['Faltan'] = implode(', ', $faltan);
        }

        $validaciones[] = [
            'nombre' => 'Datos de tutoría en bloque cero',
            'estado' => $label_encontrado,
            'mensaje' => $label_encontrado
                ? 'Datos de tutoría encontrados'
                : 'No se han encontrado los datos de tutoría requeridos',
            'detalle' => $detalle_label
        ];

        return $validaciones;
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
        $validaciones = $this->obtener_validaciones($COURSE, $config);

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

        $html = '<h4>Valida Cursos</h4>';
        foreach ($validaciones as $i => $val) {
            $iconoid = uniqid('validacursos_icono_' . $i . '_');
            $color = $val['estado'] ? 'green' : 'red';
            $html .= '<div style="margin-bottom:6px;">';
            $html .= '<span style="cursor:pointer;color:' . $color . ';font-size:1.2em;vertical-align:middle;" onclick="var d=document.getElementById(\'' . $iconoid . '\');d.style.display=d.style.display==\'none\'?\'block\':\'none\';">&#9679;</span> ';
            $html .= '<span style="cursor:pointer;vertical-align:middle;" onclick="var d=document.getElementById(\'' . $iconoid . '\');d.style.display=d.style.display==\'none\'?\'block\':\'none\';">' . $val['nombre'] . '</span>';
            $html .= '<div id="' . $iconoid . '" style="display:none;margin-top:4px;padding:6px;border:1px solid #ccc;background:#f9f9f9;font-size:0.95em;">';
            foreach ($val['detalle'] as $label => $valor) {
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
                // Mostrar botón solo si es la validación del foro de tutorías, no está validada y es el campo "Estado"
                if ($val['nombre'] === 'Foro de tutorías de la asignatura' && !$val['estado'] && $label === 'Estado' && has_capability('moodle/course:manageactivities', $context)) {
                    // Icono de "añadir": ➕
                    $html .= ' <button title="Crear foro de tutorías en la sección 0" style="border:none;background:none;padding:0;margin-left:6px;cursor:pointer;" onclick="if(confirm(\'¿Quieres crear el foro de tutorías de la asignatura en la sección 0?\')){window.location.href=\'?createforotutorias=1&id=' . $COURSE->id . '\';}"><span style="font-size:1.1em;color:#28a745;">&#10133;</span></button>';
                }
                // Mostrar botón solo si es la validación del foro de comunicación entre estudiantes, no está validada y es el campo "Estado"
                if ($val['nombre'] === 'Foro de comunicación entre estudiantes' && !$val['estado'] && $label === 'Estado' && has_capability('moodle/course:manageactivities', $context)) {
                    // Icono de "añadir": ➕
                    $html .= ' <button title="Crear foro de comunicación entre estudiantes en la sección 0" style="border:none;background:none;padding:0;margin-left:6px;cursor:pointer;" onclick="if(confirm(\'¿Quieres crear el foro de comunicación entre estudiantes en la sección 0?\')){window.location.href=\'?createforoestudiantes=1&id=' . $COURSE->id . '\';}"><span style="font-size:1.1em;color:#28a745;">&#10133;</span></button>';
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

    /**
     * Limita el bloque solo al contexto de curso.
     *
     * @return array
     */
    public function applicable_formats() {
        return [
            'course-view' => true, // Solo disponible en cursos.
            'site' => false,       // No disponible en la página principal.
            'my' => false,         // No disponible en "Mi área".
        ];
    }

    /**
     * Valida si las dos fechas son iguales (timestamp).
     *
     * @param int $fecha1
     * @param int $fecha2
     * @return bool
     */
    private function fechas_son_iguales($fecha1, $fecha2) {
        return (int)$fecha1 === (int)$fecha2;
    }
}
