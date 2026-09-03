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

        // Validación: el curso debe tener un bloque del tipo "bloquecero" en la región "content-upper"
        // (región extra que aporta el tema Boost Union, no la "side-pre" estándar de Moodle).
        $regionesperada = 'content-upper';
        $coursecontext = \context_course::instance($course->id);
        $bloquecero_instancias = $DB->get_records('block_instances', [
            'blockname' => 'bloquecero',
            'parentcontextid' => $coursecontext->id,
        ]);

        $bloquecero_existe = !empty($bloquecero_instancias);
        $bloquecero_region_detectada = '';
        $bloquecero_en_header = false;

        // Configuración serializada del bloque cero (primera instancia), reutilizada por varias validaciones.
        // Si no hay bloque o la config está vacía, queda como objeto vacío.
        $bcconfig = new \stdClass();
        $bloquecero_instanceid = 0;
        if ($bloquecero_existe) {
            $bcprimera = reset($bloquecero_instancias);
            $bloquecero_instanceid = (int)$bcprimera->id;
            if (!empty($bcprimera->configdata)) {
                $bcdecoded = unserialize(base64_decode($bcprimera->configdata));
                if (is_object($bcdecoded)) {
                    $bcconfig = $bcdecoded;
                }
            }
        }

        $bloquecero_tiene_override = false;
        foreach ($bloquecero_instancias as $bi) {
            // Misma regla que cli/add_block_to_category.php de bloquecero:
            // defaultregion debe ser content-upper y no puede haber sobreescrituras en otra región.
            $overridesotras = $DB->get_records_select(
                'block_positions',
                'blockinstanceid = :biid AND region <> :region',
                ['biid' => $bi->id, 'region' => $regionesperada]
            );
            if ($bloquecero_region_detectada === '') {
                $bloquecero_region_detectada = $bi->defaultregion;
            }
            if ($bi->defaultregion === $regionesperada && empty($overridesotras)) {
                $bloquecero_en_header = true;
                $bloquecero_region_detectada = $bi->defaultregion;
                break;
            }
            if (!empty($overridesotras)) {
                $bloquecero_tiene_override = true;
                $primeraoverride = reset($overridesotras);
                $bloquecero_region_detectada = $primeraoverride->region;
            }
        }

        if (!$bloquecero_existe) {
            $estado_detalle = 'No encontrado. Añadir el bloque "Bloque cero" al curso en la región "' . $regionesperada . '".';
        } else if ($bloquecero_en_header) {
            $estado_detalle = 'Añadido en la región "' . $regionesperada . '"';
        } else if ($bloquecero_tiene_override) {
            $estado_detalle = 'Añadido, pero hay una sobreescritura de página en la región "'
                . $bloquecero_region_detectada . '" en lugar de "' . $regionesperada
                . '". Mover el bloque a la región "' . $regionesperada . '".';
        } else {
            $estado_detalle = 'Añadido, pero en la región "' . $bloquecero_region_detectada
                . '" en lugar de "' . $regionesperada . '". Mover el bloque a la región "' . $regionesperada . '".';
        }

        $validaciones[] = [
            'nombre' => 'Bloque cero',
            'estado' => $bloquecero_en_header,
            'mensaje' => $bloquecero_en_header
                ? 'El curso tiene el bloque cero en la región correcta'
                : 'El curso NO tiene el bloque cero en la región correcta',
            'detalle' => [
                'Región requerida' => $regionesperada,
                'Estado' => $estado_detalle,
                // Datos internos: permiten al bloque ofrecer los botones de añadir (si no existe)
                // o mover a la región correcta (si existe pero está mal ubicado).
                '_existe' => $bloquecero_existe ? 1 : 0,
                '_blockinstanceid' => $bloquecero_instanceid,
            ]
        ];

        // Validación: cada profesor mostrado en el bloque cero debe tener teléfono y horario rellenos.
        // Los datos se guardan en la configuración serializada del bloque con las claves dinámicas
        // userphone_<userid> y userschedule_<userid>. El alcance son los profesores seleccionados
        // (teacher_selected_<userid>); si no hay ninguna selección, se consideran todos los editing teachers.
        $datosprofes_detalle = [];
        $datosprofes_ok = true;

        if (!$bloquecero_existe) {
            $datosprofes_ok = false;
            $datosprofes_detalle['Estado'] = 'No existe el bloque cero en el curso, no se pueden validar los datos de profesores.';
        } else {
            // Profesores del curso (mismos que lista el bloque cero: capacidad moodle/course:update).
            $profesores = get_enrolled_users($coursecontext, 'moodle/course:update');

            // ¿Hay selección explícita de profesores?
            $hayseleccion = false;
            foreach ($bcconfig as $clave => $valor) {
                if (strpos($clave, 'teacher_selected_') === 0 && !empty($valor)) {
                    $hayseleccion = true;
                    break;
                }
            }

            $profesores_en_alcance = 0;
            foreach ($profesores as $profesor) {
                // Si hay selección, solo se valida a los profesores marcados.
                if ($hayseleccion && empty($bcconfig->{'teacher_selected_' . $profesor->id})) {
                    continue;
                }
                $profesores_en_alcance++;

                // Teléfono.
                $phonekey = 'userphone_' . $profesor->id;
                $phone = isset($bcconfig->$phonekey) ? trim((string)$bcconfig->$phonekey) : '';

                // Horario (puede ser un editor: array con 'text', o una cadena).
                $schedulekey = 'userschedule_' . $profesor->id;
                $scheduleval = $bcconfig->$schedulekey ?? null;
                if (is_array($scheduleval)) {
                    $schedule = isset($scheduleval['text']) ? $scheduleval['text'] : '';
                } else {
                    $schedule = is_string($scheduleval) ? $scheduleval : '';
                }
                $schedule = trim(strip_tags($schedule));

                $faltantes = [];
                if ($phone === '') {
                    $faltantes[] = 'teléfono';
                }
                if ($schedule === '') {
                    $faltantes[] = 'horario';
                }

                if (!empty($faltantes)) {
                    $datosprofes_ok = false;
                    $datosprofes_detalle[fullname($profesor)] = 'Falta rellenar: ' . implode(' y ', $faltantes);
                }
            }

            if ($profesores_en_alcance === 0) {
                $datosprofes_detalle['Estado'] = 'No hay profesores que validar en el bloque cero.';
            } else if ($datosprofes_ok) {
                $datosprofes_detalle['Estado'] = 'Todos los profesores tienen teléfono y horario rellenos.';
            }
        }

        $validaciones[] = [
            'nombre' => 'Datos de profesores en bloque cero',
            'estado' => $datosprofes_ok,
            'mensaje' => $datosprofes_ok
                ? 'Todos los profesores tienen sus datos en el bloque cero'
                : 'Hay profesores sin datos completos en el bloque cero',
            'detalle' => $datosprofes_detalle,
        ];

        // Validación: el bloque cero debe tener al menos una guía docente.
        // Las guías se guardan en block_bloquecero_guides filtradas por blockinstanceid y courseid.
        $guias_detalle = [];
        if (!$bloquecero_existe) {
            $guias_ok = false;
            $guias_detalle['Estado'] = 'No existe el bloque cero en el curso, no se pueden validar las guías docentes.';
        } else {
            $numguias = 0;
            foreach ($bloquecero_instancias as $bi) {
                $numguias += $DB->count_records('block_bloquecero_guides', [
                    'blockinstanceid' => $bi->id,
                    'courseid' => $course->id,
                ]);
            }
            $guias_ok = $numguias > 0;
            $guias_detalle['Guías docentes'] = $numguias;
            $guias_detalle['Estado'] = $guias_ok
                ? 'El bloque cero tiene al menos una guía docente.'
                : 'No hay ninguna guía docente. Añadir al menos una en el bloque cero.';
        }

        $validaciones[] = [
            'nombre' => 'Guía docente en bloque cero',
            'estado' => $guias_ok,
            'mensaje' => $guias_ok
                ? 'El bloque cero tiene guía docente'
                : 'El bloque cero NO tiene ninguna guía docente',
            'detalle' => $guias_detalle,
        ];

        // Validación: el bloque cero debe tener al menos una sesión en directo registrada
        // (block_bloquecero_sessions) y todas deben caer dentro del periodo del curso
        // (inicio sessiondate y fin sessiondate+duration entre course->startdate y course->enddate).
        $sesiones_detalle = [];
        if (!$bloquecero_existe) {
            $sesiones_ok = false;
            $sesiones_detalle['Estado'] = 'No existe el bloque cero en el curso, no se pueden validar las sesiones.';
        } else if (empty($course->startdate) || empty($course->enddate)) {
            $sesiones_ok = false;
            $sesiones_detalle['Estado'] = 'El curso no tiene fechas de inicio y fin definidas; no se puede validar el periodo.';
        } else {
            $sesiones = [];
            foreach ($bloquecero_instancias as $bi) {
                $sesiones += $DB->get_records('block_bloquecero_sessions', [
                    'blockinstanceid' => $bi->id,
                    'courseid' => $course->id,
                ]);
            }

            $numsesiones = count($sesiones);
            $fuera_de_rango = [];
            foreach ($sesiones as $s) {
                $inicio = (int)$s->sessiondate;
                $fin = $inicio + (int)($s->duration ?? 0);
                if ($inicio < (int)$course->startdate || $fin > (int)$course->enddate) {
                    $fuera_de_rango[$s->name] = userdate($inicio);
                }
            }

            $sesiones_ok = $numsesiones > 0 && empty($fuera_de_rango);
            $sesiones_detalle['Sesiones'] = $numsesiones;
            $sesiones_detalle['Periodo del curso'] = userdate($course->startdate) . ' — ' . userdate($course->enddate);
            if ($numsesiones === 0) {
                $sesiones_detalle['Estado'] = 'No hay ninguna sesión en directo. Añadir al menos una en el bloque cero.';
            } else if (!empty($fuera_de_rango)) {
                $sesiones_detalle['Estado'] = 'Hay sesiones fuera del periodo del curso.';
                foreach ($fuera_de_rango as $nombre => $fecha) {
                    $sesiones_detalle['Fuera de rango: ' . $nombre] = $fecha;
                }
            } else {
                $sesiones_detalle['Estado'] = 'Todas las sesiones están dentro del periodo del curso.';
            }
        }

        $validaciones[] = [
            'nombre' => 'Sesiones en directo en bloque cero',
            'estado' => $sesiones_ok,
            'mensaje' => $sesiones_ok
                ? 'El bloque cero tiene sesiones en directo válidas'
                : 'El bloque cero NO tiene sesiones en directo válidas',
            'detalle' => $sesiones_detalle,
        ];

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
                'titulo' => 'Tablón de anuncios',
                // Clave de la config del bloque cero que debe apuntar a este foro.
                'configkey' => 'forumid'
            ],
            [
                'nombre' => 'Foro de comunicación entre estudiantes',
                'type'   => 'general',
                'titulo' => 'Foro de comunicación entre estudiantes',
                'configkey' => 'forumestudiantesid'
            ],
            [
                'nombre' => 'Foro de tutorías de la asignatura',
                'type'   => 'general',
                'titulo' => 'Foro de tutorías de la asignatura',
                'configkey' => 'forumtutoriasid'
            ]
        ];

        foreach ($foros_a_validar as $finfo) {
            $foro_ok = false;
            $foro_nombre_tipo_incorrecto = false; // Nombre coincide pero el tipo no.
            $foro_tipo_detectado = '';
            $foro_id_detectado = 0;
            $foro_id_valido = 0; // Id del foro que cumple tipo + nombre + sección 0.
            foreach ($foros as $f) {
                $nombre_coincide = self::normalizar_para_comparar($f->name) === self::normalizar_para_comparar($finfo['titulo']);
                if ($nombre_coincide) {
                    if ($f->type === $finfo['type']) {
                        // Tipo correcto y nombre correcto: validamos ubicación.
                        $cm = $cms_by_instance[$f->id] ?? null;
                        if ($cm && $cm->section == $section0id) {
                            $foro_ok = true;
                            $foro_id_valido = $f->id;
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

            // Para los foros configurables en el bloque cero (p. ej. Tablón de anuncios), además
            // de existir el foro correcto, el bloque cero debe tenerlo seleccionado en su config.
            $config_requerida = !empty($finfo['configkey']);
            $config_ok = true;
            $config_forumid = 0;
            if ($config_requerida) {
                $ck = $finfo['configkey'];
                $config_forumid = isset($bcconfig->$ck) ? (int)$bcconfig->$ck : 0;
                $config_ok = $bloquecero_existe && $foro_ok
                    && $config_forumid > 0 && $config_forumid === (int)$foro_id_valido;
            }

            $estado_final = $foro_ok && $config_ok;

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

            // Detalle del requisito de configuración en el bloque cero.
            if ($config_requerida) {
                if (!$bloquecero_existe) {
                    $detalle['Bloque cero'] = 'No existe el bloque cero donde configurar el foro.';
                } else if (!$foro_ok) {
                    $detalle['Bloque cero'] = 'Pendiente: primero debe existir el foro correcto en la sección 0.';
                } else if ($config_forumid === 0) {
                    $detalle['Bloque cero'] = 'El foro existe pero NO está seleccionado en la configuración del bloque cero.';
                } else if ($config_forumid !== (int)$foro_id_valido) {
                    $detalle['Bloque cero'] = 'El bloque cero tiene seleccionado otro foro (id ' . $config_forumid
                        . ') en lugar de "' . $finfo['titulo'] . '".';
                } else {
                    $detalle['Bloque cero'] = 'Seleccionado correctamente en el bloque cero.';
                }

                // Datos internos para que el bloque pueda ofrecer el botón de "configurar en bloque cero".
                $detalle['_configkey'] = $finfo['configkey'];
                $detalle['_foro_existe'] = $foro_ok ? 1 : 0;
                $detalle['_foro_id_valido'] = (int)$foro_id_valido;
                $detalle['_bloquecero_instanceid'] = $bloquecero_instanceid;
            }

            $validaciones[] = [
                'nombre' => $finfo['nombre'],
                'estado' => $estado_final,
                'mensaje' => $estado_final ? 'Validado' : 'No validado',
                'detalle' => $detalle
            ];
        }

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
            // Eliminar contenido entre paréntesis para comparación flexible.
            // Ej: "Actividades de Aprendizaje (AA)" → "Actividades de Aprendizaje".
            $nombrebase = trim(preg_replace('/\s*\(.*?\)\s*/', ' ', $nombre));

            // Quitar de la lista de faltantes con comparación insensible a mayúsculas y tildes.
            foreach ($faltan_categorias as $i => $req) {
                if (self::normalizar_para_comparar($nombrebase) === self::normalizar_para_comparar($req)) {
                    unset($faltan_categorias[$i]);
                    break;
                }
            }

            // Guardar el peso de "Actividades no evaluables" (insensible a mayúsculas y tildes).
            if (self::normalizar_para_comparar($nombrebase) === self::normalizar_para_comparar('Actividades no evaluables')) {
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
        // Se excluyen las actividades que estén en la categoría "Actividades no evaluables".
        if ($completion_enabled) {
            $sin_finalizacion = [];
            // Obtener el ID de la categoría "Actividades no evaluables" para excluirla.
            $cat_no_evaluables_id = null;
            foreach ($grade_categories as $cat) {
                $catnombrebase = trim(preg_replace('/\s*\(.*?\)\s*/', ' ', $cat->fullname));
                if (self::normalizar_para_comparar($catnombrebase) === self::normalizar_para_comparar('Actividades no evaluables')) {
                    $cat_no_evaluables_id = $cat->id;
                    break;
                }
            }
            $grade_items_mod = $DB->get_records('grade_items', [
                'courseid' => $course->id,
                'itemtype' => 'mod',
            ]);
            foreach ($grade_items_mod as $gi) {
                // Excluir actividades en la categoría "Actividades no evaluables".
                if ($cat_no_evaluables_id && $gi->categoryid == $cat_no_evaluables_id) {
                    continue;
                }
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

        // Validación: el curso debe estar oculto (no visible para estudiantes).
        $coursehidden = empty($course->visible);
        $validaciones[] = [
            'nombre' => 'Curso oculto',
            'estado' => $coursehidden,
            'mensaje' => $coursehidden
                ? 'El curso está oculto'
                : 'El curso NO está oculto',
            'detalle' => [
                'Estado' => $coursehidden
                    ? 'Oculto'
                    : 'Visible. El curso debería estar oculto para los estudiantes.',
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
     * Elimina tildes y diacríticos de un texto Unicode.
     * Descompone los caracteres (NFD) y elimina los combining marks.
     * @param string $texto
     * @return string
     */
    private static function quitar_tildes(string $texto): string {
        $texto = \Normalizer::normalize($texto, \Normalizer::FORM_D);
        return preg_replace('/\pM/u', '', $texto);
    }

    /**
     * Normaliza un texto para comparación: minúsculas, sin tildes, sin espacios extra.
     * @param string $texto
     * @return string
     */
    private static function normalizar_para_comparar(string $texto): string {
        return rtrim(self::quitar_tildes(core_text::strtolower(trim($texto))), '.');
    }

}
