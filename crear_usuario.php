<?php
// Crea (o cambia) el usuario y la clave del sistema. Se usa UNA vez desde la consola:
//     php crear_usuario.php
// Guarda la clave cifrada (hash) en auth_config.php. Nunca se guarda la clave en texto.

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
$repetida = pedir('Repetí la clave: ');
if ($clave !== $repetida) {
    exit("Las claves no coinciden.\n");
}

$config = "<?php\n// Generado por crear_usuario.php - no subir a git.\nreturn " . var_export([
    'usuario' => $usuario,
    'hash'    => password_hash($clave, PASSWORD_DEFAULT),
], true) . ";\n";

file_put_contents(__DIR__ . '/auth_config.php', $config);
echo "Listo. Usuario guardado en auth_config.php\n";
