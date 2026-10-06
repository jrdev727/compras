<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Configuración de conexión de base de datos en InfinityFree (Producción)
$host = 'sql306.infinityfree.com';
$db   = 'if0_42355456_compras';
$user = 'if0_42355456';
$pass = 'F11c13w27s30';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
     $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
     // Si la base de datos no existe, sugerimos al usuario que corra el script
     die("<h3>Error de conexión a la base de datos</h3>
          <p>Detalle: {$e->getMessage()}</p>
          <hr>
          <p><strong>¿Cómo solucionar esto?</strong></p>
          <ol>
            <li>Asegúrate de que Apache y MySQL estén iniciados en el panel de control de XAMPP.</li>
            <li>Ve a <strong>phpMyAdmin</strong> (<a href='http://localhost/phpmyadmin' target='_blank'>localhost/phpmyadmin</a>).</li>
            <li>Crea una base de datos llamada <code>gestion_obras</code> o importa el archivo <code>schema.sql</code> que se encuentra en la carpeta del proyecto.</li>
          </ol>");
}
?>
