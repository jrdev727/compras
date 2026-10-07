<?php
require_once 'bootstrap.php';
require_once 'reglas_db.php';

$action = isset($_GET['action']) ? $_GET['action'] : 'listar';

/** Devuelve la obra que ya usa ese n.º de concurso (distinta de $excluir_id), o false si está libre. */
function concurso_en_uso(PDO $pdo, string $nro_concurso, int $excluir_id)
{
    $st = $pdo->prepare("SELECT nombre, expediente FROM obras WHERE nro_concurso = ? AND id <> ? LIMIT 1");
    $st->execute([$nro_concurso, $excluir_id]);
    return $st->fetch();
}
$message = '';
$msg_type = 'success';

// Procesar Formularios POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'guardar_nuevo') {
        $nombre = trim($_POST['nombre']);
        $expediente = trim($_POST['expediente']);
        $nro_concurso = trim($_POST['nro_concurso'] ?? '');
        $descripcion = trim($_POST['descripcion']);
        $estado = $_POST['estado'];
        $materiales = isset($_POST['materiales']) ? $_POST['materiales'] : [];

        if (empty($nombre) || empty($expediente)) {
            $message = "El nombre y número de expediente son obligatorios.";
            $msg_type = "danger";
            $action = "nuevo";
        } elseif ($nro_concurso === '' || mb_strlen($nro_concurso) > 50) {
            $message = "El número de concurso de precios es obligatorio (hasta 50 caracteres).";
            $msg_type = "danger";
            $action = "nuevo";
        } elseif ($err_mat = materiales_error($materiales)) {
            $message = $err_mat;
            $msg_type = "danger";
            $action = "nuevo";
        } elseif ($otra = concurso_en_uso($pdo, $nro_concurso, 0)) {
            $message = "El número de concurso \"$nro_concurso\" ya está cargado en la obra \"{$otra['nombre']}\" (expediente {$otra['expediente']}). Cada concurso debe ser único.";
            $msg_type = "danger";
            $action = "nuevo";
        } else {
            try {
                $pdo->beginTransaction();

                // 1. Insertar Obra
                $stmt = $pdo->prepare("INSERT INTO obras (nombre, expediente, nro_concurso, descripcion, estado) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$nombre, $expediente, $nro_concurso, $descripcion, $estado]);
                $obra_id = $pdo->lastInsertId();

                // 2. Insertar Materiales Solicitados
                if (!empty($materiales)) {
                    $stmtMat = $pdo->prepare("INSERT INTO materiales_solicitados (obra_id, descripcion, cantidad, unidad) VALUES (?, ?, ?, ?)");
                    foreach ($materiales as $mat) {
                        if (!empty($mat['descripcion']) && $mat['cantidad'] > 0) {
                            $stmtMat->execute([
                                $obra_id,
                                trim($mat['descripcion']),
                                floatval($mat['cantidad']),
                                trim($mat['unidad'])
                            ]);
                        }
                    }
                }

                $pdo->commit();
                $message = "La obra y su listado de materiales fueron creados correctamente.";
                $msg_type = "success";
                $action = "listar";
            } catch (\PDOException $e) {
                $pdo->rollBack();
                if ($e->getCode() == 23000) { // Duplicate entry for unique key
                    $message = "Error: El número de expediente o de concurso ya está registrado en otra obra.";
                } else {
                    $message = error_generico($e, "Error al guardar la obra");
                }
                $msg_type = "danger";
                $action = "nuevo";
            }
        }
    }
    
    if ($action === 'guardar_editar') {
        $id = intval($_POST['id']);
        $nombre = trim($_POST['nombre']);
        $expediente = trim($_POST['expediente']);
        $nro_concurso = trim($_POST['nro_concurso'] ?? '');
        $descripcion = trim($_POST['descripcion']);
        $estado = $_POST['estado'];
        $materiales = isset($_POST['materiales']) ? $_POST['materiales'] : [];

        if (empty($nombre) || empty($expediente)) {
            $message = "El nombre y número de expediente son obligatorios.";
            $msg_type = "danger";
            $action = "editar";
        } elseif ($nro_concurso === '' || mb_strlen($nro_concurso) > 50) {
            $message = "El número de concurso de precios es obligatorio (hasta 50 caracteres).";
            $msg_type = "danger";
            $action = "editar";
        } elseif ($err_mat = materiales_error($materiales)) {
            $message = $err_mat;
            $msg_type = "danger";
            $action = "editar";
        } elseif ($otra = concurso_en_uso($pdo, $nro_concurso, $id)) {
            $message = "El número de concurso \"$nro_concurso\" ya está cargado en la obra \"{$otra['nombre']}\" (expediente {$otra['expediente']}). Cada concurso debe ser único.";
            $msg_type = "danger";
            $action = "editar";
        } else {
            try {
                $pdo->beginTransaction();

                // 1. Actualizar datos de la obra
                $stmt = $pdo->prepare("UPDATE obras SET nombre = ?, expediente = ?, nro_concurso = ?, descripcion = ?, estado = ? WHERE id = ?");
                $stmt->execute([$nombre, $expediente, $nro_concurso, $descripcion, $estado, $id]);

                // 2. Gestionar listado de materiales
                $stmtGetIds = $pdo->prepare("SELECT id FROM materiales_solicitados WHERE obra_id = ?");
                $stmtGetIds->execute([$id]);
                $existing_ids = $stmtGetIds->fetchAll(PDO::FETCH_COLUMN);

                $form_ids = [];
                if (!empty($materiales)) {
                    $stmtInsert = $pdo->prepare("INSERT INTO materiales_solicitados (obra_id, descripcion, cantidad, unidad) VALUES (?, ?, ?, ?)");
                    $stmtUpdate = $pdo->prepare("UPDATE materiales_solicitados SET descripcion = ?, cantidad = ?, unidad = ? WHERE id = ? AND obra_id = ?");

                    foreach ($materiales as $mat) {
                        $mat_id = isset($mat['id']) ? intval($mat['id']) : 0;
                        if ($mat_id > 0) {
                            // Actualizar existente
                            $stmtUpdate->execute([
                                trim($mat['descripcion']),
                                floatval($mat['cantidad']),
                                trim($mat['unidad']),
                                $mat_id,
                                $id
                            ]);
                            $form_ids[] = $mat_id;
                        } else {
                            // Insertar nuevo
                            if (!empty($mat['descripcion']) && $mat['cantidad'] > 0) {
                                $stmtInsert->execute([
                                    $id,
                                    trim($mat['descripcion']),
                                    floatval($mat['cantidad']),
                                    trim($mat['unidad'])
                                ]);
                            }
                        }
                    }
                }

                // Eliminar materiales removidos
                $ids_to_delete = array_diff($existing_ids, $form_ids);
                if (!empty($ids_to_delete)) {
                    $in = str_repeat('?,', count($ids_to_delete) - 1) . '?';
                    $stmtDel = $pdo->prepare("DELETE FROM materiales_solicitados WHERE id IN ($in) AND obra_id = ?");
                    $params = array_merge(array_values($ids_to_delete), [$id]);
                    $stmtDel->execute($params);
                }

                $pdo->commit();
                $message = "Los datos de la obra y su listado de materiales fueron actualizados correctamente.";
                $msg_type = "success";
                $action = "listar";
            } catch (\PDOException $e) {
                $pdo->rollBack();
                if ($e->getCode() == 23000) {
                    $message = "Error: El número de expediente o de concurso ya está registrado en otra obra.";
                } else {
                    $message = error_generico($e, "Error al actualizar la obra");
                }
                $msg_type = "danger";
                $action = "editar";
            }
        }
    }
}

// Procesar GET para eliminar
if ($action === 'eliminar') {
    $id = intval($_GET['id']);
    try {
        $stmt = $pdo->prepare("DELETE FROM obras WHERE id = ?");
        $stmt->execute([$id]);
        $message = "La obra fue eliminada correctamente del sistema.";
        $msg_type = "success";
    } catch (\PDOException $e) {
        $message = error_generico($e, "Error al eliminar la obra");
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

<!-- ACCIÓN: LISTAR OBRAS -->
<?php if ($action === 'listar'): 
    // Obtener obras con conteo de materiales y compras
    $query = $pdo->query("
        SELECT o.*, 
               (SELECT COUNT(*) FROM materiales_solicitados WHERE obra_id = o.id) AS cant_materiales,
               (SELECT COUNT(*) FROM compras WHERE obra_id = o.id) AS cant_compras
        FROM obras o 
        ORDER BY o.fecha_creacion DESC
    ");
    $obras = $query->fetchAll();
?>
    <div class="page-header">
        <div class="page-title">
            <h1>Obras e Hitos Públicos</h1>
            <p>Registro y seguimiento de expedientes y materiales solicitados</p>
        </div>
        <div>
            <a href="obras.php?action=nuevo" class="btn btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5v14"/></svg>
                Nueva Obra
            </a>
        </div>
    </div>

    <!-- Barra de Búsqueda -->
    <div class="filter-bar">
        <div class="form-group" style="flex-grow: 1;">
            <label class="form-label">Buscar Obra o Expediente</label>
            <input type="text" class="form-control table-search" data-table="tabla-obras" placeholder="Escriba para filtrar...">
        </div>
    </div>

    <div class="card">
        <div class="card-body" style="padding: 0;">
            <div class="table-responsive">
                <table class="table" id="tabla-obras">
                    <tbody>
                        <?php if (empty($obras)): ?>
                            <tr>
                                <td class="text-center" style="padding: 3rem; color: var(--text-secondary);">
                                    No hay obras registradas en el sistema. ¡Crea una nueva para comenzar!
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($obras as $o): 
                                $badge_class = 'badge-secondary';
                                if ($o['estado'] === 'Activa') $badge_class = 'badge-success';
                                elseif ($o['estado'] === 'Pausada') $badge_class = 'badge-warning';
                                elseif ($o['estado'] === 'Finalizada') $badge_class = 'badge-info';
                            ?>
                                <tr>
                                    <td style="padding: 0; border-bottom: 1px solid var(--gray-200);">
                                        <div class="obra-row-card" style="display: flex; justify-content: space-between; align-items: center; padding: 1.25rem 1.5rem; width: 100%; flex-wrap: wrap; gap: 1rem;">
                                            <div style="flex: 1 1 500px; min-width: 0;">
                                                <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.35rem; flex-wrap: wrap;">
                                                    <a href="obra_detalle.php?id=<?= $o['id'] ?>" class="text-bold" style="color: var(--primary); text-decoration: none; font-size: 1.1rem; line-height: 1.2; font-weight: 600;">
                                                        <?= htmlspecialchars($o['nombre']) ?>
                                                    </a>
                                                    <span class="badge <?= $badge_class ?>" style="font-size: 0.75rem; padding: 0.15rem 0.5rem;"><?= $o['estado'] ?></span>
                                                </div>
                                                <div style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 0.5rem; max-width: 100%;">
                                                    <?= htmlspecialchars($o['descripcion'] ?: 'Sin descripción') ?>
                                                </div>
                                                <div style="display: flex; gap: 1rem; font-size: 0.8rem; color: var(--text-secondary); align-items: center; flex-wrap: wrap;">
                                                    <span><strong>Expediente:</strong> <?= htmlspecialchars($o['expediente']) ?></span>
                                                    <span style="color: var(--gray-300);">|</span>
                                                    <span><strong>Concurso:</strong> <?= ($o['nro_concurso'] ?? '') !== '' ? htmlspecialchars($o['nro_concurso']) : '<span class="badge badge-danger">Falta completar</span>' ?></span>
                                                    <span style="color: var(--gray-300);">|</span>
                                                    <span><strong>Materiales:</strong> <?= $o['cant_materiales'] ?> ítems</span>
                                                    <span style="color: var(--gray-300);">|</span>
                                                    <span><strong>Compras:</strong> <?= $o['cant_compras'] ?> órdenes</span>
                                                </div>
                                            </div>
                                            <div style="display: flex; gap: 0.5rem; align-items: center; flex-shrink: 0;">
                                                <a href="obra_detalle.php?id=<?= $o['id'] ?>" class="btn btn-secondary btn-sm" style="font-weight: 500;">
                                                    Ver Panel
                                                </a>
                                                <a href="obras.php?action=editar&id=<?= $o['id'] ?>" class="btn btn-secondary btn-sm" style="padding: 0.4rem;" title="Editar">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                                </a>
                                                <a href="obras.php?action=eliminar&id=<?= $o['id'] ?><?= csrf_url() ?>" class="btn btn-danger btn-sm" style="padding: 0.4rem;" onclick="return confirm('¿Está seguro de eliminar esta obra? Se borrarán todos los materiales, compras y remitos asociados.');" title="Eliminar">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2M10 11v6M14 11v6"/></svg>
                                                </a>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<!-- ACCIÓN: NUEVA OBRA -->
<?php elseif ($action === 'nuevo'): ?>
    <div class="page-header">
        <div class="page-title">
            <h1>Cargar Solicitud de Obra Pública</h1>
            <p>Registre una nueva obra y cargue el listado original de materiales requeridos (Paso 1)</p>
        </div>
        <div>
            <a href="obras.php" class="btn btn-secondary" onclick="if(document.referrer && !document.referrer.includes(window.location.pathname)) { window.history.back(); return false; }">Volver al Listado</a>
        </div>
    </div>

    <form action="obras.php?action=guardar_nuevo" method="POST">
        <?= csrf_campo() ?>
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Datos del Expediente y Obra</h3>
            </div>
            <div class="card-body">
                <div class="form-grid">
                    <div class="form-group" style="grid-column: span 2;">
                        <label class="form-label">Nombre de la Obra *</label>
                        <input type="text" name="nombre" class="form-control" placeholder="Ej. Red Cloacal Barrio Centenario" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Nro Expediente *</label>
                        <input type="text" name="expediente" class="form-control" placeholder="Ej. EXP-2026-9045" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">N.º de Concurso de Precios *</label>
                        <input type="text" name="nro_concurso" class="form-control" maxlength="50" placeholder="Ej. 12/2026" value="<?= htmlspecialchars($_POST['nro_concurso'] ?? '') ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Estado Inicial</label>
                        <select name="estado" class="form-control">
                            <option value="Planificación">Planificación</option>
                            <option value="Activa" selected>Activa</option>
                            <option value="Pausada">Pausada</option>
                            <option value="Finalizada">Finalizada</option>
                        </select>
                    </div>
                    <div class="form-group full-width">
                        <label class="form-label">Descripción o Destino de la Obra</label>
                        <textarea name="descripcion" class="form-control" rows="3" placeholder="Detalles de la ubicación, alcance, plazos o especificaciones..."></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Listado de Materiales Requeridos (Solicitud de Obra)</h3>
            </div>
            <div class="card-body">
                <p style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 1.5rem;">
                    Ingrese los materiales e insumos aprobados en la memoria técnica original de la obra. Luego los contrastará contra las compras y los remitos.
                </p>
                
                <div id="dynamic-materials">
                    <!-- Fila Inicial por Defecto -->
                    <div class="dynamic-item-row">
                        <div class="form-group">
                            <label class="form-label">Material / Descripción</label>
                            <input type="text" name="materiales[0][descripcion]" class="form-control" placeholder="Ej. Cemento Portland 50kg" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Cantidad</label>
                            <input type="number" step="0.01" name="materiales[0][cantidad]" class="form-control" placeholder="0.00" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Unidad</label>
                            <select name="materiales[0][unidad]" class="form-control" required>
                                <option value="Bolsas">Bolsas</option>
                                <option value="Unidades">Unidades</option>
                                <option value="Metros Cúbicos">Metros Cúbicos</option>
                                <option value="Metros Cuadrados">Metros Cuadrados</option>
                                <option value="Varillas">Varillas</option>
                                <option value="Mallas">Mallas</option>
                                <option value="Metros">Metros</option>
                                <option value="Kilogramos">Kilogramos</option>
                                <option value="Litros">Litros</option>
                            </select>
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
                    <button type="button" id="btn-add-material" class="btn btn-secondary btn-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5v14"/></svg>
                        Añadir Material
                    </button>
                </div>

                <div style="margin-top: 2rem; display: flex; justify-content: flex-end; gap: 1rem;">
                    <a href="obras.php" class="btn btn-secondary" onclick="if(document.referrer && !document.referrer.includes(window.location.pathname)) { window.history.back(); return false; }">Cancelar</a>
                    <button type="submit" class="btn btn-primary">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                        Guardar Obra y Materiales
                    </button>
                </div>
            </div>
        </div>
    </form>

<!-- ACCIÓN: EDITAR OBRA -->
<?php elseif ($action === 'editar'): 
    $id = isset($_GET['id']) ? intval($_GET['id']) : (isset($_POST['id']) ? intval($_POST['id']) : 0);
    $stmt = $pdo->prepare("SELECT * FROM obras WHERE id = ?");
    $stmt->execute([$id]);
    $obra = $stmt->fetch();
    
    if (!$obra) {
        echo "<h3>La obra solicitada no existe.</h3>";
        require_once 'footer.php';
        exit;
    }

    // Obtener los materiales solicitados actuales de la obra
    $stmtMat = $pdo->prepare("SELECT * FROM materiales_solicitados WHERE obra_id = ? ORDER BY id ASC");
    $stmtMat->execute([$id]);
    $materiales_obra = $stmtMat->fetchAll();
?>
    <div class="page-header">
        <div class="page-title">
            <h1>Editar Obra y Solicitud</h1>
            <p>Modificar expediente, metadatos y listado original de insumos requeridos</p>
        </div>
        <div>
            <a href="obras.php" class="btn btn-secondary" onclick="if(document.referrer && !document.referrer.includes(window.location.pathname)) { window.history.back(); return false; }">Volver al Listado</a>
        </div>
    </div>

    <form action="obras.php?action=guardar_editar" method="POST">
        <?= csrf_campo() ?>
        <input type="hidden" name="id" value="<?= $obra['id'] ?>">
        
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Datos del Expediente y Obra</h3>
            </div>
            <div class="card-body">
                <div class="form-grid">
                    <div class="form-group" style="grid-column: span 2;">
                        <label class="form-label">Nombre de la Obra *</label>
                        <input type="text" name="nombre" class="form-control" value="<?= htmlspecialchars($obra['nombre']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Nro Expediente *</label>
                        <input type="text" name="expediente" class="form-control" value="<?= htmlspecialchars($obra['expediente']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">N.º de Concurso de Precios *</label>
                        <input type="text" name="nro_concurso" class="form-control" maxlength="50" value="<?= htmlspecialchars($obra['nro_concurso'] ?? '') ?>" placeholder="Falta completar" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Estado</label>
                        <select name="estado" class="form-control">
                            <option value="Planificación" <?= ($obra['estado'] === 'Planificación') ? 'selected' : '' ?>>Planificación</option>
                            <option value="Activa" <?= ($obra['estado'] === 'Activa') ? 'selected' : '' ?>>Activa</option>
                            <option value="Pausada" <?= ($obra['estado'] === 'Pausada') ? 'selected' : '' ?>>Pausada</option>
                            <option value="Finalizada" <?= ($obra['estado'] === 'Finalizada') ? 'selected' : '' ?>>Finalizada</option>
                        </select>
                    </div>
                    <div class="form-group full-width">
                        <label class="form-label">Descripción o Destino de la Obra</label>
                        <textarea name="descripcion" class="form-control" rows="3"><?= htmlspecialchars($obra['descripcion'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Listado de Materiales Requeridos</h3>
            </div>
            <div class="card-body">
                <p style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 1.5rem;">
                    Modifique las cantidades, descripciones o añada nuevos insumos. Al remover un material, se conservará en compras previas si ya estaba adjudicado.
                </p>
                
                <div id="dynamic-materials-edit">
                    <?php 
                    $idx = 0;
                    foreach ($materiales_obra as $m): 
                    ?>
                        <div class="dynamic-item-row">
                            <input type="hidden" name="materiales[<?= $idx ?>][id]" value="<?= $m['id'] ?>">
                            <div class="form-group">
                                <label class="form-label">Material / Descripción</label>
                                <input type="text" name="materiales[<?= $idx ?>][descripcion]" class="form-control" value="<?= htmlspecialchars($m['descripcion']) ?>" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Cantidad</label>
                                <input type="number" step="0.01" name="materiales[<?= $idx ?>][cantidad]" class="form-control" value="<?= floatval($m['cantidad']) ?>" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Unidad</label>
                                <select name="materiales[<?= $idx ?>][unidad]" class="form-control" required>
                                    <option value="Bolsas" <?= ($m['unidad'] === 'Bolsas') ? 'selected' : '' ?>>Bolsas</option>
                                    <option value="Unidades" <?= ($m['unidad'] === 'Unidades') ? 'selected' : '' ?>>Unidades</option>
                                    <option value="Metros Cúbicos" <?= ($m['unidad'] === 'Metros Cúbicos') ? 'selected' : '' ?>>Metros Cúbicos</option>
                                    <option value="Metros Cuadrados" <?= ($m['unidad'] === 'Metros Cuadrados') ? 'selected' : '' ?>>Metros Cuadrados</option>
                                    <option value="Varillas" <?= ($m['unidad'] === 'Varillas') ? 'selected' : '' ?>>Varillas</option>
                                    <option value="Mallas" <?= ($m['unidad'] === 'Mallas') ? 'selected' : '' ?>>Mallas</option>
                                    <option value="Metros" <?= ($m['unidad'] === 'Metros') ? 'selected' : '' ?>>Metros</option>
                                    <option value="Kilogramos" <?= ($m['unidad'] === 'Kilogramos') ? 'selected' : '' ?>>Kilogramos</option>
                                    <option value="Litros" <?= ($m['unidad'] === 'Litros') ? 'selected' : '' ?>>Litros</option>
                                </select>
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

                <!-- Botón de añadir material colocado abajo en edición -->
                <div style="margin-top: 1.5rem; margin-bottom: 1rem;">
                    <button type="button" id="btn-add-material-edit" class="btn btn-secondary btn-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5v14"/></svg>
                        Añadir Material
                    </button>
                </div>

                <!-- Script local para el comportamiento dinámico en la edición -->
                <script>
                    document.addEventListener('DOMContentLoaded', () => {
                        const btnAdd = document.getElementById('btn-add-material-edit');
                        const container = document.getElementById('dynamic-materials-edit');
                        let editIdx = <?= $idx ?>;

                        if (btnAdd && container) {
                            btnAdd.addEventListener('click', () => {
                                const newRow = document.createElement('div');
                                newRow.className = 'dynamic-item-row';
                                newRow.innerHTML = `
                                    <div class="form-group">
                                        <label class="form-label">Material / Descripción</label>
                                        <input type="text" name="materiales[${editIdx}][descripcion]" class="form-control" placeholder="Ej. Ladrillo Hueco" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Cantidad</label>
                                        <input type="number" step="0.01" name="materiales[${editIdx}][cantidad]" class="form-control" placeholder="0.00" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Unidad</label>
                                        <select name="materiales[${editIdx}][unidad]" class="form-control" required>
                                            <option value="Bolsas">Bolsas</option>
                                            <option value="Unidades">Unidades</option>
                                            <option value="Metros Cúbicos">Metros Cúbicos</option>
                                            <option value="Metros Cuadrados">Metros Cuadrados</option>
                                            <option value="Varillas">Varillas</option>
                                            <option value="Mallas">Mallas</option>
                                            <option value="Metros">Metros</option>
                                            <option value="Kilogramos">Kilogramos</option>
                                            <option value="Litros">Litros</option>
                                        </select>
                                    </div>
                                    <div style="padding-bottom: 5px;">
                                        <button type="button" class="btn btn-danger btn-sm btn-remove-row" style="margin-top: 1.8rem;">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2M10 11v6M14 11v6"/></svg>
                                        </button>
                                    </div>
                                `;
                                container.appendChild(newRow);
                                editIdx++;
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
                    <a href="obras.php" class="btn btn-secondary" onclick="if(document.referrer && !document.referrer.includes(window.location.pathname)) { window.history.back(); return false; }">Cancelar</a>
                    <button type="submit" class="btn btn-primary">Guardar Cambios</button>
                </div>
            </div>
        </div>
    </form>
<?php endif; ?>

<?php
require_once 'footer.php';
?>
