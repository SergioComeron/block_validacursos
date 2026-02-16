# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Qué es este proyecto

Plugin de tipo bloque para Moodle (`block_validacursos`) que valida automáticamente la estructura y contenido de cursos contra reglas institucionales predefinidas (UDIMA). Verifica fechas, foros obligatorios, guía docente, datos de tutoría, cronogramas y categorías del calificador. Permite crear recursos faltantes con un clic y registra las incidencias en base de datos para su seguimiento.

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

- **`block_validacursos.php`** — Clase principal del bloque. Renderiza la UI y maneja las acciones inline (crear foros, actualizar fechas, crear cronogramas, crear categorías del calificador). Las acciones se disparan vía `optional_param` en GET.
- **`classes/local/validator.php`** — Motor de validación. Contiene toda la lógica de las reglas. Métodos estáticos auxiliares: `normalizar_texto()` para limpiar HTML, `quitar_tildes()` para eliminar diacríticos Unicode (NFD + `\pM`), `normalizar_para_comparar()` para comparación insensible a mayúsculas y tildes, `label_contiene_frase_relajada()` para matching flexible de texto, `existe_label_con_tabla_y_frase()` para buscar labels con tablas HTML.
- **`classes/task/validate_all_courses.php`** — Tarea programada que valida todos los cursos de las categorías permitidas (tanto visibles como ocultos). Se ejecuta diariamente a las 2 AM.
- **`classes/local/logger.php`** — Persistencia en BD. Crea incidencias nuevas, actualiza `lastseen` en incidencias abiertas, y marca como resueltas cuando la validación pasa.
- **`report.php`** — Página de reporte admin con pestañas de contenido (top/issues/ok/validations), filtros por categoría, tipo de validación y semestre (detecta "Segundo" y "-2S-" en fullname), estadísticas (total issues, open, aulas validadas, % cumplimiento), gráficos Chart.js (barras por validación, donut de cumplimiento). Pestaña issues paginada (50/pág) ordenada por nº incidencias. Pestaña ok muestra cursos sin incidencias. Descarga con `download_dataformat_selector` estándar de Moodle en issues/ok y descarga integrada en `table_sql` en validations. Los cursos ocultos se muestran con clase `dimmed_text` (gris). Usa SQL diferenciado para PostgreSQL y MySQL.
- **`classes/output/issues_table.php`** — Tabla extensible (`table_sql`) para el reporte.
- **`classes/admin_setting_configdate.php`** — Setting personalizado de tipo fecha para la configuración admin.
- **`content/`** — Plantillas HTML de cronogramas que se insertan como labels al crear desde el bloque.
- **`db/tasks.php`** — Definición de la tarea programada.

### Validaciones

| Regla | Qué comprueba |
|-------|---------------|
| Fecha de inicio | `startdate` del curso coincide con `fechainiciovalidacion` |
| Fecha de fin | `enddate` del curso coincide con `fechafinvalidacion` |
| Tablón de anuncios | Foro tipo `news` en sección 0 con nombre correcto |
| Foro estudiantes | Foro tipo `general` "Foro de comunicación entre estudiantes" |
| Foro tutorías | Foro tipo `general` "Foro de tutorías de la asignatura" |
| Guía Docente | URL en sección 0 con título "Guía Docente..." y URL de UDIMA |
| Datos de tutoría | Label con nombre, email, teléfono, extensión y horario |
| Cronograma actividades | Label con tabla HTML conteniendo "CRONOGRAMA DE ACTIVIDADES CALIFICABLES" |
| Cronograma sesiones | Label con tabla conteniendo "CRONOGRAMA DE SESIONES SÍNCRONAS" |
| Categorías calificador | 5 categorías requeridas con pesos correctos (no evaluables = 0). Matching flexible: ignora contenido entre paréntesis en el nombre |
| Actividades evaluables en categorías | Todos los módulos con `grade_item` deben estar en una categoría (no en raíz) |
| Flujo de trabajo en buzones | Todos los assigns deben tener `markingworkflow` activado |
| Finalización de curso | El curso debe tener `enablecompletion` activado |
| Condiciones de finalización | Las actividades evaluables deben tener condiciones de finalización (excluye "Actividades no evaluables") |
| Mostrar condiciones de finalización | El curso debe tener `showcompletionconditions` activado |
| Mostrar fechas de actividad | El curso debe tener `showactivitydates` activado |
| Curso oculto | El curso debe estar oculto (`visible` = 0) |

### Base de datos

Tabla `block_validacursos_issues`: campos `courseid`, `validation`, `state` (0=abierta, 1=resuelta), `firstseen`, `lastseen`, `resolvedat`.

### Permisos (capabilities)

- `block/validacursos:addinstance` — Añadir bloque (manager, editingteacher)
- `block/validacursos:view` — Ver bloque (editingteacher, manager)
- `block/validacursos:viewissuesreport` — Acceder al reporte (manager)

## Convenciones del código

- Namespaces: `block_validacursos\local\*` para lógica, `block_validacursos\output\*` para renderizado.
- Comparación de nombres insensible a mayúsculas y tildes usando `normalizar_para_comparar()` (internamente: `core_text::strtolower()` + `Normalizer::FORM_D` + eliminación de combining marks). Todas las validaciones que comparan nombres (foros, guía docente, categorías del calificador, claves de tutoría, cronogramas) usan este helper.
- SQL compatible PostgreSQL/MySQL: usar `$DB->sql_concat()` y condicionales con `$CFG->dbtype` cuando sea necesario.
- Cada validación retorna un array `[nombre, estado (bool), mensaje, detalle]`.
- Las acciones del bloque usan `optional_param()` + `require_sesskey()` y redirigen tras ejecutar.
