<?php
require_once 'db.php';

$action = isset($_GET['action']) ? $_GET['action'] : 'listar';
$message = '';
$msg_type = 'success';

// Obtener todos los proveedores distintos registrados previamente en compras
try {
    $prov_stmt = $pdo->query("SELECT DISTINCT proveedor FROM compras WHERE proveedor IS NOT NULL AND proveedor != '' ORDER BY proveedor ASC");
    $proveedores_existentes = $prov_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (\PDOException $e) {
    $proveedores_existentes = [];
}

// Procesar Formulario de Alta de Compra
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'guardar_nuevo') {
        $obra_id = intval($_POST['obra_id']);
        $nro_compra = trim($_POST['nro_compra']);
        $proveedor = trim($_POST['proveedor']);
        $fecha_compra = $_POST['fecha_compra'];
        $items = isset($_POST['items']) ? $_POST['items'] : [];

        if ($obra_id <= 0 || empty($nro_compra) || empty($proveedor) || empty($fecha_compra) || empty($items)) {
            $message = "Todos los campos principales y al menos un ítem son obligatorios.";
            $msg_type = "danger";
            $action = "nuevo";
        } else {
            try {
                $pdo->beginTransaction();

                // 1. Insertar Cabecera de Compra
                $stmt = $pdo->prepare("INSERT INTO compras (obra_id, nro_compra, proveedor, fecha_compra, estado) VALUES (?, ?, ?, ?, 'Adjudicado')");
                $stmt->execute([$obra_id, $nro_compra, $proveedor, $fecha_compra]);
                $compra_id = $pdo->lastInsertId();

                // 2. Insertar Detalles de Compra
                $stmtItem = $pdo->prepare("
                    INSERT INTO compra_detalles (compra_id, material_solicitado_id, descripcion, cantidad_comprada, unidad, precio_unitario) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                
                foreach ($items as $item) {
                    // Solo guardamos si el checkbox 'incluir' está marcado
                    if (isset($item['incluir']) && !empty($item['descripcion']) && $item['cantidad_comprada'] > 0) {
                        $mat_solicitado_id = (!empty($item['material_solicitado_id'])) ? intval($item['material_solicitado_id']) : null;
                        $cantidad_comprada = floatval($item['cantidad_comprada']);
                        
                        $unidad_original = 'Unidades';
                        if ($mat_solicitado_id) {
                            // Validar cantidad solicitada vs ya comprada
                            $stmtOrig = $pdo->prepare("SELECT descripcion, cantidad FROM materiales_solicitados WHERE id = ?");
                            $stmtOrig->execute([$mat_solicitado_id]);
                            $orig = $stmtOrig->fetch();
                            $desc_original = $orig['descripcion'] ?? 'Material';
                            $cant_solicitada = floatval($orig['cantidad'] ?? 0);

                            $stmtYaComprado = $pdo->prepare("
                                SELECT COALESCE(SUM(cd.cantidad_comprada), 0) 
                                FROM compra_detalles cd
                                WHERE cd.material_solicitado_id = ? 
                                  AND cd.compra_id != ?
                            ");
                            $stmtYaComprado->execute([$mat_solicitado_id, $compra_id]);
                            $cant_ya_comprada = floatval($stmtYaComprado->fetchColumn());

                            if (($cantidad_comprada + $cant_ya_comprada) > $cant_solicitada) {
                                $disponible = $cant_solicitada - $cant_ya_comprada;
                                throw new \PDOException("La cantidad a comprar de '" . $desc_original . "' (" . $cantidad_comprada . ") supera el saldo pendiente de la solicitud original. Disponible para comprar: " . $disponible);
                            }

                            $stmtUnit = $pdo->prepare("SELECT unidad FROM materiales_solicitados WHERE id = ?");
                            $stmtUnit->execute([$mat_solicitado_id]);
                            $unidad_original = $stmtUnit->fetchColumn() ?: 'Unidades';
                        }

                        $stmtItem->execute([
                            $compra_id,
                            $mat_solicitado_id,
                            trim($item['descripcion']),
                            floatval($item['cantidad_comprada']),
                            $unidad_original,
                            floatval($item['precio_unitario'])
                        ]);
                    }
                }

                $pdo->commit();
                $message = "La orden de compra se registró con éxito.";
                $msg_type = "success";
                $action = "listar";
            } catch (\PDOException $e) {
                $pdo->rollBack();
                $message = "Error al guardar la compra: " . $e->getMessage();
                $msg_type = "danger";
                $action = "nuevo";
            }
        }
    }
    
    if ($action === 'guardar_editar') {
        $compra_id = intval($_POST['compra_id']);
        $nro_compra = trim($_POST['nro_compra']);
        $proveedor = trim($_POST['proveedor']);
        $fecha_compra = $_POST['fecha_compra'];
        $items = isset($_POST['items']) ? $_POST['items'] : [];

        if ($compra_id <= 0 || empty($nro_compra) || empty($proveedor) || empty($fecha_compra) || empty($items)) {
            $message = "Todos los campos principales y al menos un ítem son obligatorios.";
            $msg_type = "danger";
            $action = "editar";
        } else {
            try {
                $pdo->beginTransaction();

                // 1. Actualizar Cabecera
                $stmt = $pdo->prepare("UPDATE compras SET nro_compra = ?, proveedor = ?, fecha_compra = ? WHERE id = ?");
                $stmt->execute([$nro_compra, $proveedor, $fecha_compra, $compra_id]);

                // 2. Gestionar Items (Insertar / Actualizar / Eliminar)
                $stmtGetIds = $pdo->prepare("SELECT id FROM compra_detalles WHERE compra_id = ?");
                $stmtGetIds->execute([$compra_id]);
                $existing_ids = $stmtGetIds->fetchAll(PDO::FETCH_COLUMN);

                $form_ids = [];
                $stmtInsert = $pdo->prepare("
                    INSERT INTO compra_detalles (compra_id, material_solicitado_id, descripcion, cantidad_comprada, unidad, precio_unitario) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmtUpdate = $pdo->prepare("
                    UPDATE compra_detalles 
                    SET material_solicitado_id = ?, descripcion = ?, cantidad_comprada = ?, unidad = ?, precio_unitario = ? 
                    WHERE id = ? AND compra_id = ?
                ");

                foreach ($items as $item) {
                    if (isset($item['incluir'])) {
                        $item_id = isset($item['id']) ? intval($item['id']) : 0;
                        $mat_solicitado_id = (!empty($item['material_solicitado_id'])) ? intval($item['material_solicitado_id']) : null;
                        $cantidad_comprada = floatval($item['cantidad_comprada']);
                        
                        $unidad_original = 'Unidades';
                        if ($mat_solicitado_id) {
                            // Validar cantidad solicitada vs ya comprada
                            $stmtOrig = $pdo->prepare("SELECT descripcion, cantidad FROM materiales_solicitados WHERE id = ?");
                            $stmtOrig->execute([$mat_solicitado_id]);
                            $orig = $stmtOrig->fetch();
                            $desc_original = $orig['descripcion'] ?? 'Material';
                            $cant_solicitada = floatval($orig['cantidad'] ?? 0);

                            $stmtYaComprado = $pdo->prepare("
                                SELECT COALESCE(SUM(cd.cantidad_comprada), 0) 
                                FROM compra_detalles cd
                                WHERE cd.material_solicitado_id = ? 
                                  AND cd.compra_id != ?
                            ");
                            $stmtYaComprado->execute([$mat_solicitado_id, $compra_id]);
                            $cant_ya_comprada = floatval($stmtYaComprado->fetchColumn());

                            if (($cantidad_comprada + $cant_ya_comprada) > $cant_solicitada) {
                                $disponible = $cant_solicitada - $cant_ya_comprada;
                                throw new \PDOException("La cantidad a comprar de '" . $desc_original . "' (" . $cantidad_comprada . ") supera el saldo pendiente de la solicitud original. Disponible para comprar: " . $disponible);
                            }

                            $stmtUnit = $pdo->prepare("SELECT unidad FROM materiales_solicitados WHERE id = ?");
                            $stmtUnit->execute([$mat_solicitado_id]);
                            $unidad_original = $stmtUnit->fetchColumn() ?: 'Unidades';
                        } else {
                            $unidad_original = isset($item['unidad']) ? trim($item['unidad']) : 'Unidades';
                        }

                        if ($item_id > 0) {
                            // Actualizar
                            $stmtUpdate->execute([
                                $mat_solicitado_id,
                                trim($item['descripcion']),
                                floatval($item['cantidad_comprada']),
                                $unidad_original,
                                floatval($item['precio_unitario']),
                                $item_id,
                                $compra_id
                            ]);
                            $form_ids[] = $item_id;
                        } else {
                            // Insertar
                            if (!empty($item['descripcion']) && $item['cantidad_comprada'] > 0) {
                                $stmtInsert->execute([
                                    $compra_id,
                                    $mat_solicitado_id,
                                    trim($item['descripcion']),
                                    floatval($item['cantidad_comprada']),
                                    $unidad_original,
                                    floatval($item['precio_unitario'])
                                ]);
                            }
                        }
                    }
                }

                // Eliminar removidos
                $ids_to_delete = array_diff($existing_ids, $form_ids);
                if (!empty($ids_to_delete)) {
                    $in = str_repeat('?,', count($ids_to_delete) - 1) . '?';
                    $stmtDel = $pdo->prepare("DELETE FROM compra_detalles WHERE id IN ($in) AND compra_id = ?");
                    $params = array_merge(array_values($ids_to_delete), [$compra_id]);
                    $stmtDel->execute($params);
                }

                $pdo->commit();
                $message = "La orden de compra ha sido actualizada correctamente.";
                $msg_type = "success";
                $action = "listar";
            } catch (\PDOException $e) {
                $pdo->rollBack();
                $message = "Error al actualizar la compra: " . $e->getMessage();
                $msg_type = "danger";
                $action = "editar";
            }
        }
    }
}

// Eliminar Compra
if ($action === 'eliminar') {
    $id = intval($_GET['id']);
    try {
        $stmt = $pdo->prepare("DELETE FROM compras WHERE id = ?");
        $stmt->execute([$id]);
        $message = "La orden de compra fue eliminada.";
        $msg_type = "success";
    } catch (\PDOException $e) {
        $message = "Error al eliminar: " . $e->getMessage();
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

<!-- ACCIÓN: LISTAR COMPRAS -->
<?php if ($action === 'listar'): 
    // Obtener todas las compras y sus montos totales calculados
    $query = $pdo->query("
        SELECT c.*, o.nombre AS obra_nombre, o.expediente AS obra_expediente,
               (SELECT COALESCE(SUM(cantidad_comprada * precio_unitario), 0) FROM compra_detalles WHERE compra_id = c.id) AS monto_total,
               (SELECT COUNT(*) FROM compra_detalles WHERE compra_id = c.id) AS cant_items
        FROM compras c
        JOIN obras o ON c.obra_id = o.id
        ORDER BY o.nombre ASC, c.fecha_compra DESC
    ");
    $compras = $query->fetchAll();

    // Agrupar en PHP por obra
    $compras_por_obra = [];
    foreach ($compras as $c) {
        $obra_id = $c['obra_id'];
        $obra_key = $c['obra_expediente'] . ' - ' . $c['obra_nombre'];
        $compras_por_obra[$obra_id]['title'] = $obra_key;
        $compras_por_obra[$obra_id]['items'][] = $c;
    }
?>
    <div class="page-header">
        <div class="page-title">
            <h1>Órdenes de Compra y Presupuestos</h1>
            <p>Monitoreo y administración de compras contratadas para cada expediente municipal (Paso 2)</p>
        </div>
        <div>
            <a href="compras.php?action=nuevo" class="btn btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5v14"/></svg>
                Registrar Compra
            </a>
        </div>
    </div>

    <!-- Filtro de Búsqueda -->
    <div class="filter-bar">
        <div class="form-group" style="flex-grow: 1;">
            <label class="form-label">Buscar por Proveedor, OC u Obra</label>
            <input type="text" class="form-control table-search" data-table="tabla-compras" placeholder="Escriba para filtrar compras...">
        </div>
    </div>

    <?php if (empty($compras_por_obra)): ?>
        <div class="card">
            <div class="card-body">
                <p class="text-center" style="padding: 3rem; color: var(--text-secondary);">
                    No hay compras registradas en el sistema.
                </p>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($compras_por_obra as $obra_id => $group): ?>
            <div class="card" style="margin-bottom: 2rem; border-left: 4px solid var(--primary);">
                <div class="card-header" style="background-color: var(--primary-light); padding: 0.8rem 1.2rem;">
                    <h3 class="card-title" style="color: var(--primary-dark); font-size: 1rem; margin: 0; display: flex; justify-content: space-between; align-items: center; width: 100%;">
                        <span>Obra: <?= htmlspecialchars($group['title']) ?></span>
                        <a href="obra_detalle.php?id=<?= $obra_id ?>" class="btn btn-secondary btn-sm" style="font-size: 0.75rem; padding: 0.25rem 0.5rem;">Ver Obra</a>
                    </h3>
                </div>
                <div class="card-body" style="padding: 0;">
                    <div class="table-responsive">
                        <table class="table tabla-compras">
                            <thead>
                                <tr>
                                    <th>Nro OC / Adjudicación</th>
                                    <th>Proveedor</th>
                                    <th>Fecha</th>
                                    <th class="text-right">Ítems</th>
                                    <th class="text-right">Monto Total</th>
                                    <th class="text-center" style="width: 150px;">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($group['items'] as $c): ?>
                                    <tr>
                                        <td class="text-bold" style="color: var(--sidebar-bg);"><?= htmlspecialchars($c['nro_compra']) ?></td>
                                        <td class="text-bold"><?= htmlspecialchars($c['proveedor']) ?></td>
                                        <td><?= date('d/m/Y', strtotime($c['fecha_compra'])) ?></td>
                                        <td class="text-right text-bold"><?= $c['cant_items'] ?></td>
                                        <td class="text-right text-bold" style="color: var(--success-dark); font-size: 1.05rem;">
                                            $<?= number_format($c['monto_total'], 2, ',', '.') ?>
                                        </td>
                                        <td class="text-center">
                                            <div class="d-flex gap-2 justify-center">
                                                <a href="compras.php?action=editar&id=<?= $c['id'] ?>" class="btn btn-secondary btn-sm" title="Editar">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                                </a>
                                                <a href="compras.php?action=eliminar&id=<?= $c['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('¿Está seguro de eliminar esta orden de compra? Se eliminarán también las facturas y los remitos vinculados.');" title="Eliminar">
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
    <?php endif; ?>

<!-- ACCIÓN: NUEVA COMPRA -->
<?php elseif ($action === 'nuevo'): 
    $pre_obra_id = isset($_GET['obra_id']) ? intval($_GET['obra_id']) : 0;
    
    // Obtener todas las obras, conteo de compras y cantidad de materiales que AÚN NO se han comprado en absoluto
    $obras_stmt = $pdo->query("
        SELECT o.id, o.nombre, o.expediente,
               (SELECT COUNT(*) FROM compras WHERE obra_id = o.id) AS compras_count,
               (
                   SELECT COUNT(*) 
                   FROM materiales_solicitados ms
                   WHERE ms.obra_id = o.id 
                     AND NOT EXISTS (
                         SELECT 1 
                         FROM compra_detalles cd
                         WHERE cd.material_solicitado_id = ms.id
                     )
               ) AS materiales_pendientes_count
        FROM obras o 
        ORDER BY o.nombre ASC
    ");
    $obras = $obras_stmt->fetchAll();

    // Obtener solo los materiales por obra que AÚN NO se han cargado en ninguna orden de compra
    $mat_stmt = $pdo->query("
        SELECT ms.id, ms.obra_id, ms.descripcion, ms.cantidad, ms.unidad
        FROM materiales_solicitados ms
        WHERE NOT EXISTS (
            SELECT 1 
            FROM compra_detalles cd
            WHERE cd.material_solicitado_id = ms.id
        )
        ORDER BY ms.descripcion ASC
    ");
    $todos_materiales = $mat_stmt->fetchAll();
    
    // Agruparlos por obra en un array asociativo
    $materiales_por_obra = [];
    foreach ($todos_materiales as $m) {
        $materiales_por_obra[$m['obra_id']][] = [
            'id' => $m['id'],
            'descripcion' => $m['descripcion'],
            'cantidad' => floatval($m['cantidad']),
            'unidad' => $m['unidad']
        ];
    }
?>
    <div class="page-header">
        <div class="page-title">
            <h1>Cargar Nueva Orden de Compra</h1>
            <p>Asocie la adjudicación de presupuesto a los insumos requeridos por una obra (Paso 2)</p>
        </div>
        <div>
            <a href="compras.php" class="btn btn-secondary" onclick="if(document.referrer && !document.referrer.includes(window.location.pathname)) { window.history.back(); return false; }">Cancelar</a>
        </div>
    </div>

    <!-- Script Inline para inyectar materiales y cargarlos automáticamente como filas -->
    <script>
        const materialesPorObra = <?= json_encode($materiales_por_obra) ?>;
        let itemIdx = 0;

        function generarFilasMateriales(obraId) {
            const container = document.getElementById('dynamic-compra-items');
            container.innerHTML = ''; // Limpiar filas anteriores
            itemIdx = 0;

            if (materialesPorObra[obraId] && materialesPorObra[obraId].length > 0) {
                materialesPorObra[obraId].forEach(mat => {
                    agregarFilaMaterial(mat);
                });
            } else {
                container.innerHTML = '<p style="color: var(--text-secondary); font-size: 0.9rem; padding: 1rem; text-align: center;">Esta obra no tiene materiales solicitados registrados.</p>';
            }
        }

        function agregarFilaMaterial(mat = null, isExtra = false) {
            const container = document.getElementById('dynamic-compra-items');
            
            // Si el contenedor tenía el mensaje de "no tiene materiales", lo removemos
            if (container.querySelector('p')) {
                container.innerHTML = '';
            }

            const row = document.createElement('div');
            row.className = 'dynamic-item-row';
            row.style.gridTemplateColumns = '55px 3fr 1fr 100px 1fr auto';
            row.style.alignItems = 'center';
            
            const matId = mat ? mat.id : '';
            const desc = mat ? mat.descripcion : '';
            const cant = mat ? mat.cantidad : '';
            const unidad = mat ? mat.unidad : 'Unidades';
            
            // Si viene de material solicitado original (mat !== null y isExtra es falso), empieza DESTILDADO.
            // Si es un ítem extra añadido a mano, empieza TILDADO.
            const isChecked = isExtra || (mat === null);
            const checkedAttr = isChecked ? 'checked' : '';
            const disabledAttr = isChecked ? '' : 'disabled';
            const requiredAttr = isChecked ? 'required' : '';
            if (!isChecked) {
                row.style.opacity = '0.5';
            }

            row.innerHTML = `
                <div class="form-group text-center">
                    <label class="form-label" style="font-size:0.75rem;">¿Incluir?</label>
                    <input type="checkbox" name="items[${itemIdx}][incluir]" value="1" ${checkedAttr} style="width: 20px; height: 20px; margin: 0 auto; cursor: pointer;" onchange="toggleRowInputs(this)">
                </div>
                <input type="hidden" name="items[${itemIdx}][material_solicitado_id]" value="${matId}">
                <div class="form-group">
                    <label class="form-label">Descripción del Material *</label>
                    <input type="text" name="items[${itemIdx}][descripcion]" class="form-control" value="${desc}" placeholder="Ej. Cemento Portland 50kg" ${disabledAttr} ${requiredAttr}>
                </div>
                <div class="form-group">
                    <label class="form-label">Cantidad Comprada *</label>
                    <input type="number" step="0.01" name="items[${itemIdx}][cantidad_comprada]" class="form-control" value="${cant}" placeholder="0.00" ${disabledAttr} ${requiredAttr}>
                </div>
                <div class="form-group">
                    <label class="form-label">Unidad</label>
                    <span class="badge badge-secondary" style="margin-top: 0.5rem; display: block; text-align: center; padding: 0.6rem; font-size: 0.75rem;">${unidad}</span>
                </div>
                <div class="form-group">
                    <label class="form-label">Precio Unitario ($) *</label>
                    <input type="number" step="0.01" name="items[${itemIdx}][precio_unitario]" class="form-control" placeholder="0.00" ${disabledAttr} ${requiredAttr}>
                </div>
                <div style="padding-bottom: 5px;">
                    <button type="button" class="btn btn-danger btn-sm btn-remove-row" style="margin-top: 1.8rem;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2M10 11v6M14 11v6"/></svg>
                    </button>
                </div>
            `;
            
            container.appendChild(row);
            itemIdx++;
        }

        function toggleRowInputs(checkbox) {
            const row = checkbox.closest('.dynamic-item-row');
            const inputs = row.querySelectorAll('input[type="text"], input[type="number"]');
            inputs.forEach(input => {
                input.disabled = !checkbox.checked;
                if (!checkbox.checked) {
                    input.removeAttribute('required');
                    row.style.opacity = '0.5';
                } else {
                    input.setAttribute('required', 'required');
                    row.style.opacity = '1';
                }
            });
        }

        document.addEventListener('DOMContentLoaded', () => {
            const obraSelect = document.getElementById('obra_id');
            const searchContainer = document.getElementById('material-search-container');
            const searchInput = document.getElementById('input-search-materials');

            obraSelect.addEventListener('change', () => {
                if (obraSelect.value !== '') {
                    generarFilasMateriales(obraSelect.value);
                    if (searchContainer) searchContainer.style.display = 'block';
                    if (searchInput) searchInput.value = '';
                } else {
                    document.getElementById('dynamic-compra-items').innerHTML = '<p style="color: var(--text-secondary); font-size: 0.9rem; padding: 1rem; text-align: center;">Seleccione una obra para cargar sus materiales automáticamente.</p>';
                    if (searchContainer) searchContainer.style.display = 'none';
                }
            });
            
            if (searchInput) {
                searchInput.addEventListener('input', () => {
                    const query = searchInput.value.toLowerCase();
                    const rows = document.querySelectorAll('#dynamic-compra-items .dynamic-item-row');
                    rows.forEach(row => {
                        const descInput = row.querySelector('input[name*="[descripcion]"]');
                        if (descInput) {
                            const text = descInput.value.toLowerCase();
                            if (text.includes(query)) {
                                row.style.display = '';
                            } else {
                                row.style.display = 'none';
                            }
                        }
                    });
                });
            }
            
            // Botón para agregar ítems extras/adicionales
            const btnAddExtra = document.getElementById('btn-add-compra-item');
            if (btnAddExtra) {
                btnAddExtra.addEventListener('click', () => {
                    agregarFilaMaterial(null, true);
                });
            }

            // Event delegation para eliminar fila
            document.getElementById('dynamic-compra-items').addEventListener('click', (e) => {
                if (e.target.closest('.btn-remove-row')) {
                    const row = e.target.closest('.dynamic-item-row');
                    row.remove();
                }
            });

            // Inicializar si ya viene con pre_obra_id
            if (obraSelect.value !== '') {
                generarFilasMateriales(obraSelect.value);
                if (searchContainer) searchContainer.style.display = 'block';
            }
        });
    </script>

    <form action="compras.php?action=guardar_nuevo" method="POST">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Datos Generales del Contrato / Compra</h3>
            </div>
            <div class="card-body">
                <div class="form-grid">
                    <div class="form-group" style="grid-column: span 2;">
                        <label class="form-label">Obra Destinataria *</label>
                        <select name="obra_id" id="obra_id" class="form-control" required>
                            <option value="">-- Seleccione la Obra --</option>
                            <?php 
                            // Mostrar solo obras que no tienen compras o tienen compras pendientes parciales
                            $pendientes = array_filter($obras, function($o) { 
                                return $o['compras_count'] == 0 || $o['materiales_pendientes_count'] > 0; 
                            });
                            ?>
                            <?php foreach ($pendientes as $o): ?>
                                <option value="<?= $o['id'] ?>" <?= ($pre_obra_id == $o['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($o['nombre']) ?> (Exp: <?= htmlspecialchars($o['expediente']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Número de Orden de Compra (OC) *</label>
                        <input type="text" name="nro_compra" class="form-control" placeholder="Ej. OC-2026-0045" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Proveedor Adjudicado *</label>
                        <input type="text" name="proveedor" class="form-control" list="proveedores-list" placeholder="Ej. Corralón Central S.A." required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Fecha de Compra / Adjudicación *</label>
                        <input type="date" name="fecha_compra" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Ítems Adjudicados en la Compra</h3>
            </div>
            <div class="card-body">
                <p style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 1.5rem;">
                    Los materiales solicitados originalmente para esta obra se han cargado automáticamente a continuación. Ingrese los precios unitarios adjudicados y desmarque los ítems que no correspondan a esta orden de compra.
                </p>
                
                <!-- Buscador de Materiales Solicitados -->
                <div id="material-search-container" style="display: none; margin-bottom: 1.5rem; background-color: var(--card-bg); border: 1px solid var(--border-color); padding: 1rem; border-radius: 6px;">
                    <div style="display: flex; gap: 1rem; align-items: center;">
                        <div style="flex-grow: 1;">
                            <label class="form-label" style="font-weight: bold; margin-bottom: 0.5rem; display: block; color: var(--text-primary);">Buscar Material Solicitado</label>
                            <input type="text" id="input-search-materials" class="form-control" placeholder="Escriba el nombre del material para filtrar la lista...">
                        </div>
                    </div>
                </div>
                
                <div id="dynamic-compra-items">
                    <p style="color: var(--text-secondary); font-size: 0.9rem; padding: 1rem; text-align: center;">Seleccione una obra para cargar sus materiales automáticamente.</p>
                </div>

                <!-- Botón de añadir item colocado abajo -->
                <div style="margin-top: 1.5rem; margin-bottom: 1rem;">
                    <button type="button" id="btn-add-compra-item" class="btn btn-secondary btn-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5v14"/></svg>
                        + Agregar Otro Ítem Extra
                    </button>
                </div>

                <div style="margin-top: 2rem; display: flex; justify-content: flex-end; gap: 1rem;">
                    <a href="compras.php" class="btn btn-secondary" onclick="if(document.referrer && !document.referrer.includes(window.location.pathname)) { window.history.back(); return false; }">Cancelar</a>
                    <button type="submit" class="btn btn-primary">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                        Guardar Adjudicación de Compra
                    </button>
                </div>
            </div>
        </div>
    </form>

<!-- ACCIÓN: EDITAR ORDEN DE COMPRA -->
<?php elseif ($action === 'editar'): 
    $id = isset($_GET['id']) ? intval($_GET['id']) : (isset($_POST['compra_id']) ? intval($_POST['compra_id']) : 0);
    // Obtener cabecera de la compra
    $stmt = $pdo->prepare("
        SELECT c.*, o.nombre AS obra_nombre, o.expediente AS obra_expediente 
        FROM compras c 
        JOIN obras o ON c.obra_id = o.id 
        WHERE c.id = ?
    ");
    $stmt->execute([$id]);
    $compra = $stmt->fetch();

    if (!$compra) {
        echo "<h3>La orden de compra solicitada no existe.</h3>";
        require_once 'footer.php';
        exit;
    }

    // Obtener los detalles de los items de esta compra
    $stmtItems = $pdo->prepare("SELECT * FROM compra_detalles WHERE compra_id = ? ORDER BY id ASC");
    $stmtItems->execute([$id]);
    $items_compra = $stmtItems->fetchAll();

    // Obtener materiales solicitados de la obra que están en esta compra o aún no se han adjudicado a ninguna otra
    $stmtMat = $pdo->prepare("
        SELECT id, descripcion, cantidad, unidad
        FROM materiales_solicitados
        WHERE obra_id = ?
          AND (
              id IN (
                  SELECT material_solicitado_id 
                  FROM compra_detalles 
                  WHERE compra_id = ? AND material_solicitado_id IS NOT NULL
              )
              OR id NOT IN (
                  SELECT material_solicitado_id 
                  FROM compra_detalles 
                  WHERE material_solicitado_id IS NOT NULL
              )
          )
        ORDER BY descripcion ASC
    ");
    $stmtMat->execute([$compra['obra_id'], $id]);
    $materiales_obra = $stmtMat->fetchAll();
?>
    <div class="page-header">
        <div class="page-title">
            <h1>Editar Orden de Compra</h1>
            <p>Modificar montos, proveedor o ítems adjudicados en la OC: <strong><?= htmlspecialchars($compra['nro_compra']) ?></strong></p>
        </div>
        <div>
            <a href="compras.php" class="btn btn-secondary" onclick="if(document.referrer && !document.referrer.includes(window.location.pathname)) { window.history.back(); return false; }">Cancelar</a>
        </div>
    </div>

    <form action="compras.php?action=guardar_editar" method="POST">
        <input type="hidden" name="compra_id" value="<?= $compra['id'] ?>">
        
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Datos Generales del Contrato / Compra</h3>
            </div>
            <div class="card-body">
                <div class="form-grid">
                    <div class="form-group" style="grid-column: span 2;">
                        <label class="form-label">Obra Destinataria (No modificable)</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($compra['obra_nombre']) ?> (Exp: <?= htmlspecialchars($compra['obra_expediente']) ?>)" disabled>
                        <input type="hidden" name="obra_id" value="<?= $compra['obra_id'] ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Número de Orden de Compra (OC) *</label>
                        <input type="text" name="nro_compra" class="form-control" value="<?= htmlspecialchars($compra['nro_compra']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Proveedor Adjudicado *</label>
                        <input type="text" name="proveedor" class="form-control" list="proveedores-list" value="<?= htmlspecialchars($compra['proveedor']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Fecha de Compra / Adjudicación *</label>
                        <input type="date" name="fecha_compra" class="form-control" value="<?= $compra['fecha_compra'] ?>" required>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Ítems Adjudicados en la Compra</h3>
            </div>
            <div class="card-body">
                <p style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 1.5rem;">
                    Modifique las cantidades, descripciones o añada nuevos ítems. Si desmarca la casilla "Incluir", ese ítem se eliminará de la orden de compra.
                </p>
                
                <div id="dynamic-compra-items-edit">
                    <?php 
                    $idx = 0;
                    foreach ($items_compra as $item): 
                    ?>
                        <div class="dynamic-item-row" style="grid-template-columns: 55px 3fr 1fr 100px 1fr auto; align-items: center;">
                            <input type="hidden" name="items[<?= $idx ?>][id]" value="<?= $item['id'] ?>">
                            <div class="form-group text-center">
                                <label class="form-label" style="font-size:0.75rem;">¿Incluir?</label>
                                <input type="checkbox" name="items[<?= $idx ?>][incluir]" value="1" checked style="width: 20px; height: 20px; margin: 0 auto; cursor: pointer;" onchange="toggleRowInputs(this)">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Vincular a Material Solicitado</label>
                                <select name="items[<?= $idx ?>][material_solicitado_id]" class="form-control" onchange="actualizarUnidadEtiqueta(this)">
                                    <option value="">-- No vincular --</option>
                                    <?php foreach ($materiales_obra as $mat): ?>
                                        <option value="<?= $mat['id'] ?>" <?= ($item['material_solicitado_id'] == $mat['id']) ? 'selected' : '' ?> data-unidad="<?= htmlspecialchars($mat['unidad']) ?>">
                                            <?= htmlspecialchars($mat['descripcion']) ?> (Solicitado: <?= $mat['cantidad'] ?> <?= $mat['unidad'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Descripción *</label>
                                <input type="text" name="items[<?= $idx ?>][descripcion]" class="form-control" value="<?= htmlspecialchars($item['descripcion']) ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Cantidad *</label>
                                <input type="number" step="0.01" name="items[<?= $idx ?>][cantidad_comprada]" class="form-control" value="<?= floatval($item['cantidad_comprada']) ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Unidad</label>
                                <input type="hidden" name="items[<?= $idx ?>][unidad]" class="unidad-hidden" value="<?= htmlspecialchars($item['unidad']) ?>">
                                <span class="badge badge-secondary unidad-label" style="margin-top: 0.5rem; display: block; text-align: center; padding: 0.6rem; font-size: 0.75rem;"><?= htmlspecialchars($item['unidad']) ?></span>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Precio Unitario ($) *</label>
                                <input type="number" step="0.01" name="items[<?= $idx ?>][precio_unitario]" class="form-control" value="<?= floatval($item['precio_unitario']) ?>" required>
                            </div>
                            
                            <div style="padding-bottom: 5px;">
                                <button type="button" class="btn btn-danger btn-sm btn-remove-row" style="margin-top: 1.8rem;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2M10 11v6M14 11v6"/></svg>
                                </button>
                            </div>
                        </div>
                    <?php 
                        $idx++;
                    endforeach; 
                    ?>
                </div>

                <!-- Botón de añadir item colocado abajo en edición -->
                <div style="margin-top: 1.5rem; margin-bottom: 1rem;">
                    <button type="button" id="btn-add-compra-item-edit" class="btn btn-secondary btn-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5v14"/></svg>
                        + Agregar Otro Ítem Extra
                    </button>
                </div>

                <!-- Script local para dinámicas de edición de compras -->
                <script>
                    function actualizarUnidadEtiqueta(select) {
                        const opt = select.options[select.selectedIndex];
                        const row = select.closest('.dynamic-item-row');
                        const hiddenInput = row.querySelector('.unidad-hidden');
                        const label = row.querySelector('.unidad-label');
                        if (opt && opt.value !== '') {
                            const unidad = opt.getAttribute('data-unidad');
                            hiddenInput.value = unidad;
                            label.textContent = unidad;
                        }
                    }

                    function toggleRowInputs(checkbox) {
                        const row = checkbox.closest('.dynamic-item-row');
                        const inputs = row.querySelectorAll('input[type="text"], input[type="number"], select');
                        inputs.forEach(input => {
                            input.disabled = !checkbox.checked;
                            if (!checkbox.checked) {
                                input.removeAttribute('required');
                                row.style.opacity = '0.5';
                            } else {
                                input.setAttribute('required', 'required');
                                row.style.opacity = '1';
                            }
                        });
                    }

                    document.addEventListener('DOMContentLoaded', () => {
                        const btnAdd = document.getElementById('btn-add-compra-item-edit');
                        const container = document.getElementById('dynamic-compra-items-edit');
                        let editItemIdx = <?= $idx ?>;

                        // Opciones de materiales de la obra
                        const materialOptions = `
                            <option value="">-- No vincular --</option>
                            <?php foreach ($materiales_obra as $mat): ?>
                                <option value="<?= $mat['id'] ?>" data-unidad="<?= htmlspecialchars($mat['unidad']) ?>">
                                    <?= htmlspecialchars($mat['descripcion']) ?> (Solicitado: <?= $mat['cantidad'] ?> <?= $mat['unidad'] ?>)
                                </option>
                            <?php endforeach; ?>
                        `;

                        if (btnAdd && container) {
                            btnAdd.addEventListener('click', () => {
                                const newRow = document.createElement('div');
                                newRow.className = 'dynamic-item-row';
                                newRow.style.gridTemplateColumns = '55px 3fr 1fr 100px 1fr auto';
                                newRow.style.alignItems = 'center';
                                
                                newRow.innerHTML = `
                                    <div class="form-group text-center">
                                        <label class="form-label" style="font-size:0.75rem;">¿Incluir?</label>
                                        <input type="checkbox" name="items[${editItemIdx}][incluir]" value="1" checked style="width: 20px; height: 20px; margin: 0 auto; cursor: pointer;" onchange="toggleRowInputs(this)">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Vincular a Material Solicitado</label>
                                        <select name="items[${editItemIdx}][material_solicitado_id]" class="form-control" onchange="actualizarUnidadEtiqueta(this)">
                                            ${materialOptions}
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Descripción *</label>
                                        <input type="text" name="items[${editItemIdx}][descripcion]" class="form-control" placeholder="Ej. Ladrillo Hueco" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Cantidad *</label>
                                        <input type="number" step="0.01" name="items[${editItemIdx}][cantidad_comprada]" class="form-control" placeholder="0.00" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Unidad</label>
                                        <input type="hidden" name="items[${editItemIdx}][unidad]" class="unidad-hidden" value="Unidades">
                                        <span class="badge badge-secondary unidad-label" style="margin-top: 0.5rem; display: block; text-align: center; padding: 0.6rem; font-size: 0.75rem;">Unidades</span>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Precio Unitario ($) *</label>
                                        <input type="number" step="0.01" name="items[${editItemIdx}][precio_unitario]" class="form-control" placeholder="0.00" required>
                                    </div>
                                    <div style="padding-bottom: 5px;">
                                        <button type="button" class="btn btn-danger btn-sm btn-remove-row" style="margin-top: 1.8rem;">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2M10 11v6M14 11v6"/></svg>
                                        </button>
                                    </div>
                                `;
                                container.appendChild(newRow);
                                editItemIdx++;
                            });

                            container.addEventListener('click', (e) => {
                                if (e.target.closest('.btn-remove-row')) {
                                    const row = e.target.closest('.dynamic-item-row');
                                    row.remove();
                                }
                            });
                        }
                    });
                </script>
                
                <div style="margin-top: 2rem; display: flex; justify-content: flex-end; gap: 1rem;">
                    <a href="compras.php" class="btn btn-secondary" onclick="if(document.referrer && !document.referrer.includes(window.location.pathname)) { window.history.back(); return false; }">Cancelar</a>
                    <button type="submit" class="btn btn-primary">Guardar Cambios</button>
                </div>
            </div>
        </div>
    </form>
<?php endif; ?>

<datalist id="proveedores-list">
    <?php foreach ($proveedores_existentes as $p): ?>
        <option value="<?= htmlspecialchars($p) ?>"></option>
    <?php endforeach; ?>
</datalist>

<?php
require_once 'footer.php';
?>
