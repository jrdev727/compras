<?php
require_once 'bootstrap.php';

$obra_id = isset($_GET['obra_id']) ? intval($_GET['obra_id']) : 0;

if ($obra_id <= 0) {
    die("Obra no válida");
}

// Obtener detalles de la obra
$stmt = $pdo->prepare("SELECT * FROM obras WHERE id = ?");
$stmt->execute([$obra_id]);
$obra = $stmt->fetch();

if (!$obra) {
    die("La obra no existe");
}

// Obtener conciliación
$stmt = $pdo->prepare("
    SELECT 
        cd.id AS compra_detalle_id,
        c.nro_compra,
        c.proveedor,
        cd.descripcion,
        cd.cantidad_comprada,
        cd.unidad,
        cd.precio_unitario,
        COALESCE((
            SELECT SUM(rd.cantidad_entregada)
            FROM remito_detalles rd
            JOIN remitos r ON rd.remito_id = r.id
            WHERE rd.compra_detalle_id = cd.id
        ), 0) AS cantidad_entregada
    FROM compra_detalles cd
    JOIN compras c ON cd.compra_id = c.id
    WHERE c.obra_id = ?
    ORDER BY c.proveedor ASC, cd.descripcion ASC
");
$stmt->execute([$obra_id]);
$conciliacion = $stmt->fetchAll();

// Obtener detalle de remitos
$stmt = $pdo->prepare("
    SELECT r.nro_remito, r.fecha_entrega, r.recibido_por, rd.cantidad_entregada, cd.descripcion, cd.unidad, c.proveedor, c.nro_compra
    FROM remito_detalles rd
    JOIN remitos r ON rd.remito_id = r.id
    JOIN compra_detalles cd ON rd.compra_detalle_id = cd.id
    JOIN compras c ON cd.compra_id = c.id
    WHERE r.obra_id = ?
    ORDER BY r.fecha_entrega DESC, r.nro_remito DESC
");
$stmt->execute([$obra_id]);
$remitos_detalle = $stmt->fetchAll();

// Cabeceras para forzar la descarga de Excel (.xls)
$filename = "informe_materiales_" . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $obra['expediente']) . ".xls";
header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename={$filename}");
header("Pragma: no-cache");
header("Expires: 0");

?>
<!DOCTYPE html>
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<style>
    body { font-family: Arial, sans-serif; }
    .title { font-size: 16pt; font-weight: bold; color: #1e293b; }
    .subtitle { font-size: 11pt; color: #475569; }
    .table-header { background-color: #4f46e5; color: #ffffff; font-weight: bold; text-align: center; border: 1px solid #cbd5e1; }
    .table-header-sec { background-color: #0284c7; color: #ffffff; font-weight: bold; text-align: center; border: 1px solid #cbd5e1; }
    .section-title { font-size: 13pt; font-weight: bold; color: #0f172a; margin-top: 20px; }
    .cell-text { font-size: 10pt; border: 1px solid #e2e8f0; }
    .cell-number { font-size: 10pt; text-align: right; border: 1px solid #e2e8f0; }
    .cell-bold { font-size: 10pt; font-weight: bold; border: 1px solid #e2e8f0; }
    .cell-danger { font-size: 10pt; color: #b91c1c; font-weight: bold; border: 1px solid #e2e8f0; }
    .cell-success { font-size: 10pt; color: #15803d; font-weight: bold; border: 1px solid #e2e8f0; }
</style>
</head>
<body>

    <table>
        <tr>
            <td colspan="7" class="title">INFORME DE CONCILIACIÓN DE MATERIALES</td>
        </tr>
        <tr>
            <td colspan="7" class="subtitle">DIRECCIÓN DE OBRAS PÚBLICAS - EXP: <?= htmlspecialchars($obra['expediente']) ?></td>
        </tr>
        <tr>
            <td colspan="7"><b>Obra:</b> <?= htmlspecialchars($obra['nombre']) ?></td>
        </tr>
        <tr>
            <td colspan="7"><b>Fecha de Emisión:</b> <?= date('d/m/Y H:i') ?></td>
        </tr>
        <tr>
            <td colspan="7"><b>Estado de la Obra:</b> <?= htmlspecialchars($obra['estado']) ?></td>
        </tr>
        <tr>
            <td colspan="7"></td>
        </tr>
        
        <!-- SECCIÓN 1: RESUMEN DE CONCILIACIÓN -->
        <tr>
            <td colspan="7" class="section-title"><b>1. PLANILLA DE CONCILIACIÓN DE MATERIALES (COMPRADO VS ENTREGADO)</b></td>
        </tr>
        <thead>
            <tr>
                <th class="table-header" style="width: 250px;">Material Comprado</th>
                <th class="table-header" style="width: 180px;">Proveedor</th>
                <th class="table-header" style="width: 100px;">Nro OC</th>
                <th class="table-header" style="width: 120px;">Cant. Comprada</th>
                <th class="table-header" style="width: 120px;">Cant. Entregada</th>
                <th class="table-header" style="width: 120px;">Cant. Faltante (Pendiente)</th>
                <th class="table-header" style="width: 100px;">Estado</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($conciliacion)): ?>
                <tr>
                    <td colspan="7" class="cell-text" style="text-align: center; color: #64748b;">No se han registrado compras adjudicadas para esta obra.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($conciliacion as $c): 
                    $comprado = floatval($c['cantidad_comprada']);
                    $entregado = floatval($c['cantidad_entregada']);
                    $faltante = $comprado - $entregado;
                    
                    if ($faltante <= 0) {
                        $estado_text = "Completo";
                        $style_class = "cell-success";
                    } elseif ($entregado > 0) {
                        $estado_text = "Parcial";
                        $style_class = "cell-bold";
                    } else {
                        $estado_text = "Pendiente";
                        $style_class = "cell-danger";
                    }
                ?>
                    <tr>
                        <td class="cell-bold"><?= htmlspecialchars($c['descripcion']) ?></td>
                        <td class="cell-text"><?= htmlspecialchars($c['proveedor']) ?></td>
                        <td class="cell-text" style="text-align: center; mso-number-format:'\@';">OC: <?= htmlspecialchars($c['nro_compra']) ?></td>
                        <td class="cell-number" style="mso-number-format:'\#\,\#\#0\.00';"><?= number_format($comprado, 2, '.', '') ?> <?= htmlspecialchars($c['unidad']) ?></td>
                        <td class="cell-number" style="mso-number-format:'\#\,\#\#0\.00';"><?= number_format($entregado, 2, '.', '') ?> <?= htmlspecialchars($c['unidad']) ?></td>
                        <td class="cell-number" style="mso-number-format:'\#\,\#\#0\.00'; font-weight: bold; <?= $faltante > 0 ? 'color: #b91c1c;' : '' ?>"><?= number_format($faltante, 2, '.', '') ?> <?= htmlspecialchars($c['unidad']) ?></td>
                        <td class="<?= $style_class ?>" style="text-align: center;"><?= $estado_text ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
        
        <tr>
            <td colspan="7"></td>
        </tr>
        <tr>
            <td colspan="7"></td>
        </tr>
        
        <!-- SECCIÓN 2: DETALLE HISTÓRICO DE REMITOS -->
        <tr>
            <td colspan="7" class="section-title"><b>2. DETALLE DE DESCARGAS Y RECEPCIONES EN OBRA (HISTORIAL DE REMITOS)</b></td>
        </tr>
        <thead>
            <tr>
                <th class="table-header-sec" style="width: 100px;">Fecha Descarga</th>
                <th class="table-header-sec" style="width: 120px;">Nro Remito</th>
                <th class="table-header-sec" style="width: 150px;">Proveedor Emisor</th>
                <th class="table-header-sec" style="width: 220px;">Material Recibido</th>
                <th class="table-header-sec" style="width: 120px;">Cant. Entregada</th>
                <th class="table-header-sec" style="width: 150px;">Recibido en Obra por</th>
                <th class="table-header-sec" style="width: 100px;">Referencia OC</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($remitos_detalle)): ?>
                <tr>
                    <td colspan="7" class="cell-text" style="text-align: center; color: #64748b;">No se han registrado descargas de remitos para esta obra.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($remitos_detalle as $r): ?>
                    <tr>
                        <td class="cell-text" style="text-align: center;"><?= date('d/m/Y', strtotime($r['fecha_entrega'])) ?></td>
                        <td class="cell-bold" style="text-align: center; mso-number-format:'\@';"><?= $r['nro_remito'] ? htmlspecialchars($r['nro_remito']) : 'PENDIENTE DE PAPEL' ?></td>
                        <td class="cell-text"><?= htmlspecialchars($r['proveedor']) ?></td>
                        <td class="cell-text"><?= htmlspecialchars($r['descripcion']) ?></td>
                        <td class="cell-number" style="mso-number-format:'\#\,\#\#0\.00';"><?= number_format(floatval($r['cantidad_entregada']), 2, '.', '') ?> <?= htmlspecialchars($r['unidad']) ?></td>
                        <td class="cell-text"><?= htmlspecialchars($r['recibido_por'] ?: 'S/D') ?></td>
                        <td class="cell-text" style="text-align: center; mso-number-format:'\@';">OC: <?= htmlspecialchars($r['nro_compra']) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

</body>
</html>
