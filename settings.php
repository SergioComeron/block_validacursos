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

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/classes/admin_setting_configdate.php');

if ($hassiteconfig) {
    // No crear ni añadir la página: $settings ya lo hace Moodle.
    if ($ADMIN->fulltree) {
        $settings->add(new admin_setting_configdate(
            'block_validacursos/fechainiciovalidacion',
            get_string('fechainiciovalidacion', 'block_validacursos'),
            get_string('fechainiciovalidacion_desc', 'block_validacursos'),
            time()
        ));
        $settings->add(new admin_setting_configdate(
            'block_validacursos/fechafinvalidacion',
            get_string('fechafinvalidacion', 'block_validacursos'),
            get_string('fechafinvalidacion_desc', 'block_validacursos'),
            time()
        ));

        // Enlace dentro de la página de ajustes.
        $reporturl = new moodle_url('/blocks/validacursos/report.php');
        $settings->add(new admin_setting_heading(
            'block_validacursos_reportlink',
            get_string('issuesreport', 'block_validacursos'),
            html_writer::link($reporturl, get_string('issuesreport', 'block_validacursos'))
        ));
    }

    // Nodo externo opcional en el árbol de administración.
    $ADMIN->add('blocksettings', new admin_externalpage(
        'block_validacursos_report',
        get_string('issuesreport', 'block_validacursos'),
        new moodle_url('/blocks/validacursos/report.php'),
        'block/validacursos:viewissuesreport'
    ));
}


