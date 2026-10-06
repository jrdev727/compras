<?php
require_once 'db.php';

$action = isset($_GET['action']) ? $_GET['action'] : 'listar';
$message = '';
$msg_type = 'success';

// Procesar Formulario de Alta de Remito
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'guardar_nuevo') {
    $obra_id = intval($_POST['obra_id']);
    $nro_remito = trim($_POST['nro_remito']);
    $fecha_entrega = $_POST['fecha_entrega'];
    $estado_remito = $_POST['estado_remito'];
    $recibido_por = trim($_POST['recibido_por']);
    $observaciones = trim($_POST['observaciones']);
    $items = isset($_POST['items']) ? $_POST['items'] : [];

    // Si el remito físico es pendiente, el número puede estar vacío (se guardará NULL en BD)
    if ($estado_remito === 'Pendiente de Remito Físico' && empty($nro_remito)) {
        $db_nro_remito = null;
    } else {
        $db_nro_remito = !empty($nro_remito) ? $nro_remito : 'PENDIENTE';
    }

    if ($obra_id <= 0 || empty($fecha_entrega) || empty($items)) {
        $message = "La obra, fecha de entrega y al menos un material son obligatorios.";
        $msg_type = "danger";
        $action = "nuevo";
    } else {
        try {
            $pdo->beginTransaction();

            // 1. Insertar Cabecera de Remito
            $stmt = $pdo->prepare("
                INSERT INTO remitos (obra_id, nro_remito, fecha_entrega, estado_remito, recibido_por, observaciones) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$obra_id, $db_nro_remito, $fecha_entrega, $estado_remito, $recibido_por, $observaciones]);
            $remito_id = $pdo->lastInsertId();

            // 2. Insertar Detalles de Entrega
            $stmtDetail = $pdo->prepare("
                INSERT INTO remito_detalles (remito_id, compra_detalle_id, cantidad_entregada) 
                VALUES (?, ?, ?)
            ");

            foreach ($items as $item) {
                if (intval($item['compra_detalle_id']) > 0 && floatval($item['cantidad_entregada']) > 0) {
                    $stmtDetail->execute([
                        $remito_id,
                        intval($item['compra_detalle_id']),
                        floatval($item['cantidad_entregada'])
                    ]);
                }
            }

            $pdo->commit();
            $message = "La entrega y descarga de materiales se registró correctamente.";
            $msg_type = "success";
            $action = "listar";
        } catch (\PDOException $e) {
            $pdo->rollBack();
            $message = "Error al guardar el remito: " . $e->getMessage();
            $msg_type = "danger";
            $action = "nuevo";
        }
    }
}

// Resolver Remito Pendiente Rápido
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'resolver_rapido') {
    $id = intval($_POST['id']);
    $nro_remito = trim($_POST['nro_remito']);
    
    if ($id > 0 && !empty($nro_remito)) {
        try {
            $stmt = $pdo->prepare("UPDATE remitos SET nro_remito = ?, estado_remito = 'Recibido' WHERE id = ?");
            $stmt->execute([$nro_remito, $id]);
            $message = "Remito conciliado correctamente.";
            $msg_type = "success";
        } catch (\PDOException $e) {
            $message = "Error: " . $e->getMessage();
            $msg_type = "danger";
        }
    } else {
        $message = "Debe ingresar el número de remito correspondiente.";
        $msg_type = "danger";
    }
    $action = "listar";
}

// Eliminar Remito
if ($action === 'eliminar') {
    $id = intval($_GET['id']);
    try {
        $stmt = $pdo->prepare("DELETE FROM remitos WHERE id = ?");
        $stmt->execute([$id]);
        $message = "El remito y sus cantidades entregadas fueron eliminadas.";
        $msg_type = "success";
    } catch (\PDOException $e) {
        $message = "Error al eliminar remito: " . $e->getMessage();
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

<!-- ACCIÓN: LISTAR REMITOS -->
<?php if ($action === 'listar'): 
    $filter_status = isset($_GET['status']) ? $_GET['status'] : 'todos';
    
    // Consulta según filtro
    $sql = "
        SELECT r.*, o.nombre AS obra_nombre, o.expediente AS obra_expediente,
               (SELECT c.proveedor 
                FROM remito_detalles rd 
                JOIN compra_detalles cd ON rd.compra_detalle_id = cd.id 
                JOIN compras c ON cd.compra_id = c.id 
                WHERE rd.remito_id = r.id LIMIT 1) AS proveedor
        FROM remitos r
        JOIN obras o ON r.obra_id = o.id
    ";
    
    if ($filter_status === 'faltantes') {
        $sql .= " WHERE r.estado_remito = 'Pendiente de Remito Físico'";
    }
    
    $sql .= " ORDER BY o.nombre ASC, r.fecha_entrega DESC";
    $query = $pdo->query($sql);
    $remitos = $query->fetchAll();

    // Agrupar en PHP por obra y luego por proveedor
    $remitos_por_obra_y_prov = [];
    foreach ($remitos as $r) {
        $obra_id = $r['obra_id'];
        $obra_key = $r['obra_expediente'] . ' - ' . $r['obra_nombre'];
        $prov = $r['proveedor'] ?: 'Sin Proveedor';
        
        $remitos_por_obra_y_prov[$obra_id]['title'] = $obra_key;
        $remitos_por_obra_y_prov[$obra_id]['providers'][$prov][] = $r;
    }
?>
    <div class="page-header">
        <div class="page-title">
            <h1>Control de Remitos y Entregas</h1>
            <p>Registro de descargas y auditoría de documentos físicos en obra (Paso 4)</p>
        </div>
        <div>
            <a href="remitos.php?action=nuevo" class="btn btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5v14"/></svg>
                Registrar Entrega
            </a>
        </div>
    </div>

    <!-- Barra de Filtros -->
    <div class="filter-bar">
        <div class="form-group" style="flex-grow: 1;">
            <label class="form-label">Buscar por Proveedor, Obra o Nro Remito</label>
            <input type="text" class="form-control table-search" data-table="tabla-remitos" placeholder="Escriba para buscar...">
        </div>
        <div class="form-group" style="flex-grow: 0; min-width: 200px;">
            <label class="form-label">Filtrar por Documentación</label>
            <select class="form-control" onchange="location.href='remitos.php?status=' + this.value;">
                <option value="todos" <?= ($filter_status === 'todos') ? 'selected' : '' ?>>Ver Todos los Remitos</option>
                <option value="faltantes" <?= ($filter_status === 'faltantes') ? 'selected' : '' ?>>Falta Documento Físico</option>
            </select>
        </div>
    </div>

    <?php if (empty($remitos_por_obra_y_prov)): ?>
        <div class="card">
            <div class="card-body">
                <p class="text-center" style="padding: 3rem; color: var(--text-secondary);">
                    No se encontraron remitos con el filtro seleccionado.
                </p>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($remitos_por_obra_y_prov as $obra_id => $group): ?>
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
                                <span>Proveedor Emisor: <strong style="color: var(--primary);"><?= htmlspecialchars($prov_name) ?></strong> (<?= count($list) ?> remitos)</span>
                                <svg class="toggle-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="transition: transform 0.2s ease; transform: <?= $is_collapsed ? 'rotate(-90deg)' : 'rotate(0deg)' ?>;"><polyline points="6 9 12 15 18 9"/></svg>
                            </div>
                            
                            <div class="provider-collapse-content" style="padding: 1rem; <?= $is_collapsed ? 'display: none;' : '' ?>">
                                <div class="table-responsive" style="margin: 0;">
                                    <table class="table tabla-remitos">
                                        <thead>
                                            <tr>
                                                <th>Nro Remito</th>
                                                <th>Fecha descarga</th>
                                                <th>Recibido Por</th>
                                                <th>Estado Doc</th>
                                                <th class="text-center" style="width: 250px;">Acciones / Resolver</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($list as $r): 
                                                $es_pendiente = ($r['estado_remito'] === 'Pendiente de Remito Físico');
                                            ?>
                                                <tr>
                                                    <td class="text-bold" style="color: var(--sidebar-bg);">
                                                        <?= $r['nro_remito'] ? htmlspecialchars($r['nro_remito']) : '<span style="color:var(--danger-dark);">SIN NRO FÍSICO</span>' ?>
                                                    </td>
                                                    <td><?= date('d/m/Y', strtotime($r['fecha_entrega'])) ?></td>
                                                    <td><?= htmlspecialchars($r['recibido_por'] ?: 'S/D') ?></td>
                                                    <td>
                                                        <span class="badge <?= $es_pendiente ? 'badge-danger' : 'badge-success' ?>">
                                                            <?= $r['estado_remito'] ?>
                                                        </span>
                                                    </td>
                                                    <td class="text-center">
                                                        <?php if ($es_pendiente): ?>
                                                            <!-- Resolver rápido inline -->
                                                            <form action="remitos.php?action=resolver_rapido" method="POST" class="d-flex gap-2 justify-center">
                                                                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                                                <input type="text" name="nro_remito" class="form-control" style="padding: 0.25rem 0.5rem; font-size: 0.75rem; width: 110px;" placeholder="Nro Remito" required>
                                                                <button type="submit" class="btn btn-primary btn-sm" style="padding: 0.25rem 0.5rem; font-size: 0.75rem;">Guardar</button>
                                                            </form>
                                                        <?php else: ?>
                                                            <div class="d-flex gap-2 justify-center">
                                                                <a href="obra_detalle.php?id=<?= $r['obra_id'] ?>#remitos" class="btn btn-secondary btn-sm" style="flex-grow: 1;">Ver Detalle</a>
                                                                <a href="remitos.php?action=eliminar&id=<?= $r['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('¿Eliminar esta entrega de materiales? Se descontará del stock de la obra.');">
                                                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2M10 11v6M14 11v6"/></svg>
                                                                </a>
                                                            </div>
                                                        <?php endif; ?>
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

<!-- ACCIÓN: REGISTRAR REMITO -->
<?php elseif ($action === 'nuevo'): 
    $pre_obra_id = isset($_GET['obra_id']) ? intval($_GET['obra_id']) : 0;
    
    // Obtener todas las obras para el select
    $obras_stmt = $pdo->query("SELECT id, nombre, expediente FROM obras ORDER BY nombre ASC");
    $obras = $obras_stmt->fetchAll();

    // Obtener todos los ítems de compra activos para que el usuario elija qué está recibiendo
    $items_stmt = $pdo->query("
        SELECT cd.id, cd.compra_id, c.nro_compra, c.proveedor, cd.descripcion, cd.cantidad_comprada, cd.unidad, c.obra_id,
               COALESCE((
                   SELECT SUM(rd.cantidad_entregada)
                   FROM remito_detalles rd
                   WHERE rd.compra_detalle_id = cd.id
               ), 0) AS cantidad_entregada
        FROM compra_detalles cd
        JOIN compras c ON cd.compra_id = c.id
        ORDER BY cd.descripcion ASC
    ");
    $todos_items = $items_stmt->fetchAll();

    // Agruparlos por obra
    $items_por_obra = [];
    foreach ($todos_items as $item) {
        $pendiente = floatval($item['cantidad_comprada']) - floatval($item['cantidad_entregada']);
        $items_por_obra[$item['obra_id']][] = [
            'id' => $item['id'],
            'descripcion' => $item['descripcion'],
            'proveedor' => $item['proveedor'],
            'nro_compra' => $item['nro_compra'],
            'unidad' => $item['unidad'],
            'comprado' => floatval($item['cantidad_comprada']),
            'entregado' => floatval($item['cantidad_entregada']),
            'pendiente' => $pendiente
        ];
    }
?>
    <div class="page-header">
        <div class="page-title">
            <h1>Registrar Entrega de Materiales</h1>
            <p>Registre las descargas que se efectúan en obra y el control del remito (Paso 4)</p>
        </div>
        <div>
            <a href="remitos.php" class="btn btn-secondary" onclick="if(document.referrer && !document.referrer.includes(window.location.pathname)) { window.history.back(); return false; }">Cancelar</a>
        </div>
    </div>

    <!-- Script Inline para inyectar ítems de compra y hacer autocomplete dinámico -->
    <script>
        const itemsPorObra = <?= json_encode($items_por_obra) ?>;
        
        function actualizarItemsSelect(obraId) {
            const selectRows = document.querySelectorAll('.compra-item-select');
            selectRows.forEach(select => {
                const currentVal = select.value;
                select.innerHTML = '<option value="">-- Seleccionar Material Comprado --</option>';
                
                if (itemsPorObra[obraId]) {
                    itemsPorObra[obraId].forEach(item => {
                        const opt = document.createElement('option');
                        opt.value = item.id;
                        opt.textContent = `${item.descripcion} (${item.proveedor} OC: ${item.nro_compra}) - Pendiente: ${item.pendiente} ${item.unidad}`;
                        opt.setAttribute('data-unidad', item.unidad);
                        opt.setAttribute('data-pendiente', item.pendiente);
                        
                        if(currentVal == item.id) {
                            opt.selected = true;
                        }
                        select.appendChild(opt);
                    });
                }
            });
        }

        document.addEventListener('DOMContentLoaded', () => {
            const obraSelect = document.getElementById('obra_id');
            obraSelect.addEventListener('change', () => {
                actualizarItemsSelect(obraSelect.value);
            });
            
            // Inicializar
            if (obraSelect.value !== '') {
                actualizarItemsSelect(obraSelect.value);
            }
            
            // Lógica para añadir filas dinámicas específicas de remito
            const btnAddRemitoItem = document.getElementById('btn-add-remito-item');
            const remitoItemsContainer = document.getElementById('dynamic-remito-items');
            let itemIdx = 1;

            if (btnAddRemitoItem && remitoItemsContainer) {
                btnAddRemitoItem.addEventListener('click', () => {
                    const selectedObra = obraSelect.value;
                    if (selectedObra === '') {
                        alert("Por favor, seleccione una obra primero.");
                        return;
                    }
                    
                    const newRow = document.createElement('div');
                    newRow.className = 'dynamic-item-row';
                    newRow.innerHTML = `
                        <div class="form-group" style="grid-column: span 2;">
                            <label class="form-label">Material de Orden de Compra *</label>
                            <select name="items[${itemIdx}][compra_detalle_id]" class="form-control compra-item-select" required>
                                <!-- Opciones cargadas por JS -->
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Cantidad Entregada *</label>
                            <input type="number" step="0.01" name="items[${itemIdx}][cantidad_entregada]" class="form-control qty-input" placeholder="0.00" required>
                        </div>
                        <div style="padding-bottom: 5px;">
                            <button type="button" class="btn btn-danger btn-sm btn-remove-row" style="margin-top: 1.8rem;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2M10 11v6M14 11v6"/></svg>
                            </button>
                        </div>
                    `;
                    remitoItemsContainer.appendChild(newRow);
                    actualizarItemsSelect(selectedObra);
                    
                    // Lógica de alerta por si se excede la cantidad pendiente
                    bindQtyWarning(newRow);
                    
                    itemIdx++;
                });

                remitoItemsContainer.addEventListener('click', (e) => {
                    if (e.target.closest('.btn-remove-row')) {
                        const row = e.target.closest('.dynamic-item-row');
                        if (remitoItemsContainer.children.length > 1) {
                            row.remove();
                        } else {
                            alert("Debe registrar al menos un material.");
                        }
                    }
                });
                
                // Vincular fila inicial
                bindQtyWarning(remitoItemsContainer.querySelector('.dynamic-item-row'));
            }
        });
        
        function bindQtyWarning(row) {
            const select = row.querySelector('.compra-item-select');
            const qtyInput = row.querySelector('.qty-input');
            
            qtyInput.addEventListener('input', () => {
                const opt = select.options[select.selectedIndex];
                if(opt && opt.value !== '') {
                    const pendiente = parseFloat(opt.getAttribute('data-pendiente'));
                    const ingresado = parseFloat(qtyInput.value);
                    if(ingresado > pendiente) {
                        qtyInput.style.borderColor = 'var(--danger)';
                        qtyInput.style.backgroundColor = 'var(--danger-light)';
                    } else {
                        qtyInput.style.borderColor = '';
                        qtyInput.style.backgroundColor = '';
                    }
                }
            });
        }
    </script>

    <form action="remitos.php?action=guardar_nuevo" method="POST">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Datos del Remito y Recepción</h3>
            </div>
            <div class="card-body">
                <div class="form-grid">
                    <div class="form-group" style="grid-column: span 2;">
                        <label class="form-label">Obra en la que se descarga *</label>
                        <select name="obra_id" id="obra_id" class="form-control" required>
                            <option value="">-- Seleccione la Obra --</option>
                            <?php foreach ($obras as $o): ?>
                                <option value="<?= $o['id'] ?>" <?= ($pre_obra_id == $o['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($o['nombre']) ?> (Exp: <?= htmlspecialchars($o['expediente']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Número de Remito Físico</label>
                        <input type="text" name="nro_remito" id="nro_remito" class="form-control" placeholder="Ej. 0002-00048154 (Dejar vacío si no llegó)">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Estado de Documentación *</label>
                        <select name="estado_remito" id="estado_remito" class="form-control" required onchange="
                            const nro = document.getElementById('nro_remito');
                            if(this.value === 'Pendiente de Remito Físico') {
                                nro.placeholder = 'PENDIENTE (No llegó papel)';
                            } else {
                                nro.placeholder = 'Ej. 0002-00048154';
                            }
                        ">
                            <option value="Recibido" selected>Recibido Físicamente</option>
                            <option value="Pendiente de Remito Físico">Pendiente de Papel Físico (Alerta)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Fecha de Descarga / Entrega *</label>
                        <input type="date" name="fecha_entrega" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Recibido en Obra por (Capataz/Responsable)</label>
                        <input type="text" name="recibido_por" class="form-control" placeholder="Ej. Capataz Juan Gómez">
                    </div>

                    <div class="form-group full-width">
                        <label class="form-label">Observaciones de la Descarga</label>
                        <textarea name="observaciones" class="form-control" rows="2" placeholder="Describa si hubo materiales rotos, faltantes, o novedades del camión..."></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Materiales Descargados en esta Etapa</h3>
            </div>
            <div class="card-body">
                <p style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 1.5rem;">
                    Seleccione qué materiales de la Orden de Compra están ingresando en el predio y en qué cantidad.
                </p>
                
                <div id="dynamic-remito-items">
                    <!-- Fila Inicial de Item de Descarga -->
                    <div class="dynamic-item-row" style="grid-template-columns: 3fr 1fr auto;">
                        <div class="form-group">
                            <label class="form-label">Material de Orden de Compra *</label>
                            <select name="items[0][compra_detalle_id]" class="form-control compra-item-select" required>
                                <option value="">-- Seleccione Material Comprado --</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Cantidad Entregada *</label>
                            <input type="number" step="0.01" name="items[0][cantidad_entregada]" class="form-control qty-input" placeholder="0.00" required>
                        </div>
                        <div style="padding-bottom: 5px;">
                            <button type="button" class="btn btn-danger btn-sm btn-remove-row" style="margin-top: 1.8rem;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2M10 11v6M14 11v6"/></svg>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Botón de añadir material colocado abajo -->
                <div style="margin-top: 1.5rem; margin-bottom: 1rem;">
                    <button type="button" id="btn-add-remito-item" class="btn btn-secondary btn-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5v14"/></svg>
                        Registrar Otro Material
                    </button>
                </div>

                <div style="margin-top: 2rem; display: flex; justify-content: flex-end; gap: 1rem;">
                    <a href="remitos.php" class="btn btn-secondary" onclick="if(document.referrer && !document.referrer.includes(window.location.pathname)) { window.history.back(); return false; }">Cancelar</a>
                    <button type="submit" class="btn btn-primary">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                        Guardar Descarga y Controlar
                    </button>
                </div>
            </div>
        </div>
    </form>
<?php endif; ?>

<?php
require_once 'footer.php';
?>
