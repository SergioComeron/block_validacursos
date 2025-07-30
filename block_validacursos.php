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
        if (!empty($COURSE->startdate)) {
            $fechainicio = userdate($COURSE->startdate, get_string('strftimedate', 'langconfig'));
        } else {
            $fechainicio = get_string('notavailable', 'moodle');
        }

        // Obtener y formatear la fecha de validación configurada.
        $config = get_config('block_validacursos');
        if (!empty($config->fechainiciovalidacion)) {
            $fechavalidacion = userdate($config->fechainiciovalidacion, get_string('strftimedate', 'langconfig'));
        } else {
            $fechavalidacion = get_string('notavailable', 'moodle');
        }

        $this->content = (object)[
            'footer' => '',
            'text' => '<h4>Valida Cursos</h4>' .
                      '<p><strong>Fecha de inicio del curso:</strong> ' . $fechainicio . '</p>' .
                      '<p><strong>Fecha de validación configurada:</strong> ' . $fechavalidacion . '</p>',
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
}
