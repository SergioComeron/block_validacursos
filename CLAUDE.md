# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Qué es este proyecto

Plugin de tipo bloque para Moodle (`block_validacursos`) que valida automáticamente la estructura y contenido de cursos contra reglas institucionales predefinidas (UDIMA). Verifica fechas, foros obligatorios, contenido del bloque cero (guía docente, sesiones en directo, datos de profesores) y categorías del calificador. Permite crear o configurar recursos faltantes con un clic y registra las incidencias en base de datos para su seguimiento.

**Versión mínima Moodle:** 4.4.0 (2022100700)

## Desarrollo

Este es un plugin PHP puro sin dependencias externas (no hay package.json, ni Composer). Se desarrolla dentro de una instalación Moodle completa en `blocks/validacursos/`.

No hay tests automatizados, ni linter configurado, ni sistema de build propio. Para verificar cambios:
- Probar directamente en un curso Moodle con el bloque añadido.
- Comprobar el reporte de incidencias en `/blocks/validacursos/report.php`.
- Si se modifica `db/install.xml` o `db/upgrade.php`, incrementar la versión en `version.php` y ejecutar la actualización de Moodle.

## Arquitectura

### Flujo principal

1. El bloque se renderiza en la página del curso (`block_validacursos::get_content()`)
2. Se comprueba que el curso pertenezca a las categorías permitidas (setting `allowedcategories`)
3. `validator::get_validaciones()` ejecuta las reglas de validación
4. `logger::save_course_results_history()` registra las validaciones fallidas en `block_validacursos_issues`
5. El bloque muestra secciones colapsables con resultados y botones de acción

### Componentes clave

- **`block_validacursos.php`** — Clase principal del bloque. Renderiza la UI y maneja las acciones inline (crear foros, actualizar fechas, crear cronogramas, crear categorías del calificador, añadir el bloque cero, configurar foros en el bloque cero). Las acciones se disparan vía `optional_param` en GET. Si el bloque cero no existe, la validación "Bloque cero" muestra el botón añadir (+, acción `addbloquecero` que inserta una instancia de bloquecero en `block_instances` con `defaultregion = content-upper`); si existe pero está en región incorrecta, muestra el botón mover (➜, acción `movebloquecero` que fija `defaultregion = content-upper` y alinea los `block_positions` de la instancia a esa región). Para los foros validados contra el bloque cero: si el foro no existe muestra el botón crear (+); si existe pero no está seleccionado en el bloque cero muestra el botón configurar (⚙, acción `configforobloquecero` que escribe la clave correspondiente en el `configdata` de la instancia de bloquecero).
- **`classes/local/validator.php`** — Motor de validación. Contiene toda la lógica de las reglas. Métodos estáticos auxiliares: `quitar_tildes()` para eliminar diacríticos Unicode (NFD + `\pM`), `normalizar_para_comparar()` para comparación insensible a mayúsculas y tildes.
- **`classes/task/validate_all_courses.php`** — Tarea programada que valida todos los cursos de las categorías permitidas (tanto visibles como ocultos), excluyendo cursos hijos meta-enlazados. Se ejecuta diariamente a las 2 AM.
- **`classes/local/logger.php`** — Persistencia en BD. Crea incidencias nuevas, actualiza `lastseen` en incidencias abiertas, y marca como resueltas cuando la validación pasa.
- **`report.php`** — Página de reporte admin con pestañas de contenido (top/issues/ok/validations), filtros por categoría, tipo de validación y semestre (detecta "Segundo" y "-2S-" en fullname), estadísticas (total issues, open, aulas validadas, % cumplimiento), gráficos Chart.js (barras por validación, donut de cumplimiento). Todas las pestañas usan `table_sql` con descarga integrada, ordenación por columnas y paginación. Pestaña issues muestra curso, categoría, profesores, nº incidencias y validaciones fallidas (separadas por comas). Los cursos ocultos se muestran con clase `dimmed_text` (gris). Excluye cursos hijos meta-enlazados de todas las consultas. Botón de envío de email a profesores (editingteacher/teacher) con validaciones pendientes en la pestaña top (usa `email_to_user()` con confirmación JS). Usa SQL diferenciado para PostgreSQL y MySQL. Las pestañas top/issues usan subconsultas con GROUP BY envueltas como FROM para compatibilidad con `table_sql`.
- **`classes/output/issues_table.php`** — Tabla `table_sql` para la pestaña validations (una fila por incidencia).
- **`classes/output/top_courses_table.php`** — Tabla `table_sql` para la pestaña top (cursos agrupados por nº incidencias con botón email).
- **`classes/output/issues_courses_table.php`** — Tabla `table_sql` para la pestaña issues (cursos con categoría, profesores, nº incidencias y validaciones fallidas).
- **`classes/output/ok_courses_table.php`** — Tabla `table_sql` para la pestaña ok (cursos sin incidencias).
- **`classes/admin_setting_configdate.php`** — Setting personalizado de tipo fecha para la configuración admin.
- **`db/tasks.php`** — Definición de la tarea programada.

### Validaciones

| Regla | Qué comprueba |
|-------|---------------|
| Fecha de inicio | `startdate` del curso coincide con `fechainiciovalidacion` |
| Fecha de fin | `enddate` del curso coincide con `fechafinvalidacion` |
| Tablón de anuncios | Foro tipo `news` en sección 0 con nombre correcto Y que el bloque cero lo tenga seleccionado en su config (`forumid` debe apuntar a ese mismo foro) |
| Foro estudiantes | Foro tipo `general` "Foro de comunicación entre estudiantes" Y que el bloque cero lo tenga seleccionado (`forumestudiantesid` debe apuntar a ese mismo foro) |
| Foro tutorías | Foro tipo `general` "Foro de tutorías de la asignatura" Y que el bloque cero lo tenga seleccionado (`forumtutoriasid` debe apuntar a ese mismo foro) |
| Categorías calificador | 5 categorías requeridas con pesos correctos (no evaluables = 0). Matching flexible: ignora contenido entre paréntesis en el nombre |
| Actividades evaluables en categorías | Todos los módulos con `grade_item` deben estar en una categoría (no en raíz) |
| Flujo de trabajo en buzones | Todos los assigns deben tener `markingworkflow` activado |
| Finalización de curso | El curso debe tener `enablecompletion` activado |
| Condiciones de finalización | Las actividades evaluables deben tener condiciones de finalización (excluye "Actividades no evaluables") |
| Mostrar condiciones de finalización | El curso debe tener `showcompletionconditions` activado |
| Mostrar fechas de actividad | El curso debe tener `showactivitydates` activado |
| Curso oculto | El curso debe estar oculto (`visible` = 0) |
| Bloque cero | El curso debe tener un bloque del tipo `bloquecero` con `defaultregion` = `content-upper` (región extra de Boost Union, no `side-pre`) y sin sobreescrituras en `block_positions` hacia otra región. Misma regla que `block_bloquecero/cli/add_block_to_category.php` |
| Datos de profesores en bloque cero | Cada profesor mostrado en el bloque cero debe tener teléfono y horario rellenos en la config del bloque (claves `userphone_<id>` y `userschedule_<id>` en `configdata`). Alcance: profesores seleccionados (`teacher_selected_<id>`); si no hay selección, todos los editing teachers (`moodle/course:update`). El detalle indica por profesor qué campo falta |
| Guía docente en bloque cero | El bloque cero debe tener al menos una guía docente registrada en `block_bloquecero_guides` (filtrando por `blockinstanceid` y `courseid`) |
| Sesiones en directo en bloque cero | El bloque cero debe tener al menos una sesión registrada en `block_bloquecero_sessions` y todas deben caer dentro del periodo del curso: inicio (`sessiondate`) y fin (`sessiondate + duration`) entre `course->startdate` y `course->enddate`. El detalle lista las sesiones fuera de rango |

### Base de datos

Tabla `block_validacursos_issues`: campos `courseid`, `validation`, `state` (0=abierta, 1=resuelta), `firstseen`, `lastseen`, `resolvedat`.

### Permisos (capabilities)

- `block/validacursos:addinstance` — Añadir bloque (manager, editingteacher)
- `block/validacursos:view` — Ver bloque (editingteacher, manager)
- `block/validacursos:viewissuesreport` — Acceder al reporte (manager)

## Convenciones del código

- Namespaces: `block_validacursos\local\*` para lógica, `block_validacursos\output\*` para renderizado.
- Comparación de nombres insensible a mayúsculas, tildes y puntos finales usando `normalizar_para_comparar()` (internamente: `core_text::strtolower()` + trim + eliminación de puntos finales con `rtrim(..., '.')` + `Normalizer::FORM_D` + eliminación de combining marks). Todas las validaciones que comparan nombres (foros, categorías del calificador) usan este helper.
- SQL compatible PostgreSQL/MySQL: usar `$DB->sql_concat()` y condicionales con `$CFG->dbtype` cuando sea necesario.
- Cada validación retorna un array `[nombre, estado (bool), mensaje, detalle]`.
- Las acciones del bloque usan `optional_param()` + `require_sesskey()` y redirigen tras ejecutar.
