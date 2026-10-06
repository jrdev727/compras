-- 002 - Control de expedientes físicos
-- No borra ni modifica datos existentes: solo agrega columnas nuevas (vacías) y un índice.
--   obras.nro_concurso   : n.º de concurso de precios (único). Queda vacío (NULL) en las obras
--                          existentes hasta que las edites; la aplicación lo exige al guardar.
--   *.verificado_en      : fecha en que se verificó el documento contra el papel (NULL = no verificado).
-- Se puede ejecutar más de una vez sin problema (IF NOT EXISTS). Requiere MariaDB (XAMPP / InfinityFree).

ALTER TABLE `obras`     ADD COLUMN IF NOT EXISTS `nro_concurso`  VARCHAR(50) NULL AFTER `expediente`;
ALTER TABLE `compras`   ADD COLUMN IF NOT EXISTS `verificado_en` DATE NULL;
ALTER TABLE `facturas`  ADD COLUMN IF NOT EXISTS `verificado_en` DATE NULL;
ALTER TABLE `remitos`   ADD COLUMN IF NOT EXISTS `verificado_en` DATE NULL;

-- Único (varias obras sin completar, con NULL, no chocan entre sí).
CREATE UNIQUE INDEX IF NOT EXISTS `uq_obras_nro_concurso` ON `obras` (`nro_concurso`);
