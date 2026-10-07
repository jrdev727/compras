-- 004 - Tabla de proveedores (SOLO ESTRUCTURA: no modifica ni borra ningún dato)
--   proveedores            : un registro por proveedor (razón social única; sin distinguir mayúsculas ni tildes)
--   compras.proveedor_id   : proveedor de la OC (queda vacío hasta correr la migración de datos)
--   facturas.proveedor_id  : proveedor de la factura (se copia de la OC)
-- Se CONSERVAN las columnas de texto viejas (compras.proveedor) hasta que vos confirmes.
-- Después de ejecutar esto, entrá a "Proveedores" > "Migrar proveedores": ahí ves qué se va a crear
-- y qué nombres casi iguales hay que unificar ANTES de migrar los datos.
-- Se puede ejecutar más de una vez. Requiere MariaDB (XAMPP / InfinityFree).

CREATE TABLE IF NOT EXISTS `proveedores` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `razon_social` VARCHAR(255) NOT NULL,
    `creado_en` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_proveedores_razon_social` (`razon_social`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `compras`  ADD COLUMN IF NOT EXISTS `proveedor_id` INT NULL AFTER `proveedor`;
ALTER TABLE `facturas` ADD COLUMN IF NOT EXISTS `proveedor_id` INT NULL AFTER `compra_id`;

-- RESTRICT: no se puede borrar un proveedor que tenga OC o facturas.
ALTER TABLE `compras`  ADD CONSTRAINT `fk_compras_proveedor`
    FOREIGN KEY IF NOT EXISTS (`proveedor_id`) REFERENCES `proveedores`(`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `facturas` ADD CONSTRAINT `fk_facturas_proveedor`
    FOREIGN KEY IF NOT EXISTS (`proveedor_id`) REFERENCES `proveedores`(`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
