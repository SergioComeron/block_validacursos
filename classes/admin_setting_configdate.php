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

defined('MOODLE_INTERNAL') || die();

class admin_setting_configdate extends \admin_setting {
    public function get_setting() {
        $value = $this->config_read($this->name);
        return is_numeric($value) ? (int)$value : 0;
    }

    public function write_setting($data) {
        // Si es array, intenta construir el timestamp.
        if (is_array($data)) {
            // Verifica que todas las claves existen.
            $year    = isset($data['year'])    ? (int)$data['year']    : 0;
            $mon     = isset($data['mon'])     ? (int)$data['mon']     : 0;
            $mday    = isset($data['mday'])    ? (int)$data['mday']    : 0;
            $hours   = isset($data['hours'])   ? (int)$data['hours']   : 0;
            $minutes = isset($data['minutes']) ? (int)$data['minutes'] : 0;
            if ($year && $mon && $mday) {
                $timestamp = make_timestamp($year, $mon, $mday, $hours, $minutes);
            } else {
                return get_string('errorsetting', 'admin');
            }
        } else if (is_numeric($data)) {
            // Si es un timestamp, úsalo directamente.
            $timestamp = (int)$data;
        } else {
            // Si los datos no son válidos, no guardar nada.
            return get_string('errorsetting', 'admin');
        }
        $result = $this->config_write($this->name, $timestamp);
        return $result ? '' : get_string('errorsetting', 'admin');
    }

    public function output_html($data, $query='') {
        $default = $this->get_defaultsetting();
        if ($default) {
            $defaultinfo = userdate($default, get_string('strftimedatetime', 'langconfig'));
        } else {
            $defaultinfo = '';
        }

        // Asegurarse de que $data sea un timestamp válido.
        if (empty($data) || !is_numeric($data)) {
            $data = time();
        }

        if (!is_array($data)) {
            $data = usergetdate($data);
        }

        $yearnow = (int)userdate(time(), '%Y');
        $monnames = [];
        for ($m = 1; $m <= 12; $m++) {
            $monnames[$m] = userdate(gmmktime(12, 0, 0, $m, 15, 2000), '%B');
        }

        $opts = [
            'mday'   => range(1, 31),
            'mon'    => $monnames,
            'year'   => range($yearnow - 2, $yearnow + 10),
            ' '      => ' ',
            'hours'  => range(0, 23),
            ':'      => ':',
            'minutes'=> range(0, 59)
        ];

        $output = '';
        foreach ($opts as $type => $choices) {
            if (!is_array($choices)) {
                $output .= $choices;
                continue;
            }
            if ($type !== 'mon') {
                $choices = array_combine($choices, $choices);
            }
            if ($type === 'hours' || $type === 'minutes') {
                $choices = array_map(function($n) { return sprintf('%02d', $n); }, $choices);
            }
            $selectname = $this->get_full_name() . "[$type]";
            $output .= html_writer::select($choices, $selectname, $data[$type], null);
        }
        $output = html_writer::tag('div', $output, ['class' => 'form-date defaultsnext']);

        return format_admin_setting($this, $this->visiblename, $output,
            $this->description, false, '', $defaultinfo, $query);
    }
}
