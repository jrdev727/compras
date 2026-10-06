<?php
// Arranque común de TODAS las páginas: errores, base de datos, login y token CSRF.
// Cada página empieza con:   require_once 'bootstrap.php';
// (login.php define SIN_LOGIN antes, porque es la única pantalla pública.)

// --- Errores: al usuario un mensaje genérico; el detalle técnico va al log -------------
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$__logs = __DIR__ . '/logs';
if (!is_dir($__logs)) {
    @mkdir($__logs, 0750, true);
}
if (is_dir($__logs) && is_writable($__logs)) {
    if (!is_file($__logs . '/.htaccess')) {
        @file_put_contents($__logs . '/.htaccess', "Require all denied\nDeny from all\n");
    }
    ini_set('error_log', $__logs . '/app.log');
}
unset($__logs);

const MENSAJE_ERROR_GENERICO = 'Ocurrió un error inesperado. Los datos no se modificaron. Si el problema persiste, avisá al administrador.';

ob_start();   // si algo falla a mitad de página, se descarta lo ya dibujado y se muestra solo el error

function pagina_error_generica(): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Error</title></head><body style="font-family:sans-serif;max-width:600px;margin:5rem auto;">'
        . '<h2>Ocurrió un error</h2><p>' . htmlspecialchars(MENSAJE_ERROR_GENERICO) . '</p>'
        . '<p><a href="index.php">Volver al inicio</a></p></body></html>';
}

/** Registra el error técnico en el log y devuelve un texto genérico para mostrar al usuario. */
function error_generico(\Throwable $e, string $contexto = 'Error'): string
{
    error_log(sprintf('[%s] %s: %s en %s:%d', basename($_SERVER['SCRIPT_NAME'] ?? '-'), $contexto, $e->getMessage(), $e->getFile(), $e->getLine()));
    return $contexto . '. No se pudo completar la operación; los datos no se modificaron. Si el problema persiste, avisá al administrador.';
}

set_exception_handler(function (\Throwable $e) {
    error_log('[' . basename($_SERVER['SCRIPT_NAME'] ?? '-') . '] Excepción no controlada: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    pagina_error_generica();
});
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        pagina_error_generica();
    }
});

// --- Cabeceras de seguridad ----------------------------------------------------------
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

// --- Configuración y conexión (db.php NO está en git) --------------------------------
if (!is_file(__DIR__ . '/db.php')) {
    error_log('Falta db.php (copiar db.example.php y completar los datos).');
    pagina_error_generica();
    exit;
}
require_once __DIR__ . '/db.php';
// Por si un db.php viejo activó la muestra de errores:
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

if (!isset($pdo)) {
    try {
        $pdo = new PDO(
            "mysql:host=$host;dbname=$db;charset=" . ($charset ?? 'utf8mb4'),
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    } catch (\PDOException $e) {
        error_log('Error de conexión a la base de datos: ' . $e->getMessage());
        pagina_error_generica();
        exit;
    }
}

// --- Login y CSRF ----------------------------------------------------------------------
require_once __DIR__ . '/auth.php';

if (!defined('SIN_LOGIN')) {
    auth_requerir();

    // Todo formulario POST debe traer el token.
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_exigir();
    }
    // Enlaces que cambian datos (eliminar, cambiar estado, ...) deben traer el token en la URL.
    if (isset($_GET['action']) && in_array($_GET['action'], ACCIONES_GET_PROTEGIDAS, true)) {
        csrf_exigir_get();
    }
}
