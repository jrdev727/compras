<?php
require_once 'bootstrap.php';

$message = '';
$msg_type = 'success';

// Procesar resolución rápida en el Dashboard directamente
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resolver_rapido') {
    $id = intval($_POST['id']);
    $nro_remito = trim($_POST['nro_remito']);
    
    if ($id > 0 && !empty($nro_remito)) {
        try {
            $stmt = $pdo->prepare("UPDATE remitos SET nro_remito = ?, estado_remito = 'Recibido' WHERE id = ?");
            $stmt->execute([$nro_remito, $id]);
            $message = "El remito físico ha sido conciliado y registrado correctamente.";
            $msg_type = "success";
        } catch (\PDOException $e) {
            $message = error_generico($e, "Error al conciliar el remito");
            $msg_type = "danger";
        }
    } else {
        $message = "Debe ingresar el número de remito correspondiente.";
        $msg_type = "danger";
    }
}

// 1. Obtener estadísticas para las tarjetas KPI
// Obras Activas
$stmt = $pdo->query("SELECT COUNT(*) FROM obras WHERE estado = 'Activa'");
$obras_activas = $stmt->fetchColumn();

// Remitos con copia física faltante (Papel administrativo pendiente)
$stmt = $pdo->query("SELECT COUNT(*) FROM remitos WHERE estado_remito = 'Pendiente de Remito Físico'");
$remitos_pendientes = $stmt->fetchColumn();

// Cantidad de materiales con entregas pendientes (Logístico)
$stmt = $pdo->query("
    SELECT COUNT(*) FROM (
        SELECT cd.id
        FROM compra_detalles cd
        JOIN compras c ON cd.compra_id = c.id
        JOIN obras o ON c.obra_id = o.id
        WHERE o.estado = 'Activa'
          AND (SELECT COALESCE(SUM(rd.cantidad_entregada), 0) FROM remito_detalles rd WHERE rd.compra_detalle_id = cd.id) < cd.cantidad_comprada
    ) AS t
");
$materiales_pendientes_count = $stmt->fetchColumn();

// Monto total facturado
$stmt = $pdo->query("SELECT SUM(monto) FROM facturas WHERE estado = 'Aprobada' OR estado = 'Pagada'");
$total_facturado = $stmt->fetchColumn() ?: 0.00;


// 2. OBTENER LISTAS DE CONTROL
// Pestaña 1: Remitos Físicos Faltantes (Papel)
$remitos_alertas_query = $pdo->query("
    SELECT r.id, r.nro_remito, r.fecha_entrega, r.recibido_por, r.observaciones, o.nombre AS obra_nombre, o.id AS obra_id, o.expediente AS obra_expediente,
           (SELECT GROUP_CONCAT(CONCAT(rd.cantidad_entregada, ' ', cd.unidad, ' de ', cd.descripcion) SEPARATOR ', ')
            FROM remito_detalles rd
            JOIN compra_detalles cd ON rd.compra_detalle_id = cd.id
            WHERE rd.remito_id = r.id) AS descripcion_materiales,
           (SELECT c.proveedor 
            FROM remito_detalles rd 
            JOIN compra_detalles cd ON rd.compra_detalle_id = cd.id 
            JOIN compras c ON cd.compra_id = c.id 
            WHERE rd.remito_id = r.id LIMIT 1) AS proveedor
    FROM remitos r
    JOIN obras o ON r.obra_id = o.id
    WHERE r.estado_remito = 'Pendiente de Remito Físico'
    ORDER BY r.fecha_entrega ASC
");
$remitos_alertas = $remitos_alertas_query->fetchAll();

// Pestaña 2: Materiales Pendientes de Entrega (Logística de compras)
$materiales_pendientes_query = $pdo->query("
    SELECT * FROM (
        SELECT cd.id AS compra_detalle_id, cd.descripcion AS material_nombre, cd.cantidad_comprada, cd.unidad,
               c.id AS compra_id, c.nro_compra, c.proveedor,
               o.id AS obra_id, o.nombre AS obra_nombre, o.expediente AS obra_expediente,
               (SELECT COALESCE(SUM(rd.cantidad_entregada), 0)
                FROM remito_detalles rd
                WHERE rd.compra_detalle_id = cd.id) AS cantidad_entregada
        FROM compra_detalles cd
        JOIN compras c ON cd.compra_id = c.id
        JOIN obras o ON c.obra_id = o.id
        WHERE o.estado = 'Activa'
    ) AS t
    WHERE t.cantidad_entregada < t.cantidad_comprada
    ORDER BY t.obra_nombre ASC, t.proveedor ASC
");
$materiales_pendientes = $materiales_pendientes_query->fetchAll();

// Pestaña 3: Obtener avance general de stock por obra activa
$obras_query = $pdo->query("
    SELECT o.id, o.nombre, o.expediente, o.estado,
           (SELECT COALESCE(SUM(cd.cantidad_comprada), 0) 
            FROM compras c 
            JOIN compra_detalles cd ON c.id = cd.compra_id 
            WHERE c.obra_id = o.id) AS total_comprado,
           (SELECT COALESCE(SUM(rd.cantidad_entregada), 0) 
            FROM remitos r 
            JOIN remito_detalles rd ON r.id = rd.remito_id 
            JOIN compra_detalles cd ON rd.compra_detalle_id = cd.id
            WHERE r.obra_id = o.id) AS total_entregado
    FROM obras o
    WHERE o.estado = 'Activa'
    ORDER BY o.fecha_creacion DESC
");
$obras = $obras_query->fetchAll();

require_once 'header.php';
?>

<!-- Mostrar Mensajes de Operaciones -->
<?php if (!empty($message)): ?>
    <div class="alert-banner <?= ($msg_type === 'danger') ? 'alert-banner-danger' : '' ?>">
        <div class="alert-banner-text">
            <h4>Notificación del Sistema</h4>
            <p><?= htmlspecialchars($message) ?></p>
        </div>
    </div>
<?php endif; ?>

<!-- Cabecera de Página -->
<div class="page-header">
    <div class="page-title">
        <h1>Centro de Control y Remitos</h1>
        <p>Seguimiento de documentación física y control de entregas de materiales pendientes</p>
    </div>
    <div>
        <a href="remitos.php?action=nuevo" class="btn btn-primary">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
            Registrar Entrega (Remito)
        </a>
    </div>
</div>

<!-- Tarjetas de Estadísticas (KPIs) -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-info">
            <h3>Obras Activas</h3>
            <span class="stat-value"><?= $obras_activas ?></span>
        </div>
        <div class="stat-icon info">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 20h20"/><path d="M5 17V7a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v10"/><path d="M9 9h6"/><path d="M9 13h6"/></svg>
        </div>
    </div>
    
    <div class="stat-card" style="border-left: 4px solid var(--danger);">
        <div class="stat-info">
            <h3>Remitos Físicos Faltantes</h3>
            <span class="stat-value" style="color: var(--danger-dark);"><?= $remitos_pendientes ?></span>
        </div>
        <div class="stat-icon danger">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
        </div>
    </div>
    
    <div class="stat-card" style="border-left: 4px solid var(--warning);">
        <div class="stat-info">
            <h3>Materiales por Entregar</h3>
            <span class="stat-value" style="color: var(--warning-dark);"><?= $materiales_pendientes_count ?></span>
        </div>
        <div class="stat-icon warning">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-info">
            <h3>Monto Facturado</h3>
            <span class="stat-value">$<?= number_format($total_facturado, 2, ',', '.') ?></span>
        </div>
        <div class="stat-icon success">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        </div>
    </div>
</div>

<!-- Filtro de Búsqueda Rápida unificado para el Dashboard -->
<div class="filter-bar" style="margin-bottom: 1.5rem;">
    <div class="form-group" style="flex-grow: 1;">
        <label class="form-label">Filtrar información en pantalla</label>
        <input type="text" class="form-control table-search" data-table="tabla-dashboard" placeholder="Escriba proveedor, material u obra para buscar en las tablas...">
    </div>
</div>

<!-- Panel de Control Principal de Remitos y Entregas -->
<div class="card">
    <div class="card-header" style="background-color: #fafafa;">
        <div class="tabs-navigation" style="margin-bottom: 0; width: 100%; border-bottom: none;">
            <button class="tab-btn active" data-tab="tab-remitos-fisicos" style="padding: 0.5rem 1rem;">
                Remitos Físicos Faltantes (<?= count($remitos_alertas) ?>)
            </button>
            <button class="tab-btn" data-tab="tab-entregas-materiales" style="padding: 0.5rem 1rem;">
                Materiales Pendientes de Entrega (<?= count($materiales_pendientes) ?>)
            </button>
            <button class="tab-btn" data-tab="tab-estado-obras" style="padding: 0.5rem 1rem;">
                Estado General de Obras
            </button>
        </div>
    </div>
    
    <div class="card-body" style="padding: 1.5rem 0 0 0;">
        
        <!-- PESTAÑA 1: REMITOS FÍSICOS FALTANTES -->
        <div id="tab-remitos-fisicos" class="tab-panel active">
            <div style="padding: 0 1.5rem 1rem 1.5rem;">
                <p style="font-size: 0.9rem; color: var(--text-secondary);">
                    Lista de materiales que ya se descargaron físicamente en las obras pero cuyos capataces o choferes aún no entregaron el papel físico firmado a la administración para autorizar pagos de facturas.
                </p>
            </div>
            <div class="table-responsive">
                <table class="table tabla-dashboard">
                    <thead>
                        <tr>
                            <th>Obra / Expediente</th>
                            <th>Proveedor</th>
                            <th>Fecha Descarga</th>
                            <th>Recibido Por</th>
                            <th style="width: 35%;">Materiales Recibidos</th>
                            <th class="text-center" style="width: 260px; background-color: var(--primary-light); color: var(--primary-dark);">Conciliación Rápida</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($remitos_alertas)): ?>
                            <tr>
                                <td colspan="6" class="text-center" style="padding: 3rem; color: var(--success); font-weight: 500;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 0.5rem; display: block; margin-left: auto; margin-right: auto;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                    ¡Excelente! No hay documentos de remitos pendientes de entrega.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($remitos_alertas as $r): 
                                $fecha_entrega = new DateTime($r['fecha_entrega']);
                                $hoy = new DateTime();
                                $dias_retraso = $hoy->diff($fecha_entrega)->days;
                                $color_tiempo = ($dias_retraso > 10) ? 'color: var(--danger-dark); font-weight: 700;' : 'color: var(--warning-dark);';
                            ?>
                                <tr>
                                    <td>
                                        <div class="text-bold" style="color: var(--sidebar-bg);"><?= htmlspecialchars($r['obra_nombre']) ?></div>
                                        <div style="font-size: 0.8rem; color: var(--text-secondary);">Exp: <?= htmlspecialchars($r['obra_expediente']) ?></div>
                                    </td>
                                    <td class="text-bold"><?= htmlspecialchars($r['proveedor'] ?: 'S/D') ?></td>
                                    <td>
                                        <div><?= date('d/m/Y', strtotime($r['fecha_entrega'])) ?></div>
                                        <div style="font-size: 0.75rem; <?= $color_tiempo ?>">Hace <?= $dias_retraso ?> días</div>
                                    </td>
                                    <td><?= htmlspecialchars($r['recibido_por'] ?: 'S/D') ?></td>
                                    <td style="font-size: 0.85rem; color: var(--text-secondary); line-height: 1.3;">
                                        <?= htmlspecialchars($r['descripcion_materiales']) ?>
                                    </td>
                                    <td style="background-color: rgba(224, 231, 255, 0.2); vertical-align: middle;">
                                        <form action="index.php" method="POST" class="d-flex gap-2 justify-center" style="margin: 0; align-items: center;">
        <?= csrf_campo() ?>
                                            <input type="hidden" name="action" value="resolver_rapido">
                                            <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                            <input type="text" name="nro_remito" class="form-control" style="padding: 0.35rem 0.6rem; font-size: 0.8rem; width: 140px; border-radius: var(--border-radius-sm); border-color: var(--primary-light);" placeholder="Nro Remito Físico" required>
                                            <button type="submit" class="btn btn-primary btn-sm" style="padding: 0.35rem 0.85rem; font-size: 0.8rem; border-radius: var(--border-radius-sm);">
                                                Guardar
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- PESTAÑA 2: MATERIALES PENDIENTES DE ENTREGA -->
        <div id="tab-entregas-materiales" class="tab-panel">
            <div style="padding: 0 1.5rem 1rem 1.5rem;">
                <p style="font-size: 0.9rem; color: var(--text-secondary);">
                    Muestra el backlog logístico: qué materiales han sido comprados mediante adjudicaciones/ordenes de compra pero aún no han sido despachados o entregados en su totalidad por los proveedores.
                </p>
            </div>
            <div class="table-responsive">
                <table class="table tabla-dashboard">
                    <thead>
                        <tr>
                            <th>Obra / Expediente</th>
                            <th>Proveedor (OC)</th>
                            <th>Material / Descripción</th>
                            <th class="text-right">Comprado</th>
                            <th class="text-right">Entregado</th>
                            <th style="width: 250px;">Progreso de Entrega</th>
                            <th class="text-right" style="color: var(--danger-dark);">Faltante</th>
                            <th class="text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($materiales_pendientes)): ?>
                            <tr>
                                <td colspan="8" class="text-center" style="padding: 3rem; color: var(--success); font-weight: 500;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 0.5rem; display: block; margin-left: auto; margin-right: auto;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                    ¡Al día! Todos los materiales comprados han sido entregados en su totalidad.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($materiales_pendientes as $item): 
                                $compra = floatval($item['cantidad_comprada']);
                                $entregado = floatval($item['cantidad_entrega']);
                                $faltante = $compra - $entregado;
                                $porcentaje = ($compra > 0) ? round(($entregado / $compra) * 100) : 0;
                                
                                $bar_class = '';
                                if ($porcentaje >= 80) $bar_class = 'success';
                                elseif ($porcentaje > 30) $bar_class = '';
                                else $bar_class = 'warning';
                            ?>
                                <tr>
                                    <td>
                                        <div class="text-bold" style="color: var(--sidebar-bg);"><?= htmlspecialchars($item['obra_nombre']) ?></div>
                                        <div style="font-size: 0.8rem; color: var(--text-secondary);">Exp: <?= htmlspecialchars($item['obra_expediente']) ?></div>
                                    </td>
                                    <td>
                                        <div class="text-bold"><?= htmlspecialchars($item['proveedor']) ?></div>
                                        <div style="font-size: 0.75rem; color: var(--text-secondary);">OC: <?= htmlspecialchars($item['nro_compra']) ?></div>
                                    </td>
                                    <td class="text-bold" style="color: var(--primary);"><?= htmlspecialchars($item['material_nombre']) ?></td>
                                    <td class="text-right"><?= number_format($compra, 2, ',', '.') ?> <?= htmlspecialchars($item['unidad']) ?></td>
                                    <td class="text-right"><?= number_format($entregado, 2, ',', '.') ?> <?= htmlspecialchars($item['unidad']) ?></td>
                                    <td>
                                        <div class="progress-container">
                                            <div class="progress-bar-wrapper">
                                                <div class="progress-bar-fill <?= $bar_class ?>" style="width: <?= min($porcentaje, 100) ?>%"></div>
                                            </div>
                                            <span class="progress-text"><?= $porcentaje ?>%</span>
                                        </div>
                                    </td>
                                    <td class="text-right text-bold" style="color: var(--danger-dark); font-size: 1rem;">
                                        <?= number_format($faltante, 2, ',', '.') ?> <?= htmlspecialchars($item['unidad']) ?>
                                    </td>
                                    <td class="text-center">
                                        <a href="remitos.php?action=nuevo&obra_id=<?= $item['obra_id'] ?>" class="btn btn-secondary btn-sm" title="Registrar Entrega para esta obra">
                                            Recibir
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- PESTAÑA 3: ESTADO GENERAL DE OBRAS -->
        <div id="tab-estado-obras" class="tab-panel">
            <div style="padding: 0 1.5rem 1rem 1.5rem;">
                <p style="font-size: 0.9rem; color: var(--text-secondary);">
                    Porcentaje general de recepción de materiales comprados para cada obra municipal activa.
                </p>
            </div>
            <div class="table-responsive">
                <table class="table tabla-dashboard">
                    <thead>
                        <tr>
                            <th>Obra / Expediente</th>
                            <th class="text-right">Total Comprado (Items)</th>
                            <th class="text-right">Total Recibido (Items)</th>
                            <th style="width: 40%;">Progreso de Entrega General</th>
                            <th class="text-center">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($obras)): ?>
                            <tr>
                                <td colspan="5" class="text-center" style="padding: 2rem; color: var(--text-secondary);">
                                    No hay obras activas registradas en este momento.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($obras as $obra): 
                                $compra = floatval($obra['total_comprado']);
                                $entregado = floatval($obra['total_entregado']);
                                $porcentaje = ($compra > 0) ? round(($entregado / $compra) * 100) : 0;
                                
                                $bar_class = '';
                                if ($porcentaje >= 95) $bar_class = 'success';
                                elseif ($porcentaje > 40) $bar_class = '';
                                else $bar_class = 'warning';
                            ?>
                                <tr>
                                    <td>
                                        <div class="text-bold" style="color: var(--sidebar-bg);"><?= htmlspecialchars($obra['nombre']) ?></div>
                                        <div style="font-size: 0.85rem; color: var(--text-secondary);"><?= htmlspecialchars($obra['expediente']) ?></div>
                                    </td>
                                    <td class="text-right"><?= number_format($compra, 2, ',', '.') ?> u.</td>
                                    <td class="text-right"><?= number_format($entregado, 2, ',', '.') ?> u.</td>
                                    <td>
                                        <div class="progress-container">
                                            <div class="progress-bar-wrapper">
                                                <div class="progress-bar-fill <?= $bar_class ?>" style="width: <?= min($porcentaje, 100) ?>%"></div>
                                            </div>
                                            <span class="progress-text"><?= $porcentaje ?>%</span>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <a href="obra_detalle.php?id=<?= $obra['id'] ?>" class="btn btn-secondary btn-sm">
                                            Gestionar Obra
                                        </a>
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

<?php
require_once 'footer.php';
?>
