# Contributing to block_validacursos

## Releases

Los releases los genera [release-please](https://github.com/googleapis/release-please) al hacer push a `master`.

Los commits tienen que seguir [Conventional Commits](https://www.conventionalcommits.org/):

| Prefijo | ¿Sube versión? | Ejemplo |
|---|---|---|
| `feat:` | minor (3.2.0) | `feat: validar guía docente en bloquecero` |
| `fix:` | patch (3.1.1) | `fix: región content-upper con override` |
| `perf:` | patch | |
| `docs:` / `refactor:` | changelog, sin bump | |
| `ci:` / `chore:` | no | |

Tras un `feat`/`fix` en `master`, release-please abre un PR `chore(master): release X.Y.Z`, lo fusiona y (con un push de trigger) publica el tag `vX.Y.Z` y el ZIP instalable.

El ZIP lleva la carpeta `validacursos/` (nombre Moodle del bloque) y un `$plugin->version` mayor que el anterior para forzar upgrade.
