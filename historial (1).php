<?php
declare(strict_types=1);
require __DIR__ . '/config/auth.php';
require __DIR__ . '/config/database.php';
require __DIR__ . '/config/historial.php';

function h(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function fechaHoraMx(?string $f): string {
    $dt = $f ? DateTime::createFromFormat('Y-m-d H:i:s', $f) : false;
    return $dt ? $dt->format('d/m/Y H:i') : '—';
}
function fechaValida(string $f): bool {
    $dt = DateTime::createFromFormat('!Y-m-d', $f);
    return $dt && $dt->format('Y-m-d') === $f;
}

$porPagina = 50;
$modulo = trim((string)($_GET['modulo'] ?? ''));
$actor = trim((string)($_GET['actor'] ?? ''));
$desde = fechaValida((string)($_GET['desde'] ?? '')) ? (string)$_GET['desde'] : '';
$hasta = fechaValida((string)($_GET['hasta'] ?? '')) ? (string)$_GET['hasta'] : '';
$pagina = max(1, (int)($_GET['p'] ?? 1));

$registros = [];
$modulos = [];
$actores = [];
$total = 0;
$errorTabla = null;
try {
    historial_asegurar($pdo);
    $modulos = $pdo->query('SELECT DISTINCT modulo FROM historial ORDER BY modulo')->fetchAll(PDO::FETCH_COLUMN);
    $actores = $pdo->query('SELECT DISTINCT actor FROM historial ORDER BY actor')->fetchAll(PDO::FETCH_COLUMN);

    $where = [];
    $params = [];
    if ($modulo !== '') { $where[] = 'modulo = ?'; $params[] = $modulo; }
    if ($actor !== '') { $where[] = 'actor = ?'; $params[] = $actor; }
    if ($desde !== '') { $where[] = 'fecha >= ?'; $params[] = $desde . ' 00:00:00'; }
    if ($hasta !== '') { $where[] = 'fecha <= ?'; $params[] = $hasta . ' 23:59:59'; }
    $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $st = $pdo->prepare('SELECT COUNT(*) FROM historial' . $w);
    $st->execute($params);
    $total = (int)$st->fetchColumn();
    $paginas = max(1, (int)ceil($total / $porPagina));
    $pagina = min($pagina, $paginas);
    $offset = ($pagina - 1) * $porPagina;

    $st = $pdo->prepare('SELECT fecha, actor, actor_tipo, modulo, accion, detalle, ip FROM historial' . $w . ' ORDER BY fecha DESC, id DESC LIMIT ' . $porPagina . ' OFFSET ' . $offset);
    $st->execute($params);
    $registros = $st->fetchAll();
} catch (PDOException $e) {
    $errorTabla = 'No se pudo leer el historial.';
    $paginas = 1;
}

function enlacePagina(int $p, string $modulo, string $actor, string $desde, string $hasta): string {
    return 'historial.php?' . http_build_query(['modulo' => $modulo, 'actor' => $actor, 'desde' => $desde, 'hasta' => $hasta, 'p' => $p]);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Historial de modificaciones | Prácticas Profesionales</title>
<style>
:root{--navy:#142b4a;--blue:#2457a7;--bg:#f3f6fb;--text:#243247;--muted:#64748b;--border:#dbe3ee}
*{box-sizing:border-box}body{margin:0;font-family:"Segoe UI",Arial,sans-serif;background:var(--bg);color:var(--text)}
header{color:#fff;padding:26px 20px;background:linear-gradient(120deg,#142b4a,#2457a7)}
.header-inner,main{max-width:1200px;margin:auto}.brand{display:flex;gap:20px;align-items:center}
h1{font-size:clamp(1.3rem,3vw,1.9rem);margin:0}header p{color:#dbeafe;margin:6px 0 0}
.marca-logo{height:55px;width:auto;border-radius:10px;object-fit:contain}
nav{display:flex;gap:8px;margin-top:16px;flex-wrap:wrap}nav a{color:#fff;text-decoration:none;padding:8px 14px;border-radius:8px;background:#ffffff22;font-weight:600;font-size:.88rem}nav a.activo{background:#fff;color:var(--navy)}
main{padding:25px 20px}.card{background:#fff;border:1px solid var(--border);border-radius:14px;box-shadow:0 8px 28px #142b4a0d;padding:23px;margin-bottom:20px}
.card-head{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:18px}h2{font-size:1.15rem;color:var(--navy);margin:0}.hint{color:var(--muted);font-size:.88rem;margin:6px 0 0}
.filtros{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:16px}.field{display:flex;flex-direction:column;gap:7px;min-width:0}label{font-weight:650;font-size:.88rem;color:#34445a}
input,select{width:100%;min-width:0;padding:11px 12px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:var(--text);font:inherit;font-size:.93rem}input:focus,select:focus{outline:0;border-color:var(--blue);box-shadow:0 0 0 3px #2457a71f}
.buttons{grid-column:1/-1;display:flex;gap:8px;flex-wrap:wrap}.btn{display:inline-block;border:0;border-radius:8px;padding:10px 14px;font-weight:650;font-size:.88rem;text-decoration:none;cursor:pointer}.primary{background:var(--blue);color:#fff}.secondary{background:#edf2f8;color:#334155}
.table-wrap{overflow-x:auto;border:1px solid var(--border);border-radius:10px}table{border-collapse:collapse;width:100%;min-width:900px}th,td{text-align:left;padding:11px 13px;border-bottom:1px solid #e8edf4;font-size:.85rem;vertical-align:top}th{background:#f0f4fa;color:#34445a;font-size:.76rem;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap}tbody tr:hover{background:#f8fbff}tbody tr:last-child td{border-bottom:0}
.nowrap{white-space:nowrap}.badge{display:inline-block;padding:2px 8px;border-radius:99px;background:#edf2f8;color:#334155;font-size:.72rem;font-weight:700}.badge.alumno{background:#dcfce7;color:#166534}.badge.admin{background:#dbeafe;color:#1e40af}
.pag{display:flex;gap:10px;align-items:center;justify-content:center;margin-top:16px;color:var(--muted);font-size:.88rem}
.flash{padding:12px 15px;border-radius:9px;margin-bottom:17px}.error{background:#fee2e2;color:#991b1b}.empty{text-align:center;color:var(--muted);padding:30px}
footer{text-align:center;color:var(--muted);padding:0 20px 25px;font-size:.82rem}
@media(max-width:760px){.filtros{grid-template-columns:1fr}.card{padding:17px}}
</style>
</head>
<body>
<header><div class="header-inner">
  <div class="brand"><img class="marca-logo" src="https://esiima.uaa.mx/cursoextension/imgs/logouaa2.jpg" alt="Logo UAA"><div><h1>Control de Prácticas Profesionales y Servicio Social</h1><p>Historial de modificaciones</p><p>Departamento de Sistemas de Información</p></div></div>
  <nav><a href="index.php">Estudiantes</a><a href="documentos.php">Documentos</a><a href="bitacora.php">Bitácora</a><a href="qr.php">Código QR</a><a href="historial.php" class="activo">Historial</a><a href="administradores.php">Administradores</a><a href="login.php?salir=1">Cerrar sesión</a></nav>
</div></header>
<main>
<?php if ($errorTabla): ?><div class="flash error"><?= h($errorTabla) ?></div><?php endif; ?>
<section class="card">
<div class="card-head"><div><h2>Historial</h2><p class="hint">Quién hizo cada cambio, cuándo y desde qué IP. Los más recientes aparecen primero.</p></div></div>
<form method="get" class="filtros">
<div class="field"><label for="modulo">Módulo</label><select id="modulo" name="modulo"><option value="">Todos</option>
<?php foreach ($modulos as $m): ?><option value="<?= h((string)$m) ?>" <?= $modulo === $m ? 'selected' : '' ?>><?= h((string)$m) ?></option><?php endforeach; ?></select></div>
<div class="field"><label for="actor">Quién</label><select id="actor" name="actor"><option value="">Todos</option>
<?php foreach ($actores as $a): ?><option value="<?= h((string)$a) ?>" <?= $actor === $a ? 'selected' : '' ?>><?= h((string)$a) ?></option><?php endforeach; ?></select></div>
<div class="field"><label for="desde">Desde</label><input id="desde" type="date" name="desde" value="<?= h($desde) ?>"></div>
<div class="field"><label for="hasta">Hasta</label><input id="hasta" type="date" name="hasta" value="<?= h($hasta) ?>"></div>
<div class="buttons"><button class="btn primary" type="submit">Filtrar</button><a class="btn secondary" href="historial.php">Limpiar</a></div>
</form>
<div class="table-wrap"><table><thead><tr><th>Fecha</th><th>Quién</th><th>Módulo</th><th>Acción</th><th>Detalle</th><th>IP</th></tr></thead><tbody>
<?php if (!$registros): ?><tr><td colspan="6" class="empty">No hay movimientos registrados.</td></tr>
<?php else: foreach ($registros as $r): ?>
<tr>
<td class="nowrap"><?= h(fechaHoraMx($r['fecha'])) ?></td>
<td><strong><?= h($r['actor']) ?></strong> <span class="badge <?= h($r['actor_tipo']) ?>"><?= h($r['actor_tipo']) ?></span></td>
<td class="nowrap"><?= h($r['modulo']) ?></td>
<td class="nowrap"><?= h($r['accion']) ?></td>
<td><?= nl2br(h($r['detalle'])) ?></td>
<td class="nowrap"><?= h((string)$r['ip']) ?></td>
</tr>
<?php endforeach; endif; ?>
</tbody></table></div>
<?php if ($total > 0): ?>
<div class="pag">
<?php if ($pagina > 1): ?><a class="btn secondary" href="<?= h(enlacePagina($pagina - 1, $modulo, $actor, $desde, $hasta)) ?>">← Anterior</a><?php endif; ?>
<span>Página <?= $pagina ?> de <?= $paginas ?> · <?= $total ?> movimiento(s)</span>
<?php if ($pagina < $paginas): ?><a class="btn secondary" href="<?= h(enlacePagina($pagina + 1, $modulo, $actor, $desde, $hasta)) ?>">Siguiente →</a><?php endif; ?>
</div>
<?php endif; ?>
</section>
</main>
<footer>© 2026 · Control de Prácticas Profesionales y Servicio Social del edificio 55</footer>
</body>
</html>
