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

use \core_text; // <-- añadir esta línea

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
            $foro_nombre_tipo_incorrecto = false; // Nombre coincide pero el tipo no.
            $foro_tipo_detectado = '';
            $foro_id_detectado = 0;
            foreach ($foros as $f) {
                $nombre_coincide = core_text::strtolower(trim($f->name)) === core_text::strtolower(trim($finfo['titulo']));
                if ($nombre_coincide) {
                    if ($f->type === $finfo['type']) {
                        // Tipo correcto y nombre correcto: validamos ubicación.
                        $cm = $cms_by_instance[$f->id] ?? null;
                        if ($cm && $cm->section == $section0id) {
                            $foro_ok = true;
                            break; // Ya está validado, salimos.
                        }
                    } else {
                        // Nombre correcto pero tipo erróneo.
                        $foro_nombre_tipo_incorrecto = true;
                        $foro_tipo_detectado = $f->type;
                        $foro_id_detectado = $f->id;
                        // No hacemos break: podría existir otro con el tipo correcto.
                    }
                }
            }

            $estado_texto = $foro_ok ? 'Encontrado en la primera sección'
                : ($foro_nombre_tipo_incorrecto
                    ? 'Existe un foro con el nombre pero el tipo es incorrecto (se requiere tipo: ' . $finfo['type'] . ')'
                    : 'No encontrado o fuera de sección');

            $detalle = [
                'Nombre buscado' => $finfo['titulo'],
                'Estado' => $estado_texto
            ];
            if (!$foro_ok && $foro_nombre_tipo_incorrecto) {
                $detalle['Tipo detectado'] = $foro_tipo_detectado;
                $detalle['Tipo requerido'] = $finfo['type'];
                $detalle['_forumid'] = $foro_id_detectado;
            }

            $validaciones[] = [
                'nombre' => $finfo['nombre'],
                'estado' => $foro_ok,
                'mensaje' => $foro_ok ? 'Validado' : 'No validado',
                'detalle' => $detalle
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
                        $name = trim($urlobj->name);

                        // Prefijo con acento (válido).
                        if (preg_match('/^guía docente\b/iu', $name)) {
                            $guiadocente_ok = true;
                            $guiaurl = (new \moodle_url('/mod/url/view.php', ['id' => $cm->id]))->out();
                            if (strpos(trim($urlobj->externalurl), 'https://www.udima.es') === 0) {
                                $guiaurlok = true;
                            }
                            // Encontrado válido, salimos.
                            break;
                        }

                        // Prefijo sin acento (aviso).
                        if (preg_match('/^guia docente\b/iu', $name)) {
                            $guia_docente_sin_acentos = true;
                            // No break: podría existir otro con acento correcto.
                        }
                    }
                }
            }
        }

        $detalle_guia = [
            'Nombre buscado' => 'Guía Docente (prefijo, p.e. "Guía Docente", "Guía Docente de la asignatura")',
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
            $detalle_guia['Aviso'] = 'Existe un recurso cuyo título empieza por "Guia Docente" (sin acento en la i). Debe empezar por "Guía Docente".';
        }
        $validaciones[] = [
            'nombre' => 'Guía Docente en bloque cero',
            'estado' => $guiadocente_ok && $guiaurlok,
            'mensaje' => ($guiadocente_ok && $guiaurlok) ? 'Guía Docente encontrada y URL válida'
                : ($guiadocente_ok ? 'Guía Docente encontrada pero URL NO válida' : 'Guía Docente NO encontrada'),
            'detalle' => $detalle_guia
        ];

        // Validación de datos de tutoría en bloque cero (label con claves mínimas)
        $label_module_id = $DB->get_field('modules', 'id', ['name' => 'label'], MUST_EXIST);
        $label_encontrado = false;
        $claves = [
            ['Profesor:', 'Profesora:', 'Docente:'],
            ['Correo electrónico:', 'Correo:', 'Email:'],
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
                        if (is_array($clave)) {
                            $encontrada = false;
                            foreach ($clave as $variante) {
                                if (mb_stripos($intro, $variante) !== false) {
                                    $encontrada = true;
                                    break;
                                }
                            }
                            if (!$encontrada) {
                                $faltan_actual[] = implode(' / ', $clave);
                            }
                        } else {
                            if (mb_stripos($intro, $clave) === false) {
                                $faltan_actual[] = $clave;
                            }
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
            'Claves buscadas' => implode(', ', array_map(function($c) {
                return is_array($c) ? '[' . implode(' | ', $c) . ']' : $c;
            }, $claves)),
            'Estado' => $label_encontrado ? 'Encontrado' : 'No encontrado'
        ];
        if (!$label_encontrado && count($faltan) > 0) {
            $detalle_label['Faltan'] = implode(', ', array_map(function($c) {
                return is_array($c) ? '[' . implode(' | ', $c) . ']' : $c;
            }, $faltan));
        }

        $validaciones[] = [
            'nombre' => 'Datos de tutoría en bloque cero',
            'estado' => $label_encontrado,
            'mensaje' => $label_encontrado
                ? 'Datos de tutoría encontrados'
                : 'No se han encontrado los datos de tutoría requeridos',
            'detalle' => $detalle_label
        ];

        // --- NUEVAS VALIDACIONES USANDO HELPER (reemplaza las específicas anteriores) ---
        $cronograma_actividades_ok = self::existe_label_con_tabla_y_frase(
            $course->id,
            $section0id,
            'CRONOGRAMA DE ACTIVIDADES CALIFICABLES',
            $claves // Para excluir un label que sea realmente el de tutoría.
        );

        $validaciones[] = [
            'nombre' => 'Cronograma de actividades calificables en bloque cero',
            'estado' => $cronograma_actividades_ok,
            'mensaje' => $cronograma_actividades_ok
                ? 'Cronograma encontrado'
                : 'No se ha encontrado el cronograma en la sección 0',
            'detalle' => [
                'Requisito' => 'Label en sección 0 con tabla y texto: CRONOGRAMA DE ACTIVIDADES CALIFICABLES'
            ]
        ];

        $cronograma_sesiones_ok = self::existe_label_con_tabla_y_frase(
            $course->id,
            $section0id,
            'CRONOGRAMA DE SESIONES SÍNCRONAS',
            $claves
        );

        $validaciones[] = [
            'nombre' => 'Cronograma de sesiones síncronas en bloque cero',
            'estado' => $cronograma_sesiones_ok,
            'mensaje' => $cronograma_sesiones_ok
                ? 'Cronograma de sesiones encontrado'
                : 'No se ha encontrado el cronograma de sesiones en la sección 0',
            'detalle' => [
                'Requisito' => 'Label en sección 0 con tabla y texto: CRONOGRAMA DE SESIONES SÍNCRONAS'
            ]
        ];
        // --- FIN NUEVAS VALIDACIONES ---

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

            // Quitar de la lista de faltantes con comparación *insensible a mayúsculas/minúsculas*.
            foreach ($faltan_categorias as $i => $req) {
                if (core_text::strtolower($nombre) === core_text::strtolower($req)) {
                    unset($faltan_categorias[$i]);
                    break;
                }
            }

            // Guardar el peso de "Actividades no evaluables" (también insensible a mayúsculas/minúsculas).
            if (core_text::strtolower($nombre) === core_text::strtolower('Actividades no evaluables')) {
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

        // Validación: todas las actividades evaluables deben estar en una categoría del calificador.
        // La categoría raíz del curso (depth=1) equivale a "Sin categoría" (uncategorised).
        // Solo se muestra si existen actividades evaluables en el curso.
        $cat_raiz = $DB->get_record('grade_categories', ['courseid' => $course->id, 'depth' => 1]);
        $total_gradeitems = $DB->count_records('grade_items', [
            'courseid' => $course->id,
            'itemtype' => 'mod',
        ]);
        if ($total_gradeitems > 0) {
            $sin_categoria = [];
            if ($cat_raiz) {
                $items_sin_cat = $DB->get_records('grade_items', [
                    'courseid' => $course->id,
                    'itemtype' => 'mod',
                    'categoryid' => $cat_raiz->id,
                ]);
                foreach ($items_sin_cat as $gi) {
                    $mod_id = $DB->get_field('modules', 'id', ['name' => $gi->itemmodule]);
                    if (!$mod_id) {
                        $sin_categoria[] = s($gi->itemname) . ' (' . s($gi->itemmodule) . ')';
                        continue;
                    }
                    $cm = $DB->get_record('course_modules', [
                        'course' => $course->id,
                        'module' => $mod_id,
                        'instance' => $gi->iteminstance,
                    ]);
                    if ($cm) {
                        $url = (new \moodle_url('/course/modedit.php', ['update' => $cm->id]))->out();
                        $sin_categoria[] = '<a href="' . $url . '">' . s($gi->itemname) . '</a> (' . s($gi->itemmodule) . ')';
                    } else {
                        $sin_categoria[] = s($gi->itemname) . ' (' . s($gi->itemmodule) . ')';
                    }
                }
            }

            $actividades_ok = empty($sin_categoria);
            $validaciones[] = [
                'nombre' => 'Actividades evaluables en categorías del calificador',
                'estado' => $actividades_ok,
                'mensaje' => $actividades_ok
                    ? 'Todas las actividades evaluables están asignadas a una categoría'
                    : 'Hay actividades evaluables sin categoría en el calificador',
                'detalle' => $actividades_ok
                    ? ['Estado' => 'Todas las actividades evaluables tienen categoría asignada']
                    : ['Sin categoría' => implode(', ', $sin_categoria)],
            ];
        }

        // Validación: todos los buzones de tareas deben tener el flujo de trabajo (markingworkflow) activado.
        $assigns = $DB->get_records('assign', ['course' => $course->id]);
        if ($assigns) {
            $sin_workflow = [];
            $assign_module_id = $DB->get_field('modules', 'id', ['name' => 'assign']);
            foreach ($assigns as $assign) {
                if (empty($assign->markingworkflow)) {
                    $cm = $DB->get_record('course_modules', [
                        'course' => $course->id,
                        'module' => $assign_module_id,
                        'instance' => $assign->id,
                    ]);
                    if ($cm) {
                        $url = (new \moodle_url('/course/modedit.php', ['update' => $cm->id]))->out();
                        $sin_workflow[] = '<a href="' . $url . '">' . s($assign->name) . '</a>';
                    } else {
                        $sin_workflow[] = s($assign->name);
                    }
                }
            }

            $workflow_ok = empty($sin_workflow);
            $validaciones[] = [
                'nombre' => 'Flujo de trabajo en buzones de tareas',
                'estado' => $workflow_ok,
                'mensaje' => $workflow_ok
                    ? 'Todos los buzones tienen el flujo de trabajo activado'
                    : 'Hay buzones sin flujo de trabajo activado',
                'detalle' => $workflow_ok
                    ? ['Estado' => 'Todos los buzones de tareas tienen el flujo de trabajo activado']
                    : ['Sin flujo de trabajo' => implode(', ', $sin_workflow)],
            ];
        }

        // Validación: el curso debe tener la finalización activada.
        $completion_enabled = !empty($course->enablecompletion);
        $validaciones[] = [
            'nombre' => 'Finalización de curso activada',
            'estado' => $completion_enabled,
            'mensaje' => $completion_enabled
                ? 'La finalización de curso está activada'
                : 'La finalización de curso NO está activada',
            'detalle' => [
                'Estado' => $completion_enabled
                    ? 'Activada'
                    : 'Desactivada. Activar en ajustes del curso > Finalización.',
            ]
        ];

        // Validación: todas las actividades evaluables deben tener condiciones de finalización.
        // Solo se comprueba si la finalización del curso está activada.
        if ($completion_enabled) {
            $sin_finalizacion = [];
            $grade_items_mod = $DB->get_records('grade_items', [
                'courseid' => $course->id,
                'itemtype' => 'mod',
            ]);
            foreach ($grade_items_mod as $gi) {
                $mod_id = $DB->get_field('modules', 'id', ['name' => $gi->itemmodule]);
                if (!$mod_id) {
                    continue;
                }
                $cm = $DB->get_record('course_modules', [
                    'course' => $course->id,
                    'module' => $mod_id,
                    'instance' => $gi->iteminstance,
                ]);
                if ($cm && empty($cm->completion)) {
                    $url = (new \moodle_url('/course/modedit.php', ['update' => $cm->id]))->out();
                    $sin_finalizacion[] = '<a href="' . $url . '">' . s($gi->itemname) . '</a>';
                }
            }

            if (!empty($grade_items_mod)) {
                $finalizacion_ok = empty($sin_finalizacion);
                $validaciones[] = [
                    'nombre' => 'Condiciones de finalización en actividades evaluables',
                    'estado' => $finalizacion_ok,
                    'mensaje' => $finalizacion_ok
                        ? 'Todas las actividades evaluables tienen condiciones de finalización'
                        : 'Hay actividades evaluables sin condiciones de finalización',
                    'detalle' => $finalizacion_ok
                        ? ['Estado' => 'Todas las actividades evaluables tienen condiciones de finalización configuradas']
                        : ['Sin finalización' => implode(', ', $sin_finalizacion)],
                ];
            }
        }

        // Validación: el curso debe tener activada la opción de mostrar condiciones de finalización de actividad.
        $showcompletionconditions = !empty($course->showcompletionconditions);
        $validaciones[] = [
            'nombre' => 'Mostrar condiciones de finalización de actividad',
            'estado' => $showcompletionconditions,
            'mensaje' => $showcompletionconditions
                ? 'La opción de mostrar condiciones de finalización está activada'
                : 'La opción de mostrar condiciones de finalización NO está activada',
            'detalle' => [
                'Estado' => $showcompletionconditions
                    ? 'Activada'
                    : 'Desactivada. Activar en ajustes del curso > Finalización.',
            ]
        ];

        // Validación: el curso debe tener activada la opción de mostrar fechas de actividad.
        $showactivitydates = !empty($course->showactivitydates);
        $validaciones[] = [
            'nombre' => 'Mostrar fechas de actividad',
            'estado' => $showactivitydates,
            'mensaje' => $showactivitydates
                ? 'La opción de mostrar fechas de actividad está activada'
                : 'La opción de mostrar fechas de actividad NO está activada',
            'detalle' => [
                'Estado' => $showactivitydates
                    ? 'Activada'
                    : 'Desactivada. Activar en ajustes del curso > Apariencia.',
            ]
        ];

        return $validaciones;
    }

    /**
     * Valida si las dos fechas son iguales (timestamp).
     * @param int $fecha1
     * @param int $fecha2
     * @return bool
     */
    private static function fechas_son_iguales($fecha1, $fecha2) {
        return (int)$fecha1 === (int)$fecha2;
    }

    /**
     * Helper: normaliza texto HTML (elimina etiquetas, decodifica entidades y comprime espacios).
     * @param string $html
     * @return string
     */
    private static function normalizar_texto(string $html): string {
        $plaintext = html_entity_decode(strip_tags((string)$html), ENT_QUOTES, 'UTF-8');
        return preg_replace('/\s+/u', ' ', trim($plaintext));
    }

    /**
     * Helper: comprueba si el HTML de un label contiene (relajado) todas las palabras de la frase
     * con cualquier cantidad de espacios intermedios. Ignora mayúsculas/minúsculas y saltos.
     * @param string $html
     * @param string $frase
     * @return bool
     */
    private static function label_contiene_frase_relajada(string $html, string $frase): bool {
        $normalized = self::normalizar_texto($html);
        $parts = preg_split('/\s+/u', trim($frase));
        if (empty($parts)) {
            return false;
        }
        $pattern = '/'.implode('\s+', array_map('preg_quote', $parts)).'/iu';
        return (bool)preg_match($pattern, $normalized);
    }

    /**
     * Helper genérico: devuelve true si existe en la sección 0 un label que contenga
     * (a) una tabla (<table) y (b) la frase relajada indicada, excluyendo labels que contengan
     * todas las claves de exclusión (por ejemplo, datos de tutoría).
     *
     * Acepta $section0id nullable para evitar TypeError en contextos (por ejemplo, página principal)
     * donde no exista la sección 0 en la base de datos.
     *
     * @param int $courseid
     * @param int|null $section0id
     * @param string $frase
     * @param array $clavesexclusion (cada clave debe aparecer para excluir)
     * @return bool
     */
    private static function existe_label_con_tabla_y_frase(int $courseid, ?int $section0id, string $frase, array $clavesexclusion = []): bool {
        global $DB;
        if (!$section0id) {
            return false;
        }
        $labelmoduleid = $DB->get_field('modules', 'id', ['name' => 'label'], IGNORE_MISSING);
        if (!$labelmoduleid) {
            return false;
        }
        $cms = $DB->get_records('course_modules', [
            'course' => $courseid,
            'section' => $section0id,
            'module' => $labelmoduleid
        ]);
        if (!$cms) {
            return false;
        }
        $instances = array_map(fn($cm) => $cm->instance, $cms);
        list($in, $params) = $DB->get_in_or_equal($instances, SQL_PARAMS_NAMED);
        $labels = $DB->get_records_select('label', "id $in", $params);
        foreach ($labels as $label) {
            $html = (string)$label->intro;
            if (stripos($html, '<table') === false) {
                continue;
            }
            if (!self::label_contiene_frase_relajada($html, $frase)) {
                continue;
            }
            if ($clavesexclusion) {
                $normalized = self::normalizar_texto($html);
                $todos = true;
                foreach ($clavesexclusion as $clave) {
                    if (is_array($clave)) {
                        $algunaVariante = false;
                        foreach ($clave as $variante) {
                            if (mb_stripos($normalized, self::normalizar_texto($variante)) !== false) {
                                $algunaVariante = true;
                                break;
                            }
                        }
                        if (!$algunaVariante) {
                            $todos = false;
                            break;
                        }
                    } else {
                        if (mb_stripos($normalized, self::normalizar_texto($clave)) === false) {
                            $todos = false;
                            break;
                        }
                    }
                }
                if ($todos) {
                    // Es un label de exclusión (ej: tutoría), descartamos.
                    continue;
                }
            }
            return true;
        }
        return false;
    }
}
