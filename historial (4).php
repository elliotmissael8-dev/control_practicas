<?php
declare(strict_types=1);
require __DIR__ . '/config/auth.php';
require __DIR__ . '/config/database.php';
require __DIR__ . '/config/historial.php';

const HIST_DIAS = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];
const HIST_MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
const HIST_LIMITE_SEMANA = 1000;

function h(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
// "martes 17 de noviembre"
function histDia(DateTimeImmutable $d): string {
    return HIST_DIAS[(int)$d->format('N') - 1] . ' ' . $d->format('j') . ' de ' . HIST_MESES[(int)$d->format('n') - 1];
}
// "10 al 16 de noviembre de 2026" (o "30 de octubre al 5 de noviembre de 2026")
function histRango(DateTimeImmutable $lunes): string {
    $dom = $lunes->modify('+6 days');
    $mes = static fn(DateTimeImmutable $d): string => HIST_MESES[(int)$d->format('n') - 1];
    if ($lunes->format('Y-n') === $dom->format('Y-n')) {
        return $lunes->format('j') . ' al ' . $dom->format('j') . ' de ' . $mes($dom) . ' de ' . $dom->format('Y');
    }
    if ($lunes->format('Y') === $dom->format('Y')) {
        return $lunes->format('j') . ' de ' . $mes($lunes) . ' al ' . $dom->format('j') . ' de ' . $mes($dom) . ' de ' . $dom->format('Y');
    }
    return $lunes->format('j') . ' de ' . $mes($lunes) . ' de ' . $lunes->format('Y') . ' al ' . $dom->format('j') . ' de ' . $mes($dom) . ' de ' . $dom->format('Y');
}
// "10-16 nov"
function histCorto(DateTimeImmutable $lunes): string {
    $dom = $lunes->modify('+6 days');
    $m = static fn(DateTimeImmutable $d): string => mb_substr(HIST_MESES[(int)$d->format('n') - 1], 0, 3);
    return $lunes->format('Y-n') === $dom->format('Y-n')
        ? $lunes->format('j') . '-' . $dom->format('j') . ' ' . $m($dom)
        : $lunes->format('j') . ' ' . $m($lunes) . ' - ' . $dom->format('j') . ' ' . $m($dom);
}
function lunesDe(string $fecha, DateTimeZone $zona): ?DateTimeImmutable {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha, $zona);
    if (!$d || $d->format('Y-m-d') !== $fecha) {
        return null;
    }
    return $d->modify('-' . ((int)$d->format('N') - 1) . ' days');
}

$zona = new DateTimeZone('America/Mexico_City');
$hoy = new DateTimeImmutable('today', $zona);
$modulo = trim((string)($_GET['modulo'] ?? ''));
$actor = trim((string)($_GET['actor'] ?? ''));
$lunes = lunesDe((string)($_GET['fecha'] ?? ''), $zona) ?? lunesDe((string)($_GET['semana'] ?? ''), $zona);

$claseModulo = ['Estudiantes' => 'm-est', 'Documentos' => 'm-doc', 'Bitácora' => 'm-bit', 'Recordatorios' => 'm-rec', 'Administradores' => 'm-adm', 'Respaldo' => 'm-res', 'Sesión' => 'm-ses'];

$semanas = [];      // [lunes => cantidad], de la más reciente a la más antigua
$modulos = [];
$actores = [];
$porDia = [];
$totalSemana = 0;
$errorTabla = null;
try {
    historial_asegurar($pdo);
    $modulos = $pdo->query('SELECT DISTINCT modulo FROM historial ORDER BY modulo')->fetchAll(PDO::FETCH_COLUMN);
    $actores = $pdo->query('SELECT DISTINCT actor FROM historial ORDER BY actor')->fetchAll(PDO::FETCH_COLUMN);

    $where = [];
    $params = [];
    if ($modulo !== '') { $where[] = 'modulo = ?'; $params[] = $modulo; }
    if ($actor !== '') { $where[] = 'actor = ?'; $params[] = $actor; }
    $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $st = $pdo->prepare('SELECT DATE_SUB(DATE(fecha), INTERVAL WEEKDAY(fecha) DAY) AS lunes, COUNT(*) AS n FROM historial' . $w . ' GROUP BY lunes ORDER BY lunes DESC LIMIT 60');
    $st->execute($params);
    foreach ($st->fetchAll() as $r) {
        $semanas[(string)$r['lunes']] = (int)$r['n'];
    }
    if ($lunes === null && $semanas) {
        $lunes = lunesDe((string)array_key_first($semanas), $zona);
    }
    if ($lunes !== null) {
        $ini = $lunes->format('Y-m-d') . ' 00:00:00';
        $fin = $lunes->modify('+7 days')->format('Y-m-d') . ' 00:00:00';
        $st = $pdo->prepare('SELECT fecha, actor, actor_tipo, modulo, accion, detalle, ip FROM historial WHERE fecha >= ? AND fecha < ?'
            . ($modulo !== '' ? ' AND modulo = ?' : '') . ($actor !== '' ? ' AND actor = ?' : '')
            . ' ORDER BY fecha DESC, id DESC LIMIT ' . HIST_LIMITE_SEMANA);
        $st->execute(array_merge([$ini, $fin], $params));
        foreach ($st->fetchAll() as $r) {
            $porDia[substr((string)$r['fecha'], 0, 10)][] = $r;
            $totalSemana++;
        }
    }
} catch (PDOException $e) {
    $errorTabla = 'No se pudo leer el historial.';
}

// Semana anterior y siguiente que tienen movimientos
$anterior = null;
$siguiente = null;
if ($lunes !== null) {
    $actual = $lunes->format('Y-m-d');
    foreach (array_keys($semanas) as $l) {          // de la más reciente a la más antigua
        $l = (string)$l;
        if ($l > $actual) {
            $siguiente = $l;                         // la última que se asigne es la más cercana por arriba
        } elseif ($l < $actual && $anterior === null) {
            $anterior = $l;
        }
    }
}

function hl(array $extra, string $modulo, string $actor, ?DateTimeImmutable $lunes): string {
    $q = array_merge(['modulo' => $modulo, 'actor' => $actor, 'semana' => $lunes ? $lunes->format('Y-m-d') : ''], $extra);
    return 'historial.php?' . http_build_query(array_filter($q, static fn($v): bool => $v !== '' && $v !== null));
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
main{max-width:1100px;margin:auto;padding:25px 20px}
.card{background:#fff;border:1px solid var(--border);border-radius:14px;box-shadow:0 8px 28px #142b4a0d;padding:20px;margin-bottom:20px}
h2{font-size:1.15rem;color:var(--navy);margin:0}.hint{color:var(--muted);font-size:.86rem;margin:4px 0 0}
.btn{display:inline-block;border:0;border-radius:8px;padding:9px 14px;font-weight:650;font-size:.86rem;text-decoration:none;cursor:pointer;background:#edf2f8;color:#334155}.primary{background:var(--blue);color:#fff}
.btn.off{opacity:.4;pointer-events:none}
input,select{padding:9px 10px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:var(--text);font:inherit;font-size:.88rem}
.flash{padding:12px 15px;border-radius:9px;margin-bottom:17px}.error{background:#fee2e2;color:#991b1b}.empty{text-align:center;color:var(--muted);padding:34px 20px}

/* Navegación por semanas */
.sem-nav{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.sem-titulo{text-align:center;flex:1;min-width:220px}.sem-titulo strong{display:block;font-size:1.1rem;color:var(--navy)}
.semanas{display:flex;gap:6px;overflow-x:auto;padding:12px 0 4px;margin-top:6px;border-top:1px solid var(--border)}
.semanas a{flex:0 0 auto;text-decoration:none;color:#334155;background:#f3f6fb;border:1px solid var(--border);border-radius:99px;padding:6px 12px;font-size:.8rem;font-weight:600;white-space:nowrap}
.semanas a span{color:var(--muted);font-weight:500;margin-left:4px}
.semanas a.sel{background:var(--blue);color:#fff;border-color:var(--blue)}.semanas a.sel span{color:#dbeafe}

/* Filtros */
.filtros{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.chips{display:flex;gap:6px;flex-wrap:wrap}
.chips a{text-decoration:none;font-size:.8rem;font-weight:650;color:#334155;background:#fff;border:1px solid var(--border);border-left:4px solid var(--mc,#94a3b8);border-radius:8px;padding:5px 10px}
.chips a.sel{background:var(--navy);color:#fff;border-color:var(--navy)}
.filtros-fila{display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between;margin-bottom:12px}

/* Días y movimientos */
.dia{margin-bottom:12px}
.dia>summary{cursor:pointer;list-style:none;display:flex;justify-content:space-between;align-items:center;gap:10px;background:#eaf1fc;color:var(--navy);border-radius:10px;padding:10px 14px;font-weight:700;font-size:.92rem}
.dia>summary::-webkit-details-marker{display:none}
.dia>summary .n{font-weight:600;color:#475569;font-size:.8rem}
.dia[open]>summary{margin-bottom:8px}
.mov{border-left:4px solid var(--mc,#94a3b8);background:#fff;border-radius:8px;margin:0 0 6px;box-shadow:0 1px 2px rgba(20,43,74,.08);border-top:1px solid #eef1f6;border-right:1px solid #eef1f6;border-bottom:1px solid #eef1f6}
.mov>summary{display:grid;grid-template-columns:46px minmax(110px,170px) minmax(140px,190px) 1fr;gap:10px;align-items:center;padding:9px 12px;cursor:pointer;list-style:none;font-size:.86rem}
.mov>summary::-webkit-details-marker{display:none}
.mov[open]>summary{background:#f8fafd}
.mov .hora{color:var(--muted);font-variant-numeric:tabular-nums}
.mov .quien{font-weight:650;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.mov .tipo{font-weight:500;color:var(--muted);font-size:.74rem;margin-left:4px}
.mov .que{white-space:nowrap}.mov .que b{color:var(--navy)}
.mov .resumen{color:#475569;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.mov-cuerpo{padding:6px 14px 12px 70px;font-size:.86rem;line-height:1.55}.mov-cuerpo small{display:block;color:var(--muted);margin-top:6px}
.m-est{--mc:#2563eb}.m-doc{--mc:#0f766e}.m-bit{--mc:#b45309}.m-rec{--mc:#be185d}.m-adm{--mc:#9f1239}.m-res{--mc:#15803d}.m-ses{--mc:#64748b}
@media(max-width:760px){.mov>summary{grid-template-columns:44px 1fr}.mov .que,.mov .resumen{grid-column:2}.mov-cuerpo{padding-left:14px}.sem-titulo{order:-1;flex-basis:100%}}
</style>
<link rel="stylesheet" href="assets/estilo.css?v=1">
</head>
<body class="pg-historial">
<?php $menuSeccion = 'historial'; $menuSubtitulo = 'Historial de modificaciones'; require __DIR__ . '/config/encabezado.php'; ?>
<main>
<?php if ($errorTabla): ?><div class="flash error"><?= h($errorTabla) ?></div><?php endif; ?>

<section class="card">
<div class="sem-nav">
<a class="btn <?= $anterior ? '' : 'off' ?>" href="<?= $anterior ? h(hl(['semana' => $anterior], $modulo, $actor, null)) : '#' ?>">Semana anterior</a>
<div class="sem-titulo">
<?php if ($lunes): ?>
<strong>Semana del <?= h(histRango($lunes)) ?></strong>
<span class="hint"><?= $totalSemana ?> movimiento(s)<?= $lunes->format('Y-m-d') === lunesDe($hoy->format('Y-m-d'), $zona)?->format('Y-m-d') ? ' · esta semana' : '' ?><?= $totalSemana >= HIST_LIMITE_SEMANA ? ' · se muestran los ' . HIST_LIMITE_SEMANA . ' más recientes' : '' ?></span>
<?php else: ?><strong>Sin movimientos todavía</strong><?php endif; ?>
</div>
<a class="btn <?= $siguiente ? '' : 'off' ?>" href="<?= $siguiente ? h(hl(['semana' => $siguiente], $modulo, $actor, null)) : '#' ?>">Semana siguiente</a>
</div>
<?php if ($semanas): ?>
<div class="semanas" aria-label="Semanas con movimientos">
<?php foreach ($semanas as $l => $n): $d = lunesDe((string)$l, $zona); if (!$d) continue; ?>
<a href="<?= h(hl(['semana' => (string)$l], $modulo, $actor, null)) ?>"<?= $lunes && (string)$l === $lunes->format('Y-m-d') ? ' class="sel"' : '' ?>><?= h(histCorto($d)) ?><span><?= (int)$n ?></span></a>
<?php endforeach; ?>
</div>
<?php endif; ?>
</section>

<section class="card">
<div class="filtros-fila">
<div class="chips" aria-label="Filtrar por módulo">
<a href="<?= h(hl(['modulo' => ''], '', $actor, $lunes)) ?>"<?= $modulo === '' ? ' class="sel"' : '' ?>>Todos</a>
<?php foreach ($modulos as $m): ?>
<a class="<?= h($claseModulo[$m] ?? 'm-otro') ?><?= $modulo === $m ? ' sel' : '' ?>" href="<?= h(hl(['modulo' => (string)$m], '', $actor, $lunes)) ?>"><?= h((string)$m) ?></a>
<?php endforeach; ?>
</div>
<form method="get" class="filtros">
<input type="hidden" name="modulo" value="<?= h($modulo) ?>">
<?php if ($lunes): ?><input type="hidden" name="semana" value="<?= h($lunes->format('Y-m-d')) ?>"><?php endif; ?>
<select name="actor" aria-label="Filtrar por persona" onchange="this.form.submit()">
<option value="">Todas las personas</option>
<?php foreach ($actores as $a): ?><option value="<?= h((string)$a) ?>" <?= $actor === $a ? 'selected' : '' ?>><?= h((string)$a) ?></option><?php endforeach; ?>
</select>
<input type="date" name="fecha" aria-label="Ir a la semana de esta fecha" onchange="this.form.submit()">
<?php if ($modulo !== '' || $actor !== ''): ?><a class="btn" href="historial.php">Quitar filtros</a><?php endif; ?>
</form>
</div>

<?php if (!$porDia): ?>
<div class="empty"><?= $semanas ? 'No hay movimientos con esos filtros en esta semana.' : 'Todavía no hay movimientos registrados.' ?></div>
<?php else: ?>
<div style="display:flex;gap:8px;justify-content:flex-end;margin-bottom:10px">
<button type="button" class="btn" onclick="document.querySelectorAll('.dia').forEach(function(d){d.open=true})">Abrir todos los días</button>
<button type="button" class="btn" onclick="document.querySelectorAll('.dia').forEach(function(d){d.open=false})">Cerrar todos los días</button>
</div>
<?php $primero = true; foreach ($porDia as $dia => $movs): $d = DateTimeImmutable::createFromFormat('!Y-m-d', $dia, $zona);
    $rotulo = $d ? histDia($d) : $dia;
    if ($d && $d->format('Y-m-d') === $hoy->format('Y-m-d')) { $rotulo = 'Hoy · ' . $rotulo; }
    elseif ($d && $d->format('Y-m-d') === $hoy->modify('-1 day')->format('Y-m-d')) { $rotulo = 'Ayer · ' . $rotulo; }
?>
<details class="dia"<?= ($primero || $totalSemana <= 15) ? ' open' : '' ?>>
<summary><span><?= h($rotulo) ?></span><span class="n"><?= count($movs) ?> movimiento(s)</span></summary>
<?php foreach ($movs as $r): ?>
<details class="mov <?= h($claseModulo[$r['modulo']] ?? 'm-otro') ?>">
<summary>
<span class="hora"><?= h(substr((string)$r['fecha'], 11, 5)) ?></span>
<span class="quien"><?= h($r['actor']) ?><?php if ($r['actor_tipo'] !== 'admin'): ?><span class="tipo"><?= h($r['actor_tipo']) ?></span><?php endif; ?></span>
<span class="que"><b><?= h($r['modulo']) ?></b> · <?= h($r['accion']) ?></span>
<span class="resumen"><?= h($r['detalle']) ?></span>
</summary>
<div class="mov-cuerpo"><?= nl2br(h($r['detalle'])) ?><small><?= h(substr((string)$r['fecha'], 0, 16)) ?> · IP <?= h((string)($r['ip'] ?? '')) ?: '—' ?></small></div>
</details>
<?php endforeach; ?>
</details>
<?php $primero = false; endforeach; ?>
<?php endif; ?>
</section>
</main>
<?php require __DIR__ . '/config/footer.php'; ?>
</body>
</html>
