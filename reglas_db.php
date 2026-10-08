<?php
// Reglas de validación que necesitan consultar la base de datos (Paso 3).
// Todas lanzan ErrorValidacion con un mensaje en español, listo para mostrar, que dice qué documento
// y qué número o fecha chocan. Quien las llama NO guarda nada si se lanza el error.
require_once __DIR__ . '/validaciones.php';

/** Fecha para mostrar en mensajes: 12/03/2025. */
function fmt_fecha($f): string
{
    return fecha_valida((string)$f) ? date('d/m/Y', strtotime($f)) : '(fecha inválida: ' . ($f === null || $f === '' ? 'vacía' : $f) . ')';
}

function fmt_lista(array $lineas, int $max = 5): string
{
    $extra = count($lineas) - $max;
    $lineas = array_slice($lineas, 0, $max);
    return implode('; ', $lineas) . ($extra > 0 ? "; y $extra más" : '');
}

// ------------------------------------------------------------------ ítems y cantidades

/**
 * Prepara y valida los ítems de una OC. Cada ítem es un material de la SOLICITUD de la obra: la
 * descripción y la unidad salen de la solicitud, no se escriben a mano. $compra_id = 0 en el alta.
 * Controla cantidad > 0, precio > 0 y que no se supere el saldo pendiente de la solicitud
 * (sumando también las filas repetidas del mismo material dentro de esta OC).
 * Devuelve los ítems listos para guardar: id, material_id, descripcion, unidad, cantidad, precio.
 */
function preparar_items_oc(PDO $pdo, int $obra_id, array $items, int $compra_id = 0): array
{
    $st = $pdo->prepare("SELECT id, descripcion, cantidad, unidad FROM materiales_solicitados WHERE obra_id = ?");
    $st->execute([$obra_id]);
    $materiales = [];
    foreach ($st as $m) {
        $materiales[(int)$m['id']] = $m;
    }

    // Cantidad ya adjudicada en OTRAS órdenes de compra
    $st = $pdo->prepare("
        SELECT material_solicitado_id, SUM(cantidad_comprada) AS total
        FROM compra_detalles
        WHERE material_solicitado_id IS NOT NULL AND compra_id <> ?
        GROUP BY material_solicitado_id
    ");
    $st->execute([$compra_id]);
    $en_otras = [];
    foreach ($st as $r) {
        $en_otras[(int)$r['material_solicitado_id']] = (float)$r['total'];
    }

    // Ítems que ya tiene esta OC (al editar)
    $existentes = [];
    if ($compra_id > 0) {
        $st = $pdo->prepare("SELECT id, material_solicitado_id, descripcion, unidad FROM compra_detalles WHERE compra_id = ?");
        $st->execute([$compra_id]);
        foreach ($st as $r) {
            $existentes[(int)$r['id']] = $r;
        }
    }

    $salida = [];
    $acumulado = [];
    foreach (array_values($items) as $i => $it) {
        if (!isset($it['incluir'])) continue;
        $n = $i + 1;
        $item_id = (int)($it['id'] ?? 0);
        $ex = null;
        if ($item_id > 0) {
            if (!isset($existentes[$item_id])) {
                throw new ErrorValidacion("Ítem #$n: no pertenece a esta orden de compra.");
            }
            $ex = $existentes[$item_id];
        }
        // Si el ítem ya estaba vinculado a un material, el vínculo no cambia
        $mat_id = ($ex && $ex['material_solicitado_id'] !== null) ? (int)$ex['material_solicitado_id'] : (int)($it['material_solicitado_id'] ?? 0);

        if ($mat_id > 0) {
            if (!isset($materiales[$mat_id])) {
                throw new ErrorValidacion("Ítem #$n: el material elegido no pertenece a la solicitud de esta obra.");
            }
            $desc = $materiales[$mat_id]['descripcion'];
            $unidad = $materiales[$mat_id]['unidad'];
        } elseif ($ex) {
            // Ítem antiguo sin vincular a la solicitud: se conserva como está
            $desc = $ex['descripcion'];
            $unidad = $ex['unidad'];
        } else {
            throw new ErrorValidacion("Ítem #$n: elegí el material de la solicitud de la obra.");
        }

        $etq = "Ítem «{$desc}»";
        $cant_txt = $it['cantidad_comprada'] ?? '';
        $prec_txt = $it['precio_unitario'] ?? '';
        if (!numero_positivo($cant_txt)) {
            throw new ErrorValidacion("$etq: la cantidad debe ser mayor que cero (se ingresó «{$cant_txt}»).");
        }
        if (!numero_positivo($prec_txt)) {
            throw new ErrorValidacion("$etq: el precio unitario debe ser mayor que cero (se ingresó «{$prec_txt}»).");
        }
        $cantidad = round((float)$cant_txt, 2);

        if ($mat_id > 0) {
            $acumulado[$mat_id] = ($acumulado[$mat_id] ?? 0) + $cantidad;
            $solicitada = (float)$materiales[$mat_id]['cantidad'];
            $otras = $en_otras[$mat_id] ?? 0.0;
            $disponible = $solicitada - $otras;
            if ($acumulado[$mat_id] > $disponible + 0.005) {
                throw new ErrorValidacion("La cantidad a comprar de '{$desc}' (" . rtrim(rtrim(number_format($acumulado[$mat_id], 2, '.', ''), '0'), '.') . ") supera el saldo pendiente de la solicitud original. Solicitado: " . rtrim(rtrim(number_format($solicitada, 2, '.', ''), '0'), '.') . ", ya adjudicado en otras OC: " . rtrim(rtrim(number_format($otras, 2, '.', ''), '0'), '.') . ". Disponible para comprar: " . rtrim(rtrim(number_format(max($disponible, 0), 2, '.', ''), '0'), '.') . '.');
            }
        }
        $salida[] = [
            'id' => $item_id,
            'material_id' => $mat_id > 0 ? $mat_id : null,
            'descripcion' => $desc,
            'unidad' => $unidad,
            'cantidad' => $cantidad,
            'precio' => round((float)$prec_txt, 2),
        ];
    }
    if (!$salida) {
        throw new ErrorValidacion('La OC debe tener al menos un ítem tildado.');
    }
    return $salida;
}

/** Materiales solicitados de una obra: las filas con datos deben tener descripción y cantidad > 0. Devuelve el error o null. */
function materiales_error(array $materiales): ?string
{
    foreach ($materiales as $idx => $m) {
        $desc = trim((string)($m['descripcion'] ?? ''));
        $cant = trim((string)($m['cantidad'] ?? ''));
        if ($desc === '' && ($cant === '' || (float)$cant == 0.0)) continue;   // fila vacía
        if ($desc === '') {
            return 'Material #' . ($idx + 1) . ': falta la descripción.';
        }
        if (!numero_positivo($cant)) {
            return "Material «{$desc}»: la cantidad debe ser mayor que cero (se ingresó «{$cant}»).";
        }
    }
    return null;
}

// ------------------------------------------------------------------ Orden de compra

/**
 * Valida número y fecha de una OC (alta o edición). $excluir_id = la propia OC al editar.
 * Devuelve una lista de ADVERTENCIAS (no bloquean).
 */
function validar_oc(PDO $pdo, string $nro, string $fecha, int $excluir_id = 0): array
{
    if (!oc_formato_valido($nro)) {
        throw new ErrorValidacion("El número de OC «{$nro}» no es válido. Debe tener el formato AAAA-NNNN: año de 4 dígitos, guion y número de 4 dígitos (ejemplo: 2025-0001).");
    }
    if (!fecha_valida($fecha)) {
        throw new ErrorValidacion("La fecha de la OC $nro no es válida («{$fecha}»). Usá una fecha real entre " . ANIO_MIN . ' y ' . anio_max() . '.');
    }
    $st = $pdo->prepare("
        SELECT c.nro_compra, c.proveedor, c.fecha_compra, o.expediente
        FROM compras c JOIN obras o ON o.id = c.obra_id
        WHERE c.nro_compra = ? AND c.id <> ? LIMIT 1
    ");
    $st->execute([$nro, $excluir_id]);
    if ($otra = $st->fetch()) {
        throw new ErrorValidacion("El número de OC $nro ya existe en el sistema: pertenece a la OC de «{$otra['proveedor']}» del " . fmt_fecha($otra['fecha_compra']) . " (obra {$otra['expediente']}). Cada OC debe tener un número único.");
    }
    $adv = [];
    if (oc_anio($nro) !== (int)substr($fecha, 0, 4)) {
        $adv[] = 'Advertencia: el número ' . $nro . ' indica el año ' . oc_anio($nro) . ' pero la fecha de la OC es ' . fmt_fecha($fecha) . '. Se guardó igual; revisá que sea correcto.';
    }
    return $adv;
}

/** Al cambiar la fecha de una OC: no puede quedar posterior a la de sus facturas ni a la de sus remitos. */
function validar_fecha_oc_vs_vinculados(PDO $pdo, int $compra_id, string $nro, string $fecha): void
{
    $st = $pdo->prepare("SELECT nro_factura, fecha_factura FROM facturas WHERE compra_id = ? ORDER BY fecha_factura");
    $st->execute([$compra_id]);
    $malas = [];
    foreach ($st as $f) {
        if (!fecha_valida((string)$f['fecha_factura']) || $f['fecha_factura'] < $fecha) {
            $malas[] = 'la factura ' . $f['nro_factura'] . ' es del ' . fmt_fecha($f['fecha_factura']);
        }
    }
    if ($malas) {
        throw new ErrorValidacion("No se puede fechar la OC $nro el " . fmt_fecha($fecha) . ': ' . fmt_lista($malas) . '. La fecha de la OC no puede ser posterior a la de sus facturas.');
    }

    $st = $pdo->prepare("
        SELECT DISTINCT r.id, r.nro_remito, r.fecha_entrega
        FROM remito_detalles rd
        JOIN compra_detalles cd ON cd.id = rd.compra_detalle_id
        JOIN remitos r ON r.id = rd.remito_id
        WHERE cd.compra_id = ? ORDER BY r.fecha_entrega
    ");
    $st->execute([$compra_id]);
    $malos = [];
    foreach ($st as $r) {
        if (!fecha_valida((string)$r['fecha_entrega']) || $r['fecha_entrega'] < $fecha) {
            $malos[] = 'el remito ' . (remito_sin_numero($r['nro_remito']) ? '(sin número)' : $r['nro_remito']) . ' es del ' . fmt_fecha($r['fecha_entrega']);
        }
    }
    if ($malos) {
        throw new ErrorValidacion("No se puede fechar la OC $nro el " . fmt_fecha($fecha) . ': ' . fmt_lista($malos) . '. La fecha de la OC no puede ser posterior a la de sus remitos.');
    }
}

// ------------------------------------------------------------------ Factura

/** Busca otra factura del mismo proveedor con el mismo número (ARCA normalizado). null si no hay. */
function buscar_factura_duplicada(PDO $pdo, string $proveedor, string $nro, int $excluir_factura_id = 0): ?array
{
    $pk = proveedor_clave($proveedor);
    $fk = factura_clave($nro);
    $st = $pdo->query("
        SELECT f.id, f.nro_factura, f.fecha_factura, c.nro_compra, c.proveedor
        FROM facturas f JOIN compras c ON c.id = f.compra_id
    ");
    foreach ($st as $f) {
        if ((int)$f['id'] !== $excluir_factura_id && proveedor_clave($f['proveedor']) === $pk && factura_clave($f['nro_factura']) === $fk) {
            return $f;
        }
    }
    return null;
}

/** Valida una factura nueva contra su OC. */
function validar_factura(PDO $pdo, int $compra_id, string $nro, string $fecha, $monto): void
{
    $st = $pdo->prepare("SELECT nro_compra, proveedor, fecha_compra FROM compras WHERE id = ?");
    $st->execute([$compra_id]);
    if (!($oc = $st->fetch())) {
        throw new ErrorValidacion('La orden de compra elegida no existe.');
    }
    if (!factura_arca_valida($nro)) {
        throw new ErrorValidacion("El número de factura «{$nro}» no cumple la nomenclatura ARCA: punto de venta de 4 o 5 dígitos, guion y número de 8 dígitos (ejemplo: 00001-00001234).");
    }
    if (!fecha_valida($fecha)) {
        throw new ErrorValidacion("La fecha de la factura $nro no es válida («{$fecha}»). Usá una fecha real entre " . ANIO_MIN . ' y ' . anio_max() . '.');
    }
    if (!numero_positivo($monto)) {
        throw new ErrorValidacion("El monto de la factura $nro debe ser mayor que cero (se ingresó «{$monto}»).");
    }
    if (fecha_valida((string)$oc['fecha_compra']) && $fecha < $oc['fecha_compra']) {
        throw new ErrorValidacion("La factura $nro (" . fmt_fecha($fecha) . ") no puede ser anterior a su OC {$oc['nro_compra']} (" . fmt_fecha($oc['fecha_compra']) . ').');
    }
    if ($dup = buscar_factura_duplicada($pdo, $oc['proveedor'], $nro)) {
        throw new ErrorValidacion("La factura $nro del proveedor «{$oc['proveedor']}» ya está cargada (OC {$dup['nro_compra']}, fecha " . fmt_fecha($dup['fecha_factura']) . '). No se puede repetir el número para el mismo proveedor.');
    }
}

// ------------------------------------------------------------------ Remito

/** Proveedores de un remito ya guardado (clave => nombre), deducidos de las OC de sus ítems. */
function proveedores_de_remito(PDO $pdo, int $remito_id): array
{
    $st = $pdo->prepare("
        SELECT DISTINCT c.proveedor
        FROM remito_detalles rd
        JOIN compra_detalles cd ON cd.id = rd.compra_detalle_id
        JOIN compras c ON c.id = cd.compra_id
        WHERE rd.remito_id = ?
    ");
    $st->execute([$remito_id]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $p) {
        $out[proveedor_clave($p)] = $p;
    }
    return $out;
}

/** Que ese número no esté ya usado por otro remito de alguno de esos proveedores. Formato libre. */
function validar_numero_remito_unico(PDO $pdo, string $nro, array $proveedores, int $excluir_remito_id = 0): void
{
    if (remito_sin_numero($nro) || !$proveedores) return;
    $clave = remito_clave($nro);
    $st = $pdo->query("
        SELECT DISTINCT r.id, r.nro_remito, r.fecha_entrega, c.proveedor
        FROM remitos r
        JOIN remito_detalles rd ON rd.remito_id = r.id
        JOIN compra_detalles cd ON cd.id = rd.compra_detalle_id
        JOIN compras c ON c.id = cd.compra_id
        WHERE r.nro_remito IS NOT NULL
    ");
    foreach ($st as $r) {
        if ((int)$r['id'] !== $excluir_remito_id && remito_clave($r['nro_remito']) === $clave && isset($proveedores[proveedor_clave($r['proveedor'])])) {
            throw new ErrorValidacion("El remito $nro ya existe para el proveedor «{$r['proveedor']}» (fecha " . fmt_fecha($r['fecha_entrega']) . '). No se puede repetir el mismo número para el mismo proveedor.');
        }
    }
}

/** Conciliar un remito pendiente: el número que se carga no puede repetir otro del mismo proveedor. */
function validar_conciliar_remito(PDO $pdo, int $remito_id, string $nro): void
{
    if (remito_sin_numero($nro)) {
        throw new ErrorValidacion('Ingresá el número real del remito (no puede quedar vacío ni decir PENDIENTE).');
    }
    validar_numero_remito_unico($pdo, $nro, proveedores_de_remito($pdo, $remito_id), $remito_id);
}

/**
 * Valida un remito nuevo: fecha válida, cantidades > 0, fecha >= fecha de la OC de cada ítem y
 * número no repetido para el proveedor (que se deduce de los ítems).
 */
function validar_remito(PDO $pdo, ?string $nro, string $fecha, array $items): void
{
    $etq = ($nro === null || remito_sin_numero($nro)) ? 'El remito' : "El remito $nro";
    if (!fecha_valida($fecha)) {
        throw new ErrorValidacion("La fecha del remito no es válida («{$fecha}»). Usá una fecha real entre " . ANIO_MIN . ' y ' . anio_max() . '.');
    }
    $ids = [];
    foreach ($items as $idx => $it) {
        $det = (int)($it['compra_detalle_id'] ?? 0);
        $cant = $it['cantidad_entregada'] ?? '';
        if ($det <= 0 && trim((string)$cant) === '') continue;   // fila sin completar
        if ($det <= 0) {
            throw new ErrorValidacion("$etq: el ítem #" . ($idx + 1) . ' no tiene material elegido.');
        }
        if (!numero_positivo($cant)) {
            throw new ErrorValidacion("$etq: la cantidad entregada debe ser mayor que cero (se ingresó «{$cant}»).");
        }
        $ids[] = $det;
    }
    if (!$ids) {
        throw new ErrorValidacion("$etq debe tener al menos un material con cantidad.");
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("
        SELECT cd.id, cd.descripcion, c.nro_compra, c.proveedor, c.fecha_compra
        FROM compra_detalles cd JOIN compras c ON c.id = cd.compra_id
        WHERE cd.id IN ($in)
    ");
    $st->execute($ids);
    $filas = $st->fetchAll();
    if (count($filas) !== count(array_unique($ids))) {
        throw new ErrorValidacion("$etq tiene un ítem que no existe en las órdenes de compra.");
    }
    $malas = [];
    $proveedores = [];
    foreach ($filas as $f) {
        $proveedores[proveedor_clave($f['proveedor'])] = $f['proveedor'];
        if (fecha_valida((string)$f['fecha_compra']) && $fecha < $f['fecha_compra']) {
            $malas[$f['nro_compra']] = 'OC ' . $f['nro_compra'] . ' (' . fmt_fecha($f['fecha_compra']) . ')';
        }
    }
    if ($malas) {
        throw new ErrorValidacion("$etq (" . fmt_fecha($fecha) . ') no puede ser anterior a la fecha de la OC de sus ítems: ' . fmt_lista(array_values($malas)) . '.');
    }
    if ($nro !== null) {
        validar_numero_remito_unico($pdo, $nro, $proveedores);
    }
}

// ------------------------------------------------------------------ cambio de proveedor de una OC

/** Si una OC cambia de proveedor, sus facturas y remitos no pueden quedar duplicados con los del nuevo proveedor. */
function validar_cambio_proveedor_oc(PDO $pdo, int $compra_id, string $proveedor_nuevo): void
{
    $st = $pdo->prepare("SELECT id, nro_factura FROM facturas WHERE compra_id = ?");
    $st->execute([$compra_id]);
    foreach ($st as $f) {
        if ($dup = buscar_factura_duplicada($pdo, $proveedor_nuevo, $f['nro_factura'], (int)$f['id'])) {
            throw new ErrorValidacion("No se puede cambiar el proveedor a «{$proveedor_nuevo}»: la factura {$f['nro_factura']} de esta OC repetiría la factura {$dup['nro_factura']} ya cargada para ese proveedor (OC {$dup['nro_compra']}).");
        }
    }
    $st = $pdo->prepare("
        SELECT DISTINCT r.id, r.nro_remito FROM remito_detalles rd
        JOIN compra_detalles cd ON cd.id = rd.compra_detalle_id
        JOIN remitos r ON r.id = rd.remito_id WHERE cd.compra_id = ?
    ");
    $st->execute([$compra_id]);
    foreach ($st as $r) {
        validar_numero_remito_unico($pdo, (string)$r['nro_remito'], [proveedor_clave($proveedor_nuevo) => $proveedor_nuevo], (int)$r['id']);
    }
}
