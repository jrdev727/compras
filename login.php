<?php
require_once 'auth.php';

$error = '';

// Dónde volver después de entrar: solo páginas propias (nada de URLs externas).
$volver = isset($_GET['volver']) ? (string)$_GET['volver'] : 'informe_datos.php';
if (!preg_match('/^[a-z_]+\.php(\?[A-Za-z0-9_=&%.\-]*)?$/', $volver)) {
    $volver = 'informe_datos.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_exigir();
    if (($_POST['accion'] ?? '') === 'salir') {
        auth_cerrar_sesion();
        header('Location: login.php');
        exit;
    }
    $usuario = trim((string)($_POST['usuario'] ?? ''));
    $clave   = (string)($_POST['clave'] ?? '');
    if (auth_verificar($usuario, $clave)) {
        auth_iniciar_sesion($usuario);
        header('Location: ' . $volver);
        exit;
    }
    sleep(1); // frena los intentos repetidos de adivinar la clave
    $error = 'Usuario o clave incorrectos.';
}

if (auth_usuario() !== null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $volver);
    exit;
}
$configurado = auth_config() !== null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ingresar - Muni Obras</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div style="max-width: 380px; margin: 8vh auto; padding: 0 1rem;">
        <div class="card">
            <div class="card-header"><h2 class="card-title">Ingresar al sistema</h2></div>
            <div class="card-body">
                <?php if (!$configurado): ?>
                    <div class="alert-banner alert-banner-danger">
                        <div class="alert-banner-text">
                            <h4>Falta crear el usuario</h4>
                            <p>Abrí una consola en la carpeta del sistema y ejecutá: <code>php crear_usuario.php</code></p>
                        </div>
                    </div>
                <?php else: ?>
                    <?php if ($error): ?>
                        <div class="alert-banner alert-banner-danger"><div class="alert-banner-text"><p><?= h($error) ?></p></div></div>
                    <?php endif; ?>
                    <form method="POST" action="login.php?volver=<?= h(rawurlencode($volver)) ?>">
                        <?= csrf_campo() ?>
                        <div class="form-group">
                            <label class="form-label" for="usuario">Usuario</label>
                            <input class="form-control" type="text" id="usuario" name="usuario" required autofocus autocomplete="username">
                        </div>
                        <div class="form-group mt-4">
                            <label class="form-label" for="clave">Clave</label>
                            <input class="form-control" type="password" id="clave" name="clave" required autocomplete="current-password">
                        </div>
                        <button type="submit" class="btn btn-primary mt-4">Entrar</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
