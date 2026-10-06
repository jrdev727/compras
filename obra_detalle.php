<?php
require_once 'db.php';

$obra_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($obra_id <= 0) {
    header("Location: obras.php");
    exit;
}

// Obtener detalles de la obra
$stmt = $pdo->prepare("SELECT * FROM obras WHERE id = ?");
$stmt->execute([$obra_id]);
$obra = $stmt->fetch();

if (!$obra) {
    die("<h3>La obra solicitada no existe.</h3><p><a href='obras.php'>Volver al listado</a></p>");
}

$message = '';
$msg_type = 'success';

// Lógica de acciones rápidas (ej. Resolver remito pendiente o Agregar Material)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Agregar material individualmente
    if (isset($_POST['action']) && $_POST['action'] === 'agregar_material') {
        $descripcion = trim($_POST['descripcion']);
        $cantidad = floatval($_POST['cantidad']);
        $unidad = trim($_POST['unidad']);
        
        if (!empty($descripcion) && $cantidad > 0) {
            try {
                $stmt = $pdo->prepare("INSERT INTO materiales_solicitados (obra_id, descripcion, cantidad, unidad) VALUES (?, ?, ?, ?)");
                $stmt->execute([$obra_id, $descripcion, $cantidad, $unidad]);
                $message = "Material añadido a la solicitud original de la obra.";
                $msg_type = "success";
            } catch (\PDOException $e) {
                $message = "Error al añadir material: " . $e->getMessage();
                $msg_type = "danger";
            }
        } else {
            $message = "Complete todos los campos del material.";
            $msg_type = "danger";
        }
    }
    
    // 2. Resolver remito físico pendiente
    if (isset($_POST['action']) && $_POST['action'] === 'resolver_remito') {
        $remito_id = intval($_POST['remito_id']);
        $nro_remito = trim($_POST['nro_remito']);
        
        if (!empty($nro_remito)) {
            try {
                $stmt = $pdo->prepare("UPDATE remitos SET nro_remito = ?, estado_remito = 'Recibido' WHERE id = ? AND obra_id = ?");
                $stmt->execute([$nro_remito, $remito_id, $obra_id]);
                $message = "El remito físico ha sido conciliado y registrado correctamente.";
                $msg_type = "success";
            } catch (\PDOException $e) {
                $message = "Error al actualizar el remito: " . $e->getMessage();
                $msg_type = "danger";
            }
        } else {
            $message = "Debe proveer un número de remito físico válido.";
            $msg_type = "danger";
        }
    }
}

// Consultas para las Pestañas (Tabs)
// Tab 1: Materiales Solicitados
$stmt = $pdo->prepare("SELECT * FROM materiales_solicitados WHERE obra_id = ? ORDER BY id ASC");
$stmt->execute([$obra_id]);
$materiales_solicitados = $stmt->fetchAll();

// Tab 2: Compras Adjudicadas (incluyendo items)
$stmt = $pdo->prepare("SELECT * FROM compras WHERE obra_id = ? ORDER BY fecha_compra DESC");
$stmt->execute([$obra_id]);
$compras = $stmt->fetchAll();

$compras_con_detalles = [];
foreach ($compras as $c) {
    $stmtItems = $pdo->prepare("SELECT * FROM compra_detalles WHERE compra_id = ?");
    $stmtItems->execute([$c['id']]);
    $c['items'] = $stmtItems->fetchAll();
    $compras_con_detalles[] = $c;
}

// Tab 3: Remitos / Entregas
$stmt = $pdo->prepare("
    SELECT r.*,
           (SELECT COUNT(*) FROM remito_detalles WHERE remito_id = r.id) AS cant_items
    FROM remitos r 
    WHERE r.obra_id = ? 
    ORDER BY r.fecha_entrega DESC
");
$stmt->execute([$obra_id]);
$remitos = $stmt->fetchAll();

$remitos_con_detalles = [];
foreach ($remitos as $r) {
    $stmtItems = $pdo->prepare("
        SELECT rd.*, cd.descripcion AS compra_desc, cd.unidad, c.proveedor, c.nro_compra
        FROM remito_detalles rd
        JOIN compra_detalles cd ON rd.compra_detalle_id = cd.id
        JOIN compras c ON cd.compra_id = c.id
        WHERE rd.remito_id = ?
    ");
    $stmtItems->execute([$r['id']]);
    $r['items'] = $stmtItems->fetchAll();
    $remitos_con_detalles[] = $r;
}

// Tab 4: Conciliación (Compras vs Entregas) - EL NÚCLEO DEL PROGRAMA
$stmt = $pdo->prepare("
    SELECT 
        cd.id AS compra_detalle_id,
        c.nro_compra,
        c.proveedor,
        cd.descripcion,
        cd.cantidad_comprada,
        cd.unidad,
        cd.precio_unitario,
        ms.descripcion AS material_original,
        ms.cantidad AS cantidad_solicitada,
        COALESCE((
            SELECT SUM(rd.cantidad_entregada)
            FROM remito_detalles rd
            JOIN remitos r ON rd.remito_id = r.id
            WHERE rd.compra_detalle_id = cd.id
        ), 0) AS cantidad_entregada
    FROM compra_detalles cd
    JOIN compras c ON cd.compra_id = c.id
    LEFT JOIN materiales_solicitados ms ON cd.material_solicitado_id = ms.id
    WHERE c.obra_id = ?
    ORDER BY c.proveedor ASC, cd.descripcion ASC
");
$stmt->execute([$obra_id]);
$conciliacion = $stmt->fetchAll();

$stmtMismatches = $pdo->prepare("
    SELECT c.id, c.nro_compra, c.proveedor,
           (SELECT COALESCE(SUM(cd.cantidad_comprada * cd.precio_unitario), 0) FROM compra_detalles cd WHERE cd.compra_id = c.id) AS total_oc,
           (SELECT COALESCE(SUM(f.monto), 0) FROM facturas f WHERE f.compra_id = c.id) AS total_facturado
    FROM compras c
    WHERE c.obra_id = ?
");
$stmtMismatches->execute([$obra_id]);
$compras_montos = $stmtMismatches->fetchAll();

$inconsistencias_facturas = [];
foreach ($compras_montos as $cm) {
    $total_oc = floatval($cm['total_oc']);
    $total_fac = floatval($cm['total_facturado']);
    
    if ($total_fac > 0 && abs($total_fac - $total_oc) > 0.01) {
        $inconsistencias_facturas[] = [
            'nro_compra' => $cm['nro_compra'],
            'proveedor' => $cm['proveedor'],
            'total_oc' => $total_oc,
            'total_facturado' => $total_fac
        ];
    }
}

require_once 'header.php';
?>

<!-- Mostrar Mensajes -->
<?php if (!empty($message)): ?>
    <div class="alert-banner <?= ($msg_type === 'danger') ? 'alert-banner-danger' : '' ?>">
        <div class="alert-banner-text">
            <h4>Aviso del Sistema</h4>
            <p><?= htmlspecialchars($message) ?></p>
        </div>
    </div>
<?php endif; ?>

<!-- Encabezado de la Obra -->
<div class="page-header">
    <div class="page-title">
        <span class="badge <?= ($obra['estado'] === 'Activa') ? 'badge-success' : 'badge-warning' ?>" style="margin-bottom: 0.5rem;"><?= $obra['estado'] ?></span>
        <h1><?= htmlspecialchars($obra['nombre']) ?></h1>
        <p>Expediente: <strong><?= htmlspecialchars($obra['expediente']) ?></strong> | <?= htmlspecialchars($obra['descripcion'] ?: 'Sin descripción adicional') ?></p>
    </div>
    <div style="display: flex; gap: 0.75rem; align-items: center;">
        <a href="exportar_excel.php?obra_id=<?= $obra_id ?>" class="btn btn-secondary" style="background-color: #107c41; color: white; border-color: #107c41; display: inline-flex; align-items: center;" title="Exportar a Excel">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 0.35rem;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Excel Obras Públicas
        </a>
        <a href="obras.php" class="btn btn-secondary" onclick="if(document.referrer && !document.referrer.includes(window.location.pathname)) { window.history.back(); return false; }">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
            Volver
        </a>
    </div>
</div>

<!-- Alerta de Inconsistencia en Facturas -->
<?php if (!empty($inconsistencias_facturas)): ?>
    <div class="alert-banner alert-banner-danger" style="margin-bottom: 1.5rem; display: flex; align-items: flex-start; gap: 1rem; border-left: 5px solid var(--danger-dark); background-color: #fef2f2; padding: 1.25rem; border-radius: var(--border-radius);">
        <div style="color: #b91c1c; display: flex; align-items: center; justify-content: center; margin-top: 0.15rem;">
            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        </div>
        <div style="flex-grow: 1;">
            <h4 style="margin: 0 0 0.4rem 0; color: #991b1b; font-size: 1.05rem; font-weight: bold;">⚠️ Inconsistencia Detectada en Montos de Facturas</h4>
            <p style="margin: 0; font-size: 0.9rem; color: #7f1d1d;">Se han registrado facturas cuyo monto acumulado no coincide con el total adjudicado en su Orden de Compra. Por favor, revise y concilie los siguientes contratos:</p>
            <ul style="margin: 0.6rem 0 0 1.25rem; padding: 0; font-size: 0.85rem; color: #7f1d1d; line-height: 1.55;">
                <?php foreach ($inconsistencias_facturas as $inc): ?>
                    <li>
                        <strong>Orden de Compra: <?= htmlspecialchars($inc['nro_compra']) ?></strong> (Proveedor: <em><?= htmlspecialchars($inc['proveedor']) ?></em>) 
                        <br>&bull; Total Adjudicado en OC: <strong>$<?= number_format($inc['total_oc'], 2, ',', '.') ?></strong> 
                        | Total Facturado: <strong style="color: #b91c1c; text-decoration: underline;">$<?= number_format($inc['total_facturado'], 2, ',', '.') ?></strong>
                        | Diferencia: <strong>$<?= number_format($inc['total_facturado'] - $inc['total_oc'], 2, ',', '.') ?></strong>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>

<!-- Tarjetas Rápidas de la Obra -->
<div class="stats-grid" style="margin-bottom: 1.5rem;">
    <div class="stat-card" style="padding: 1rem 1.25rem;">
        <div class="stat-info">
            <h3>Materiales Solicitados</h3>
            <span class="stat-value" style="font-size: 1.5rem;"><?= count($materiales_solicitados) ?> ítems</span>
        </div>
        <div class="stat-icon info" style="width: 38px; height: 38px;"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg></div>
    </div>
    
    <div class="stat-card" style="padding: 1rem 1.25rem;">
        <div class="stat-info">
            <h3>Órdenes de Compra</h3>
            <span class="stat-value" style="font-size: 1.5rem;"><?= count($compras) ?> registradas</span>
        </div>
        <div class="stat-icon primary" style="width: 38px; height: 38px;"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/></svg></div>
    </div>

    <div class="stat-card" style="padding: 1rem 1.25rem;">
        <div class="stat-info">
            <h3>Remitos de Obra</h3>
            <span class="stat-value" style="font-size: 1.5rem;"><?= count($remitos) ?> entregas</span>
        </div>
        <div class="stat-icon success" style="width: 38px; height: 38px;"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 12 2 2 4-4"/><rect width="20" height="14" x="2" y="6" rx="2"/></svg></div>
    </div>
    
    <?php
    $faltan_remitos_obra = 0;
    foreach($remitos as $r) {
        if ($r['estado_remito'] === 'Pendiente de Remito Físico') $faltan_remitos_obra++;
    }
    ?>
    <div class="stat-card" style="padding: 1rem 1.25rem; <?= ($faltan_remitos_obra > 0) ? 'border-color: var(--danger-light); background-color: var(--danger-light);' : '' ?>">
        <div class="stat-info">
            <h3>Papeles Faltantes</h3>
            <span class="stat-value" style="font-size: 1.5rem; color: <?= ($faltan_remitos_obra > 0) ? 'var(--danger-dark)' : 'var(--success-dark)' ?>;"><?= $faltan_remitos_obra ?> remitos</span>
        </div>
        <div class="stat-icon danger" style="width: 38px; height: 38px;"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/></svg></div>
    </div>
</div>

<!-- Navegación de Pestañas -->
<div class="tabs-navigation">
    <button class="tab-btn active" data-tab="conciliacion">1. Conciliación / Stock de Obra</button>
    <button class="tab-btn" data-tab="compras">2. Compras y Adjudicaciones</button>
    <button class="tab-btn" data-tab="remitos">3. Remitos y Entregas</button>
    <button class="tab-btn" data-tab="materiales">4. Solicitud Original</button>
</div>

<!-- ==================== TAB: CONCILIACIÓN ==================== -->
<div class="tab-panel active" id="conciliacion">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Planilla de Conciliación de Materiales</h3>
            <span class="badge badge-info">Conciliación Activa</span>
        </div>
        <div class="card-body">
            <p style="font-size: 0.9rem; color: var(--text-secondary); margin-bottom: 1.5rem;">
                Control en tiempo real de lo <strong>Comprado</strong> frente a lo <strong>Entregado en Obra</strong> (a través de Remitos).
            </p>
            
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Material Comprado</th>
                            <th>Proveedor / OC</th>
                            <th class="text-right">Comprado</th>
                            <th class="text-right">Entregado</th>
                            <th class="text-right">Pendiente</th>
                            <th style="width: 25%;">Avance</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($conciliacion)): ?>
                            <tr>
                                <td colspan="7" class="text-center" style="padding: 3rem; color: var(--text-secondary);">
                                    No hay compras registradas para esta obra. No se puede calcular conciliación.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($conciliacion as $item): 
                                $comprado = floatval($item['cantidad_comprada']);
                                $entregado = floatval($item['cantidad_entregada']);
                                $pendiente = $comprado - $entregado;
                                $porcentaje = ($comprado > 0) ? round(($entregado / $comprado) * 100) : 0;
                                
                                // Determinar estado y clase de color
                                if ($entregado == 0) {
                                    $estado_lbl = 'Sin Entregar';
                                    $badge = 'badge-secondary';
                                    $bar = 'warning';
                                } elseif ($pendiente > 0) {
                                    $estado_lbl = 'Parcial';
                                    $badge = 'badge-warning';
                                    $bar = '';
                                } elseif ($pendiente == 0) {
                                    $estado_lbl = 'Completo';
                                    $badge = 'badge-success';
                                    $bar = 'success';
                                } else {
                                    $estado_lbl = 'Excedido';
                                    $badge = 'badge-danger';
                                    $bar = 'danger';
                                }
                            ?>
                                <tr>
                                    <td>
                                        <div class="text-bold" style="color: var(--sidebar-bg);"><?= htmlspecialchars($item['descripcion']) ?></div>
                                        <?php if ($item['material_original']): ?>
                                            <div style="font-size: 0.75rem; color: var(--text-secondary);">Vínculo original: <?= htmlspecialchars($item['material_original']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="font-weight: 500;"><?= htmlspecialchars($item['proveedor']) ?></div>
                                        <div style="font-size: 0.8rem; color: var(--text-secondary);"><?= htmlspecialchars($item['nro_compra']) ?></div>
                                    </td>
                                    <td class="text-right text-bold"><?= number_format($comprado, 2, ',', '.') ?> <span style="font-size: 0.8rem; font-weight:normal;"><?= htmlspecialchars($item['unidad']) ?></span></td>
                                    <td class="text-right text-bold" style="color: var(--primary);"><?= number_format($entregado, 2, ',', '.') ?> <span style="font-size: 0.8rem; font-weight:normal;"><?= htmlspecialchars($item['unidad']) ?></span></td>
                                    <td class="text-right text-bold" style="color: <?= ($pendiente > 0) ? 'var(--warning-dark)' : 'var(--success-dark)' ?>;">
                                        <?= number_format($pendiente, 2, ',', '.') ?>
                                    </td>
                                    <td>
                                        <div class="progress-container">
                                            <div class="progress-bar-wrapper">
                                                <div class="progress-bar-fill <?= $bar ?>" style="width: <?= min(max($porcentaje, 0), 100) ?>%"></div>
                                            </div>
                                            <span class="progress-text"><?= $porcentaje ?>%</span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge <?= $badge ?>"><?= $estado_lbl ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ==================== TAB: COMPRAS ==================== -->
<div class="tab-panel" id="compras">
    <div class="card">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
            <h3 class="card-title">Orden de Compras y Adjudicaciones (Paso 2)</h3>
            <a href="compras.php?action=nuevo&obra_id=<?= $obra_id ?>" class="btn btn-primary btn-sm">
                + Cargar Orden de Compra
            </a>
        </div>
        <div class="card-body">
            <?php if (empty($compras_con_detalles)): ?>
                <div class="text-center" style="padding: 3rem 0; color: var(--text-secondary);">
                    No hay compras cargadas para esta obra. Use el botón superior para cargar una.
                </div>
            <?php else: ?>
                <?php foreach ($compras_con_detalles as $compra): ?>
                    <div style="border: 1px solid var(--gray-200); border-radius: var(--border-radius); padding: 1.25rem; margin-bottom: 1.5rem; background-color: #fafafa;">
                        <div class="d-flex justify-between align-center" style="border-bottom: 1px solid var(--gray-200); padding-bottom: 0.75rem; margin-bottom: 1rem;">
                            <div>
                                <span class="badge badge-primary" style="font-size: 0.85rem; padding: 0.3rem 0.75rem;">OC: <?= htmlspecialchars($compra['nro_compra']) ?></span>
                                <strong style="font-size: 1.1rem; margin-left: 0.5rem; color: var(--sidebar-bg);"><?= htmlspecialchars($compra['proveedor']) ?></strong>
                            </div>
                            <div style="font-size: 0.9rem; color: var(--text-secondary);">
                                Fecha: <?= date('d/m/Y', strtotime($compra['fecha_compra'])) ?>
                            </div>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table" style="background-color: transparent;">
                                <thead>
                                    <tr>
                                        <th>Material / Descripción</th>
                                        <th class="text-right">Cantidad Comprada</th>
                                        <th class="text-right">Precio Unitario</th>
                                        <th class="text-right">Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $total_compra = 0;
                                    foreach ($compra['items'] as $item): 
                                        $subtotal = $item['cantidad_comprada'] * $item['precio_unitario'];
                                        $total_compra += $subtotal;
                                    ?>
                                        <tr>
                                            <td><?= htmlspecialchars($item['descripcion']) ?></td>
                                            <td class="text-right text-bold"><?= number_format($item['cantidad_comprada'], 2, ',', '.') ?> <?= htmlspecialchars($item['unidad']) ?></td>
                                            <td class="text-right">$<?= number_format($item['precio_unitario'], 2, ',', '.') ?></td>
                                            <td class="text-right text-bold">$<?= number_format($subtotal, 2, ',', '.') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr style="background-color: #f1f5f9;">
                                        <td colspan="3" class="text-right text-bold">TOTAL DE LA ORDEN DE COMPRA:</td>
                                        <td class="text-right text-bold" style="color: var(--primary); font-size: 1.05rem;">$<?= number_format($total_compra, 2, ',', '.') ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ==================== TAB: REMITOS ==================== -->
<div class="tab-panel" id="remitos">
    <div class="card">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
            <h3 class="card-title">Remitos de Entrada y Descarga (Paso 4 - Crítico)</h3>
            <a href="remitos.php?action=nuevo&obra_id=<?= $obra_id ?>" class="btn btn-primary btn-sm">
                + Registrar Entrega (Remito)
            </a>
        </div>
        <div class="card-body">
            <p style="font-size: 0.9rem; color: var(--text-secondary); margin-bottom: 1.5rem;">
                Listado de descargas realizadas en el predio de la obra. Controle la documentación física respaldatoria.
            </p>
            
            <?php if (empty($remitos_con_detalles)): ?>
                <div class="text-center" style="padding: 3rem 0; color: var(--text-secondary);">
                    No hay remitos ni entregas registradas. Utilice el botón superior para cargar la primera descarga.
                </div>
            <?php else: ?>
                <?php foreach ($remitos_con_detalles as $remito): 
                    $es_faltante = ($remito['estado_remito'] === 'Pendiente de Remito Físico');
                    $border_col = $es_faltante ? 'border-color: var(--danger-light);' : 'border-color: var(--gray-200);';
                    $header_bg = $es_faltante ? 'background-color: var(--danger-light);' : 'background-color: #f8fafc;';
                ?>
                    <div style="border: 1px solid var(--gray-200); border-radius: var(--border-radius); <?= $border_col ?> margin-bottom: 1.5rem; overflow: hidden; box-shadow: var(--shadow);">
                        
                        <!-- Header del Remito -->
                        <div class="d-flex justify-between align-center" style="<?= $header_bg ?> padding: 0.75rem 1.25rem; border-bottom: 1px solid var(--gray-200);">
                            <div>
                                <?php if ($es_faltante): ?>
                                    <span class="badge badge-danger">PENDIENTE REMITO FISICO</span>
                                    <strong style="color: var(--danger-dark); margin-left: 0.5rem;">Carga sin documento físico</strong>
                                <?php else: ?>
                                    <span class="badge badge-success">Recibido</span>
                                    <strong style="color: var(--sidebar-bg); margin-left: 0.5rem;">Remito N°: <?= htmlspecialchars($remito['nro_remito']) ?></strong>
                                <?php endif; ?>
                            </div>
                            <div style="font-size: 0.85rem; color: var(--text-secondary);">
                                Fecha Entrega: <strong><?= date('d/m/Y', strtotime($remito['fecha_entrega'])) ?></strong>
                            </div>
                        </div>
                        
                        <!-- Body del Remito -->
                        <div style="padding: 1rem 1.25rem; background-color: #fff;">
                            <div class="form-grid" style="grid-template-columns: 2fr 1fr; margin-bottom: 1rem; font-size: 0.9rem;">
                                <div>
                                    <strong>Recibió en Obra:</strong> <?= htmlspecialchars($remito['recibido_por'] ?: 'No registrado') ?><br>
                                    <strong>Observaciones:</strong> <span style="font-style: italic; color: var(--text-secondary);"><?= htmlspecialchars($remito['observaciones'] ?: 'Sin observaciones') ?></span>
                                </div>
                                <div class="text-right">
                                    <?php if ($es_faltante): ?>
                                        <!-- Formulario rápido para resolver remito pendiente -->
                                        <form action="obra_detalle.php?id=<?= $obra_id ?>" method="POST" class="d-flex gap-2" style="justify-content: flex-end; align-items: flex-end;">
                                            <input type="hidden" name="action" value="resolver_remito">
                                            <input type="hidden" name="remito_id" value="<?= $remito['id'] ?>">
                                            <div class="form-group" style="text-align: left;">
                                                <label class="form-label" style="font-size: 0.75rem; color: var(--danger-dark);">Nro Remito Real *</label>
                                                <input type="text" name="nro_remito" class="form-control" style="padding: 0.35rem 0.5rem; font-size: 0.8rem; width: 150px; border-color: var(--danger);" placeholder="Ej. 0001-0002345" required>
                                            </div>
                                            <button type="submit" class="btn btn-primary btn-sm" style="padding: 0.35rem 0.75rem; font-size: 0.8rem; height: 32px;">
                                                Conciliar Papel
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Items entregados -->
                            <div class="table-responsive" style="border-top: 1px dashed var(--gray-200); padding-top: 0.5rem;">
                                <table class="table" style="font-size: 0.85rem;">
                                    <thead>
                                        <tr>
                                            <th>Material Entregado</th>
                                            <th>Proveedor Emisor</th>
                                            <th>Orden de Compra Asociada</th>
                                            <th class="text-right">Cantidad Entregada</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($remito['items'] as $item): ?>
                                            <tr>
                                                <td class="text-bold" style="color: var(--sidebar-bg);"><?= htmlspecialchars($item['compra_desc']) ?></td>
                                                <td><?= htmlspecialchars($item['proveedor']) ?></td>
                                                <td><?= htmlspecialchars($item['nro_compra']) ?></td>
                                                <td class="text-right text-bold" style="color: var(--primary); font-size: 0.95rem;"><?= number_format($item['cantidad_entregada'], 2, ',', '.') ?> <?= htmlspecialchars($item['unidad']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ==================== TAB: MATERIALES ORIGINALES ==================== -->
<div class="tab-panel" id="materiales">
    <div class="card">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
            <h3 class="card-title">Memoria de Materiales Originales (Solicitud de Obra)</h3>
            <button type="button" onclick="document.getElementById('form-rapido-material').style.display='block';" class="btn btn-secondary btn-sm">
                + Añadir Material a Solicitud
            </button>
        </div>
        <div class="card-body">
            <!-- Formulario Rápido Oculto -->
            <div id="form-rapido-material" style="display: none; border: 1px solid var(--gray-300); border-radius: var(--border-radius-sm); padding: 1.25rem; margin-bottom: 1.5rem; background-color: #fafafa;">
                <h4 style="margin-bottom: 0.75rem; font-size: 0.95rem;">Cargar Material Adicional a la Solicitud</h4>
                <form action="obra_detalle.php?id=<?= $obra_id ?>" method="POST">
                    <input type="hidden" name="action" value="agregar_material">
                    <div class="form-grid">
                        <div class="form-group" style="grid-column: span 2;">
                            <label class="form-label">Descripción Material</label>
                            <input type="text" name="descripcion" class="form-control" placeholder="Ej. Piedra Partida" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Cantidad Requerida</label>
                            <input type="number" step="0.01" name="cantidad" class="form-control" placeholder="0.00" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Unidad</label>
                            <select name="unidad" class="form-control" required>
                                <option value="Bolsas">Bolsas</option>
                                <option value="Unidades">Unidades</option>
                                <option value="Metros Cúbicos">Metros Cúbicos</option>
                                <option value="Varillas">Varillas</option>
                                <option value="Mallas">Mallas</option>
                                <option value="Metros">Metros</option>
                                <option value="Kilogramos">Kilogramos</option>
                            </select>
                        </div>
                    </div>
                    <div class="d-flex" style="justify-content: flex-end; gap: 0.75rem; margin-top: 1rem;">
                        <button type="button" onclick="document.getElementById('form-rapido-material').style.display='none';" class="btn btn-secondary btn-sm">Cancelar</button>
                        <button type="submit" class="btn btn-primary btn-sm">Añadir Material</button>
                    </div>
                </form>
            </div>
            
            <!-- Listado de Materiales -->
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Nro</th>
                            <th>Descripción del Insumo / Material</th>
                            <th class="text-right">Cantidad Solicitada</th>
                            <th>Unidad de Medida</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($materiales_solicitados)): ?>
                            <tr>
                                <td colspan="4" class="text-center" style="padding: 2rem; color: var(--text-secondary);">
                                    No hay materiales registrados en la solicitud original.
                                </td>
                            </tr>
                        <?php else: 
                            $idx = 1;
                            foreach ($materiales_solicitados as $mat): ?>
                                <tr>
                                    <td><?= $idx++ ?></td>
                                    <td class="text-bold" style="color: var(--sidebar-bg);"><?= htmlspecialchars($mat['descripcion']) ?></td>
                                    <td class="text-right text-bold" style="font-size: 1.05rem;"><?= number_format($mat['cantidad'], 2, ',', '.') ?></td>
                                    <td><span class="badge badge-secondary"><?= htmlspecialchars($mat['unidad']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
require_once 'footer.php';
?>
