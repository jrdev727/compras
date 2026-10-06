<?php
// Genera el usuario y la clave cifrada (hash) para pegar en db.php. Se usa desde la consola:
//     php crear_usuario.php
// No guarda nada por sí mismo: solo muestra las dos líneas que tenés que pegar en db.php.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script solo se puede ejecutar desde la consola.');
}

function pedir(string $texto): string
{
    echo $texto;
    return trim((string)fgets(STDIN));
}

$usuario = pedir('Nombre de usuario: ');
if ($usuario === '') {
    exit("El usuario no puede estar vacío.\n");
}
$clave = pedir('Clave (mínimo 8 caracteres): ');
if (strlen($clave) < 8) {
    exit("La clave es demasiado corta.\n");
}
if ($clave !== pedir('Repetí la clave: ')) {
    exit("Las claves no coinciden.\n");
}

echo "\nPegá estas dos líneas en db.php (reemplazando las que ya están):\n\n";
echo "define('AUTH_USUARIO', " . var_export($usuario, true) . ");\n";
echo "define('AUTH_HASH', " . var_export(password_hash($clave, PASSWORD_DEFAULT), true) . ");\n";
