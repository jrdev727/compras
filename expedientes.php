<?php
// CONTROL DE EXPEDIENTES FÍSICOS: tablero por obra, avance por año, buscador y marca
// "Verificado contra el papel" (con fecha) para OC, facturas y remitos.
require_once 'bootstrap.php';
require_once 'validaciones.php';

const TABLAS_VERIFICABLES = ['compra' => 'compras', 'factura' => 'facturas', 'remito' => 'remitos'];
const NOMBRES_DOC = ['compra' => 'Orden de compra', 'factura' => 'Factura', 'remito' => 'Remito'];

/** Solo se vuelve a esta misma pantalla (evita redirecciones a otros sitios). */
function volver_seguro($v): string
{
    return (is_string($v) && preg_match('/^expedientes\.php(\?[A-Za-z0-9_=&%.\-+]*)?$/', $v)) ? $v : 'expedientes.php';
}

// ------------------------------------------------------------------ marcar / quitar verificación (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {   // el token CSRF ya fue validado en bootstrap.php
    $tipo = $_POST['tipo'] ?? '';
    $id   = (int)($_POST['id'] ?? 0);
    $op   = $_POST['operacion'] ?? '';
    $fecha = trim((string)($_POST['fecha'] ?? ''));
    $volver = volver_seguro($_POST['volver'] ?? '');

    $flash = null;
    if (!isset(TABLAS_VERIFICABLES[$tipo]) || $id <= 0 || !in_array($op, ['verificar', 'quitar'], true)) {
        $flash = ['danger', 'Solicitud no válida.'];
    } else {
        $tabla = TABLAS_VERIFICABLES[$tipo];   // viene de una lista fija, nunca del usuario
        try {
            $existe = $pdo->prepare("SELECT id FROM $tabla WHERE id = ?");
            $existe->execute([$id]);
            if (!$existe->fetch()) {
                $flash = ['danger', 'El documento no existe.'];
            } elseif ($op === 'quitar') {
                $pdo->prepare("UPDATE $tabla SET verificado_en = NULL WHERE id = ?")->execute([$id]);
                $flash = ['success', 'Se quitó la marca de verificación.'];
            } else {
                if ($fecha === '') {
                    $fecha = date('Y-m-d');
                }
                if (!fecha_valida($fecha)) {
                    $flash = ['danger', 'La fecha de verificación no es válida.'];
                } elseif ($fecha > date('Y-m-d')) {
                    $flash = ['danger', 'La fecha de verificación no puede ser futura.'];
                } else {
                    $pdo->prepare("UPDATE $tabla SET verificado_en = ? WHERE id = ?")->execute([$fecha, $id]);
                    $flash = ['success', 'Marcado como verificado contra el papel (' . date('d/m/Y', strtotime($fecha)) . ').'];
                }
            }
        } catch (\PDOException $e) {
            $flash = ['danger', error_generico($e, 'Error al guardar la verificación')];
        }
    }
    $_SESSION['flash'] = $flash;
    header('Location: ' . $volver);
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ------------------------------------------------------------------ parámetros de la pantalla
$q       = trim((string)($_GET['q'] ?? ''));
$obra_id = (int)($_GET['obra'] ?? 0);
$anio    = (int)($_GET['anio'] ?? 0);          // 0 = todos los años
$volver_actual = 'expedientes.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
$volver_actual = volver_seguro($volver_actual);

/** Formulario chico de verificación para una fila. */
function celda_verificacion(string $tipo, int $id, ?string $verificado_en, string $volver): string
{
    $base = csrf_campo()
        . '<input type="hidden" name="tipo" value="' . h($tipo) . '">'
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<input type="hidden" name="volver" value="' . h($volver) . '">';
    if ($verificado_en) {
        return '<form method="POST" action="expedientes.php" class="d-flex gap-2 align-center" style="margin:0;">' . $base
            . '<input type="hidden" name="operacion" value="quitar">'
            . '<span class="badge badge-success">✔ ' . h(date('d/m/Y', strtotime($verificado_en))) . '</span>'
            . '<button type="submit" class="btn btn-secondary btn-sm" onclick="return confirm(\'¿Quitar la marca de verificado?\');">Quitar</button></form>';
    }
    return '<form method="POST" action="expedientes.php" class="d-flex gap-2 align-center" style="margin:0;">' . $base
        . '<input type="hidden" name="operacion" value="verificar">'
        . '<input type="date" name="fecha" class="form-control" value="' . h(date('Y-m-d')) . '" max="' . h(date('Y-m-d')) . '" style="width:auto; padding:.25rem .4rem;" aria-label="Fecha de verificación">'
        . '<button type="submit" class="btn btn-primary btn-sm">Verificar</button></form>';
}

function fecha_corta($f): string
{
    return fecha_valida((string)$f) ? date('d/m/Y', strtotime($f)) : '(fecha inválida)';
}

function barra(int $verificados, int $total): string
{
    $pct = $total > 0 ? (int)round($verificados * 100 / $total) : 0;
    $clase = $pct >= 100 ? 'success' : ($pct >= 50 ? 'warning' : 'danger');
    if ($total === 0) {
        return '<span style="color: var(--text-secondary);">—</span>';
    }
    return '<div class="progress-container"><div class="progress-bar-wrapper"><div class="progress-bar-fill ' . $clase
        . '" style="width: ' . $pct . '%"></div></div><span class="progress-text">' . $pct . '%</span></div>';
}

function vt(int $v, int $t): string
{
    return '<strong>' . $v . '</strong> / ' . $t;
}

// ------------------------------------------------------------------ datos según el modo
$modo = $q !== '' ? 'buscar' : ($obra_id > 0 ? 'obra' : 'tablero');

if ($modo === 'buscar') {
    // Escapamos % y _ para que se busquen como texto común.
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $cols_obra = "o.expediente LIKE ? OR o.nro_concurso LIKE ? OR o.nombre LIKE ?";
    $sql = "
        SELECT 'compra' AS tipo, c.id, c.nro_compra AS numero, c.proveedor AS proveedor, c.fecha_compra AS fecha, c.verificado_en,
               o.id AS obra_id, o.expediente, o.nro_concurso, o.nombre AS obra_nombre
        FROM compras c JOIN obras o ON c.obra_id = o.id
        WHERE c.nro_compra LIKE ? OR c.proveedor LIKE ? OR $cols_obra
        UNION ALL
        SELECT 'factura', f.id, f.nro_factura, c.proveedor, f.fecha_factura, f.verificado_en,
               o.id, o.expediente, o.nro_concurso, o.nombre
        FROM facturas f JOIN compras c ON f.compra_id = c.id JOIN obras o ON c.obra_id = o.id
        WHERE f.nro_factura LIKE ? OR c.proveedor LIKE ? OR $cols_obra
        UNION ALL
        SELECT 'remito', r.id, COALESCE(NULLIF(r.nro_remito, ''), 'PENDIENTE'),
               (SELECT GROUP_CONCAT(DISTINCT c2.proveedor SEPARATOR ' / ')
                  FROM remito_detalles rd2 JOIN compra_detalles cd2 ON rd2.compra_detalle_id = cd2.id
                  JOIN compras c2 ON cd2.compra_id = c2.id WHERE rd2.remito_id = r.id),
               r.fecha_entrega, r.verificado_en, o.id, o.expediente, o.nro_concurso, o.nombre
        FROM remitos r JOIN obras o ON r.obra_id = o.id
        WHERE r.nro_remito LIKE ?
           OR EXISTS (SELECT 1 FROM remito_detalles rd3 JOIN compra_detalles cd3 ON rd3.compra_detalle_id = cd3.id
                      JOIN compras c3 ON cd3.compra_id = c3.id WHERE rd3.remito_id = r.id AND c3.proveedor LIKE ?)
           OR $cols_obra
        ORDER BY expediente, tipo, fecha
        LIMIT 301";
    $st = $pdo->prepare($sql);
    $st->execute(array_fill(0, 15, $like));
    $resultados = $st->fetchAll();
    $truncado = count($resultados) > 300;
    $resultados = array_slice($resultados, 0, 300);
} elseif ($modo === 'obra') {
    $st = $pdo->prepare("SELECT * FROM obras WHERE id = ?");
    $st->execute([$obra_id]);
    $obra = $st->fetch();
    if ($obra) {
        $st = $pdo->prepare("SELECT * FROM compras WHERE obra_id = ? ORDER BY fecha_compra, id");
        $st->execute([$obra_id]);
        $docs_oc = $st->fetchAll();
        $st = $pdo->prepare("SELECT f.*, c.nro_compra, c.proveedor FROM facturas f JOIN compras c ON f.compra_id = c.id WHERE c.obra_id = ? ORDER BY f.fecha_factura, f.id");
        $st->execute([$obra_id]);
        $docs_fact = $st->fetchAll();
        $st = $pdo->prepare("
            SELECT r.*, (SELECT GROUP_CONCAT(DISTINCT c2.proveedor SEPARATOR ' / ')
                           FROM remito_detalles rd2 JOIN compra_detalles cd2 ON rd2.compra_detalle_id = cd2.id
                           JOIN compras c2 ON cd2.compra_id = c2.id WHERE rd2.remito_id = r.id) AS proveedor
            FROM remitos r WHERE r.obra_id = ? ORDER BY r.fecha_entrega, r.id");
        $st->execute([$obra_id]);
        $docs_rem = $st->fetchAll();
    }
} else {
    // Tablero: contadores por obra (opcionalmente filtrados por año del documento)
    $filtro_oc   = $anio > 0 ? "WHERE YEAR(fecha_compra) = " . $anio : '';
    $filtro_fact = $anio > 0 ? "WHERE YEAR(f.fecha_factura) = " . $anio : '';
    $filtro_rem  = $anio > 0 ? "WHERE YEAR(fecha_entrega) = " . $anio : '';   // $anio es int: no hay inyección posible
    $cont = [];
    $sumar = function (array $filas, string $clave) use (&$cont) {
        foreach ($filas as $f) {
            $cont[$f['obra_id']][$clave] = [(int)$f['v'], (int)$f['t']];
        }
    };
    $sumar($pdo->query("SELECT obra_id, COUNT(*) t, COALESCE(SUM(verificado_en IS NOT NULL),0) v FROM compras $filtro_oc GROUP BY obra_id")->fetchAll(), 'oc');
    $sumar($pdo->query("SELECT c.obra_id, COUNT(*) t, COALESCE(SUM(f.verificado_en IS NOT NULL),0) v FROM facturas f JOIN compras c ON f.compra_id = c.id $filtro_fact GROUP BY c.obra_id")->fetchAll(), 'fact');
    $sumar($pdo->query("SELECT obra_id, COUNT(*) t, COALESCE(SUM(verificado_en IS NOT NULL),0) v FROM remitos $filtro_rem GROUP BY obra_id")->fetchAll(), 'rem');
    $obras = $pdo->query("SELECT id, nombre, expediente, nro_concurso, estado FROM obras ORDER BY expediente ASC")->fetchAll();

    // Avance por año (siempre sobre todos los documentos)
    $por_anio = [];
    $acum = function (array $filas, string $clave) use (&$por_anio) {
        foreach ($filas as $f) {
            $por_anio[(int)$f['anio']][$clave] = [(int)$f['v'], (int)$f['t']];
        }
    };
    $acum($pdo->query("SELECT YEAR(fecha_compra) anio, COUNT(*) t, COALESCE(SUM(verificado_en IS NOT NULL),0) v FROM compras GROUP BY anio")->fetchAll(), 'oc');
    $acum($pdo->query("SELECT YEAR(fecha_factura) anio, COUNT(*) t, COALESCE(SUM(verificado_en IS NOT NULL),0) v FROM facturas GROUP BY anio")->fetchAll(), 'fact');
    $acum($pdo->query("SELECT YEAR(fecha_entrega) anio, COUNT(*) t, COALESCE(SUM(verificado_en IS NOT NULL),0) v FROM remitos GROUP BY anio")->fetchAll(), 'rem');
    krsort($por_anio);
    $sin_concurso = 0;
    foreach ($obras as $o) {
        if (($o['nro_concurso'] ?? '') === '') $sin_concurso++;
    }
}

require_once 'header.php';
?>
<div class="page-header">
    <div class="page-title">
        <h1>Control de Expedientes</h1>
        <p>Verificación de OC, facturas y remitos contra el papel del expediente físico</p>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert-banner <?= $flash[0] === 'danger' ? 'alert-banner-danger' : '' ?>">
        <div class="alert-banner-text"><h4>Aviso del Sistema</h4><p><?= h($flash[1]) ?></p></div>
    </div>
<?php endif; ?>

<form method="GET" action="expedientes.php" class="filter-bar">
    <div class="form-group" style="flex-grow: 1;">
        <label class="form-label" for="q">Buscar</label>
        <input type="text" id="q" name="q" class="form-control" value="<?= h($q) ?>" placeholder="N.º de OC, factura o remito, proveedor, expediente, concurso u obra...">
    </div>
    <div class="form-group" style="align-self: flex-end;">
        <button type="submit" class="btn btn-primary">Buscar</button>
        <?php if ($q !== ''): ?><a href="expedientes.php" class="btn btn-secondary">Limpiar</a><?php endif; ?>
    </div>
</form>

<?php if ($modo === 'buscar'): ?>
    <div class="card">
        <div class="card-header"><h2 class="card-title">Resultados para «<?= h($q) ?>» (<?= count($resultados) ?><?= $truncado ? '+' : '' ?>)</h2></div>
        <div class="card-body">
        <?php if ($truncado): ?><p style="color: var(--text-secondary);">Se muestran los primeros 300 resultados: afiná la búsqueda.</p><?php endif; ?>
        <?php if (!$resultados): ?>
            <p class="text-center" style="padding: 2rem; color: var(--text-secondary);">No se encontró nada.</p>
        <?php else: ?>
            <div class="table-responsive"><table class="table">
                <thead><tr><th>Documento</th><th>Número</th><th>Proveedor</th><th>Fecha</th><th>Obra</th><th>Verificado contra el papel</th></tr></thead>
                <tbody>
                <?php foreach ($resultados as $r): ?>
                    <tr>
                        <td><?= h(NOMBRES_DOC[$r['tipo']]) ?></td>
                        <td class="text-bold"><?= h($r['numero']) ?></td>
                        <td><?= h($r['proveedor']) ?></td>
                        <td><?= h(fecha_corta($r['fecha'])) ?></td>
                        <td>
                            <a href="expedientes.php?obra=<?= (int)$r['obra_id'] ?>"><?= h($r['expediente']) ?></a>
                            <div style="font-size:.8rem; color: var(--text-secondary);">Concurso: <?= h(($r['nro_concurso'] ?? '') !== '' ? $r['nro_concurso'] : '—') ?> · <?= h($r['obra_nombre']) ?></div>
                        </td>
                        <td><?= celda_verificacion($r['tipo'], (int)$r['id'], $r['verificado_en'], $volver_actual) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
        </div>
    </div>

<?php elseif ($modo === 'obra'): ?>
    <?php if (!$obra): ?>
        <div class="card"><div class="card-body"><p>La obra no existe. <a href="expedientes.php">Volver al tablero</a></p></div></div>
    <?php else:
        $sec = [
            ['compra',  'Órdenes de compra', $docs_oc],
            ['factura', 'Facturas',          $docs_fact],
            ['remito',  'Remitos',           $docs_rem],
        ]; ?>
        <div class="card" style="margin-bottom: 1.5rem;">
            <div class="card-header">
                <h2 class="card-title"><?= h($obra['expediente']) ?> — <?= h($obra['nombre']) ?></h2>
                <a href="expedientes.php" class="btn btn-secondary btn-sm">Volver al tablero</a>
            </div>
            <div class="card-body">
                <p><strong>N.º de concurso:</strong>
                    <?php if (($obra['nro_concurso'] ?? '') !== ''): ?><?= h($obra['nro_concurso']) ?>
                    <?php else: ?><span class="badge badge-danger">Falta completar</span> <a href="obras.php?action=editar&id=<?= (int)$obra['id'] ?>">Editar obra</a><?php endif; ?>
                    · <strong>Estado:</strong> <?= h($obra['estado']) ?></p>
            </div>
        </div>
        <?php foreach ($sec as [$tipo, $titulo, $docs]):
            $v = count(array_filter($docs, fn($d) => !empty($d['verificado_en']))); ?>
            <div class="card" style="margin-bottom: 1.5rem;">
                <div class="card-header"><h3 class="card-title"><?= h($titulo) ?> — <?= vt($v, count($docs)) ?> verificadas</h3></div>
                <div class="card-body">
                <?php if (!$docs): ?>
                    <p style="color: var(--text-secondary);">Sin documentos.</p>
                <?php else: ?>
                    <div class="table-responsive"><table class="table">
                        <thead><tr><th>Número</th><th>Proveedor</th><th>Fecha</th><th>Verificado contra el papel</th></tr></thead>
                        <tbody>
                        <?php foreach ($docs as $d):
                            $numero = $tipo === 'compra' ? $d['nro_compra'] : ($tipo === 'factura' ? $d['nro_factura'] : (($d['nro_remito'] ?? '') !== '' ? $d['nro_remito'] : 'PENDIENTE'));
                            $fecha  = $tipo === 'compra' ? $d['fecha_compra'] : ($tipo === 'factura' ? $d['fecha_factura'] : $d['fecha_entrega']); ?>
                            <tr>
                                <td class="text-bold"><?= h($numero) ?></td>
                                <td><?= h($d['proveedor']) ?></td>
                                <td><?= h(fecha_corta($fecha)) ?></td>
                                <td><?= celda_verificacion($tipo, (int)$d['id'], $d['verificado_en'], $volver_actual) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

<?php else: ?>
    <?php if ($sin_concurso > 0): ?>
        <div class="alert-banner alert-banner-danger"><div class="alert-banner-text">
            <h4><?= $sin_concurso ?> obra(s) sin n.º de concurso</h4>
            <p>Editá cada obra (botón "Editar" en Obras) y completá el número de concurso de precios.</p>
        </div></div>
    <?php endif; ?>

    <form method="GET" action="expedientes.php" class="filter-bar">
        <div class="form-group">
            <label class="form-label" for="anio">Contar solo documentos del año</label>
            <select name="anio" id="anio" class="form-control" onchange="this.form.submit()">
                <option value="0">Todos los años</option>
                <?php foreach (array_keys($por_anio) as $y): if ($y === 0) continue; ?>
                    <option value="<?= $y ?>" <?= $y === $anio ? 'selected' : '' ?>><?= $y ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <noscript><button type="submit" class="btn btn-secondary">Aplicar</button></noscript>
    </form>

    <div class="card" style="margin-bottom: 2rem;">
        <div class="card-header"><h2 class="card-title">Obras<?= $anio ? ' — documentos de ' . $anio : '' ?></h2></div>
        <div class="card-body">
            <div class="table-responsive"><table class="table">
                <thead><tr><th>Expediente</th><th>Obra</th><th>N.º concurso</th><th>OC verif.</th><th>Facturas verif.</th><th>Remitos verif.</th><th style="min-width:160px;">Avance</th><th></th></tr></thead>
                <tbody>
                <?php
                $tot = ['oc' => [0, 0], 'fact' => [0, 0], 'rem' => [0, 0]];
                foreach ($obras as $o):
                    $c = $cont[$o['id']] ?? [];
                    $oc = $c['oc'] ?? [0, 0]; $fa = $c['fact'] ?? [0, 0]; $re = $c['rem'] ?? [0, 0];
                    foreach (['oc' => $oc, 'fact' => $fa, 'rem' => $re] as $k => $par) { $tot[$k][0] += $par[0]; $tot[$k][1] += $par[1]; }
                    if ($anio && $oc[1] + $fa[1] + $re[1] === 0) continue; ?>
                    <tr>
                        <td class="text-bold"><?= h($o['expediente']) ?></td>
                        <td><?= h($o['nombre']) ?></td>
                        <td><?= ($o['nro_concurso'] ?? '') !== '' ? h($o['nro_concurso']) : '<span class="badge badge-danger">Falta</span>' ?></td>
                        <td><?= vt($oc[0], $oc[1]) ?></td>
                        <td><?= vt($fa[0], $fa[1]) ?></td>
                        <td><?= vt($re[0], $re[1]) ?></td>
                        <td><?= barra($oc[0] + $fa[0] + $re[0], $oc[1] + $fa[1] + $re[1]) ?></td>
                        <td><a href="expedientes.php?obra=<?= (int)$o['id'] ?>" class="btn btn-secondary btn-sm">Verificar</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr>
                    <th colspan="3">Total</th>
                    <th><?= vt($tot['oc'][0], $tot['oc'][1]) ?></th>
                    <th><?= vt($tot['fact'][0], $tot['fact'][1]) ?></th>
                    <th><?= vt($tot['rem'][0], $tot['rem'][1]) ?></th>
                    <th colspan="2"><?= barra($tot['oc'][0] + $tot['fact'][0] + $tot['rem'][0], $tot['oc'][1] + $tot['fact'][1] + $tot['rem'][1]) ?></th>
                </tr></tfoot>
            </table></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2 class="card-title">Avance por año</h2></div>
        <div class="card-body">
        <?php if (!$por_anio): ?>
            <p style="color: var(--text-secondary);">Todavía no hay documentos.</p>
        <?php else: ?>
            <div class="table-responsive"><table class="table">
                <thead><tr><th>Año (fecha del documento)</th><th>OC verif.</th><th>Facturas verif.</th><th>Remitos verif.</th><th style="min-width:160px;">Avance</th></tr></thead>
                <tbody>
                <?php foreach ($por_anio as $y => $d):
                    $oc = $d['oc'] ?? [0, 0]; $fa = $d['fact'] ?? [0, 0]; $re = $d['rem'] ?? [0, 0]; ?>
                    <tr>
                        <td class="text-bold"><?= $y === 0 ? 'Sin fecha válida' : $y ?></td>
                        <td><?= vt($oc[0], $oc[1]) ?></td>
                        <td><?= vt($fa[0], $fa[1]) ?></td>
                        <td><?= vt($re[0], $re[1]) ?></td>
                        <td><?= barra($oc[0] + $fa[0] + $re[0], $oc[1] + $fa[1] + $re[1]) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php require_once 'footer.php'; ?>
