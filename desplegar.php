<?php
// Script de despliegue automático por FTP a InfinityFree
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script solo se puede ejecutar desde la consola.');
}

// Los datos del FTP están en desplegar_config.php (no se sube a git; ver desplegar_config.example.php).
if (!is_file(__DIR__ . '/desplegar_config.php')) {
    die("Falta desplegar_config.php (copiá desplegar_config.example.php y completá los datos).\n");
}
require __DIR__ . '/desplegar_config.php';
$remote_dir = "htdocs";

echo "Iniciando despliegue automático a InfinityFree...\n";

// Conectarse al servidor FTP
$conn_id = ftp_connect($ftp_server) or die("Error: No se pudo conectar a $ftp_server\n");

// Iniciar sesión
if (@ftp_login($conn_id, $ftp_user, $ftp_pass)) {
    echo "Conexión establecida exitosamente como $ftp_user.\n";
} else {
    die("Error: Credenciales de FTP incorrectas.\n");
}

// Habilitar modo pasivo (requerido para la mayoría de conexiones tras firewalls y hostings gratuitos)
ftp_pasv($conn_id, true);

// Cambiar al directorio remoto
if (ftp_chdir($conn_id, $remote_dir)) {
    echo "Cambiado al directorio remoto: $remote_dir\n";
} else {
    die("Error: No se pudo cambiar al directorio $remote_dir\n");
}

// Función para subir archivos de forma recursiva
function upload_directory($conn_id, $local_dir, $remote_sub_dir = "") {
    $dir = dir($local_dir);
    while (($file = $dir->read()) !== false) {
        if ($file === '.' || $file === '..') {
            continue;
        }

        $local_path = $local_dir . '/' . $file;
        $remote_path = $remote_sub_dir ? $remote_sub_dir . '/' . $file : $file;

        if (is_dir($local_path)) {
            // Excluir carpetas ocultas o de sistema locales
            if (strpos($file, '.') === 0 || $file === 'temp_zip' || $file === 'scratch') {
                continue;
            }
            
            // Crear el directorio remoto si no existe
            if (!@ftp_chdir($conn_id, $remote_path)) {
                if (ftp_mkdir($conn_id, $remote_path)) {
                    echo "Creado directorio remoto: $remote_path\n";
                } else {
                    echo "Advertencia: No se pudo crear el directorio remoto: $remote_path\n";
                }
            }
            // Regresar al directorio htdocs
            ftp_chdir($conn_id, "/htdocs");
            
            // Subir el contenido recursivamente
            upload_directory($conn_id, $local_path, $remote_path);
        } else {
            // Excluir archivos innecesarios de base de datos o instaladores del despliegue público
            $ext = pathinfo($file, PATHINFO_EXTENSION);
            if (in_array($ext, ['php', 'css', 'js', 'png', 'jpg', 'jpeg', 'svg', 'ico'])) {
                // No subir el propio script de despliegue
                if (in_array($file, ['desplegar.php', 'desplegar_config.php', 'desplegar_config.example.php', 'crear_usuario.php', 'db.example.php'], true)) {
                    continue;
                }
                
                echo "Subiendo: $remote_path... ";
                if (ftp_put($conn_id, $remote_path, $local_path, FTP_BINARY)) {
                    echo "OK\n";
                } else {
                    echo "ERROR\n";
                }
            }
        }
    }
    $dir->close();
}

// Empezar la subida
upload_directory($conn_id, ".");

// Cerrar conexión
ftp_close($conn_id);
echo "Despliegue finalizado con éxito!\n";
?>
