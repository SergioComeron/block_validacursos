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
 * Class validator
 *
 * @package    block_validacursos
 * @copyright  2025 Sergio Comerón <info@sergiocomeron.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class validator {
    /**
     * Ejecuta todas las validaciones y devuelve el array de resultados.
     * @param \stdClass $course
     * @param \stdClass $config
     * @return array
     */
    public static function get_validaciones(\stdClass $course, \stdClass $config): array {
        global $DB;

        $validaciones = [];

        // Validación fecha de inicio.
        $validafecha = !empty($course->startdate) && !empty($config->fechainiciovalidacion)
            && self::fechas_son_iguales($course->startdate, $config->fechainiciovalidacion);

        $validaciones[] = [
            'nombre' => 'Fecha de inicio',
            'estado' => $validafecha,
            'mensaje' => $validafecha ? 'Fecha Inicio validada' : 'Fecha Inicio NO validada',
            'detalle' => [
                'Curso' => !empty($course->startdate) ? userdate($course->startdate) : get_string('notavailable', 'moodle'),
                'Configuración' => !empty($config->fechainiciovalidacion) ? userdate($config->fechainiciovalidacion) : get_string('notavailable', 'moodle')
            ]
        ];

        // Validación fecha de fin.
        $validafechafin = !empty($course->enddate) && !empty($config->fechafinvalidacion)
            && self::fechas_son_iguales($course->enddate, $config->fechafinvalidacion);

        $validaciones[] = [
            'nombre' => 'Fecha de fin',
            'estado' => $validafechafin,
            'mensaje' => $validafechafin ? 'Fecha Fin validada' : 'Fecha Fin NO validada',
            'detalle' => [
                'Curso' => !empty($course->enddate) ? userdate($course->enddate) : get_string('notavailable', 'moodle'),
                'Configuración' => !empty($config->fechafinvalidacion) ? userdate($config->fechafinvalidacion) : get_string('notavailable', 'moodle')
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

        // Validación de la existencia de la URL "Guia Docente" en el bloque cero (sección 0)
        $guiadocente_ok = false;
        $guiaurl = '';
        $guiaurlok = false;
        $guia_docente_sin_acentos = false;
        // Obtener todos los módulos de la sección 0
        $section0mods = [];
        if ($section0id) {
            $section0mods = $DB->get_records('course_modules', ['course' => $course->id, 'section' => $section0id]);
        }
        if ($section0mods) {
            // Obtener todos los recursos url del curso
            $urls = $DB->get_records('url', ['course' => $course->id]);
            foreach ($section0mods as $cm) {
                if ($cm->module == $DB->get_field('modules', 'id', ['name' => 'url'])) {
                    if (isset($urls[$cm->instance])) {
                        $urlobj = $urls[$cm->instance];
                        if (trim($urlobj->name) === 'Guía Docente') {
                            $guiadocente_ok = true;
                            $guiaurl = (new \moodle_url('/mod/url/view.php', ['id' => $cm->id]))->out();
                            // Comprobar que la URL empieza por https://www.udima.es
                            if (strpos(trim($urlobj->externalurl), 'https://www.udima.es') === 0) {
                                $guiaurlok = true;
                            }
                            break;
                        } else if (trim($urlobj->name) === 'Guia Docente') {
                            $guia_docente_sin_acentos = true;
                        }
                    }
                }
            }
        }
        $detalle_guia = [
            'Nombre buscado' => 'Guía Docente',
            'Estado' => $guiadocente_ok
                ? ('Encontrada en la sección 0 <a href="' . $guiaurl . '" target="_blank" style="margin-left:8px;">Ver</a>')
                : 'No encontrada en la sección 0',
            'URL' => $guiadocente_ok
                ? (isset($urlobj->externalurl) ? s($urlobj->externalurl) : '-')
                : '-',
            'Validación URL' => $guiadocente_ok
                ? ($guiaurlok ? 'La URL es válida' : 'La URL NO es válida (debe empezar por https://www.udima.es)')
                : '-'
        ];
        if (!$guiadocente_ok && $guia_docente_sin_acentos) {
            $detalle_guia['Aviso'] = 'Existe una URL llamada "Guia Docente" (sin acento en la i). Debe llamarse exactamente "Guía Docente".';
        }
        $validaciones[] = [
            'nombre' => 'Guía Docente en bloque cero',
            'estado' => $guiadocente_ok && $guiaurlok,
            'mensaje' => ($guiadocente_ok && $guiaurlok) ? 'Guía Docente encontrada y URL válida' :
                ($guiadocente_ok ? 'Guía Docente encontrada pero URL NO válida' : 'Guía Docente NO encontrada'),
            'detalle' => $detalle_guia
        ];

        // Validación de datos de tutoría en bloque cero (label con claves mínimas)
        $label_module_id = $DB->get_field('modules', 'id', ['name' => 'label'], MUST_EXIST);
        $label_encontrado = false;
        $claves = [
            'Profesor:',
            'Correo electrónico:',
            'Teléfono',
            'Extensión',
            'Horario de tutorías'
        ];
        $faltan = $claves;

        // Obtener todos los course_modules de sección 0 que sean label
        if ($section0id) {
            $section0mods = $DB->get_records('course_modules', [
                'course' => $course->id,
                'section' => $section0id,
                'module' => $label_module_id
            ]);
            if ($section0mods) {
                $label_instances = array_map(function($cm) { return $cm->instance; }, $section0mods);
                list($in_sql, $params) = $DB->get_in_or_equal($label_instances);
                $labels = $DB->get_records_select('label', "id $in_sql", $params);
                foreach ($labels as $label) {
                    $intro = strip_tags($label->intro);
                    $faltan_actual = [];
                    foreach ($claves as $clave) {
                        if (mb_stripos($intro, $clave) === false) {
                            $faltan_actual[] = $clave;
                        }
                    }
                    if (empty($faltan_actual)) {
                        $label_encontrado = true;
                        $faltan = [];
                        break;
                    } else {
                        // Si hay varias labels, nos quedamos con la que menos le falte
                        if (count($faltan_actual) < count($faltan)) {
                            $faltan = $faltan_actual;
                        }
                    }
                }
            }
        }

        $detalle_label = [
            'Claves buscadas' => implode(', ', $claves),
            'Estado' => $label_encontrado ? 'Encontrado' : 'No encontrado'
        ];
        if (!$label_encontrado && count($faltan) > 0) {
            $detalle_label['Faltan'] = implode(', ', $faltan);
        }

        $validaciones[] = [
            'nombre' => 'Datos de tutoría en bloque cero',
            'estado' => $label_encontrado,
            'mensaje' => $label_encontrado
                ? 'Datos de tutoría encontrados'
                : 'No se han encontrado los datos de tutoría requeridos',
            'detalle' => $detalle_label
        ];

        // Validación de label con tabla y texto "CRONOGRAMA DE ACTIVIDADES CALIFICABLES" en bloque cero
        $label_cronograma_ok = false;
        $label_cronograma_faltas = [];
        $label_cronograma_texto = 'CRONOGRAMA DE ACTIVIDADES CALIFICABLES';

        if ($section0id) {
            // Reutilizamos $section0mods y $labels si ya están definidos, si no, los obtenemos
            if (!isset($section0mods) || !$section0mods) {
                $section0mods = $DB->get_records('course_modules', [
                    'course' => $course->id,
                    'section' => $section0id,
                    'module' => $label_module_id
                ]);
            }
            if (!isset($labels) || !$labels) {
                if ($section0mods) {
                    $label_instances = array_map(function($cm) { return $cm->instance; }, $section0mods);
                    list($in_sql, $params) = $DB->get_in_or_equal($label_instances);
                    $labels = $DB->get_records_select('label', "id $in_sql", $params);
                } else {
                    $labels = [];
                }
            }

            foreach ($labels as $label) {
                $intro = $label->intro;
                // Comprobar que contiene una tabla y el texto buscado
                $tiene_tabla = stripos($intro, '<table') !== false;
                $tiene_texto = stripos(strip_tags($intro), $label_cronograma_texto) !== false;
                // Evitar que sea el mismo label que el de tutoría (por ejemplo, comprobando que no contiene todas las claves de tutoría)
                $es_label_tutoria = true;
                foreach ($claves as $clave) {
                    if (mb_stripos(strip_tags($intro), $clave) === false) {
                        $es_label_tutoria = false;
                        break;
                    }
                }
                if ($tiene_tabla && $tiene_texto && !$es_label_tutoria) {
                    $label_cronograma_ok = true;
                    break;
                }
            }
        }

        $validaciones[] = [
            'nombre' => 'Cronograma de actividades calificables en bloque cero',
            'estado' => $label_cronograma_ok,
            'mensaje' => $label_cronograma_ok
                ? 'Cronograma encontrado'
                : 'No se ha encontrado el cronograma en la sección 0',
            'detalle' => [
                'Debe existir un recurso de tipo "Text and media area" (label) en la sección 0 que contenga una tabla y el texto: ' . $label_cronograma_texto
            ]
        ];

        // Validación de label con tabla y texto "CRONOGRAMA DE SESIONES SÍNCRONAS" en bloque cero
        $label_sesiones_ok = false;
        $label_sesiones_texto = 'CRONOGRAMA DE SESIONES SÍNCRONAS';

        if ($section0id) {
            // Reutilizar $section0mods y $labels si ya existen; si no, cargarlos.
            if (!isset($section0mods) || !$section0mods) {
                $section0mods = $DB->get_records('course_modules', [
                    'course' => $course->id,
                    'section' => $section0id,
                    'module' => $label_module_id
                ]);
            }
            if (!isset($labels) || !$labels) {
                if ($section0mods) {
                    $label_instances = array_map(function($cm) { return $cm->instance; }, $section0mods);
                    list($in_sql, $params) = $DB->get_in_or_equal($label_instances);
                    $labels = $DB->get_records_select('label', "id $in_sql", $params);
                } else {
                    $labels = [];
                }
            }

            foreach ($labels as $label) {
                $intro = $label->intro;
                $tiene_tabla = stripos($intro, '<table') !== false;
                $tiene_texto = stripos(strip_tags($intro), $label_sesiones_texto) !== false;

                // Evitar confundir con el label de tutoría (no debe contener todas las claves de tutoría).
                $es_label_tutoria = true;
                foreach ($claves as $clave) {
                    if (mb_stripos(strip_tags($intro), $clave) === false) {
                        $es_label_tutoria = false;
                        break;
                    }
                }

                if ($tiene_tabla && $tiene_texto && !$es_label_tutoria) {
                    $label_sesiones_ok = true;
                    break;
                }
            }
        }

        $validaciones[] = [
            'nombre' => 'Cronograma de sesiones síncronas en bloque cero',
            'estado' => $label_sesiones_ok,
            'mensaje' => $label_sesiones_ok
                ? 'Cronograma de sesiones encontrado'
                : 'No se ha encontrado el cronograma de sesiones en la sección 0',
            'detalle' => [
                'Debe existir un recurso de tipo "Text and media area" (label) en la sección 0 que contenga una tabla y el texto: ' . $label_sesiones_texto
            ]
        ];

        // Validación de categorías del calificador.
        $categorias_requeridas = [
            'Actividades de aprendizaje',
            'Controles',
            'Actividades de evaluación continua',
            'Examen final',
            'Actividades no evaluables'
        ];
        $faltan_categorias = $categorias_requeridas;
        $peso_actividades_no_evaluables = null;

        // Obtener todas las categorías del calificador del curso
        $grade_categories = $DB->get_records('grade_categories', ['courseid' => $course->id]);
        foreach ($grade_categories as $cat) {
            $nombre = trim($cat->fullname);
            $idx = array_search($nombre, $faltan_categorias);
            if ($idx !== false) {
                unset($faltan_categorias[$idx]);
            }
            // Guardar el peso de "Actividades no evaluables"
            if ($nombre === 'Actividades no evaluables') {
                // Buscar el grade_item asociado a la categoría
                $gradeitem = $DB->get_record('grade_items', [
                    'itemtype' => 'category',
                    'iteminstance' => $cat->id,
                    'courseid' => $course->id
                ]);
                if ($gradeitem) {
                    if (!empty($gradeitem->weightoverride)) {
                        $peso_actividades_no_evaluables = $gradeitem->aggregationcoef2;
                    } else {
                        $peso_actividades_no_evaluables = $gradeitem->aggregationcoef;
                    }
                } else {
                    $peso_actividades_no_evaluables = null;
                }
            }
        }

        $detalle_categorias = [
            'Requeridas' => implode(', ', $categorias_requeridas),
            'Faltan' => empty($faltan_categorias) ? '-' : implode(', ', $faltan_categorias)
        ];

        if ($peso_actividades_no_evaluables !== null) {
            $detalle_categorias['Peso "Actividades no evaluables"'] = $peso_actividades_no_evaluables;
            // Compara como float, permitiendo pequeñas diferencias
            if (abs((float)$peso_actividades_no_evaluables) > 0.00001) {
                $detalle_categorias['Error peso'] = 'La categoría "Actividades no evaluables" debe tener peso 0';
            }
        } else {
            $detalle_categorias['Peso "Actividades no evaluables"'] = '-';
        }

        $validaciones[] = [
            'nombre' => 'Categorías del calificador',
            'estado' => empty($faltan_categorias) && (is_null($peso_actividades_no_evaluables) || abs((float)$peso_actividades_no_evaluables) < 0.00001),
            'mensaje' => (empty($faltan_categorias) && $peso_actividades_no_evaluables === 0)
                ? 'Todas las categorías requeridas están presentes y el peso es correcto'
                : 'Faltan categorías o el peso de "Actividades no evaluables" no es 0',
            'detalle' => $detalle_categorias
        ];

        return $validaciones;



        // Aquí se agregarían las validaciones específicas.
        return $result;
    }

    /**
     * Valida si las dos fechas son iguales (timestamp).
     *
     * @param int $fecha1
     * @param int $fecha2
     * @return bool
     */
    private static function fechas_son_iguales($fecha1, $fecha2) {
        return (int)$fecha1 === (int)$fecha2;
    }
}
