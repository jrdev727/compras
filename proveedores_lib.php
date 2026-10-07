<?php
// Funciones de proveedores compartidas por las OC, facturas y las pantallas de proveedores.
require_once __DIR__ . '/validaciones.php';

/** Error con un mensaje pensado para mostrarse tal cual al usuario. */
class ProveedorInvalido extends ErrorValidacion
{
}

/** ¿Existen la tabla proveedores y las columnas proveedor_id (migración 004 ejecutada)? */
function proveedores_estructura_lista(PDO $pdo): bool
{
    static $lista = null;
    if ($lista === null) {
        $n = (int)$pdo->query("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND ((TABLE_NAME = 'compras'     AND COLUMN_NAME = 'proveedor_id')
                OR (TABLE_NAME = 'facturas'    AND COLUMN_NAME = 'proveedor_id')
                OR (TABLE_NAME = 'proveedores' AND COLUMN_NAME = 'razon_social'))
        ")->fetchColumn();
        $lista = ($n === 3);
    }
    return $lista;
}

/**
 * ¿Los formularios deben usar la lista de proveedores? Solo cuando la estructura existe Y todas las
 * OC ya fueron migradas (si no, se sigue con el campo de texto de siempre y no se mezclan datos).
 */
function proveedores_activo(PDO $pdo): bool
{
    static $activo = null;
    if ($activo === null) {
        $activo = proveedores_estructura_lista($pdo)
            && (int)$pdo->query("SELECT COUNT(*) FROM compras WHERE proveedor_id IS NULL")->fetchColumn() === 0;
    }
    return $activo;
}

function proveedores_listar(PDO $pdo): array
{
    return $pdo->query("SELECT id, razon_social FROM proveedores ORDER BY razon_social ASC")->fetchAll();
}

/** Quita espacios de los costados y junta espacios repetidos. */
function normalizar_razon_social(string $s): string
{
    return trim(preg_replace('/\s+/u', ' ', $s));
}

/** Proveedor ya existente con nombre casi igual (mayúsculas, tildes, puntos, espacios), o null. */
function proveedor_similar(PDO $pdo, string $nombre, int $excluir_id = 0): ?array
{
    $clave = proveedor_clave($nombre);
    foreach ($pdo->query("SELECT id, razon_social FROM proveedores") as $p) {
        if ((int)$p['id'] !== $excluir_id && proveedor_clave($p['razon_social']) === $clave) {
            return $p;
        }
    }
    return null;
}

/** Valida una razón social nueva (sin guardar). Devuelve el nombre normalizado o lanza ProveedorInvalido. */
function proveedor_validar_nombre(PDO $pdo, string $nombre, int $excluir_id = 0): string
{
    $nombre = normalizar_razon_social($nombre);
    if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 255 || proveedor_clave($nombre) === '') {
        throw new ProveedorInvalido('La razón social del proveedor debe tener entre 2 y 255 caracteres.');
    }
    if ($otro = proveedor_similar($pdo, $nombre, $excluir_id)) {
        throw new ProveedorInvalido("Ya existe un proveedor con nombre igual o muy parecido: «{$otro['razon_social']}». Elegilo de la lista en lugar de crear uno nuevo.");
    }
    return $nombre;
}

/**
 * Lee el proveedor elegido en un formulario (lista o "agregar nuevo") y devuelve ['id' => ..., 'razon_social' => ...].
 * Si es nuevo, lo crea (llamar dentro de la transacción). Lanza ProveedorInvalido si algo está mal.
 */
function proveedor_resolver(PDO $pdo, array $post): array
{
    $elegido = trim((string)($post['proveedor_id'] ?? ''));
    if ($elegido === 'nuevo') {
        $nombre = proveedor_validar_nombre($pdo, (string)($post['proveedor_nuevo'] ?? ''));
        $pdo->prepare("INSERT INTO proveedores (razon_social) VALUES (?)")->execute([$nombre]);
        return ['id' => (int)$pdo->lastInsertId(), 'razon_social' => $nombre];
    }
    $id = (int)$elegido;
    if ($id > 0) {
        $st = $pdo->prepare("SELECT id, razon_social FROM proveedores WHERE id = ?");
        $st->execute([$id]);
        if ($p = $st->fetch()) {
            return ['id' => (int)$p['id'], 'razon_social' => $p['razon_social']];
        }
    }
    throw new ProveedorInvalido('Elegí un proveedor de la lista o agregá uno nuevo.');
}

/** HTML del campo "Proveedor": lista desplegable con la opción "Agregar proveedor nuevo". */
function campo_proveedor(array $lista, $seleccionado = '', string $texto_nuevo = ''): string
{
    $seleccionado = (string)$seleccionado;
    $html = '<select name="proveedor_id" id="proveedor_id" class="form-control" required onchange="'
        . "var n=document.getElementById('proveedor_nuevo');var nuevo=this.value==='nuevo';n.style.display=nuevo?'':'none';n.required=nuevo;if(nuevo){n.focus();}"
        . '">';
    $html .= '<option value="">— Elegir proveedor —</option>';
    foreach ($lista as $p) {
        $html .= '<option value="' . (int)$p['id'] . '"' . ((string)$p['id'] === $seleccionado ? ' selected' : '') . '>' . h($p['razon_social']) . '</option>';
    }
    $html .= '<option value="nuevo"' . ($seleccionado === 'nuevo' ? ' selected' : '') . '>➕ Agregar proveedor nuevo…</option></select>';
    $mostrar = $seleccionado === 'nuevo';
    $html .= '<input type="text" name="proveedor_nuevo" id="proveedor_nuevo" class="form-control mt-2" maxlength="255"'
        . ' placeholder="Razón social del proveedor nuevo" value="' . h($texto_nuevo) . '"'
        . ' style="' . ($mostrar ? '' : 'display:none;') . '"' . ($mostrar ? ' required' : '') . '>';
    return $html;
}
