<?php
declare(strict_types=1);
// Recordatorios por correo para que cada alumno haga su reporte bimestral.
// - Desde el navegador (administrador): muestra a quién le toca y envía con un botón.
// - Desde el Programador de tareas de Windows: C:\xampp\php\php.exe C:\xampp\htdocs\control_practicas_sql\recordatorios.php
$esCli = PHP_SAPI === 'cli';
if (!$esCli) {
    require __DIR__ . '/config/auth.php';
}
require __DIR__ . '/config/database.php';
require __DIR__ . '/config/historial.php';
require __DIR__ . '/config/migraciones.php';
require __DIR__ . '/config/correo.php';
require __DIR__ . '/config/reporte.php';
require __DIR__ . '/config/qr.php';
migrar($pdo);

// Se avisa desde 7 días antes de que termine el periodo y hasta 7 días después (una sola vez por periodo).
const RECORDATORIO_DIAS_ANTES = 7;
const RECORDATORIO_DIAS_DESPUES = 7;

function h(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Alumnos "En curso" a quienes les toca recordatorio, con su situación: enviar, ya_enviado, sin_correo o entregado.
function recordatorios_pendientes(PDO $pdo): array {
    $zona = new DateTimeZone('America/Mexico_City');
    $hoy = new DateTimeImmutable('today', $zona);
    $hoyTxt = $hoy->format('Y-m-d');

    $enviados = [];
    foreach ($pdo->query('SELECT estudiante_id, periodo FROM recordatorios_reporte')->fetchAll() as $r) {
        $enviados[(int)$r['estudiante_id'] . ':' . (int)$r['periodo']] = true;
    }
    $entrego = $pdo->prepare("SELECT COUNT(*) FROM documentos_entregados WHERE estudiante_id = ? AND tipo_documento IN ('Reporte parcial', 'Reporte final') AND fecha_entrega >= ? AND revision <> 'Rechazado'");
    $alumnos = $pdo->query("SELECT id, matricula, apellidos, nombre, correo, fecha_inicio, tipo_programa FROM estudiantes_practicas WHERE estado = 'En curso' ORDER BY apellidos, nombre")->fetchAll();

    $filas = [];
    foreach ($alumnos as $a) {
        $inicio = (string)$a['fecha_inicio'];
        if (!rep_inicio_valido($inicio)) {
            continue;
        }
        $actual = rep_periodo_actual($inicio, $hoyTxt);
        foreach ([$actual - 1, $actual] as $p) {
            if ($p < 1) {
                continue;
            }
            [$desde, $hasta] = rep_periodo($inicio, $p);
            $dias = (int)$hoy->diff(new DateTimeImmutable($hasta, $zona))->format('%r%a');
            if ($dias > RECORDATORIO_DIAS_ANTES || $dias < -RECORDATORIO_DIAS_DESPUES) {
                continue;
            }
            $correo = trim((string)($a['correo'] ?? ''));
            $entrego->execute([(int)$a['id'], $desde]);
            if (isset($enviados[(int)$a['id'] . ':' . $p])) {
                $estado = 'ya_enviado';
            } elseif ($correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                $estado = 'sin_correo';
            } elseif ((int)$entrego->fetchColumn() > 0) {
                $estado = 'entregado';
            } else {
                $estado = 'enviar';
            }
            $filas[] = [
                'id' => (int)$a['id'],
                'alumno' => $a['matricula'] . ' — ' . trim($a['apellidos'] . ' ' . $a['nombre']),
                'nombre' => trim((string)$a['nombre']),
                'programa' => (string)$a['tipo_programa'],
                'correo' => $correo,
                'periodo' => $p,
                'desde' => $desde,
                'hasta' => $hasta,
                'dias' => $dias,
                'estado' => $estado,
            ];
            break; // un recordatorio por alumno
        }
    }
    return $filas;
}

// Envía los recordatorios pendientes. Devuelve ['enviados' => n, 'errores' => n, 'ultimo_error' => texto].
function recordatorios_enviar(PDO $pdo): array {
    $res = ['enviados' => 0, 'errores' => 0, 'ultimo_error' => ''];
    $base = QR_BASE_URL !== '' ? rtrim(QR_BASE_URL, '/') : 'http://localhost/control_practicas_sql';
    foreach (recordatorios_pendientes($pdo) as $f) {
        if ($f['estado'] !== 'enviar') {
            continue;
        }
        $cuando = $f['dias'] > 0 ? 'en ' . $f['dias'] . ' día(s)' : ($f['dias'] === 0 ? 'hoy' : 'hace ' . abs($f['dias']) . ' día(s)');
        $texto = 'Hola ' . $f['nombre'] . ",\r\n\r\n"
            . 'Tu periodo ' . $f['periodo'] . ' de ' . $f['programa'] . ' (' . rep_fecha($f['desde']) . ' al ' . rep_fecha($f['hasta']) . ') termina ' . $cuando . ".\r\n"
            . "Recuerda generar tu reporte bimestral y entregarlo:\r\n\r\n"
            . "1. Escanea el código QR del Edificio 55 y escribe tu matrícula.\r\n"
            . "2. En \"Mi reporte bimestral\" elige tu periodo, escribe la clave de asistencia y descarga el reporte (PDF, Excel o Word).\r\n"
            . "3. Si tu coordinador te lo pide, súbelo en \"Mis documentos\" como \"Reporte parcial\" para que lo revise.\r\n\r\n"
            . 'Guía de uso: ' . $base . "/manual.php#alumnos\r\n\r\n"
            . "Si ya entregaste tu reporte, ignora este mensaje.\r\n"
            . "Departamento de Sistemas de Información\r\n";
        if (enviar_correo($f['correo'], 'Recordatorio: tu reporte bimestral', $texto)) {
            $ahora = (new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City')))->format('Y-m-d H:i:s');
            $pdo->prepare('INSERT IGNORE INTO recordatorios_reporte (estudiante_id, periodo, enviado_en) VALUES (?, ?, ?)')->execute([$f['id'], $f['periodo'], $ahora]);
            historial_registrar($pdo, 'Recordatorios', 'enviar', 'Recordatorio de reporte enviado a ' . $f['alumno'] . ' (periodo ' . $f['periodo'] . ')');
            $res['enviados']++;
        } else {
            $res['errores']++;
            $res['ultimo_error'] = correo_ultimo_error();
        }
    }
    if ($res['errores'] > 0) {
        historial_registrar($pdo, 'Recordatorios', 'error', $res['errores'] . ' recordatorio(s) no se pudieron enviar: ' . $res['ultimo_error']);
    }
    return $res;
}

if ($esCli) {
    $r = recordatorios_enviar($pdo);
    echo 'Recordatorios enviados: ' . $r['enviados'] . ' | con error: ' . $r['errores'] . ($r['ultimo_error'] !== '' ? ' | ' . $r['ultimo_error'] : '') . PHP_EOL;
    exit($r['errores'] > 0 ? 1 : 0);
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function redirectTo(string $message, string $type = 'success'): never {
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
    header('Location: recordatorios.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        redirectTo('La sesión del formulario expiró. Intenta nuevamente.', 'error');
    }
    @set_time_limit(0);
    try {
        $r = recordatorios_enviar($pdo);
    } catch (Throwable $e) {
        redirectTo('No se pudieron enviar los recordatorios. Revisa la configuración del correo.', 'error');
    }
    if ($r['errores'] > 0) {
        redirectTo('Enviados: ' . $r['enviados'] . '. Con error: ' . $r['errores'] . ' (' . $r['ultimo_error'] . ').', 'error');
    }
    redirectTo($r['enviados'] > 0 ? 'Se enviaron ' . $r['enviados'] . ' recordatorio(s).' : 'No había recordatorios pendientes por enviar.');
}

$filas = [];
$errorTabla = null;
try {
    $filas = recordatorios_pendientes($pdo);
} catch (PDOException $e) {
    $errorTabla = 'No se pudo leer la información de los alumnos.';
}
$porEnviar = count(array_filter($filas, static fn(array $f): bool => $f['estado'] === 'enviar'));
$etiquetas = ['enviar' => ['Por enviar', 'b-Pendiente'], 'ya_enviado' => ['Ya enviado', 'b-Aprobado'], 'sin_correo' => ['Sin correo', 'b-Rechazado'], 'entregado' => ['Ya entregó reporte', 'b-Aprobado']];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Recordatorios | Prácticas Profesionales</title>
<style>
:root{--navy:#142b4a;--blue:#2457a7;--bg:#f3f6fb;--text:#243247;--muted:#64748b;--border:#dbe3ee}
*{box-sizing:border-box}body{margin:0;font-family:"Segoe UI",Arial,sans-serif;background:var(--bg);color:var(--text)}
header{color:#fff;padding:26px 20px;background:linear-gradient(120deg,#142b4a,#2457a7)}
.header-inner,main{max-width:1200px;margin:auto}.brand{display:flex;gap:20px;align-items:center}
h1{font-size:clamp(1.3rem,3vw,1.9rem);margin:0}header p{color:#dbeafe;margin:6px 0 0}
.marca-logo{height:55px;width:auto;border-radius:10px;object-fit:contain}
nav{display:flex;gap:8px;margin-top:16px;flex-wrap:wrap}nav a{color:#fff;text-decoration:none;padding:8px 14px;border-radius:8px;background:#ffffff22;font-weight:600;font-size:.88rem}nav a.activo{background:#fff;color:var(--navy)}
main{padding:25px 20px}.card{background:#fff;border:1px solid var(--border);border-radius:14px;box-shadow:0 8px 28px #142b4a0d;padding:23px;margin-bottom:20px}
h2{font-size:1.15rem;color:var(--navy);margin:0}.hint{color:var(--muted);font-size:.88rem;margin:6px 0 0}.card-head{margin-bottom:16px}
.btn{display:inline-block;border:0;border-radius:8px;padding:11px 16px;font-weight:650;font-size:.9rem;cursor:pointer}.primary{background:var(--blue);color:#fff}
.table-wrap{overflow-x:auto;border:1px solid var(--border);border-radius:10px}table{border-collapse:collapse;width:100%;min-width:760px}th,td{text-align:left;padding:12px 13px;border-bottom:1px solid #e8edf4;font-size:.88rem;vertical-align:top}th{background:#f0f4fa;color:#34445a;font-size:.76rem;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap}tbody tr:last-child td{border-bottom:0}
.badge{display:inline-block;padding:2px 9px;border-radius:99px;font-size:.74rem;font-weight:700}.b-Pendiente{background:#fef3c7;color:#92400e}.b-Aprobado{background:#dcfce7;color:#166534}.b-Rechazado{background:#fee2e2;color:#991b1b}
.flash{padding:12px 15px;border-radius:9px;margin-bottom:17px}.success{background:#dcfce7;color:#166534}.error{background:#fee2e2;color:#991b1b}.empty{text-align:center;color:var(--muted);padding:30px}
details{margin-top:14px;font-size:.9rem}summary{cursor:pointer;font-weight:650;color:var(--navy)}code{background:#edf2f8;padding:1px 6px;border-radius:5px;font-size:.88em;word-break:break-all}
</style>
<link rel="stylesheet" href="assets/estilo.css?v=1">
</head>
<body class="pg-recordatorios">
<?php $menuSeccion = 'recordatorios'; $menuSubtitulo = 'Recordatorios del reporte bimestral'; require __DIR__ . '/config/encabezado.php'; ?>
<main>
<?php if ($flash): ?><div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
<?php if ($errorTabla): ?><div class="flash error"><?= h($errorTabla) ?></div><?php endif; ?>

<section class="card">
<div class="card-head"><h2>A quién le toca recordatorio</h2>
<p class="hint">Se avisa por correo a los alumnos <strong>En curso</strong> desde <?= RECORDATORIO_DIAS_ANTES ?> días antes de que termine su periodo bimestral (y hasta <?= RECORDATORIO_DIAS_DESPUES ?> días después), una sola vez por periodo. No se envía a quien ya entregó un reporte en ese periodo ni a quien no tiene correo registrado.</p></div>
<div class="table-wrap"><table><thead><tr><th>Alumno</th><th>Correo</th><th>Periodo</th><th>Termina</th><th>Situación</th></tr></thead><tbody>
<?php if (!$filas): ?><tr><td colspan="5" class="empty">Nadie está cerca del cierre de su periodo.</td></tr>
<?php else: foreach ($filas as $f): [$texto, $clase] = $etiquetas[$f['estado']]; ?>
<tr>
<td><strong><?= h($f['alumno']) ?></strong></td>
<td><?= h($f['correo'] !== '' ? $f['correo'] : '—') ?></td>
<td>Periodo <?= (int)$f['periodo'] ?><br><span class="hint"><?= h(rep_fecha($f['desde'])) ?> al <?= h(rep_fecha($f['hasta'])) ?></span></td>
<td><?= h(rep_fecha($f['hasta'])) ?><br><span class="hint"><?= $f['dias'] > 0 ? 'en ' . (int)$f['dias'] . ' día(s)' : ($f['dias'] === 0 ? 'hoy' : 'hace ' . abs((int)$f['dias']) . ' día(s)') ?></span></td>
<td><span class="badge <?= h($clase) ?>"><?= h($texto) ?></span></td>
</tr>
<?php endforeach; endif; ?>
</tbody></table></div>
<form method="post" style="margin-top:16px">
<input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
<button class="btn primary" type="submit" <?= $porEnviar === 0 ? 'disabled style="opacity:.5;cursor:not-allowed"' : '' ?>>Enviar <?= $porEnviar ?> recordatorio(s) ahora</button>
</form>
</section>


</main>
<?php require __DIR__ . '/config/footer.php'; ?>
</body>
</html>
