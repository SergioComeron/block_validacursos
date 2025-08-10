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
 * Upgrade steps for Validacursos
 *
 * Documentation: {@link https://moodledev.io/docs/guides/upgrade}
 *
 * @package    block_validacursos
 * @category   upgrade
 * @copyright  2025 Sergio Comerón <info@sergiocomeron.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Execute the plugin upgrade steps from the given old version.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_block_validacursos_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    // Versión 2025081000: crear/actualizar tabla de issues con histórico.
    if ($oldversion < 2025081000) {
        $table = new xmldb_table('block_validacursos_issues');

        // Definición de campos.
        $id           = new xmldb_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $courseid     = new xmldb_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $validation   = new xmldb_field('validation', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $message      = new xmldb_field('message', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $detailsjson  = new xmldb_field('detailsjson', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $checksum     = new xmldb_field('checksum', XMLDB_TYPE_CHAR, '40', null, null, null, null);
        $state        = new xmldb_field('state', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $firstseen    = new xmldb_field('firstseen', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $lastseen     = new xmldb_field('lastseen', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $resolvedat   = new xmldb_field('resolvedat', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $timecreated  = new xmldb_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $timemodified = new xmldb_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        if (!$dbman->table_exists($table)) {
            // Crear tabla completa.
            $table->addField($id);
            $table->addField($courseid);
            $table->addField($validation);
            $table->addField($message);
            $table->addField($detailsjson);
            $table->addField($checksum);
            $table->addField($state);
            $table->addField($firstseen);
            $table->addField($lastseen);
            $table->addField($resolvedat);
            $table->addField($timecreated);
            $table->addField($timemodified);

            $table->addKey(new xmldb_key('primary', XMLDB_KEY_PRIMARY, ['id']));

            $dbman->create_table($table);

            // Índices.
            $dbman->add_index($table, new xmldb_index('courseidx', XMLDB_INDEX_NOTUNIQUE, ['courseid']));
            $dbman->add_index($table, new xmldb_index('course_validation_idx', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'validation']));
            $dbman->add_index($table, new xmldb_index('open_idx', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'validation', 'resolvedat']));
        } else {
            // Tabla existente: asegurar campos/índices (upgrade incremental).
            $fields = [$courseid, $validation, $message, $detailsjson, $checksum, $state, $firstseen, $lastseen, $resolvedat, $timecreated, $timemodified];
            foreach ($fields as $f) {
                if (!$dbman->field_exists($table, $f)) {
                    $dbman->add_field($table, $f);
                }
            }
            // Índices.
            $idx1 = new xmldb_index('courseidx', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
            if (!$dbman->index_exists($table, $idx1)) {
                $dbman->add_index($table, $idx1);
            }
            $idx2 = new xmldb_index('course_validation_idx', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'validation']);
            if (!$dbman->index_exists($table, $idx2)) {
                $dbman->add_index($table, $idx2);
            }
            $idx3 = new xmldb_index('open_idx', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'validation', 'resolvedat']);
            if (!$dbman->index_exists($table, $idx3)) {
                $dbman->add_index($table, $idx3);
            }
        }

        upgrade_block_savepoint(true, 2025081000, 'validacursos');
    }
    // Versión 2025081200: simplificar tabla quitando campos no necesarios.
    if ($oldversion < 2025081200) {
        $table = new xmldb_table('block_validacursos_issues');
        $drop = ['message','detailsjson','checksum','timecreated','timemodified'];
        foreach ($drop as $name) {
            $field = new xmldb_field($name);
            if ($dbman->field_exists($table, $field)) {
                $dbman->drop_field($table, $field);
            }
        }
        // Asegurar índices mínimos.
        $idx1 = new xmldb_index('courseidx', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
        if (!$dbman->index_exists($table, $idx1)) { $dbman->add_index($table, $idx1); }
        $idx2 = new xmldb_index('course_validation_idx', XMLDB_INDEX_NOTUNIQUE, ['courseid','validation']);
        if (!$dbman->index_exists($table, $idx2)) { $dbman->add_index($table, $idx2); }
        $idx3 = new xmldb_index('open_idx', XMLDB_INDEX_NOTUNIQUE, ['courseid','validation','resolvedat']);
        if (!$dbman->index_exists($table, $idx3)) { $dbman->add_index($table, $idx3); }

        upgrade_block_savepoint(true, 2025081200, 'validacursos');
    }

    return true;
}
