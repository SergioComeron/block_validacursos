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
 * TODO describe file settings
 *
 * @package    block_validacursos
 * @copyright  2025 Sergio Comerón <info@sergiocomeron.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/classes/admin_setting_configdate.php');

if ($hassiteconfig) { // Solo mostrar la configuración a los administradores.
    $settings->add(new admin_setting_configdate(  
        'block_validacursos/fechainiciovalidacion',               // Nombre del ajuste con prefijo del bloque  
        get_string('fechainiciovalidacion', 'block_validacursos'), // Nombre visible (cadena de idioma)  
        get_string('fechainiciovalidacion_desc', 'block_validacursos'), // Descripción (cadena de idioma)  
        time()  // Valor por defecto (timestamp actual, por ejemplo)  
    ));
    $settings->add(new admin_setting_configdate(  
        'block_validacursos/fechafinvalidacion',               // Nombre del ajuste con prefijo del bloque  
        get_string('fechafinvalidacion', 'block_validacursos'), // Nombre visible (cadena de idioma)  
        get_string('fechafinvalidacion_desc', 'block_validacursos'), // Descripción (cadena de idioma)  
        time()  // Valor por defecto (timestamp actual, por ejemplo)  
    ));
}


