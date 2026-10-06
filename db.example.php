<?php
// Plantilla de Configuración de Base de Datos
// Renombrar este archivo a db.php y completar las credenciales correspondientes.

$host = 'localhost';          // Host de la base de datos (ej: localhost o sqlXXX.infinityfree.com)
$db   = 'gestion_obras';      // Nombre de la base de datos
$user = 'root';               // Usuario de la base de datos
$pass = '';                   // Contraseña de la base de datos
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
     die("<h3>Error de conexión a la base de datos</h3><p>Detalle: {$e->getMessage()}</p>");
}
?>
