-- 003 - n.º de concurso OBLIGATORIO también a nivel base de datos (paso final, opcional)
-- Ejecutar SOLO cuando todas las obras ya tengan su n.º de concurso
-- (el tablero "Control de Expedientes" muestra un aviso mientras falten).
-- Si queda alguna obra sin número, MariaDB rechaza el cambio y NO modifica nada.

ALTER TABLE `obras` MODIFY `nro_concurso` VARCHAR(50) NOT NULL;
