# Migraciones de base de datos

Cada archivo `NNN_nombre.sql` cambia la estructura de una base **ya existente**, sin perder datos.
Se ejecutan **en orden**, una sola vez, en phpMyAdmin (pestaña *SQL* o *Importar*) sobre la base
`gestion_obras` (local) y sobre la base de InfinityFree.

> ⚠️ Antes de ejecutar cualquiera: hacé un backup (phpMyAdmin → Exportar → SQL).

`schema.sql` siempre refleja el estado final, para instalar de cero.

| N.º | Archivo | Qué hace | Datos existentes |
|-----|---------|----------|------------------|
| 001 | `schema.sql` (original) | Estructura inicial | — |
| 002 | `002_expedientes.sql` | Agrega `obras.nro_concurso` (único) y `verificado_en` en OC, facturas y remitos | No se toca ningún dato. Las obras existentes quedan con el concurso vacío hasta que lo completes |
| 003 | `003_concurso_obligatorio.sql` | Hace `nro_concurso` NOT NULL | Solo ejecutar cuando ninguna obra tenga el concurso vacío; si falta alguna, falla sin cambiar nada |

## Cómo aplicar la 002
1. Backup de la base.
2. phpMyAdmin → base `gestion_obras` → pestaña **SQL** → pegar el contenido de `002_expedientes.sql` → *Continuar*.
3. Entrar a **Control de Expedientes**: las obras sin n.º de concurso aparecen marcadas. Editá cada obra y cargá su número.
4. Cuando no quede ninguna sin número, (opcional) aplicar la 003.

Requiere MariaDB (el que trae XAMPP e InfinityFree). En MySQL puro, quitar los `IF NOT EXISTS` y ejecutar una sola vez.
