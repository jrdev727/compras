<?php
// Lista de proveedores: ver, agregar y corregir el nombre (razón social única).
require_once 'bootstrap.php';
require_once 'proveedores_lib.php';

$lista_lista = proveedores_estructura_lista($pdo);

// ------------------------------------------------------------------ acciones (POST; el token CSRF ya se validó en bootstrap.php)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $lista_lista) {
    $op = $_POST['operacion'] ?? '';
    $flash = ['danger', 'Solicitud no válida.'];
    try {
        if ($op === 'agregar') {
            $nombre = proveedor_validar_nombre($pdo, (string)($_POST['razon_social'] ?? ''));
            $pdo->prepare("INSERT INTO proveedores (razon_social) VALUES (?)")->execute([$nombre]);
            $flash = ['success', "Proveedor «{$nombre}» agregado."];
        } elseif ($op === 'renombrar') {
            $id = (int)($_POST['id'] ?? 0);
            $st = $pdo->prepare("SELECT razon_social FROM proveedores WHERE id = ?");
            $st->execute([$id]);
            $actual = $st->fetchColumn();
            if ($actual === false) {
                throw new ProveedorInvalido('El proveedor no existe.');
            }
            $nombre = proveedor_validar_nombre($pdo, (string)($_POST['razon_social'] ?? ''), $id);
            if ($nombre === $actual) {
                $flash = ['success', 'No hubo cambios.'];
            } else {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE proveedores SET razon_social = ? WHERE id = ?")->execute([$nombre, $id]);
                // Se mantiene al día el texto viejo de las OC de este proveedor
                $pdo->prepare("UPDATE compras SET proveedor = ? WHERE proveedor_id = ?")->execute([$nombre, $id]);
                $pdo->commit();
                $flash = ['success', "Se cambió «{$actual}» por «{$nombre}» (también en sus órdenes de compra)."];
            }
        }
    } catch (ProveedorInvalido $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $flash = ['danger', $e->getMessage()];
    } catch (\PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $flash = ['danger', error_generico($e, 'Error al guardar el proveedor')];
    }
    $_SESSION['flash'] = $flash;
    header('Location: proveedores.php');
    exit;
}
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$proveedores = [];
$pendientes = 0;
if ($lista_lista) {
    $proveedores = $pdo->query("
        SELECT p.id, p.razon_social,
               (SELECT COUNT(*) FROM compras  WHERE proveedor_id = p.id) AS cant_oc,
               (SELECT COUNT(*) FROM facturas WHERE proveedor_id = p.id) AS cant_facturas
        FROM proveedores p ORDER BY p.razon_social ASC
    ")->fetchAll();
    $pendientes = (int)$pdo->query("SELECT COUNT(*) FROM compras WHERE proveedor_id IS NULL")->fetchColumn();
}

require_once 'header.php';
?>
<div class="page-header">
    <div class="page-title">
        <h1>Proveedores</h1>
        <p>Cada proveedor figura una sola vez; las OC y facturas lo toman de esta lista</p>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert-banner <?= $flash[0] === 'danger' ? 'alert-banner-danger' : '' ?>">
        <div class="alert-banner-text"><h4>Aviso del Sistema</h4><p><?= h($flash[1]) ?></p></div>
    </div>
<?php endif; ?>

<?php if (!$lista_lista): ?>
    <div class="alert-banner alert-banner-danger"><div class="alert-banner-text">
        <h4>Falta preparar la base de datos</h4>
        <p>Ejecutá <code>migraciones/004_proveedores.sql</code> en phpMyAdmin (ver <code>migraciones/LEEME.md</code>) y volvé a esta pantalla.</p>
    </div></div>
<?php else: ?>
    <?php if ($pendientes > 0): ?>
        <div class="alert-banner alert-banner-danger"><div class="alert-banner-text">
            <h4><?= $pendientes ?> orden(es) de compra todavía sin proveedor vinculado</h4>
            <p>Mientras tanto los formularios siguen usando el campo de texto. <a href="migrar_proveedores.php">Ir a Migrar proveedores</a> (ves todo lo que va a cambiar antes de confirmar).</p>
        </div></div>
    <?php endif; ?>

    <div class="card" style="margin-bottom: 2rem;">
        <div class="card-header"><h2 class="card-title">Agregar proveedor</h2></div>
        <div class="card-body">
            <form method="POST" action="proveedores.php" class="d-flex gap-2 align-center" style="flex-wrap: wrap;">
                <?= csrf_campo() ?>
                <input type="hidden" name="operacion" value="agregar">
                <input type="text" name="razon_social" class="form-control" style="max-width: 420px;" maxlength="255" placeholder="Razón social. Ej. Corralón Central S.A." required>
                <button type="submit" class="btn btn-primary">Agregar</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2 class="card-title">Listado (<?= count($proveedores) ?>)</h2></div>
        <div class="card-body">
        <?php if (!$proveedores): ?>
            <p class="text-center" style="padding: 2rem; color: var(--text-secondary);">Todavía no hay proveedores cargados.</p>
        <?php else: ?>
            <div class="table-responsive"><table class="table">
                <thead><tr><th>Razón social</th><th class="text-right">OC</th><th class="text-right">Facturas</th><th>Corregir nombre</th></tr></thead>
                <tbody>
                <?php foreach ($proveedores as $p): ?>
                    <tr>
                        <td class="text-bold"><?= h($p['razon_social']) ?></td>
                        <td class="text-right"><?= (int)$p['cant_oc'] ?></td>
                        <td class="text-right"><?= (int)$p['cant_facturas'] ?></td>
                        <td>
                            <form method="POST" action="proveedores.php" class="d-flex gap-2 align-center" style="margin:0;">
                                <?= csrf_campo() ?>
                                <input type="hidden" name="operacion" value="renombrar">
                                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                <input type="text" name="razon_social" class="form-control" value="<?= h($p['razon_social']) ?>" maxlength="255" required aria-label="Nuevo nombre">
                                <button type="submit" class="btn btn-secondary btn-sm">Guardar</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php require_once 'footer.php'; ?>
