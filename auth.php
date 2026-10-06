<?php
// Sesión, login de usuario único y protección CSRF.
// Uso en cada página protegida:   require_once 'auth.php'; auth_requerir();

if (session_status() === PHP_SESSION_NONE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** Escapa texto para mostrarlo en HTML. */
function h($valor): string
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

/** Acciones por URL (GET) que modifican datos: exigen token CSRF en el enlace. */
const ACCIONES_GET_PROTEGIDAS = ['eliminar', 'cambiar_estado'];

/** Usuario y hash de clave: se definen en db.php (AUTH_USUARIO y AUTH_HASH). null si faltan. */
function auth_config(): ?array
{
    if (defined('AUTH_USUARIO') && defined('AUTH_HASH') && AUTH_USUARIO !== '' && AUTH_HASH !== '') {
        return ['usuario' => AUTH_USUARIO, 'hash' => AUTH_HASH];
    }
    return null;
}

function auth_usuario(): ?string
{
    return isset($_SESSION['usuario']) ? (string)$_SESSION['usuario'] : null;
}

/** Si no hay sesión iniciada, manda a login.php (recordando a dónde volver). */
function auth_requerir(): void
{
    if (auth_usuario() !== null) {
        return;
    }
    $volver = basename($_SERVER['SCRIPT_NAME']);
    if (!empty($_SERVER['QUERY_STRING'])) {
        $volver .= '?' . $_SERVER['QUERY_STRING'];
    }
    header('Location: login.php?volver=' . rawurlencode($volver));
    exit;
}

/** Devuelve true si usuario y clave son correctos. */
function auth_verificar(string $usuario, string $clave): bool
{
    $cfg = auth_config();
    if ($cfg === null) {
        return false;
    }
    // Se verifica siempre la clave para no revelar si el usuario existe por el tiempo de respuesta.
    $okClave = password_verify($clave, $cfg['hash']);
    return hash_equals((string)$cfg['usuario'], $usuario) && $okClave;
}

function auth_iniciar_sesion(string $usuario): void
{
    session_regenerate_id(true);
    $_SESSION['usuario'] = $usuario;
}

function auth_cerrar_sesion(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// ---------------------------------------------------------------- CSRF

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Campo oculto para poner dentro de cada <form method="POST">. */
function csrf_campo(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function csrf_valido(): bool
{
    $enviado = $_POST['csrf'] ?? '';
    return is_string($enviado) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $enviado);
}

/** Token CSRF para enlaces: se agrega a la URL como &csrf=... */
function csrf_url(): string
{
    return '&csrf=' . rawurlencode(csrf_token());
}

function csrf_exigir_get(): void
{
    $enviado = $_GET['csrf'] ?? '';
    if (!is_string($enviado) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $enviado)) {
        http_response_code(400);
        exit('Enlace no válido o vencido. Volvé atrás, recargá la página e intentá de nuevo.');
    }
}

/** Corta la ejecución si el token CSRF de un POST no es válido. */
function csrf_exigir(): void
{
    if (!csrf_valido()) {
        http_response_code(400);
        exit('Solicitud no válida (token de seguridad incorrecto o vencido). Volvé atrás, recargá la página e intentá de nuevo.');
    }
}
