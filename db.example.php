<?php
// PLANTILLA de configuración. Copiala como db.php y completá los datos.
// db.php NO se sube a git (está en .gitignore): ahí van las contraseñas reales.
// La conexión y el manejo de errores están en bootstrap.php.

// Para XAMPP local normalmente: host 'localhost', db 'gestion_obras', user 'root', pass ''.
$host    = 'localhost';
$db      = 'gestion_obras';
$user    = 'root';
$pass    = '';
$charset = 'utf8mb4';

// Usuario y clave (cifrada) para entrar al sistema.
// Para generarlos, ejecutá en una consola:  php crear_usuario.php   y pegá acá las dos líneas que muestra.
define('AUTH_USUARIO', '');
define('AUTH_HASH', '');
