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
 * Admin setting with Moodle's date/time calendar picker.
 *
 * @package    block_validacursos
 * @copyright  2025 Sergio Comerón <info@sergiocomeron.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_validacursos;

defined('MOODLE_INTERNAL') || die();

/**
 * Admin setting that stores a unix timestamp and uses the core calendar picker.
 */
class admin_setting_configdate extends \admin_setting {

    /**
     * Current value as unix timestamp.
     *
     * @return int
     */
    public function get_setting() {
        $value = $this->config_read($this->name);
        return is_numeric($value) ? (int)$value : 0;
    }

    /**
     * Save the posted day/month/year/hour/minute as a unix timestamp.
     *
     * @param array|int|string $data
     * @return string Empty string on success, error string otherwise.
     */
    public function write_setting($data) {
        if (is_array($data)) {
            $year = (int)($data['year'] ?? 0);
            $month = (int)($data['month'] ?? $data['mon'] ?? 0);
            $day = (int)($data['day'] ?? $data['mday'] ?? 0);
            $hour = (int)($data['hour'] ?? $data['hours'] ?? 0);
            $minute = (int)($data['minute'] ?? $data['minutes'] ?? 0);
            if (!$year || !$month || !$day) {
                return get_string('errorsetting', 'admin');
            }
            $timestamp = make_timestamp($year, $month, $day, $hour, $minute);
        } else if (is_numeric($data)) {
            $timestamp = (int)$data;
        } else {
            return get_string('errorsetting', 'admin');
        }

        return $this->config_write($this->name, $timestamp) ? '' : get_string('errorsetting', 'admin');
    }

    /**
     * Render Moodle's date_time_selector (dropdowns + calendar button).
     *
     * @param mixed $data Current value (timestamp or posted array).
     * @param string $query
     * @return string
     */
    public function output_html($data, $query = '') {
        global $OUTPUT, $CFG;

        require_once($CFG->libdir . '/formslib.php');
        form_init_date_js();

        $default = $this->get_defaultsetting();
        $defaultinfo = $default ? userdate($default, get_string('strftimedatetime', 'langconfig')) : '';

        if (is_array($data)) {
            $year = (int)($data['year'] ?? 0);
            $month = (int)($data['month'] ?? $data['mon'] ?? 0);
            $day = (int)($data['day'] ?? $data['mday'] ?? 0);
            $hour = (int)($data['hour'] ?? $data['hours'] ?? 0);
            $minute = (int)($data['minute'] ?? $data['minutes'] ?? 0);
            $timestamp = ($year && $month && $day) ? make_timestamp($year, $month, $day, $hour, $minute) : time();
        } else {
            $timestamp = (empty($data) || !is_numeric($data)) ? time() : (int)$data;
        }

        $parts = usergetdate($timestamp);
        $selected = [
            'day' => (int)$parts['mday'],
            'month' => (int)$parts['mon'],
            'year' => (int)$parts['year'],
            'hour' => (int)$parts['hours'],
            'minute' => (int)$parts['minutes'],
        ];

        $calendartype = \core_calendar\type_factory::get_calendar_instance();
        $dateorder = $calendartype->get_date_order($calendartype->get_min_year(), $calendartype->get_max_year());
        $fullname = $this->get_full_name();
        $selectattrs = ['class' => 'form-select d-inline-block me-1'];

        $html = '';
        foreach ($dateorder as $key => $options) {
            $html .= \html_writer::select($options, $fullname . '[' . $key . ']', $selected[$key], false, $selectattrs);
        }

        $hours = [];
        for ($i = 0; $i <= 23; $i++) {
            $hours[$i] = sprintf('%02d', $i);
        }
        $minutes = [];
        for ($i = 0; $i < 60; $i++) {
            $minutes[$i] = sprintf('%02d', $i);
        }
        $html .= \html_writer::select($hours, $fullname . '[hour]', $selected['hour'], false, $selectattrs);
        $html .= \html_writer::select($minutes, $fullname . '[minute]', $selected['minute'], false, $selectattrs);

        if ($calendartype->get_name() === 'gregorian') {
            // Unique id is required: Moodle's dateselector JS does getElementById
            // on the button; a missing/duplicate id throws and skips later pickers.
            $html .= \html_writer::tag('button', $OUTPUT->pix_icon('i/calendar', ''), [
                'type' => 'button',
                'id' => $this->get_id() . '_calendar',
                'name' => $fullname . '[calendar]',
                'title' => get_string('datepicker', 'calendar'),
                'aria-label' => get_string('datepicker', 'calendar'),
                'class' => 'btn btn-link btn-sm icon-no-margin',
            ]);
        }

        $html = \html_writer::div($html, 'fdate_time_selector d-flex flex-wrap align-items-center defaultsnext');

        return format_admin_setting($this, $this->visiblename, $html, $this->description, false, '', $defaultinfo, $query);
    }
}
