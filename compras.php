<?php
require_once 'bootstrap.php';
require_once 'reglas_db.php';
require_once 'proveedores_lib.php';

$action = isset($_GET['action']) ? $_GET['action'] : 'listar';
$message = '';
$msg_type = 'success';

/**
 * Panel "Total de la OC" que se recalcula en vivo mientras se cargan los ítems (alta y edición).
 * Solo ayuda a verificar: no se guarda nada y el servidor vuelve a validar todo al guardar.
 */
function panel_total_oc(string $id_contenedor): string
{
    ob_start(); ?>
                <div id="resumen-oc" style="margin-top: 1.5rem; padding: 1rem 1.25rem; border: 1px solid var(--border-color); border-radius: 8px; background-color: var(--card-bg);">
                    <div style="display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 1rem;">
                        <div>
                            <div style="font-size: 0.85rem; color: var(--text-secondary);">Total de la OC (<span id="total-items">0</span> ítem(s) incluido(s))</div>
                            <div id="total-oc" style="font-size: 1.7rem; font-weight: 700;">$ 0,00</div>
                        </div>
                        <div>
                            <label class="form-label" for="total-esperado">Verificar contra el total del papel (opcional, no se guarda)</label>
                            <input type="text" id="total-esperado" class="form-control" inputmode="decimal" autocomplete="off" placeholder="Ej. 1.250.000,00" style="max-width: 260px;">
                        </div>
                    </div>
                    <div id="total-aviso" style="margin-top: 0.75rem; font-size: 0.9rem;"></div>
                </div>
                <script>
                (function () {
                    const cont = document.getElementById('<?= $id_contenedor ?>');
                    const elTotal = document.getElementById('total-oc');
                    const elItems = document.getElementById('total-items');
                    const elAviso = document.getElementById('total-aviso');
                    const elEsperado = document.getElementById('total-esperado');
                    const fmt = c => '$ ' + (c / 100).toLocaleString('es-AR', {minimumFractionDigits: 2, maximumFractionDigits: 2});

                    // Acepta "1.250.000,50", "1250000.50" o "1250000"
                    function leerMonto(txt) {
                        let t = String(txt).replace(/[\s$]/g, '');
                        if (t === '') return null;
                        if (t.includes(',')) t = t.replace(/\./g, '').replace(',', '.');
                        else if (/^\d{1,3}(\.\d{3})+$/.test(t)) t = t.replace(/\./g, '');
                        const n = parseFloat(t);
                        return isNaN(n) ? NaN : Math.round(n * 100);
                    }

                    function recalcular() {
                        let total = 0, items = 0, incompletos = 0, excedidos = 0;
                        cont.querySelectorAll('.dynamic-item-row').forEach(row => {
                            const chk = row.querySelector('input[type="checkbox"]');
                            const q = row.querySelector('input[name$="[cantidad_comprada]"]');
                            const p = row.querySelector('input[name$="[precio_unitario]"]');
                            let sub = row.querySelector('.subtotal-fila');
                            if (!sub && p) {
                                sub = document.createElement('div');
                                sub.className = 'subtotal-fila';
                                sub.style.cssText = 'font-size: 0.8rem; color: var(--text-secondary); margin-top: 0.25rem;';
                                p.parentElement.appendChild(sub);
                            }
                            if (sub) sub.textContent = '';
                            if (!chk || !chk.checked) return;
                            items++;
                            const qv = parseFloat(q && q.value), pv = parseFloat(p && p.value);
                            if (!(qv > 0) || !(pv > 0)) { incompletos++; return; }
                            const max = parseFloat(q.max);
                            if (!isNaN(max) && qv > max + 0.0001) excedidos++;
                            const cent = Math.round(Math.round(qv * 100) * Math.round(pv * 100) / 100);
                            total += cent;
                            if (sub) sub.textContent = 'Subtotal: ' + fmt(cent);
                        });
                        elTotal.textContent = fmt(total);
                        elItems.textContent = items;

                        const avisos = [];
                        if (incompletos > 0) avisos.push('<span style="color: var(--danger-dark);">⚠ ' + incompletos + ' ítem(s) sin cantidad o sin precio mayor que cero.</span>');
                        if (excedidos > 0) avisos.push('<span style="color: var(--danger-dark);">⚠ ' + excedidos + ' ítem(s) con cantidad mayor a lo pendiente de la solicitud.</span>');
                        const esperado = leerMonto(elEsperado.value);
                        if (esperado !== null) {
                            if (isNaN(esperado)) {
                                avisos.push('<span style="color: var(--danger-dark);">⚠ No se entiende el total ingresado.</span>');
                            } else if (esperado === total) {
                                avisos.push('<span style="color: var(--success-dark);">✔ El total coincide con el del papel.</span>');
                            } else {
                                const dif = total - esperado;
                                avisos.push('<span style="color: var(--danger-dark);">⚠ No coincide con el papel: diferencia de ' + (dif > 0 ? '+' : '−') + fmt(Math.abs(dif)) + ' (la OC suma ' + fmt(total) + ' y el papel dice ' + fmt(esperado) + ').</span>');
                            }
                        }
                        elAviso.innerHTML = avisos.join('<br>');
                    }

                    ['input', 'change'].forEach(ev => cont.addEventListener(ev, recalcular));
                    elEsperado.addEventListener('input', recalcular);
                    new MutationObserver(recalcular).observe(cont, {childList: true});
                    document.addEventListener('DOMContentLoaded', recalcular);
                    recalcular();
                })();
                </script>
<?php
    return ob_get_clean();
}

// ¿Se usa la lista de proveedores (migración hecha) o todavía el campo de texto de siempre?
$usa_prov = proveedores_activo($pdo);
$lista_prov = $usa_prov ? proveedores_listar($pdo) : [];

// Modo texto: proveedores distintos registrados previamente en compras (para sugerir)
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
        $proveedor = $usa_prov ? trim($_POST['proveedor_id'] ?? '') : trim($_POST['proveedor'] ?? '');
        $fecha_compra = $_POST['fecha_compra'];
        $items = isset($_POST['items']) ? $_POST['items'] : [];

        if ($obra_id <= 0 || empty($nro_compra) || empty($proveedor) || empty($fecha_compra) || empty($items)) {
            $message = "Todos los campos principales y al menos un ítem son obligatorios.";
            $msg_type = "danger";
            $action = "nuevo";
        } else {
            try {
                $pdo->beginTransaction();

                // 0. Validaciones del servidor (si algo falla no se guarda nada)
                $items_ok = preparar_items_oc($pdo, $obra_id, $items, 0);
                $advertencias = validar_oc($pdo, $nro_compra, $fecha_compra);

                // 1. Insertar Cabecera de Compra
                if ($usa_prov) {
                    $prov = proveedor_resolver($pdo, $_POST);   // crea el proveedor si se eligió "agregar nuevo"
                    $stmt = $pdo->prepare("INSERT INTO compras (obra_id, nro_compra, proveedor, proveedor_id, fecha_compra, estado) VALUES (?, ?, ?, ?, ?, 'Adjudicado')");
                    $stmt->execute([$obra_id, $nro_compra, $prov['razon_social'], $prov['id'], $fecha_compra]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO compras (obra_id, nro_compra, proveedor, fecha_compra, estado) VALUES (?, ?, ?, ?, 'Adjudicado')");
                    $stmt->execute([$obra_id, $nro_compra, $proveedor, $fecha_compra]);
                }
                $compra_id = $pdo->lastInsertId();

                // 2. Insertar Detalles de Compra (descripción y unidad salen de la solicitud de la obra)
                $stmtItem = $pdo->prepare("
                    INSERT INTO compra_detalles (compra_id, material_solicitado_id, descripcion, cantidad_comprada, unidad, precio_unitario) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                foreach ($items_ok as $it) {
                    $stmtItem->execute([$compra_id, $it['material_id'], $it['descripcion'], $it['cantidad'], $it['unidad'], $it['precio']]);
                }

                $pdo->commit();
                $message = "La orden de compra se registró con éxito." . ($advertencias ? ' ' . implode(' ', $advertencias) : '');
                $msg_type = "success";
                $action = "listar";
            } catch (ErrorValidacion $e) {
                $pdo->rollBack();
                $message = $e->getMessage();
                $msg_type = "danger";
                $action = "nuevo";
            } catch (\PDOException $e) {
                $pdo->rollBack();
                $message = error_generico($e, "Error al guardar la compra");
                $msg_type = "danger";
                $action = "nuevo";
            }
        }
    }
    
    if ($action === 'guardar_editar') {
        $compra_id = intval($_POST['compra_id']);
        $nro_compra = trim($_POST['nro_compra']);
        $proveedor = $usa_prov ? trim($_POST['proveedor_id'] ?? '') : trim($_POST['proveedor'] ?? '');
        $fecha_compra = $_POST['fecha_compra'];
        $items = isset($_POST['items']) ? $_POST['items'] : [];

        if ($compra_id <= 0 || empty($nro_compra) || empty($proveedor) || empty($fecha_compra) || empty($items)) {
            $message = "Todos los campos principales y al menos un ítem son obligatorios.";
            $msg_type = "danger";
            $action = "editar";
        } else {
            try {
                $pdo->beginTransaction();

                // 0. Validaciones del servidor (si algo falla no se guarda nada)
                $stObra = $pdo->prepare("SELECT obra_id FROM compras WHERE id = ?");
                $stObra->execute([$compra_id]);
                $items_ok = preparar_items_oc($pdo, (int)$stObra->fetchColumn(), $items, $compra_id);
                $advertencias = validar_oc($pdo, $nro_compra, $fecha_compra, $compra_id);
                // Al cambiar la fecha se revisa contra las facturas y remitos vinculados
                validar_fecha_oc_vs_vinculados($pdo, $compra_id, $nro_compra, $fecha_compra);

                // 1. Actualizar Cabecera
                if ($usa_prov) {
                    $prov = proveedor_resolver($pdo, $_POST);
                    validar_cambio_proveedor_oc($pdo, $compra_id, $prov['razon_social']);
                    $stmt = $pdo->prepare("UPDATE compras SET nro_compra = ?, proveedor = ?, proveedor_id = ?, fecha_compra = ? WHERE id = ?");
                    $stmt->execute([$nro_compra, $prov['razon_social'], $prov['id'], $fecha_compra, $compra_id]);
                    // Las facturas de esta OC siguen al proveedor de la OC
                    $pdo->prepare("UPDATE facturas SET proveedor_id = ? WHERE compra_id = ?")->execute([$prov['id'], $compra_id]);
                } else {
                    validar_cambio_proveedor_oc($pdo, $compra_id, $proveedor);
                    $stmt = $pdo->prepare("UPDATE compras SET nro_compra = ?, proveedor = ?, fecha_compra = ? WHERE id = ?");
                    $stmt->execute([$nro_compra, $proveedor, $fecha_compra, $compra_id]);
                }

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

                foreach ($items_ok as $it) {
                    if ($it['id'] > 0) {
                        $stmtUpdate->execute([$it['material_id'], $it['descripcion'], $it['cantidad'], $it['unidad'], $it['precio'], $it['id'], $compra_id]);
                        $form_ids[] = $it['id'];
                    } else {
                        $stmtInsert->execute([$compra_id, $it['material_id'], $it['descripcion'], $it['cantidad'], $it['unidad'], $it['precio']]);
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
                $message = "La orden de compra ha sido actualizada correctamente." . ($advertencias ? ' ' . implode(' ', $advertencias) : '');
                $msg_type = "success";
                $action = "listar";
            } catch (ErrorValidacion $e) {
                $pdo->rollBack();
                $message = $e->getMessage();
                $msg_type = "danger";
                $action = "editar";
            } catch (\PDOException $e) {
                $pdo->rollBack();
                $message = error_generico($e, "Error al actualizar la compra");
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
        $message = error_generico($e, "Error al eliminar");
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
                                                <a href="compras.php?action=eliminar&id=<?= $c['id'] ?><?= csrf_url() ?>" class="btn btn-danger btn-sm" onclick="return confirm('¿Está seguro de eliminar esta orden de compra? Se eliminarán también las facturas y los remitos vinculados.');" title="Eliminar">
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
                     AND ms.cantidad > COALESCE((
                         SELECT SUM(cd.cantidad_comprada)
                         FROM compra_detalles cd
                         WHERE cd.material_solicitado_id = ms.id
                     ), 0)
               ) AS materiales_pendientes_count
        FROM obras o 
        ORDER BY o.nombre ASC
    ");
    $obras = $obras_stmt->fetchAll();

    // Materiales de la solicitud de cada obra que todavía tienen saldo pendiente de comprar
    $mat_stmt = $pdo->query("
        SELECT ms.id, ms.obra_id, ms.descripcion, ms.cantidad, ms.unidad,
               COALESCE((SELECT SUM(cd.cantidad_comprada) FROM compra_detalles cd WHERE cd.material_solicitado_id = ms.id), 0) AS comprado
        FROM materiales_solicitados ms
        ORDER BY ms.descripcion ASC
    ");
    $materiales_por_obra = [];
    foreach ($mat_stmt as $m) {
        $pendiente = round((float)$m['cantidad'] - (float)$m['comprado'], 2);
        if ($pendiente > 0) {
            $materiales_por_obra[$m['obra_id']][] = [
                'id' => (int)$m['id'],
                'descripcion' => $m['descripcion'],
                'cantidad' => (float)$m['cantidad'],
                'pendiente' => $pendiente,
                'unidad' => $m['unidad']
            ];
        }
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
                container.innerHTML = '<p style="color: var(--text-secondary); font-size: 0.9rem; padding: 1rem; text-align: center;">Esta obra no tiene materiales pendientes de comprar en su solicitud.</p>';
            }
        }

        function esc(t) {
            return String(t).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
        }

        // Cada fila es un material de la SOLICITUD de la obra: no se escribe descripción a mano.
        function agregarFilaMaterial(mat) {
            const container = document.getElementById('dynamic-compra-items');

            const row = document.createElement('div');
            row.className = 'dynamic-item-row';
            row.style.gridTemplateColumns = '55px 3fr 1fr 100px 1fr auto';
            row.style.alignItems = 'center';
            row.style.opacity = '0.5';
            row.dataset.texto = mat.descripcion.toLowerCase();

            const parcial = mat.pendiente < mat.cantidad;
            row.innerHTML = `
                <div class="form-group text-center">
                    <label class="form-label" style="font-size:0.75rem;">¿Incluir?</label>
                    <input type="checkbox" name="items[${itemIdx}][incluir]" value="1" style="width: 20px; height: 20px; margin: 0 auto; cursor: pointer;" onchange="toggleRowInputs(this)">
                </div>
                <input type="hidden" name="items[${itemIdx}][material_solicitado_id]" value="${mat.id}">
                <div class="form-group">
                    <label class="form-label">Material de la solicitud</label>
                    <div style="font-weight: 600; padding-top: 0.4rem;">${esc(mat.descripcion)}</div>
                    <div style="font-size: 0.8rem; color: var(--text-secondary);">Solicitado: ${mat.cantidad} ${esc(mat.unidad)}${parcial ? ' · Pendiente de comprar: ' + mat.pendiente : ''}</div>
                </div>
                <div class="form-group">
                    <label class="form-label">Cantidad Comprada *</label>
                    <input type="number" step="0.01" min="0.01" max="${mat.pendiente}" name="items[${itemIdx}][cantidad_comprada]" class="form-control" value="${mat.pendiente}" placeholder="0.00" disabled>
                </div>
                <div class="form-group">
                    <label class="form-label">Unidad</label>
                    <span class="badge badge-secondary" style="margin-top: 0.5rem; display: block; text-align: center; padding: 0.6rem; font-size: 0.75rem;">${esc(mat.unidad)}</span>
                </div>
                <div class="form-group">
                    <label class="form-label">Precio Unitario ($) *</label>
                    <input type="number" step="0.01" min="0.01" name="items[${itemIdx}][precio_unitario]" class="form-control" placeholder="0.00" disabled>
                </div>
                <div style="padding-bottom: 5px;">
                    <button type="button" class="btn btn-danger btn-sm btn-remove-row" style="margin-top: 1.8rem;" title="Quitar de la lista">
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
                        row.style.display = (row.dataset.texto || '').includes(query) ? '' : 'none';
                    });
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
        <?= csrf_campo() ?>
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
                        <input type="text" name="nro_compra" class="form-control" placeholder="Ej. 2026-45" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Proveedor Adjudicado *</label>
                        <?php if ($usa_prov): ?>
                            <?= campo_proveedor($lista_prov, $_POST['proveedor_id'] ?? '', $_POST['proveedor_nuevo'] ?? '') ?>
                        <?php else: ?>
                            <input type="text" name="proveedor" class="form-control" list="proveedores-list" placeholder="Ej. Corralón Central S.A." required>
                        <?php endif; ?>
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
                    Se listan los materiales de la solicitud de la obra que todavía tienen cantidad pendiente de comprar. Tildá los que corresponden a esta orden de compra, ajustá la cantidad si no se compra todo e ingresá el precio unitario adjudicado.
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

<?= panel_total_oc('dynamic-compra-items') ?>

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

    // Materiales de la solicitud de la obra, con lo ya adjudicado en OTRAS órdenes de compra
    $stmtMat = $pdo->prepare("
        SELECT ms.id, ms.descripcion, ms.cantidad, ms.unidad,
               COALESCE((SELECT SUM(cd.cantidad_comprada) FROM compra_detalles cd
                         WHERE cd.material_solicitado_id = ms.id AND cd.compra_id <> ?), 0) AS en_otras,
               EXISTS (SELECT 1 FROM compra_detalles cd WHERE cd.material_solicitado_id = ms.id AND cd.compra_id = ?) AS en_esta
        FROM materiales_solicitados ms
        WHERE ms.obra_id = ?
        ORDER BY ms.descripcion ASC
    ");
    $stmtMat->execute([$id, $id, $compra['obra_id']]);
    $materiales_obra = [];            // id => datos (todos los de la obra)
    $materiales_disponibles = [];     // los que se pueden agregar: no están en esta OC y tienen saldo
    foreach ($stmtMat as $m) {
        $m['pendiente'] = round((float)$m['cantidad'] - (float)$m['en_otras'], 2);
        $materiales_obra[$m['id']] = $m;
        if (!$m['en_esta'] && $m['pendiente'] > 0) {
            $materiales_disponibles[] = $m;
        }
    }
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
        <?= csrf_campo() ?>
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
                        <?php if ($usa_prov): ?>
                            <?= campo_proveedor($lista_prov, $_POST['proveedor_id'] ?? ($compra['proveedor_id'] ?? ''), $_POST['proveedor_nuevo'] ?? '') ?>
                        <?php else: ?>
                            <input type="text" name="proveedor" class="form-control" list="proveedores-list" value="<?= htmlspecialchars($compra['proveedor']) ?>" required>
                        <?php endif; ?>
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
                    Modifique las cantidades y precios, o agregue materiales de la solicitud de la obra. Si desmarca la casilla "Incluir", ese ítem se eliminará de la orden de compra.
                </p>
                
                <div id="dynamic-compra-items-edit">
                    <?php 
                    $idx = 0;
                    foreach ($items_compra as $item): 
                        $mat_vinculado = $item['material_solicitado_id'] !== null ? ($materiales_obra[$item['material_solicitado_id']] ?? null) : null;
                    ?>
                        <div class="dynamic-item-row" style="grid-template-columns: 55px 3fr 1fr 100px 1fr auto; align-items: center;">
                            <input type="hidden" name="items[<?= $idx ?>][id]" value="<?= (int)$item['id'] ?>">
                            <div class="form-group text-center">
                                <label class="form-label" style="font-size:0.75rem;">¿Incluir?</label>
                                <input type="checkbox" name="items[<?= $idx ?>][incluir]" value="1" checked style="width: 20px; height: 20px; margin: 0 auto; cursor: pointer;" onchange="toggleRowInputs(this)">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Material de la solicitud</label>
                                <?php if ($mat_vinculado): ?>
                                    <input type="hidden" name="items[<?= $idx ?>][material_solicitado_id]" value="<?= (int)$mat_vinculado['id'] ?>">
                                    <div style="font-weight: 600; padding-top: 0.4rem;"><?= h($mat_vinculado['descripcion']) ?></div>
                                    <div style="font-size: 0.8rem; color: var(--text-secondary);">Solicitado: <?= h(floatval($mat_vinculado['cantidad'])) ?> <?= h($mat_vinculado['unidad']) ?> · Se puede comprar hasta: <?= h($mat_vinculado['pendiente']) ?></div>
                                <?php else: ?>
                                    <div style="font-weight: 600; padding-top: 0.4rem;"><?= h($item['descripcion']) ?></div>
                                    <div style="font-size: 0.8rem; color: var(--danger-dark);">Ítem antiguo sin vincular a la solicitud. Podés vincularlo:</div>
                                    <select name="items[<?= $idx ?>][material_solicitado_id]" class="form-control" style="margin-top: .25rem;" onchange="actualizarUnidadEtiqueta(this)">
                                        <option value="">-- Dejar sin vincular --</option>
                                        <?php foreach ($materiales_disponibles as $mat): ?>
                                            <option value="<?= (int)$mat['id'] ?>" data-unidad="<?= h($mat['unidad']) ?>">
                                                <?= h($mat['descripcion']) ?> (Solicitado: <?= h(floatval($mat['cantidad'])) ?> <?= h($mat['unidad']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Cantidad *</label>
                                <input type="number" step="0.01" min="0.01" name="items[<?= $idx ?>][cantidad_comprada]" class="form-control" value="<?= floatval($item['cantidad_comprada']) ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Unidad</label>
                                <span class="badge badge-secondary unidad-label" style="margin-top: 0.5rem; display: block; text-align: center; padding: 0.6rem; font-size: 0.75rem;"><?= h($item['unidad']) ?></span>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Precio Unitario ($) *</label>
                                <input type="number" step="0.01" min="0.01" name="items[<?= $idx ?>][precio_unitario]" class="form-control" value="<?= floatval($item['precio_unitario']) ?>" required>
                            </div>
                            
                            <div style="padding-bottom: 5px;">
                                <button type="button" class="btn btn-danger btn-sm btn-remove-row" style="margin-top: 1.8rem;" title="Quitar este ítem de la OC">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2M10 11v6M14 11v6"/></svg>
                                </button>
                            </div>
                        </div>
                    <?php 
                        $idx++;
                    endforeach; 
                    ?>
                </div>

                <!-- Botón de añadir material de la solicitud -->
                <div style="margin-top: 1.5rem; margin-bottom: 1rem;">
                    <button type="button" id="btn-add-compra-item-edit" class="btn btn-secondary btn-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5v14"/></svg>
                        Agregar material de la solicitud
                    </button>
                </div>

                <!-- Script local para dinámicas de edición de compras -->
                <script>
                    const materialesDisponibles = <?= json_encode(array_map(fn($m) => [
                        'id' => (int)$m['id'], 'descripcion' => $m['descripcion'], 'cantidad' => (float)$m['cantidad'],
                        'pendiente' => (float)$m['pendiente'], 'unidad' => $m['unidad'],
                    ], $materiales_disponibles), JSON_UNESCAPED_UNICODE) ?>;

                    function esc(t) {
                        return String(t).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
                    }

                    function actualizarUnidadEtiqueta(select) {
                        const opt = select.options[select.selectedIndex];
                        const label = select.closest('.dynamic-item-row').querySelector('.unidad-label');
                        if (opt && opt.value !== '' && opt.getAttribute('data-unidad')) {
                            label.textContent = opt.getAttribute('data-unidad');
                        }
                    }

                    // Al elegir otro material en una fila nueva: unidad y cantidad pendiente
                    function alElegirMaterial(select) {
                        actualizarUnidadEtiqueta(select);
                        const opt = select.options[select.selectedIndex];
                        const cant = select.closest('.dynamic-item-row').querySelector('input[name$="[cantidad_comprada]"]');
                        if (opt && cant) cant.value = opt.getAttribute('data-pendiente') || '';
                    }

                    function toggleRowInputs(checkbox) {
                        const row = checkbox.closest('.dynamic-item-row');
                        const inputs = row.querySelectorAll('input[type="number"], select');
                        inputs.forEach(input => {
                            input.disabled = !checkbox.checked;
                            if (!checkbox.checked) {
                                input.removeAttribute('required');
                                row.style.opacity = '0.5';
                            } else {
                                if (input.type === 'number') input.setAttribute('required', 'required');
                                row.style.opacity = '1';
                            }
                        });
                    }

                    // Materiales de la solicitud que todavía no figuran como fila en esta pantalla
                    function materialesLibres() {
                        const usados = new Set(Array.from(document.querySelectorAll('#dynamic-compra-items-edit input[name$="[material_solicitado_id]"], #dynamic-compra-items-edit select[name$="[material_solicitado_id]"]'))
                            .map(el => el.value).filter(v => v !== ''));
                        return materialesDisponibles.filter(m => !usados.has(String(m.id)));
                    }

                    document.addEventListener('DOMContentLoaded', () => {
                        const btnAdd = document.getElementById('btn-add-compra-item-edit');
                        const container = document.getElementById('dynamic-compra-items-edit');
                        let editItemIdx = <?= $idx ?>;

                        if (btnAdd && container) {
                            btnAdd.addEventListener('click', () => {
                                const libres = materialesLibres();
                                if (libres.length === 0) {
                                    alert('No quedan materiales de la solicitud pendientes de comprar para agregar a esta OC.');
                                    return;
                                }
                                const opciones = libres.map(m =>
                                    `<option value="${m.id}" data-unidad="${esc(m.unidad)}" data-pendiente="${m.pendiente}">${esc(m.descripcion)} (Solicitado: ${m.cantidad} ${esc(m.unidad)}${m.pendiente < m.cantidad ? ' · Pendiente: ' + m.pendiente : ''})</option>`
                                ).join('');
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
                                        <label class="form-label">Material de la solicitud *</label>
                                        <select name="items[${editItemIdx}][material_solicitado_id]" class="form-control" required onchange="alElegirMaterial(this)">
                                            ${opciones}
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Cantidad *</label>
                                        <input type="number" step="0.01" min="0.01" name="items[${editItemIdx}][cantidad_comprada]" class="form-control" value="${libres[0].pendiente}" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Unidad</label>
                                        <span class="badge badge-secondary unidad-label" style="margin-top: 0.5rem; display: block; text-align: center; padding: 0.6rem; font-size: 0.75rem;">${esc(libres[0].unidad)}</span>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Precio Unitario ($) *</label>
                                        <input type="number" step="0.01" min="0.01" name="items[${editItemIdx}][precio_unitario]" class="form-control" placeholder="0.00" required>
                                    </div>
                                    <div style="padding-bottom: 5px;">
                                        <button type="button" class="btn btn-danger btn-sm btn-remove-row" style="margin-top: 1.8rem;" title="Quitar">
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
                
<?= panel_total_oc('dynamic-compra-items-edit') ?>

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
