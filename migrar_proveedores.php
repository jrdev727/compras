<?php
// MIGRACIÓN DE PROVEEDORES: pasa los nombres de proveedor escritos en las OC a la tabla proveedores.
// 1) Muestra qué se va a crear y qué nombres casi iguales hay que unificar. NO cambia nada al abrirla.
// 2) Los casi iguales se unifican con un formulario (mostrando qué OC cambian) y recién entonces se puede migrar.
// 3) El texto viejo de compras.proveedor se conserva siempre.
require_once 'bootstrap.php';
require_once 'proveedores_lib.php';

$estructura = proveedores_estructura_lista($pdo);

/**
 * Analiza las OC sin proveedor vinculado.
 * Devuelve: vacias (OC sin nombre), grupos (casi iguales por resolver), listos (nombre => ids de OC), ya_vinculadas.
 */
function analizar_proveedores(PDO $pdo): array
{
    $existentes = [];                       // nombre => id  (proveedores ya cargados)
    foreach ($pdo->query("SELECT id, razon_social FROM proveedores") as $p) {
        $existentes[$p['razon_social']] = (int)$p['id'];
    }
    $vacias = [];
    $por_clave = [];                        // clave => [nombre normalizado => [oc...]]
    foreach ($pdo->query("SELECT id, nro_compra, proveedor FROM compras WHERE proveedor_id IS NULL ORDER BY id") as $c) {
        $nombre = normalizar_razon_social((string)$c['proveedor']);
        if (proveedor_clave($nombre) === '') {
            $vacias[] = $c;
            continue;
        }
        $por_clave[proveedor_clave($nombre)][$nombre][] = $c;
    }
    // Un proveedor ya cargado cuenta como una variante más de su grupo.
    $existente_por_clave = [];
    foreach ($existentes as $nombre => $id) {
        $existente_por_clave[proveedor_clave($nombre)][] = $nombre;
    }
    $grupos = [];
    $listos = [];
    foreach ($por_clave as $clave => $variantes) {
        $nombres_existentes = $existente_por_clave[$clave] ?? [];
        $distintos = array_unique(array_merge(array_keys($variantes), $nombres_existentes));
        if (count($distintos) > 1) {
            $grupos[$clave] = ['variantes' => $variantes, 'existentes' => $nombres_existentes];
        } else {
            $nombre = array_key_first($variantes);
            $listos[$nombre] = array_column($variantes[$nombre], 'id');
        }
    }
    return ['vacias' => $vacias, 'grupos' => $grupos, 'listos' => $listos];
}

// ------------------------------------------------------------------ acciones (POST; token CSRF ya validado)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $estructura) {
    $op = $_POST['operacion'] ?? '';
    $flash = ['danger', 'Solicitud no válida.'];
    try {
        $a = analizar_proveedores($pdo);
        if ($op === 'unificar') {
            $clave = (string)($_POST['clave'] ?? '');
            $grupo = $a['grupos'][$clave] ?? null;
            if (!$grupo) {
                throw new ProveedorInvalido('Ese grupo ya no necesita unificarse. Recargá la página.');
            }
            $permitidos = $grupo['existentes'] ?: array_keys($grupo['variantes']);
            $elegido = (string)($_POST['canonico'] ?? '');
            if ($elegido === '__otro__') {
                $canon = normalizar_razon_social((string)($_POST['canonico_otro'] ?? ''));
                if ($grupo['existentes'] || proveedor_clave($canon) !== $clave) {
                    throw new ProveedorInvalido('El nombre escrito debe ser una versión del mismo proveedor (mismas letras; solo cambian mayúsculas, tildes, puntos o espacios).');
                }
            } else {
                $canon = normalizar_razon_social($elegido);
                if (!in_array($canon, $permitidos, true)) {
                    throw new ProveedorInvalido('Elegí cómo debe quedar escrito el proveedor.');
                }
            }
            $ids = [];
            foreach ($grupo['variantes'] as $oc_list) {
                foreach ($oc_list as $oc) $ids[] = (int)$oc['id'];
            }
            $pdo->beginTransaction();
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("UPDATE compras SET proveedor = ? WHERE id IN ($in) AND proveedor_id IS NULL");
            $st->execute(array_merge([$canon], $ids));
            $pdo->commit();
            $flash = ['success', "Unificado como «{$canon}» en " . count($ids) . ' orden(es) de compra.'];
        } elseif ($op === 'migrar') {
            if ($a['vacias'] || $a['grupos']) {
                throw new ProveedorInvalido('Todavía hay problemas por resolver (OC sin proveedor o nombres casi iguales). No se migró nada.');
            }
            $pdo->beginTransaction();
            $creados = 0;
            $buscar = $pdo->prepare("SELECT id FROM proveedores WHERE razon_social = ?");
            $crear  = $pdo->prepare("INSERT INTO proveedores (razon_social) VALUES (?)");
            foreach ($a['listos'] as $nombre => $ids) {
                $buscar->execute([$nombre]);
                $pid = $buscar->fetchColumn();
                if ($pid === false) {
                    $crear->execute([$nombre]);
                    $pid = $pdo->lastInsertId();
                    $creados++;
                }
                $in = implode(',', array_fill(0, count($ids), '?'));
                $pdo->prepare("UPDATE compras SET proveedor_id = ? WHERE id IN ($in) AND proveedor_id IS NULL")
                    ->execute(array_merge([(int)$pid], array_map('intval', $ids)));
            }
            // Las facturas toman el proveedor de su OC
            $pdo->exec("UPDATE facturas f JOIN compras c ON c.id = f.compra_id SET f.proveedor_id = c.proveedor_id WHERE f.proveedor_id IS NULL");
            $sin_oc = (int)$pdo->query("SELECT COUNT(*) FROM compras WHERE proveedor_id IS NULL")->fetchColumn();
            $sin_fa = (int)$pdo->query("SELECT COUNT(*) FROM facturas WHERE proveedor_id IS NULL")->fetchColumn();
            if ($sin_oc > 0 || $sin_fa > 0) {
                throw new RuntimeException("Control final falló: OC sin proveedor=$sin_oc, facturas sin proveedor=$sin_fa");
            }
            $pdo->commit();
            $flash = ['success', "Migración terminada: $creados proveedor(es) creado(s). Todas las OC y facturas quedaron vinculadas."];
        }
    } catch (ProveedorInvalido $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $flash = ['danger', $e->getMessage()];
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $flash = ['danger', error_generico($e, 'Error en la migración de proveedores (no se guardó ningún cambio)')];
    }
    $_SESSION['flash'] = $flash;
    header('Location: migrar_proveedores.php');
    exit;
}
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$a = $estructura ? analizar_proveedores($pdo) : null;
$terminada = $estructura && !$a['vacias'] && !$a['grupos'] && !$a['listos'];
if ($estructura) {
    $tot_oc_pend  = array_sum(array_map('count', $a['listos']));
    $tot_fact_pend = (int)$pdo->query("SELECT COUNT(*) FROM facturas f JOIN compras c ON c.id = f.compra_id WHERE f.proveedor_id IS NULL")->fetchColumn();
}

require_once 'header.php';
?>
<div class="page-header">
    <div class="page-title">
        <h1>Migrar proveedores</h1>
        <p>Pasa los nombres escritos en las órdenes de compra a la lista única de proveedores. No se pierde ningún dato: el texto original se conserva.</p>
    </div>
    <div><a href="proveedores.php" class="btn btn-secondary">Ir a Proveedores</a></div>
</div>

<?php if ($flash): ?>
    <div class="alert-banner <?= $flash[0] === 'danger' ? 'alert-banner-danger' : '' ?>">
        <div class="alert-banner-text"><h4>Aviso del Sistema</h4><p><?= h($flash[1]) ?></p></div>
    </div>
<?php endif; ?>

<?php if (!$estructura): ?>
    <div class="alert-banner alert-banner-danger"><div class="alert-banner-text">
        <h4>Falta preparar la base de datos</h4>
        <p>Primero ejecutá <code>migraciones/004_proveedores.sql</code> en phpMyAdmin (ver <code>migraciones/LEEME.md</code>).</p>
    </div></div>

<?php elseif ($terminada): ?>
    <div class="alert-banner" style="background: var(--success-light); border-left: 4px solid var(--success);"><div class="alert-banner-text">
        <h4>Migración completa</h4>
        <p>Todas las OC y facturas están vinculadas a un proveedor. Los formularios ya usan la lista. El texto viejo (<code>compras.proveedor</code>) sigue guardado hasta que lo confirmes.</p>
    </div></div>

<?php else: ?>
    <?php if ($a['vacias']): ?>
        <div class="card" style="margin-bottom: 1.5rem; border-left: 4px solid var(--danger);">
            <div class="card-header"><h2 class="card-title">1. OC sin proveedor (<?= count($a['vacias']) ?>)</h2></div>
            <div class="card-body">
                <p>Completá el proveedor de estas OC (botón Editar) y recargá esta página:</p>
                <ul style="margin-left: 1.25rem;">
                <?php foreach ($a['vacias'] as $oc): ?>
                    <li>OC <strong><?= h($oc['nro_compra']) ?></strong> — <a href="compras.php?action=editar&id=<?= (int)$oc['id'] ?>">Editar</a></li>
                <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($a['grupos']): ?>
        <div class="card" style="margin-bottom: 1.5rem; border-left: 4px solid var(--danger);">
            <div class="card-header"><h2 class="card-title">2. Nombres casi iguales: elegí cómo debe quedar cada uno (<?= count($a['grupos']) ?>)</h2></div>
            <div class="card-body">
                <p style="color: var(--text-secondary);">Al unificar solo cambia el texto del proveedor en las OC que se listan. Nada más.</p>
                <?php foreach ($a['grupos'] as $clave => $g): ?>
                    <form method="POST" action="migrar_proveedores.php" style="border: 1px solid var(--gray-200); border-radius: 8px; padding: 1rem; margin-bottom: 1rem;">
                        <?= csrf_campo() ?>
                        <input type="hidden" name="operacion" value="unificar">
                        <input type="hidden" name="clave" value="<?= h($clave) ?>">
                        <?php if ($g['existentes']): ?>
                            <p><strong>Ya existe en la lista de proveedores:</strong> «<?= h($g['existentes'][0]) ?>» — las OC siguientes se pasarán a ese nombre.</p>
                            <input type="hidden" name="canonico" value="<?= h($g['existentes'][0]) ?>">
                        <?php endif; ?>
                        <?php foreach ($g['variantes'] as $nombre => $ocs): ?>
                            <div style="margin-bottom: .5rem;">
                                <?php if (!$g['existentes']): ?>
                                    <label><input type="radio" name="canonico" value="<?= h($nombre) ?>" required> Dejar como <strong>«<?= h($nombre) ?>»</strong></label>
                                <?php else: ?>
                                    <strong>«<?= h($nombre) ?>»</strong>
                                <?php endif; ?>
                                <div style="font-size: .85rem; color: var(--text-secondary); margin-left: 1.6rem;">
                                    <?= count($ocs) ?> OC: <?= h(implode(', ', array_column($ocs, 'nro_compra'))) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$g['existentes']): ?>
                            <div style="margin: .5rem 0 1rem;">
                                <label><input type="radio" name="canonico" value="__otro__"> Otro nombre:</label>
                                <input type="text" name="canonico_otro" class="form-control" style="display:inline-block; width:auto; min-width: 280px;" maxlength="255">
                            </div>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary btn-sm" onclick="return confirm('¿Unificar estas OC con el nombre elegido?');">Unificar</button>
                    </form>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!$a['vacias'] && !$a['grupos']): ?>
        <div class="card">
            <div class="card-header"><h2 class="card-title">Esto es lo que se va a hacer</h2></div>
            <div class="card-body">
                <p>Se van a vincular <strong><?= $tot_oc_pend ?></strong> orden(es) de compra y <strong><?= $tot_fact_pend ?></strong> factura(s) con estos proveedores
                   (los que no estén todavía en la lista se crean):</p>
                <div class="table-responsive"><table class="table">
                    <thead><tr><th>Proveedor</th><th class="text-right">OC a vincular</th></tr></thead>
                    <tbody>
                    <?php foreach ($a['listos'] as $nombre => $ids): ?>
                        <tr><td class="text-bold"><?= h($nombre) ?></td><td class="text-right"><?= count($ids) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <p style="color: var(--text-secondary);">No se borra ni se modifica ningún texto. <strong>Hacé un backup de la base antes de confirmar.</strong></p>
                <form method="POST" action="migrar_proveedores.php">
                    <?= csrf_campo() ?>
                    <input type="hidden" name="operacion" value="migrar">
                    <button type="submit" class="btn btn-primary" onclick="return confirm('¿Ya hiciste el backup de la base? Se van a vincular las OC y facturas con sus proveedores.');">Migrar ahora</button>
                </form>
            </div>
        </div>
    <?php else: ?>
        <p style="color: var(--text-secondary);">Cuando no quede nada pendiente arriba, acá aparece el resumen y el botón para migrar.</p>
    <?php endif; ?>
<?php endif; ?>

<?php require_once 'footer.php'; ?>
