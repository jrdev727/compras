<?php
// Reglas de validación compartidas por el informe de datos y los formularios.
// Funciones puras: no tocan la base de datos.

const ANIO_MIN = 2000;

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

/** Número de OC con formato AAAA-NNNN (ej. 2025-0001). */
function oc_formato_valido($n): bool
{
    return is_string($n) && preg_match('/^(\d{4})-(\d{4})$/', $n, $m)
        && (int)$m[1] >= ANIO_MIN && (int)$m[1] <= anio_max() && (int)$m[2] > 0;
}

/** Año que figura en el número de OC, o null si no tiene formato válido. */
function oc_anio($n): ?int
{
    return (is_string($n) && preg_match('/^(\d{4})-\d{4}$/', $n, $m)) ? (int)$m[1] : null;
}

/** Factura con nomenclatura ARCA: punto de venta de 4 o 5 dígitos, guion, número de 8 dígitos. */
function factura_arca_valida($n): bool
{
    return is_string($n) && preg_match('/^(\d{4,5})-(\d{8})$/', $n, $m)
        && (int)$m[1] > 0 && (int)$m[2] > 0;
}

/** Clave para comparar números de factura ("1-1234" y "00001-00001234" son la misma). */
function factura_clave($n): string
{
    $n = trim((string)$n);
    if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $n, $m)) {
        return (int)$m[1] . '-' . (int)$m[2];
    }
    return strtoupper(preg_replace('/\s+/', '', $n));
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
