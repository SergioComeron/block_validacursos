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
        $validaciones = [];

        // Validación de fecha de inicio.
        $validafecha = !empty($course->startdate) && !empty($config->fechainiciovalidacion)
            && $this->fechas_son_iguales($course->startdate, $config->fechainiciovalidacion);

        $validaciones[] = [
            'nombre' => 'Fecha de inicio',
            'estado' => $validafecha,
            'mensaje' => $validafecha ? 'Fecha Inicio validada' : 'Fecha Inicio NO validada',
            'detalle' => [
                'Curso' => !empty($course->startdate) ? userdate($course->startdate, get_string('strftimedatetime', 'langconfig')) : get_string('notavailable', 'moodle'),
                'Configuración' => !empty($config->fechainiciovalidacion) ? userdate($config->fechainiciovalidacion, get_string('strftimedatetime', 'langconfig')) : get_string('notavailable', 'moodle')
            ]
        ];

        // Obtener el id de la primera sección del curso.
        global $DB;
        $section0 = $DB->get_record('course_sections', [
            'course' => $course->id,
            'section' => 0
        ]);
        $section0id = $section0 ? $section0->id : null;

        // Validación de foro de anuncios llamado "Tablón de anuncios" en la primera sección.
        $foro = $DB->get_record('forum', [
            'course' => $course->id,
            'type' => 'news',
            'name' => 'Tablón de anuncios'
        ]);
        $foro_en_primera_seccion = false;
        if (!empty($foro) && $section0id) {
            $cm = $DB->get_record('course_modules', [
                'course' => $course->id,
                'instance' => $foro->id,
                'module' => $DB->get_field('modules', 'id', ['name' => 'forum'])
            ]);
            $foro_en_primera_seccion = !empty($cm) && $cm->section == $section0id;
        }

        $validaciones[] = [
            'nombre' => 'Foro de anuncios',
            'estado' => $foro_en_primera_seccion,
            'mensaje' => $foro_en_primera_seccion
                ? 'Existe el foro "Tablón de anuncios" en la primera sección'
                : 'No existe el foro "Tablón de anuncios" en la primera sección',
            'detalle' => [
                'Nombre buscado' => 'Tablón de anuncios',
                'Estado' => !empty($foro) ? ($foro_en_primera_seccion ? 'Encontrado en la primera sección' : 'No está en la primera sección') : 'No encontrado'
            ]
        ];

        // Validación de foro normal llamado "Foro de comunicación entre estudiantes" en la primera sección.
        $forocom = $DB->get_record('forum', [
            'course' => $course->id,
            'type' => 'general',
            'name' => 'Foro de comunicación entre estudiantes'
        ]);
        $forocom_en_primera_seccion = false;
        if (!empty($forocom) && $section0id) {
            $cmcom = $DB->get_record('course_modules', [
                'course' => $course->id,
                'instance' => $forocom->id,
                'module' => $DB->get_field('modules', 'id', ['name' => 'forum'])
            ]);
            $forocom_en_primera_seccion = !empty($cmcom) && $cmcom->section == $section0id;
        }

        $validaciones[] = [
            'nombre' => 'Foro de comunicación entre estudiantes',
            'estado' => $forocom_en_primera_seccion,
            'mensaje' => $forocom_en_primera_seccion
                ? 'Existe el foro "Foro de comunicación entre estudiantes" en la primera sección'
                : 'No existe el foro "Foro de comunicación entre estudiantes" en la primera sección',
            'detalle' => [
                'Nombre buscado' => 'Foro de comunicación entre estudiantes',
                'Estado' => !empty($forocom) ? ($forocom_en_primera_seccion ? 'Encontrado en la primera sección' : 'No está en la primera sección') : 'No encontrado'
            ]
        ];

        // Aquí puedes añadir más validaciones en el futuro.

        return $validaciones;
    }

    /**
     * Get content
     *
     * @return stdClass
     */
    public function get_content() {
        global $COURSE;

        if ($this->content !== null) {
            return $this->content;
        }

        $config = get_config('block_validacursos');
        $validaciones = $this->obtener_validaciones($COURSE, $config);

        $html = '<h4>Valida Cursos</h4>';
        foreach ($validaciones as $i => $val) {
            $iconoid = uniqid('validacursos_icono_' . $i . '_');
            $color = $val['estado'] ? 'green' : 'red';
            $html .= '<div style="margin-bottom:6px;">';
            $html .= '<span style="cursor:pointer;color:' . $color . ';font-size:1.2em;vertical-align:middle;" onclick="var d=document.getElementById(\'' . $iconoid . '\');d.style.display=d.style.display==\'none\'?\'block\':\'none\';">&#9679;</span> ';
            $html .= '<span style="cursor:pointer;vertical-align:middle;" onclick="var d=document.getElementById(\'' . $iconoid . '\');d.style.display=d.style.display==\'none\'?\'block\':\'none\';">' . $val['mensaje'] . '</span>';
            $html .= '<div id="' . $iconoid . '" style="display:none;margin-top:4px;padding:6px;border:1px solid #ccc;background:#f9f9f9;font-size:0.95em;">';
            foreach ($val['detalle'] as $label => $valor) {
                $html .= '<strong>' . $label . ':</strong> ' . $valor . '<br>';
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
