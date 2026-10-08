<?php
// INFORME DE CALIDAD DE DATOS - Solo lectura: no modifica nada en la base.
require_once 'bootstrap.php';
require_once 'validaciones.php';

// ------------------------------------------------------------------ carga de datos
$obras = [];
foreach ($pdo->query("SELECT id, nombre, expediente FROM obras ORDER BY nombre ASC") as $r) {
    $obras[$r['id']] = $r;
}
$compras = [];
foreach ($pdo->query("SELECT id, obra_id, nro_compra, proveedor, fecha_compra FROM compras ORDER BY id") as $r) {
    $compras[$r['id']] = $r;
}
$detalles = $pdo->query("SELECT id, compra_id, descripcion, cantidad_comprada, precio_unitario FROM compra_detalles ORDER BY id")->fetchAll();
$facturas = $pdo->query("SELECT id, compra_id, nro_factura, fecha_factura, monto FROM facturas ORDER BY id")->fetchAll();
$remitos = [];
foreach ($pdo->query("SELECT id, obra_id, nro_remito, fecha_entrega FROM remitos ORDER BY id") as $r) {
    $remitos[$r['id']] = $r;
}
$remito_items = $pdo->query("
    SELECT rd.id, rd.remito_id, rd.cantidad_entregada, cd.descripcion, cd.compra_id
    FROM remito_detalles rd
    JOIN compra_detalles cd ON rd.compra_detalle_id = cd.id
    ORDER BY rd.id
")->fetchAll();
$materiales = $pdo->query("SELECT id, obra_id, descripcion, cantidad FROM materiales_solicitados ORDER BY id")->fetchAll();

// ------------------------------------------------------------------ definición de categorías
// [grupo, título, severidad]  (error = hay que corregirlo; aviso = solo informativo)
$CATEGORIAS = [
    'oc_dup'        => ['a', 'Números de OC repetidos', 'error'],
    'fact_dup'      => ['a', 'Facturas repetidas (mismo proveedor y número)', 'error'],
    'rem_dup'       => ['a', 'Remitos repetidos (mismo proveedor y número)', 'error'],
    'fact_fecha'    => ['c', 'Factura con fecha anterior a su OC', 'error'],
    'rem_fecha'     => ['c', 'Remito con fecha anterior a la OC de sus ítems', 'error'],
    'cantidad'      => ['d', 'Cantidades, precios o montos menores o iguales a cero', 'error'],
    'fecha_inval'   => ['d', 'Fechas inválidas', 'error'],
    'oc_sin_nro'    => ['d', 'OC sin número', 'error'],
    'oc_sin_prov'   => ['d', 'OC sin proveedor', 'error'],
    'rem_sin_items' => ['d', 'Remitos sin ítems (no se puede deducir el proveedor)', 'error'],
    'oc_anio'       => ['d', 'Aviso: el año del número de OC no coincide con el año de su fecha', 'aviso'],
];

$problemas = [];                       // [obra_id][categoria][] = html
$conteo    = array_fill_keys(array_keys($CATEGORIAS), 0);

/** Registra un hallazgo en una o más obras (se cuenta una sola vez). */
function hallazgo(string $cat, array $obra_ids, string $html): void
{
    global $problemas, $conteo;
    $conteo[$cat]++;
    foreach (array_unique($obra_ids) as $oid) {
        $problemas[$oid][$cat][] = $html;
    }
}

function fecha_txt($f): string
{
    if (!fecha_valida((string)$f)) {
        return '<strong>' . h($f === null || $f === '' ? '(vacía)' : $f) . '</strong> (inválida)';
    }
    return h(date('d/m/Y', strtotime($f)));
}

function oc_txt(array $c): string
{
    return 'OC <strong>' . h($c['nro_compra']) . '</strong>';
}

function obra_corta(int $obra_id): string
{
    global $obras;
    return isset($obras[$obra_id]) ? h($obras[$obra_id]['expediente'] . ' - ' . $obras[$obra_id]['nombre']) : '?';
}

// ------------------------------------------------------------------ a) duplicados
// OC repetidas (en todo el sistema; 2025-1 y 2025-0001 cuentan como el mismo número)
$grupos = [];
foreach ($compras as $c) {
    $k = oc_clave($c['nro_compra']);
    if ($k !== '') {
        $grupos[$k][] = $c;
    }
}
foreach ($grupos as $miembros) {
    if (count($miembros) < 2) continue;
    $lineas = [];
    foreach ($miembros as $c) {
        $lineas[] = oc_txt($c) . ' de ' . h($c['proveedor']) . ' del ' . fecha_txt($c['fecha_compra']) . ' (obra: ' . obra_corta($c['obra_id']) . ')';
    }
    hallazgo('oc_dup', array_column($miembros, 'obra_id'), 'El número de OC se usa ' . count($miembros) . ' veces:<br>' . implode('<br>', $lineas));
}

// Facturas repetidas (mismo proveedor + mismo número)
$grupos = [];
foreach ($facturas as $f) {
    $c = $compras[$f['compra_id']] ?? null;
    if (!$c || trim((string)$f['nro_factura']) === '') continue;
    $grupos[proveedor_clave($c['proveedor']) . '|' . factura_clave($f['nro_factura'])][] = [$f, $c];
}
foreach ($grupos as $miembros) {
    if (count($miembros) < 2) continue;
    $lineas = [];
    foreach ($miembros as [$f, $c]) {
        $lineas[] = 'Factura <strong>' . h($f['nro_factura']) . '</strong> del ' . fecha_txt($f['fecha_factura'])
            . ' por $ ' . h(number_format((float)$f['monto'], 2, ',', '.')) . ' - ' . oc_txt($c) . ' (obra: ' . obra_corta($c['obra_id']) . ')';
    }
    hallazgo('fact_dup', array_map(fn($m) => $m[1]['obra_id'], $miembros),
        'La factura de ' . h($miembros[0][1]['proveedor']) . ' está cargada ' . count($miembros) . ' veces:<br>' . implode('<br>', $lineas));
}

// Remitos repetidos (mismo proveedor + mismo número). El proveedor sale de las OC de los ítems.
$prov_por_remito = [];
foreach ($remito_items as $it) {
    $c = $compras[$it['compra_id']] ?? null;
    if ($c) {
        $prov_por_remito[$it['remito_id']][proveedor_clave($c['proveedor'])] = $c['proveedor'];
    }
}
$grupos = [];
foreach ($remitos as $r) {
    if (remito_sin_numero($r['nro_remito'])) continue;
    foreach ($prov_por_remito[$r['id']] ?? [] as $pk => $pnom) {
        $grupos[$pk . '|' . remito_clave($r['nro_remito'])][$r['id']] = [$r, $pnom];
    }
}
foreach ($grupos as $miembros) {
    if (count($miembros) < 2) continue;
    $lineas = [];
    foreach ($miembros as [$r, $pnom]) {
        $lineas[] = 'Remito <strong>' . h($r['nro_remito']) . '</strong> del ' . fecha_txt($r['fecha_entrega']) . ' (obra: ' . obra_corta($r['obra_id']) . ')';
    }
    $primero = reset($miembros);
    hallazgo('rem_dup', array_map(fn($m) => $m[0]['obra_id'], $miembros),
        'El número de remito del proveedor ' . h($primero[1]) . ' se usa ' . count($miembros) . ' veces:<br>' . implode('<br>', $lineas));
}

// ------------------------------------------------------------------ b) proveedores casi iguales (global)
$variantes = [];   // clave => [texto exacto => ['ocs' => n, 'obras' => [ids]]]
foreach ($compras as $c) {
    $k = proveedor_clave($c['proveedor']);
    if ($k === '') continue;
    $v = &$variantes[$k][$c['proveedor']];
    $v['ocs'] = ($v['ocs'] ?? 0) + 1;
    $v['obras'][$c['obra_id']] = true;
    unset($v);
}
$prov_similares = array_filter($variantes, fn($g) => count($g) > 1);

// ------------------------------------------------------------------ c) fechas fuera de orden
foreach ($facturas as $f) {
    $c = $compras[$f['compra_id']] ?? null;
    if (!$c || !fecha_valida((string)$f['fecha_factura']) || !fecha_valida((string)$c['fecha_compra'])) continue;
    if ($f['fecha_factura'] < $c['fecha_compra']) {
        hallazgo('fact_fecha', [$c['obra_id']],
            'Factura <strong>' . h($f['nro_factura']) . '</strong> (' . fecha_txt($f['fecha_factura']) . ') es anterior a su ' . oc_txt($c) . ' (' . fecha_txt($c['fecha_compra']) . ').');
    }
}
$ocs_por_remito = [];
foreach ($remito_items as $it) {
    if (isset($compras[$it['compra_id']])) {
        $ocs_por_remito[$it['remito_id']][$it['compra_id']] = $compras[$it['compra_id']];
    }
}
foreach ($remitos as $r) {
    if (!fecha_valida((string)$r['fecha_entrega'])) continue;
    $malas = [];
    foreach ($ocs_por_remito[$r['id']] ?? [] as $c) {
        if (fecha_valida((string)$c['fecha_compra']) && $r['fecha_entrega'] < $c['fecha_compra']) {
            $malas[] = oc_txt($c) . ' (' . fecha_txt($c['fecha_compra']) . ')';
        }
    }
    if ($malas) {
        hallazgo('rem_fecha', [$r['obra_id']],
            'Remito <strong>' . h(remito_sin_numero($r['nro_remito']) ? '(sin número)' : $r['nro_remito']) . '</strong> (' . fecha_txt($r['fecha_entrega'])
            . ') es anterior a: ' . implode(', ', $malas) . '.');
    }
}

// ------------------------------------------------------------------ d) valores y formatos inválidos
foreach ($compras as $c) {
    $oid = $c['obra_id'];
    if (trim((string)$c['nro_compra']) === '') {
        hallazgo('oc_sin_nro', [$oid], 'Hay una OC sin número (proveedor: ' . h($c['proveedor']) . ').');
    } elseif (fecha_valida((string)$c['fecha_compra']) && oc_anio($c['nro_compra']) !== null && oc_anio($c['nro_compra']) !== (int)substr($c['fecha_compra'], 0, 4)) {
        hallazgo('oc_anio', [$oid], oc_txt($c) . ' tiene fecha ' . fecha_txt($c['fecha_compra']) . '.');
    }
    if (!fecha_valida((string)$c['fecha_compra'])) {
        hallazgo('fecha_inval', [$oid], 'Fecha de la ' . oc_txt($c) . ': ' . fecha_txt($c['fecha_compra']));
    }
    if (trim((string)$c['proveedor']) === '') {
        hallazgo('oc_sin_prov', [$oid], oc_txt($c) . ' no tiene proveedor.');
    }
}
foreach ($detalles as $d) {
    $c = $compras[$d['compra_id']] ?? null;
    if (!$c) continue;
    foreach (['cantidad_comprada' => 'cantidad', 'precio_unitario' => 'precio unitario'] as $col => $nom) {
        if (!numero_positivo($d[$col])) {
            hallazgo('cantidad', [$c['obra_id']], oc_txt($c) . ', ítem «' . h($d['descripcion']) . '»: ' . $nom . ' = <strong>' . h($d[$col]) . '</strong>.');
        }
    }
}
foreach ($facturas as $f) {
    $c = $compras[$f['compra_id']] ?? null;
    if (!$c) continue;
    if (!numero_positivo($f['monto'])) {
        hallazgo('cantidad', [$c['obra_id']], 'Factura <strong>' . h($f['nro_factura']) . '</strong>: monto = <strong>' . h($f['monto']) . '</strong>.');
    }
    if (!fecha_valida((string)$f['fecha_factura'])) {
        hallazgo('fecha_inval', [$c['obra_id']], 'Fecha de la factura <strong>' . h($f['nro_factura']) . '</strong>: ' . fecha_txt($f['fecha_factura']));
    }
}
foreach ($remitos as $r) {
    if (!fecha_valida((string)$r['fecha_entrega'])) {
        hallazgo('fecha_inval', [$r['obra_id']], 'Fecha del remito <strong>' . h(remito_sin_numero($r['nro_remito']) ? '(sin número)' : $r['nro_remito']) . '</strong>: ' . fecha_txt($r['fecha_entrega']));
    }
    if (!isset($prov_por_remito[$r['id']])) {
        hallazgo('rem_sin_items', [$r['obra_id']], 'Remito <strong>' . h(remito_sin_numero($r['nro_remito']) ? '(sin número)' : $r['nro_remito']) . '</strong> del ' . fecha_txt($r['fecha_entrega']) . ' no tiene ítems.');
    }
}
foreach ($remito_items as $it) {
    if (!numero_positivo($it['cantidad_entregada'])) {
        $r = $remitos[$it['remito_id']] ?? null;
        $c = $compras[$it['compra_id']] ?? null;
        if ($r) {
            hallazgo('cantidad', [$r['obra_id']], 'Remito <strong>' . h(remito_sin_numero($r['nro_remito']) ? '(sin número)' : $r['nro_remito']) . '</strong>, ítem «' . h($it['descripcion']) . '»'
                . ($c ? ' (' . oc_txt($c) . ')' : '') . ': cantidad entregada = <strong>' . h($it['cantidad_entregada']) . '</strong>.');
        }
    }
}
foreach ($materiales as $m) {
    if (!numero_positivo($m['cantidad'])) {
        hallazgo('cantidad', [$m['obra_id']], 'Material solicitado «' . h($m['descripcion']) . '»: cantidad = <strong>' . h($m['cantidad']) . '</strong>.');
    }
}

// ------------------------------------------------------------------ totales
$total_errores = 0;
foreach ($CATEGORIAS as $k => $def) {
    if ($def[2] === 'error') $total_errores += $conteo[$k];
}
$total_errores += count($prov_similares);
$total_avisos = $conteo['oc_anio'];

require_once 'header.php';
?>
<div class="page-header">
    <div class="page-title">
        <h1>Informe de calidad de datos</h1>
        <p>Solo lectura: no modifica nada. Corregí lo que figure acá y volvé a cargar la página hasta que quede limpio.</p>
    </div>
    <div class="d-flex gap-2 align-center">
        <a href="informe_datos.php" class="btn btn-secondary">Actualizar</a>
        <form method="POST" action="login.php" style="margin:0;">
            <?= csrf_campo() ?>
            <input type="hidden" name="accion" value="salir">
            <button type="submit" class="btn btn-secondary">Salir (<?= h(auth_usuario()) ?>)</button>
        </form>
    </div>
</div>

<?php if ($total_errores === 0): ?>
    <div class="alert-banner" style="background: var(--success-light); border-left: 4px solid var(--success);">
        <div class="alert-banner-text"><h4>Informe limpio</h4><p>No se encontraron problemas a corregir<?= $total_avisos ? " ($total_avisos aviso(s) informativo(s) abajo)" : '' ?>.</p></div>
    </div>
<?php else: ?>
    <div class="alert-banner alert-banner-danger">
        <div class="alert-banner-text"><h4><?= $total_errores ?> problema(s) para corregir</h4><p>Detalle por obra más abajo.</p></div>
    </div>
<?php endif; ?>

<div class="card" style="margin-bottom: 2rem;">
    <div class="card-header"><h2 class="card-title">Resumen</h2></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th style="width:3rem;">Punto</th><th>Control</th><th class="text-right" style="width:8rem;">Cantidad</th></tr></thead>
                <tbody>
                <?php
                $filas = [];
                foreach ($CATEGORIAS as $k => $def) {
                    $filas[] = [$def[0], $def[1], $conteo[$k], $def[2]];
                    if ($k === 'rem_dup') {   // el punto b va entre a) y c)
                        $filas[] = ['b', 'Proveedores con nombres casi iguales', count($prov_similares), 'error'];
                    }
                }
                foreach ($filas as [$g, $titulo, $n, $sev]): ?>
                    <tr>
                        <td><?= h(strtoupper($g)) ?></td>
                        <td><?= h($titulo) ?></td>
                        <td class="text-right">
                            <span class="badge <?= $n === 0 ? 'badge-success' : ($sev === 'aviso' ? 'badge-warning' : 'badge-danger') ?>"><?= $n ?></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($prov_similares): ?>
<div class="card" style="margin-bottom: 2rem; border-left: 4px solid var(--danger);">
    <div class="card-header"><h2 class="card-title">B) Proveedores con nombres casi iguales</h2></div>
    <div class="card-body">
        <p style="color: var(--text-secondary); margin-bottom: 1rem;">Cada grupo parece ser el mismo proveedor escrito distinto (se ignoran mayúsculas, tildes, puntos y espacios). Dejá un solo nombre en todas las OC.</p>
        <?php foreach ($prov_similares as $grupo): ?>
            <div class="table-responsive" style="margin-bottom: 1rem;">
                <table class="table">
                    <thead><tr><th>Nombre tal como está cargado</th><th>OC</th><th>Obras</th></tr></thead>
                    <tbody>
                    <?php foreach ($grupo as $nombre => $info): ?>
                        <tr>
                            <td>«<?= h($nombre) ?>»</td>
                            <td><?= (int)$info['ocs'] ?></td>
                            <td><?= implode('; ', array_map(fn($id) => obra_corta($id), array_keys($info['obras']))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php
$hay_obras_con_problemas = false;
foreach ($obras as $oid => $obra):
    if (empty($problemas[$oid])) continue;
    $hay_obras_con_problemas = true; ?>
    <div class="card" style="margin-bottom: 2rem; border-left: 4px solid var(--primary);">
        <div class="card-header">
            <h2 class="card-title"><?= h($obra['expediente']) ?> - <?= h($obra['nombre']) ?></h2>
            <a href="obra_detalle.php?id=<?= (int)$oid ?>" class="btn btn-secondary btn-sm">Ver obra</a>
        </div>
        <div class="card-body">
            <?php foreach ($CATEGORIAS as $k => $def):
                if (empty($problemas[$oid][$k])) continue; ?>
                <h3 style="font-size: 1rem; margin: 1rem 0 .5rem;">
                    <?= h(strtoupper($def[0])) ?>) <?= h($def[1]) ?>
                    <span class="badge <?= $def[2] === 'aviso' ? 'badge-warning' : 'badge-danger' ?>"><?= count($problemas[$oid][$k]) ?></span>
                </h3>
                <ul style="margin: 0 0 1rem 1.25rem;">
                    <?php foreach ($problemas[$oid][$k] as $linea): ?>
                        <li style="margin-bottom: .5rem;"><?= $linea /* ya escapado al construirlo */ ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endforeach; ?>
        </div>
    </div>
<?php endforeach; ?>

<?php if (!$hay_obras_con_problemas && !$prov_similares): ?>
    <div class="card"><div class="card-body"><p class="text-center" style="padding: 2rem; color: var(--text-secondary);">Ninguna obra tiene observaciones.</p></div></div>
<?php endif; ?>

<?php require_once 'footer.php'; ?>
