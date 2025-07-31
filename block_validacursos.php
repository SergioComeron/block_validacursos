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

        // Validación fecha.
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

        return $validaciones;
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
