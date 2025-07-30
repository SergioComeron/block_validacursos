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
     * Get content
     *
     * @return stdClass
     */
    public function get_content() {
        global $COURSE;

        if ($this->content !== null) {
            return $this->content;
        }

        // Formatear la fecha de inicio del curso para el usuario.
        $fechainicio = !empty($COURSE->startdate)
            ? userdate($COURSE->startdate, get_string('strftimedatetime', 'langconfig'))
            : get_string('notavailable', 'moodle');

        // Obtener y formatear la fecha de validación configurada.
        $config = get_config('block_validacursos');
        $fechavalidacion = !empty($config->fechainiciovalidacion)
            ? userdate($config->fechainiciovalidacion, get_string('strftimedatetime', 'langconfig'))
            : get_string('notavailable', 'moodle');

        // Validar fechas.
        $validada = false;
        if (!empty($COURSE->startdate) && !empty($config->fechainiciovalidacion)) {
            $validada = $this->fechas_son_iguales($COURSE->startdate, $config->fechainiciovalidacion);
        }

        // Punto verde o rojo con desplegable.
        $iconoid = uniqid('validacursos_icono_');
        $icono = '<span style="cursor:pointer;color:' . ($validada ? 'green' : 'red') . ';font-size:1.5em;" onclick="var d=document.getElementById(\'' . $iconoid . '\');d.style.display=d.style.display==\'none\'?\'block\':\'none\';">&#9679;</span> ' .
                 '<span style="cursor:pointer;" onclick="var d=document.getElementById(\'' . $iconoid . '\');d.style.display=d.style.display==\'none\'?\'block\':\'none\';">' .
                 ($validada ? 'Fecha Inicio validada' : 'Fecha Inicio NO validada') .
                 '</span>' .
                 '<div id="' . $iconoid . '" style="display:none;margin-top:8px;padding:8px;border:1px solid #ccc;background:#f9f9f9;">' .
                 '<strong>Fecha de inicio del curso:</strong> ' . $fechainicio . '<br>' .
                 '<strong>Fecha de validación configurada:</strong> ' . $fechavalidacion . '</div>';

        $this->content = (object)[
            'footer' => '',
            'text' => '<h4>Valida Cursos</h4>' .
                      '<p>' . $icono . '</p>',
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
