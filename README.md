# Sistema de Control de Compras, Obras Públicas y Remitos (Muni Obras)

Sistema web ligero y eficiente desarrollado en PHP y MySQL para la gestión integral, trazabilidad y conciliación de insumos, contrataciones y entregas físicas en obras públicas municipales.

---

## 🚀 Características Principales

* 🏛️ **Gestión de Obras y Expedientes**: Alta y seguimiento de solicitudes de obra pública con su memoria técnica original de materiales requeridos.
* 🛒 **Órdenes de Compra (OC)**: Adjudicación de presupuestos a insumos con buscador en tiempo real e ítems desmarcados por defecto para mayor agilidad.
* 🚛 **Control de Remitos y Descargas**: Registro detallado de descargas en obra, control de papelería física pendiente y conciliación dinámica de stock entregado vs. comprado.
* 🧾 **Facturación y Alerta de Inconsistencias**: Carga flexible de facturas con detección y notificación visual en rojo en el detalle de la obra si los montos facturados no coinciden con la OC.
* 📊 **Exportación a Excel Profesional**: Generación de planillas de conciliación y trazabilidad en formato `.xls` con formateo nativo de celdas para auditorías de Obras Públicas.
* 🚀 **Despliegue Automático por FTP**: Script integrado (`desplegar.php`) para sincronización remota rápida con servidores de hosting (ej. InfinityFree).
* 🔄 **Navegación Inteligente**: Preservación del estado de pestañas activas e historial al volver o cancelar acciones.

---

## 🛠️ Tecnologías Utilizadas

* **Backend**: PHP 7.4 / 8.x (PDO con MySQL / MariaDB)
* **Frontend**: HTML5, CSS3 (Variables CSS, Flexbox/Grid), Vanilla JavaScript
* **Base de Datos**: MySQL / MariaDB

---

## 📂 Estructura del Proyecto

```text
gestion_compras_obras/
├── assets/
│   ├── css/
│   │   └── style.css       # Estilos globales y diseño responsivo
│   └── js/
│       └── main.js         # Lógica de interfaz, pestañas y filtros cliente
├── index.php               # Dashboard principal / Tablero de control
├── obras.php               # Listado y alta/edición de obras
├── obra_detalle.php        # Vista unificada de la obra (Conciliación, OCs, Remitos)
├── compras.php             # Gestión de Órdenes de Compra y adjudicación
├── remitos.php             # Control y recepción de remitos físicos
├── facturas.php            # Carga y seguimiento de facturación
├── exportar_excel.php      # Generador de reportes Excel (.xls)
├── bootstrap.php           # Arranque común: errores, conexión, login y CSRF
├── db.example.php          # Plantilla de configuración (copiar como db.php, que NO va a git)
├── expedientes.php         # Control de Expedientes físicos
├── proveedores.php         # Lista de proveedores (alta y corrección de nombre)
├── migrar_proveedores.php  # Migración guiada de los proveedores escritos en las OC
├── migraciones/            # Cambios de estructura de la base (ver LEEME.md)
├── desplegar.php           # Script de despliegue automático por FTP
├── schema.sql              # Estructura limpia de la base de datos
└── README.md               # Documentación del proyecto
```

---

## ⚙️ Instalación y Configuración Local

1. **Clonar o descargar el repositorio**:
   ```bash
   git clone <URL_DEL_REPOSITORIO>
   ```
2. **Copiar los archivos en tu servidor local** (ejemplo: `C:\xampp\htdocs\gestion_compras_obras`).
3. **Importar la Base de Datos**:
   * Abre phpMyAdmin o la consola de MySQL.
   * Crea la base de datos `gestion_obras`.
   * Importa el archivo `schema.sql`.
4. **Configurar la conexión**:
   * Duplica o renombra `db.example.php` como `db.php`.
   * Ajusta las credenciales de tu MySQL local (`$host`, `$db`, `$user`, `$pass`).

---

## 🔐 Seguridad: usuario, clave y configuración

Todo el sistema pide login (un solo usuario). Las contraseñas **no están en el código**:

1. Copiá `db.example.php` como `db.php` y completá los datos de la base. `db.php` está en `.gitignore` y **nunca se sube a git**.
2. Generá tu clave cifrada, desde una consola en la carpeta del sistema: `php crear_usuario.php`.
   Pegá las dos líneas que muestra (`AUTH_USUARIO` y `AUTH_HASH`) en `db.php`.
3. Para el despliegue por FTP, copiá `desplegar_config.example.php` como `desplegar_config.php` y completá tus datos.
4. Los errores técnicos se guardan en `logs/app.log` (no se muestran en pantalla).

Todos los formularios y los enlaces de eliminar / cambiar estado llevan un token CSRF.

## 🗂️ Control de Expedientes

Pantalla **Control de Expedientes**: tablero por obra (expediente, n.º de concurso, OC/facturas/remitos verificados contra el papel), avance por año y buscador.
Requiere aplicar `migraciones/002_expedientes.sql` (ver `migraciones/LEEME.md`).

## ✅ Reglas de validación (el servidor bloquea el guardado)

| Documento | Regla |
|-----------|-------|
| OC | Número `AAAA-NNNN` (ej. `2025-0001`), único en todo el sistema. Si el año del número no coincide con el de la fecha, solo muestra una advertencia |
| Factura | Número ARCA: punto de venta de 4 o 5 dígitos, guion y 8 dígitos (ej. `00001-00001234`). Único por proveedor |
| Remito | Formato libre. No se repite el mismo número para el mismo proveedor (se deduce de los ítems) |
| Todos | Cantidades, precios y montos mayores que cero; fechas válidas |
| Fechas | Fecha OC ≤ fecha factura; fecha remito ≥ fecha de la OC de sus ítems (la misma fecha se permite). Al editar la fecha de una OC se revisa contra sus facturas y remitos |

Las reglas están en `validaciones.php` (formatos) y `reglas_db.php` (las que consultan la base).

---

## 🌐 Despliegue en Servidor Remoto (Ej: InfinityFree)

1. Crear la base de datos MySQL en el panel de hosting e importar `schema.sql` (o un respaldo de datos).
2. Configurar las credenciales de producción en `db.php`.
3. Ejecutar el script de despliegue por FTP desde la consola:
   ```bash
   php desplegar.php
   ```

---

## 📜 Licencia

Desarrollado para la gestión y auditoría de Obras Públicas. Todos los derechos reservados.
