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

namespace block_validacursos\local;
defined('MOODLE_INTERNAL') || die();


/**
 * Class logger
 *
 * @package    block_validacursos
 * @copyright  2025 Sergio Comerón <info@sergiocomeron.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class logger {
    public static function save_course_results_history(int $courseid, array $validaciones): void {
        global $DB;
        $now = time();

        // Mapa por nombre.
        $byname = [];
        foreach ($validaciones as $v) {
            if (!empty($v['nombre'])) { $byname[$v['nombre']] = !empty($v['estado']); }
        }

        // Abrir/actualizar negativas.
        foreach ($byname as $name => $ok) {
            if ($ok) { continue; }
            $open = $DB->get_record('block_validacursos_issues', [
                'courseid' => $courseid,
                'validation' => $name,
                'resolvedat' => null
            ]);
            if ($open) {
                $open->lastseen = $now;
                $DB->update_record('block_validacursos_issues', $open);
            } else {
                $rec = (object)[
                    'courseid' => $courseid,
                    'validation' => mb_substr($name, 0, 255),
                    'state' => 0,
                    'firstseen' => $now,
                    'lastseen' => $now,
                    'resolvedat' => null
                ];
                $DB->insert_record('block_validacursos_issues', $rec);
            }
        }

        // Cerrar incidencias que ahora estén OK.
        $openissues = $DB->get_records('block_validacursos_issues', ['courseid' => $courseid, 'resolvedat' => null]);
        foreach ($openissues as $issue) {
            $name = $issue->validation;
            if (isset($byname[$name]) && $byname[$name] === true) {
                $issue->state = 1;
                $issue->resolvedat = $now;
                $DB->update_record('block_validacursos_issues', $issue);
            }
        }
    }
}
