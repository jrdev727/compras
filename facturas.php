<?php
require_once 'bootstrap.php';

$action = isset($_GET['action']) ? $_GET['action'] : 'listar';
$message = '';
$msg_type = 'success';

// Procesar Formulario de Alta de Factura
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'guardar_nuevo') {
    $compra_id = intval($_POST['compra_id']);
    $nro_factura = trim($_POST['nro_factura']);
    $fecha_factura = $_POST['fecha_factura'];
    $monto = floatval($_POST['monto']);
    $estado = $_POST['estado'];

    if ($compra_id <= 0 || empty($nro_factura) || empty($fecha_factura) || $monto <= 0) {
        $message = "La orden de compra, número de factura, fecha y monto son campos obligatorios.";
        $msg_type = "danger";
        $action = "nuevo";
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO facturas (compra_id, nro_factura, fecha_factura, monto, estado) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$compra_id, $nro_factura, $fecha_factura, $monto, $estado]);
            $message = "La factura se registró correctamente en el sistema.";
            $msg_type = "success";
            $action = "listar";
        } catch (\PDOException $e) {
            $message = error_generico($e, "Error al guardar la factura");
            $msg_type = "danger";
            $action = "nuevo";
        }
    }
}

// Procesar Cambio de Estado Rápido
if (isset($_GET['action']) && $_GET['action'] === 'cambiar_estado') {
    $id = intval($_GET['id']);
    $nuevo_estado = $_GET['estado'];
    
    if ($id > 0 && in_array($nuevo_estado, ['Pendiente', 'Aprobada', 'Pagada'])) {
        try {
            $stmt = $pdo->prepare("UPDATE facturas SET estado = ? WHERE id = ?");
            $stmt->execute([$nuevo_estado, $id]);
            $message = "Estado de la factura actualizado correctamente.";
            $msg_type = "success";
        } catch (\PDOException $e) {
            $message = error_generico($e, "Error al actualizar estado");
            $msg_type = "danger";
        }
    }
    $action = "listar";
}

// Eliminar Factura
if ($action === 'eliminar') {
    $id = intval($_GET['id']);
    try {
        $stmt = $pdo->prepare("DELETE FROM facturas WHERE id = ?");
        $stmt->execute([$id]);
        $message = "La factura fue eliminada del sistema.";
        $msg_type = "success";
    } catch (\PDOException $e) {
        $message = error_generico($e, "Error al eliminar factura");
        $msg_type = "danger";
    }
    $action = "listar";
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

<!-- ACCIÓN: LISTAR FACTURAS -->
<?php if ($action === 'listar'): 
    // Obtener facturas y relacionar con la orden de compra y el proveedor
    $query = $pdo->query("
        SELECT f.*, c.nro_compra, c.proveedor, c.obra_id, o.nombre AS obra_nombre, o.expediente AS obra_expediente,
               (SELECT COALESCE(SUM(cantidad_comprada * precio_unitario), 0) FROM compra_detalles WHERE compra_id = c.id) AS total_compra
        FROM facturas f
        JOIN compras c ON f.compra_id = c.id
        JOIN obras o ON c.obra_id = o.id
        ORDER BY o.nombre ASC, f.fecha_factura DESC
    ");
    $facturas = $query->fetchAll();

    // Agrupar en PHP por obra y luego por proveedor
    $facturas_por_obra_y_prov = [];
    foreach ($facturas as $f) {
        $obra_id = $f['obra_id'];
        $obra_key = $f['obra_expediente'] . ' - ' . $f['obra_nombre'];
        $prov = $f['proveedor'] ?: 'Sin Proveedor';
        
        $facturas_por_obra_y_prov[$obra_id]['title'] = $obra_key;
        $facturas_por_obra_y_prov[$obra_id]['providers'][$prov][] = $f;
    }
?>
    <div class="page-header">
        <div class="page-title">
            <h1>Registro y Control de Facturas</h1>
            <p>Control de solicitudes de facturación y pagos contra las órdenes de compra (Paso 3)</p>
        </div>
        <div>
            <a href="facturas.php?action=nuevo" class="btn btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5v14"/></svg>
                Registrar Factura
            </a>
        </div>
    </div>

    <!-- Filtro de Búsqueda -->
    <div class="filter-bar">
        <div class="form-group" style="flex-grow: 1;">
            <label class="form-label">Buscar Factura u Obra</label>
            <input type="text" class="form-control table-search" data-table="tabla-facturas" placeholder="Escriba para buscar...">
        </div>
    </div>

    <?php if (empty($facturas_por_obra_y_prov)): ?>
        <div class="card">
            <div class="card-body">
                <p class="text-center" style="padding: 3rem; color: var(--text-secondary);">
                    No hay facturas registradas en el sistema.
                </p>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($facturas_por_obra_y_prov as $obra_id => $group): ?>
            <div class="card" style="margin-bottom: 2.5rem; border-left: 4px solid var(--primary);">
                <div class="card-header" style="background-color: var(--primary-light); padding: 0.8rem 1.2rem;">
                    <h3 class="card-title" style="color: var(--primary-dark); font-size: 1rem; margin: 0; display: flex; justify-content: space-between; align-items: center; width: 100%;">
                        <span>Obra: <?= htmlspecialchars($group['title']) ?></span>
                        <a href="obra_detalle.php?id=<?= $obra_id ?>" class="btn btn-secondary btn-sm" style="font-size: 0.75rem; padding: 0.25rem 0.5rem;">Ver Obra</a>
                    </h3>
                </div>
                <div class="card-body" style="padding: 1.2rem;">
                    <?php 
                    $prov_idx = 0;
                    foreach ($group['providers'] as $prov_name => $list): 
                        $is_collapsed = ($prov_idx > 0);
                        $prov_idx++;
                    ?>
                        <div class="provider-subgroup" style="margin-bottom: 1.5rem; border: 1px solid var(--gray-200); border-radius: var(--border-radius-sm); overflow: hidden;">
                            <div class="provider-toggle-header" style="font-weight: bold; color: var(--sidebar-bg); background-color: var(--gray-50); padding: 0.6rem 1rem; font-size: 0.9rem; display: flex; justify-content: space-between; align-items: center; cursor: pointer; user-select: none;" onclick="toggleProviderGroup(this)">
                                <span>Proveedor: <strong style="color: var(--primary);"><?= htmlspecialchars($prov_name) ?></strong> (<?= count($list) ?> facturas)</span>
                                <svg class="toggle-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="transition: transform 0.2s ease; transform: <?= $is_collapsed ? 'rotate(-90deg)' : 'rotate(0deg)' ?>;"><polyline points="6 9 12 15 18 9"/></svg>
                            </div>
                            
                            <div class="provider-collapse-content" style="padding: 1rem; <?= $is_collapsed ? 'display: none;' : '' ?>">
                                <div class="table-responsive" style="margin: 0;">
                                    <table class="table tabla-facturas">
                                        <thead>
                                            <tr>
                                                <th>Nro Factura</th>
                                                <th>Orden de Compra</th>
                                                <th>Fecha</th>
                                                <th class="text-right">Monto Facturado</th>
                                                <th>Estado Pago</th>
                                                <th class="text-center" style="width: 250px;">Cambiar Estado / Acciones</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($list as $f): 
                                                $badge_class = 'badge-secondary';
                                                if ($f['estado'] === 'Pagada') $badge_class = 'badge-success';
                                                elseif ($f['estado'] === 'Aprobada') $badge_class = 'badge-info';
                                                else $badge_class = 'badge-warning';
                                            ?>
                                                <tr>
                                                    <td class="text-bold" style="color: var(--sidebar-bg);"><?= htmlspecialchars($f['nro_factura']) ?></td>
                                                    <td class="text-bold">OC: <?= htmlspecialchars($f['nro_compra']) ?></td>
                                                    <td><?= date('d/m/Y', strtotime($f['fecha_factura'])) ?></td>
                                                    <td class="text-right text-bold" style="color: var(--primary); font-size: 1.05rem;">
                                                        $<?= number_format($f['monto'], 2, ',', '.') ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge <?= $badge_class ?>"><?= $f['estado'] ?></span>
                                                    </td>
                                                    <td class="text-center">
                                                        <div class="d-flex gap-2 justify-center" style="align-items: center;">
                                                            <?php if ($f['estado'] !== 'Pagada'): ?>
                                                                <a href="facturas.php?action=cambiar_estado&id=<?= $f['id'] ?>&estado=Pagada<?= csrf_url() ?>" class="btn btn-secondary btn-sm" style="background-color: var(--success-light); color: var(--success-dark);" title="Marcar como Pagada">
                                                                    Pagar
                                                                </a>
                                                            <?php endif; ?>
                                                            <?php if ($f['estado'] === 'Pendiente'): ?>
                                                                <a href="facturas.php?action=cambiar_estado&id=<?= $f['id'] ?>&estado=Aprobada<?= csrf_url() ?>" class="btn btn-secondary btn-sm" style="background-color: var(--info-light); color: var(--info-dark);" title="Aprobar Factura">
                                                                    Aprobar
                                                                </a>
                                                            <?php endif; ?>
                                                            
                                                            <a href="facturas.php?action=eliminar&id=<?= $f['id'] ?><?= csrf_url() ?>" class="btn btn-danger btn-sm" onclick="return confirm('¿Está seguro de eliminar esta factura?');">
                                                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2M10 11v6M14 11v6"/></svg>
                                                            </a>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
        
        <!-- Toggle provider accordion function -->
        <script>
            function toggleProviderGroup(header) {
                const content = header.nextElementSibling;
                const icon = header.querySelector('.toggle-icon');
                if (content.style.display === 'none') {
                    content.style.display = 'block';
                    icon.style.transform = 'rotate(0deg)';
                } else {
                    content.style.display = 'none';
                    icon.style.transform = 'rotate(-90deg)';
                }
            }
        </script>
    <?php endif; ?>

<!-- ACCIÓN: REGISTRAR FACTURA -->
<?php elseif ($action === 'nuevo'): 
    // Obtener todas las órdenes de compra que aún NO tienen ninguna factura registrada
    $query = $pdo->query("
        SELECT c.id, c.nro_compra, c.proveedor, o.nombre AS obra_nombre,
               (SELECT COALESCE(SUM(cantidad_comprada * precio_unitario), 0) FROM compra_detalles WHERE compra_id = c.id) AS total_compra
        FROM compras c
        JOIN obras o ON c.obra_id = o.id
        WHERE NOT EXISTS (
            SELECT 1 
            FROM facturas f 
            WHERE f.compra_id = c.id
        )
        ORDER BY c.fecha_compra DESC
    ");
    $compras = $query->fetchAll();
?>
    <div class="page-header">
        <div class="page-title">
            <h1>Registrar Nueva Factura</h1>
            <p>Registre las facturas recibidas de los proveedores para habilitar los pagos (Paso 3)</p>
        </div>
        <div>
            <a href="facturas.php" class="btn btn-secondary" onclick="if(document.referrer && !document.referrer.includes(window.location.pathname)) { window.history.back(); return false; }">Cancelar</a>
        </div>
    </div>

    <form action="facturas.php?action=guardar_nuevo" method="POST">
        <?= csrf_campo() ?>
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Datos Comerciales de la Factura</h3>
            </div>
            <div class="card-body">
                <div class="form-grid">
                    <div class="form-group" style="grid-column: span 2;">
                        <label class="form-label">Orden de Compra Facturada *</label>
                        <select name="compra_id" class="form-control" required>
                            <option value="">-- Seleccionar Orden de Compra --</option>
                            <?php foreach ($compras as $c): ?>
                                <option value="<?= $c['id'] ?>" data-monto="<?= $c['total_compra'] ?>">
                                    OC: <?= htmlspecialchars($c['nro_compra']) ?> | Proveedor: <?= htmlspecialchars($c['proveedor']) ?> | Obra: <?= htmlspecialchars($c['obra_nombre']) ?> | Total OC: $<?= number_format($c['total_compra'], 2, ',', '.') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Número de Factura *</label>
                        <input type="text" name="nro_factura" class="form-control" placeholder="Ej. 0005-00124578" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Monto de la Factura ($) *</label>
                        <input type="number" step="0.01" name="monto" class="form-control" placeholder="0.00" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Fecha de Factura *</label>
                        <input type="date" name="fecha_factura" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Estado Inicial del Pago *</label>
                        <select name="estado" class="form-control" required>
                            <option value="Pendiente" selected>Pendiente de Pago</option>
                            <option value="Aprobada">Aprobada para Pago (Tesoreria)</option>
                            <option value="Pagada">Pagada / Liquidada</option>
                        </select>
                    </div>
                </div>

                <div style="margin-top: 2rem; display: flex; justify-content: flex-end; gap: 1rem;">
                    <a href="facturas.php" class="btn btn-secondary" onclick="if(document.referrer && !document.referrer.includes(window.location.pathname)) { window.history.back(); return false; }">Cancelar</a>
                    <button type="submit" class="btn btn-primary">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                        Registrar Factura
                    </button>
                </div>
            </div>
        </div>
    </form>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const selectOC = document.querySelector('select[name="compra_id"]');
            const inputMonto = document.querySelector('input[name="monto"]');
            if (selectOC && inputMonto) {
                selectOC.addEventListener('change', () => {
                    const opt = selectOC.options[selectOC.selectedIndex];
                    if (opt && opt.value !== '') {
                        const monto = opt.getAttribute('data-monto');
                        inputMonto.value = parseFloat(monto).toFixed(2);
                    } else {
                        inputMonto.value = '';
                    }
                });
            }
        });
    </script>
<?php endif; ?>

<?php
require_once 'footer.php';
?>
