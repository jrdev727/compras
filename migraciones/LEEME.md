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
| 004 | `004_proveedores.sql` | Crea la tabla `proveedores` y las columnas `compras.proveedor_id` y `facturas.proveedor_id` | No se toca ningún dato. El texto viejo `compras.proveedor` se conserva |

## Cómo aplicar la 002
1. Backup de la base.
2. phpMyAdmin → base `gestion_obras` → pestaña **SQL** → pegar el contenido de `002_expedientes.sql` → *Continuar*.
3. Entrar a **Control de Expedientes**: las obras sin n.º de concurso aparecen marcadas. Editá cada obra y cargá su número.
4. Cuando no quede ninguna sin número, (opcional) aplicar la 003.

Requiere MariaDB (el que trae XAMPP e InfinityFree). En MySQL puro, quitar los `IF NOT EXISTS` y ejecutar una sola vez.

## Proveedores (004): migración en dos pasos
1. Backup de la base. Ejecutá `004_proveedores.sql` en phpMyAdmin (solo crea estructura).
2. Entrá a **Proveedores → Migrar proveedores** (`migrar_proveedores.php`). La pantalla **no cambia nada al abrirla**:
   - Si hay OC sin proveedor, te las lista para que las completes.
   - Si hay nombres casi iguales ("Pérez SA" / "PEREZ S.A."), te muestra cada grupo con las OC afectadas y vos elegís cómo debe quedar; recién ahí se unifica el texto.
   - Cuando no queda nada pendiente, muestra qué proveedores se van a crear y cuántas OC y facturas se van a vincular, y el botón **Migrar ahora**.
3. Mientras la migración no esté completa, los formularios siguen con el campo de texto de siempre. Al completarla, pasan a usar la lista de proveedores con la opción "Agregar proveedor nuevo".
4. El texto viejo (`compras.proveedor`) **no se borra**: se sigue guardando junto al `proveedor_id`. Se eliminará en una migración posterior, solo cuando vos lo confirmes.
