<?php
// Reglas de validación compartidas por el informe de datos y los formularios.
// Funciones puras: no tocan la base de datos.

const ANIO_MIN = 2000;

/** Error de validación de negocio: su mensaje está pensado para mostrarse tal cual al usuario. */
class ErrorValidacion extends RuntimeException
{
}

/** Año máximo aceptado para una fecha (el año que viene, por si se carga por adelantado). */
function anio_max(): int
{
    return (int)date('Y') + 1;
}

/** Fecha en formato AAAA-MM-DD, que exista en el calendario y con año razonable. */
function fecha_valida($f): bool
{
    if (!is_string($f) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $f, $m)) {
        return false;
    }
    [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    return checkdate($mo, $d, $y) && $y >= ANIO_MIN && $y <= anio_max();
}

/** Año que figura al inicio de un número de OC tipo "2025-0001" o "2025-1" (null si no tiene ese estilo). */
function oc_anio($n): ?int
{
    if (is_string($n) && preg_match('/^(\d{4})\s*-\s*\d+$/', trim($n), $m)) {
        $y = (int)$m[1];
        return ($y >= ANIO_MIN && $y <= anio_max()) ? $y : null;
    }
    return null;
}

/** Un número de documento (OC o factura) escrito libremente: no vacío y de hasta 100 caracteres. */
function numero_documento_ok($n): bool
{
    return is_string($n) && trim($n) !== '' && mb_strlen(trim($n)) <= 100;
}

/**
 * Clave para comparar números de OC o de factura sin importar los ceros a la izquierda ni los espacios:
 * "2025-0001", "2025-1" y "2025 - 01" son el mismo número; "00001-00001234" y "1-1234" también.
 */
function numero_clave($n): string
{
    $n = trim((string)$n);
    if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $n, $m)) {
        return (ltrim($m[1], '0') ?: '0') . '-' . (ltrim($m[2], '0') ?: '0');
    }
    return strtoupper(preg_replace('/\s+/', '', $n));
}

function oc_clave($n): string
{
    return numero_clave($n);
}

function factura_clave($n): string
{
    return numero_clave($n);
}

/** Clave para comparar números de remito (formato libre: solo se ignoran espacios y mayúsculas). */
function remito_clave($n): string
{
    return strtoupper(preg_replace('/\s+/', '', trim((string)$n)));
}

/** Un remito sin número real (pendiente del papel físico). */
function remito_sin_numero($n): bool
{
    $n = remito_clave($n);
    return $n === '' || $n === 'PENDIENTE';
}

/** Clave para detectar proveedores casi iguales: sin mayúsculas, tildes, puntos, comas ni espacios. */
function proveedor_clave($nombre): string
{
    $s = mb_strtolower(trim((string)$nombre), 'UTF-8');
    $s = strtr($s, [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ñ' => 'n',
    ]);
    return preg_replace('/[^a-z0-9]/', '', $s);
}

/** Número mayor que cero (acepta "12", "12.5", 12.5). */
function numero_positivo($v): bool
{
    return is_numeric($v) && (float)$v > 0;
}
