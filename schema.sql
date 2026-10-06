-- Crear base de datos si no existe
CREATE DATABASE IF NOT EXISTS `gestion_obras` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `gestion_obras`;

-- 1. Tabla de Obras
CREATE TABLE IF NOT EXISTS `obras` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `nombre` VARCHAR(255) NOT NULL,
    `expediente` VARCHAR(100) NOT NULL UNIQUE,
    `descripcion` TEXT NULL,
    `estado` ENUM('Planificación', 'Activa', 'Pausada', 'Finalizada') DEFAULT 'Planificación',
    `fecha_creacion` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Tabla de Materiales Solicitados (Solicitud de Obra Pública / Listado de Materiales)
CREATE TABLE IF NOT EXISTS `materiales_solicitados` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `obra_id` INT NOT NULL,
    `descripcion` VARCHAR(255) NOT NULL,
    `cantidad` DECIMAL(12, 2) NOT NULL,
    `unidad` VARCHAR(50) NOT NULL,
    FOREIGN KEY (`obra_id`) REFERENCES `obras`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Tabla de Compras / Presupuestos Adjudicados
CREATE TABLE IF NOT EXISTS `compras` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `obra_id` INT NOT NULL,
    `nro_compra` VARCHAR(100) NOT NULL, -- Nro de Orden de Compra o Licitación
    `proveedor` VARCHAR(255) NOT NULL,
    `fecha_compra` DATE NOT NULL,
    `estado` ENUM('Adjudicado', 'Entregado Parcial', 'Entregado Completo', 'Cancelado') DEFAULT 'Adjudicado',
    FOREIGN KEY (`obra_id`) REFERENCES `obras`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Detalle de Compras (Items Adjudicados)
CREATE TABLE IF NOT EXISTS `compra_detalles` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `compra_id` INT NOT NULL,
    `material_solicitado_id` INT NULL, -- Enlace al material original de la solicitud
    `descripcion` VARCHAR(255) NOT NULL,
    `cantidad_comprada` DECIMAL(12, 2) NOT NULL,
    `unidad` VARCHAR(50) NOT NULL,
    `precio_unitario` DECIMAL(15, 2) NOT NULL,
    FOREIGN KEY (`compra_id`) REFERENCES `compras`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`material_solicitado_id`) REFERENCES `materiales_solicitados`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Tabla de Facturas
CREATE TABLE IF NOT EXISTS `facturas` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `compra_id` INT NOT NULL,
    `nro_factura` VARCHAR(100) NOT NULL,
    `fecha_factura` DATE NOT NULL,
    `monto` DECIMAL(15, 2) NOT NULL,
    `estado` ENUM('Pendiente', 'Aprobada', 'Pagada') DEFAULT 'Pendiente',
    FOREIGN KEY (`compra_id`) REFERENCES `compras`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Tabla de Remitos (Cabecera)
CREATE TABLE IF NOT EXISTS `remitos` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `obra_id` INT NOT NULL,
    `nro_remito` VARCHAR(100) NULL, -- Puede ser NULL o 'PENDIENTE' si no llegó el papel físico
    `fecha_entrega` DATE NOT NULL,
    `estado_remito` ENUM('Recibido', 'Pendiente de Remito Físico', 'Reclamado') DEFAULT 'Recibido',
    `recibido_por` VARCHAR(255) NULL,
    `observaciones` TEXT NULL,
    FOREIGN KEY (`obra_id`) REFERENCES `obras`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Detalle de Remitos (Items entregados en esta etapa)
CREATE TABLE IF NOT EXISTS `remito_detalles` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `remito_id` INT NOT NULL,
    `compra_detalle_id` INT NOT NULL, -- Apunta al item comprado
    `cantidad_entregada` DECIMAL(12, 2) NOT NULL,
    FOREIGN KEY (`remito_id`) REFERENCES `remitos`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`compra_detalle_id`) REFERENCES `compra_detalles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



