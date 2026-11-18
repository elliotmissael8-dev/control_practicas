<?php
declare(strict_types=1);
require __DIR__ . '/config/auth.php';
require __DIR__ . '/config/database.php';
require __DIR__ . '/config/historial.php';
$programas = [
    'Prácticas profesionales' => 240,
    'Servicio social' => 480,
];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function h(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function redirectTo(string $message, string $type = 'success'): never {
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
    header('Location: bitacora.php');
    exit;
}
function fechaMx(?string $fecha): string {
    if (!$fecha) return '—';
    $dt = DateTime::createFromFormat('!Y-m-d', $fecha);
    return $dt ? $dt->format('d/m/Y') : $fecha;
}
function hora(?string $valor): string {
    if ($valor === null || $valor === '') {
        return 'En curso';
    }

    return substr($valor, 0, 5);
}

function duracion(int $segundos): string {
    return sprintf('%dh %02dmin', intdiv($segundos, 3600), intdiv($segundos % 3600, 60));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        redirectTo('La sesión del formulario expiró. Intenta nuevamente.', 'error');
    }

    $accion = (string)($_POST['accion'] ?? '');

    if ($accion === 'cerrar_abierta') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        $salida = (string)($_POST['hora_salida'] ?? '');
        $actividades = trim((string)($_POST['actividades'] ?? ''));
        if (!$id) redirectTo('Identificador de registro no válido.', 'error');
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $salida)) {
            redirectTo('La hora de salida no es válida.', 'error');
        }
        $st = $pdo->prepare('SELECT estudiante_id, fecha, TIME_FORMAT(hora_entrada, \'%H:%i\') AS hora_entrada, actividades FROM bitacora_horas WHERE id = ? AND hora_salida IS NULL');
        $st->execute([$id]);
        $jornada = $st->fetch();
        if (!$jornada) {
            redirectTo('Esa entrada ya no está abierta.', 'error');
        }
        if ($salida <= $jornada['hora_entrada']) {
            redirectTo('La hora de salida debe ser posterior a la hora de entrada.', 'error');
        }
        $actividadesFinal = $actividades !== '' ? mb_substr($actividades, 0, 5000) : $jornada['actividades'];
        $up = $pdo->prepare('UPDATE bitacora_horas SET hora_salida = ?, actividades = ? WHERE id = ? AND hora_salida IS NULL');
        $up->execute([$salida, $actividadesFinal, $id]);
        if ($up->rowCount()) {
            historial_registrar($pdo, 'Bitácora', 'cerrar entrada', 'Entrada abierta cerrada manualmente: ' . historial_alumno($pdo, (int)$jornada['estudiante_id']) . ', ' . $jornada['fecha'] . ' ' . $jornada['hora_entrada'] . '–' . $salida . ($actividades !== '' ? ' (con actividades)' : ''));
            redirectTo('Entrada cerrada correctamente.');
        }
        redirectTo('No se pudo cerrar la entrada.', 'error');
    }

    if ($accion === 'eliminar') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if (!$id) redirectTo('Identificador de registro no válido.', 'error');
        $stAntes = $pdo->prepare('SELECT estudiante_id, fecha, TIME_FORMAT(hora_entrada, \'%H:%i\') AS hora_entrada, TIME_FORMAT(hora_salida, \'%H:%i\') AS hora_salida, actividades FROM bitacora_horas WHERE id = ?');
        $stAntes->execute([$id]);
        $antes = $stAntes->fetch() ?: [];
        $stmt = $pdo->prepare('DELETE FROM bitacora_horas WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->rowCount()) {
            historial_registrar($pdo, 'Bitácora', 'eliminar', 'Jornada eliminada: ' . historial_alumno($pdo, (int)($antes['estudiante_id'] ?? 0)) . ', ' . ($antes['fecha'] ?? '') . ' ' . ($antes['hora_entrada'] ?? '') . '–' . ($antes['hora_salida'] ?? ''));
        }
        redirectTo($stmt->rowCount() ? 'Registro eliminado correctamente.' : 'No se encontró el registro.', $stmt->rowCount() ? 'success' : 'error');
    }

    if ($accion === 'guardar') {
        $id = (int)($_POST['id'] ?? 0);
        $estudianteId = filter_input(INPUT_POST, 'estudiante_id', FILTER_VALIDATE_INT);
        $fecha = (string)($_POST['fecha'] ?? '');
        $entrada = (string)($_POST['hora_entrada'] ?? '');
        $salida = (string)($_POST['hora_salida'] ?? '');
        $actividades = trim((string)($_POST['actividades'] ?? ''));

        if (!$estudianteId || $fecha === '' || $entrada === '' || $salida === '' || $actividades === '') {
            redirectTo('Completa todos los campos obligatorios.', 'error');
        }
        $existe = $pdo->prepare('SELECT COUNT(*) FROM estudiantes_practicas WHERE id = ?');
        $existe->execute([$estudianteId]);
        if (!(int)$existe->fetchColumn()) {
            redirectTo('El estudiante seleccionado no existe.', 'error');
        }
        $dt = DateTime::createFromFormat('!Y-m-d', $fecha);
        if (!$dt || $dt->format('Y-m-d') !== $fecha) {
            redirectTo('La fecha no es válida.', 'error');
        }
        $patronHora = '/^([01]\d|2[0-3]):[0-5]\d$/';
        if (!preg_match($patronHora, $entrada) || !preg_match($patronHora, $salida)) {
            redirectTo('Las horas no son válidas.', 'error');
        }
        if ($salida <= $entrada) {
            redirectTo('La hora de salida debe ser posterior a la hora de entrada.', 'error');
        }

        try {
            if ($id > 0) {
                $stAntes = $pdo->prepare('SELECT estudiante_id, fecha, TIME_FORMAT(hora_entrada, \'%H:%i\') AS hora_entrada, TIME_FORMAT(hora_salida, \'%H:%i\') AS hora_salida, actividades FROM bitacora_horas WHERE id = ?');
                $stAntes->execute([$id]);
                $antes = $stAntes->fetch() ?: [];
                $stmt = $pdo->prepare('UPDATE bitacora_horas SET estudiante_id=?, fecha=?, hora_entrada=?, hora_salida=?, actividades=? WHERE id=?');
                $stmt->execute([$estudianteId, $fecha, $entrada, $salida, $actividades, $id]);
                historial_registrar($pdo, 'Bitácora', 'modificar', 'Jornada de ' . historial_alumno($pdo, (int)$estudianteId) . ' (' . $fecha . '): ' . historial_cambios($antes, [
                    'estudiante_id' => (string)$estudianteId, 'fecha' => $fecha, 'hora_entrada' => $entrada, 'hora_salida' => $salida, 'actividades' => $actividades,
                ], [
                    'estudiante_id' => 'Estudiante (ID)', 'fecha' => 'Fecha', 'hora_entrada' => 'Entrada', 'hora_salida' => 'Salida', 'actividades' => 'Actividades',
                ]));
                redirectTo('Registro modificado correctamente.');
            } else {
                $stmt = $pdo->prepare('INSERT INTO bitacora_horas (estudiante_id, fecha, hora_entrada, hora_salida, actividades) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$estudianteId, $fecha, $entrada, $salida, $actividades]);
                historial_registrar($pdo, 'Bitácora', 'crear', 'Jornada registrada manualmente: ' . historial_alumno($pdo, (int)$estudianteId) . ', ' . $fecha . ' ' . $entrada . '–' . $salida);
                redirectTo('Registro guardado correctamente.');
            }
        } catch (PDOException $e) {
            redirectTo('Ocurrió un error al guardar. Verifica que la tabla bitacora_horas tenga la columna estudiante_id.', 'error');
        }
    }
}

$estudiantes = $pdo->query('SELECT id, matricula, apellidos, nombre, fecha_inicio FROM estudiantes_practicas ORDER BY apellidos, nombre')->fetchAll();

$editar = null;
if (isset($_GET['editar'])) {
    $idEditar = filter_input(INPUT_GET, 'editar', FILTER_VALIDATE_INT);
    if ($idEditar) {
        $stmt = $pdo->prepare('SELECT * FROM bitacora_horas WHERE id = ?');
        $stmt->execute([$idEditar]);
        $editar = $stmt->fetch() ?: null;
    }
}

$filtro = filter_input(INPUT_GET, 'estudiante', FILTER_VALIDATE_INT) ?: 0;
$resumen = [];
$registros = [];
$errorTabla = null;
try {
    $resumen = $pdo->query('SELECT e.id, e.matricula, e.apellidos, e.nombre, e.tipo_programa, COUNT(b.id) AS jornadas, COALESCE(SUM(TIME_TO_SEC(TIMEDIFF(b.hora_salida, b.hora_entrada))), 0) AS segundos FROM estudiantes_practicas e LEFT JOIN bitacora_horas b ON b.estudiante_id = e.id GROUP BY e.id, e.matricula, e.apellidos, e.nombre, e.tipo_programa ORDER BY e.apellidos, e.nombre')->fetchAll();

    $sql = 'SELECT b.*, e.matricula, e.apellidos, e.nombre, TIME_TO_SEC(TIMEDIFF(b.hora_salida, b.hora_entrada)) AS segundos FROM bitacora_horas b LEFT JOIN estudiantes_practicas e ON e.id = b.estudiante_id';
    if ($filtro) {
        $stmt = $pdo->prepare($sql . ' WHERE b.estudiante_id = ? ORDER BY b.fecha DESC, b.hora_entrada DESC');
        $stmt->execute([$filtro]);
        $registros = $stmt->fetchAll();
    } else {
        $registros = $pdo->query($sql . ' ORDER BY b.fecha DESC, b.hora_entrada DESC')->fetchAll();
    }
} catch (PDOException $e) {
    $errorTabla = 'No se pudo leer la información. Verifica que las tablas tengan las columnas estudiante_id y tipo_programa. Detalle: ' . $e->getMessage();
}

$filas = [];
$detalle = null;
foreach ($resumen as $s) {
    $req = (int)($programas[$s['tipo_programa']] ?? 0);
    $hechos = (int)$s['segundos'];
    $rest = max(0, $req * 3600 - $hechos);
    $pct = $req > 0 ? min(100, round($hechos / ($req * 3600) * 100, 1)) : 0;
    $fila = $s + ['req' => $req, 'hechos' => $hechos, 'rest' => $rest, 'pct' => $pct];
    $filas[] = $fila;
    if ($filtro === (int)$s['id']) {
        $detalle = $fila;
    }
}

$seleccionado = (int)($editar['estudiante_id'] ?? $filtro);
$abiertas = [];
try {
    $abiertas = $pdo->query('SELECT b.id, b.fecha, b.hora_entrada, e.matricula, e.apellidos, e.nombre FROM bitacora_horas b LEFT JOIN estudiantes_practicas e ON e.id = b.estudiante_id WHERE b.hora_salida IS NULL ORDER BY b.fecha, b.hora_entrada')->fetchAll();
} catch (PDOException $ex) {
    $abiertas = [];
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Bitácora de horas | Prácticas Profesionales</title>
<style>
:root{--navy:#142b4a;--blue:#2457a7;--bg:#f3f6fb;--text:#243247;--muted:#64748b;--border:#dbe3ee}
*{box-sizing:border-box}body{margin:0;font-family:"Segoe UI",Arial,sans-serif;background:var(--bg);color:var(--text)}
header{color:#fff;padding:26px 20px;background:linear-gradient(120deg,#142b4a,#2457a7)}
.header-inner,main{max-width:1200px;margin:auto}.brand{display:flex;gap:14px;align-items:center}
h1{font-size:clamp(1.3rem,3vw,1.9rem);margin:0}header p{color:#dbeafe;margin:6px 0 0}
.marca-logo{height:55px;width:auto;border-radius:10px;object-fit:contain}
nav{display:flex;gap:8px;margin-top:16px;flex-wrap:wrap}nav a{color:#fff;text-decoration:none;padding:8px 14px;border-radius:8px;background:#ffffff22;font-weight:600;font-size:.88rem}nav a.activo{background:#fff;color:var(--navy)}
main{padding:25px 20px}.card{background:#fff;border:1px solid var(--border);border-radius:14px;box-shadow:0 8px 28px #142b4a0d;padding:23px;margin-bottom:20px}
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:15px;margin-bottom:16px}.stat{background:#f8fbff;border:1px solid var(--border);border-radius:12px;padding:15px 18px}.stat span{color:var(--muted);font-size:.84rem}.stat strong{display:block;font-size:1.6rem;color:var(--navy);margin-top:5px}
.card-head{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:18px}h2{font-size:1.15rem;color:var(--navy);margin:0}.hint{color:var(--muted);font-size:.88rem;margin:6px 0 0}
.form-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}.field{display:flex;flex-direction:column;gap:7px;min-width:0}.wide{grid-column:1/-1}label{font-weight:650;font-size:.88rem;color:#34445a}
input,select,textarea{width:100%;min-width:0;padding:11px 12px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:var(--text);font:inherit;font-size:.93rem}textarea{min-height:100px;resize:vertical}input:focus,select:focus,textarea:focus{outline:0;border-color:var(--blue);box-shadow:0 0 0 3px #2457a71f}
.buttons,.row-actions,.tools{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.buttons{grid-column:1/-1}.btn{display:inline-block;border:0;border-radius:8px;padding:10px 14px;font-weight:650;font-size:.88rem;text-decoration:none;cursor:pointer}.primary{background:var(--blue);color:#fff}.secondary{background:#edf2f8;color:#334155}.edit{background:#eaf1fb;color:#1d4d91;padding:7px 9px}.delete{background:#fff0ef;color:#b42318;padding:7px 9px}.tools select{min-width:260px}
.table-wrap{overflow-x:auto;border:1px solid var(--border);border-radius:10px}table{border-collapse:collapse;width:100%;min-width:1000px}th,td{text-align:left;padding:12px 13px;border-bottom:1px solid #e8edf4;font-size:.86rem;vertical-align:top}th{background:#f0f4fa;color:#34445a;font-size:.76rem;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap}tbody tr:hover{background:#f8fbff}tbody tr:last-child td{border-bottom:0}.nowrap{white-space:nowrap}.matricula{font-weight:700;color:var(--navy);white-space:nowrap}.horas{font-weight:700;color:var(--navy);white-space:nowrap}
.pill{display:inline-block;border-radius:20px;padding:5px 9px;font-weight:700;font-size:.75rem;white-space:nowrap;background:#dcfce7;color:#166534}.sin{color:var(--muted)}.sub{color:var(--muted);font-size:.78rem}
.barra{background:#e8edf4;border-radius:20px;height:8px;overflow:hidden;min-width:110px}.barra.grande{height:12px}.barra span{display:block;height:100%;background:var(--blue)}.pct{font-size:.78rem;color:var(--muted);margin-top:4px}
a.enlace{color:var(--blue);font-weight:650;text-decoration:none}a.enlace:hover{text-decoration:underline}
.flash{padding:12px 15px;border-radius:9px;margin-bottom:17px}.success{background:#dcfce7;color:#166534}.error{background:#fee2e2;color:#991b1b}.empty{text-align:center;color:var(--muted);padding:30px}
footer{text-align:center;color:var(--muted);padding:0 20px 25px;font-size:.82rem}
.cerrar-form{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.cerrar-form input{width:auto;min-width:0}.cerrar-form input[name=actividades]{flex:1 1 220px}
@media(max-width:760px){.stats{grid-template-columns:1fr 1fr}.form-grid{grid-template-columns:1fr}.wide,.buttons{grid-column:auto}.card{padding:17px}.tools,.tools select{width:100%;min-width:0}}
</style>
<link rel="stylesheet" href="assets/estilo.css?v=1">
</head>
<body class="pg-bitacora">
<?php $menuSeccion = 'bitacora'; $menuSubtitulo = 'Bitácora de horas y actividades por estudiante'; require __DIR__ . '/config/encabezado.php'; ?>
<main>
<?php if ($flash): ?><div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
<?php if ($errorTabla): ?><div class="flash error"><?= h($errorTabla) ?></div><?php endif; ?>

<section class="card">
<div class="card-head"><div><h2>Horas por estudiante</h2><p class="hint">Cada alumno cumple las horas de su programa: servicio social o prácticas profesionales.</p></div></div>
<div class="table-wrap"><table><thead><tr><th>Matrícula</th><th>Estudiante</th><th>Programa</th><th>Horas requeridas</th><th>Jornadas</th><th>Horas acumuladas</th><th>Horas restantes</th><th>Avance</th><th></th></tr></thead><tbody>
<?php if (!$filas): ?><tr><td colspan="9" class="empty">Aún no hay estudiantes registrados.</td></tr>
<?php else: foreach ($filas as $s): ?>
<tr>
<td class="matricula"><?= h($s['matricula']) ?></td>
<td><?= h($s['apellidos'] . ' ' . $s['nombre']) ?></td>
<td><?= h($s['tipo_programa']) ?></td>
<td class="horas"><?= (int)$s['req'] ?> h</td>
<td><?= (int)$s['jornadas'] ?></td>
<td class="horas"><?= h(duracion($s['hechos'])) ?></td>
<td class="horas"><?= $s['rest'] === 0 ? '<span class="pill">Completado</span>' : h(duracion($s['rest'])) ?></td>
<td><div class="barra"><span style="width:<?= $s['pct'] ?>%"></span></div><div class="pct"><?= $s['pct'] ?>%</div></td>
<td><a class="enlace" href="bitacora.php?estudiante=<?= (int)$s['id'] ?>#detalle">Ver jornadas</a></td>
</tr>
<?php endforeach; endif; ?>
</tbody></table></div>
</section>

<?php if ($abiertas): ?>
<section class="card" id="abiertas">
<div class="card-head"><div><h2>Entradas abiertas</h2><p class="hint">Jornadas que no tienen hora de salida. Escribe la hora de salida para cerrarlas; las actividades son opcionales.</p></div></div>
<div class="table-wrap"><table><thead><tr><th>Matrícula</th><th>Estudiante</th><th>Fecha</th><th>Entrada</th><th>Cerrar jornada</th></tr></thead><tbody>
<?php foreach ($abiertas as $ab): ?>
<tr>
<td class="matricula"><?= h($ab['matricula'] ?? '—') ?></td>
<td><?= h(trim(($ab['apellidos'] ?? '') . ' ' . ($ab['nombre'] ?? ''))) ?></td>
<td><?= h(fechaMx($ab['fecha'])) ?></td>
<td><?= h(substr((string)$ab['hora_entrada'], 0, 5)) ?></td>
<td><form method="post" class="cerrar-form">
<input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><input type="hidden" name="accion" value="cerrar_abierta"><input type="hidden" name="id" value="<?= (int)$ab['id'] ?>">
<input type="time" name="hora_salida" required aria-label="Hora de salida">
<input name="actividades" maxlength="5000" placeholder="Actividades (opcional)" aria-label="Actividades">
<button class="btn primary" type="submit">Cerrar</button></form></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
</section>
<?php endif; ?>

<section class="card" id="formulario">
<div class="card-head"><div><h2><?= $editar ? 'Modificar registro' : 'Registrar jornada' ?></h2><p class="hint">Los campos marcados con * son obligatorios.</p></div></div>
<?php if (!$estudiantes): ?>
  <p class="empty">Primero registra estudiantes en la sección <a href="index.php">Estudiantes</a>.</p>
<?php else: ?>
<form method="post" class="form-grid">
<input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
<input type="hidden" name="accion" value="guardar">
<input type="hidden" name="id" value="<?= h((string)($editar['id'] ?? '0')) ?>">
<div class="field wide"><label for="estudiante_id">Estudiante *</label>
<select id="estudiante_id" name="estudiante_id" required>
<option value="">Selecciona un estudiante</option>
<?php foreach ($estudiantes as $e): ?>
<option value="<?= (int)$e['id'] ?>" <?= $seleccionado === (int)$e['id'] ? 'selected' : '' ?>><?= h($e['matricula'] . ' — ' . $e['apellidos'] . ' ' . $e['nombre']) ?></option>
<?php endforeach; ?>
</select></div>
<div class="field"><label for="fecha">Fecha *</label><input id="fecha" type="date" name="fecha" required value="<?= h($editar['fecha'] ?? date('Y-m-d')) ?>"></div>
<div class="field"><label for="hora_entrada">Hora de entrada *</label><input id="hora_entrada" type="time" name="hora_entrada" required value="<?= h($editar ? hora($editar['hora_entrada']) : '') ?>"></div>
<div class="field"><label for="hora_salida">Hora de salida *</label><input id="hora_salida" type="time" name="hora_salida" required value="<?= h($editar ? hora($editar['hora_salida']) : '') ?>"></div>
<div class="field wide"><label for="actividades">Actividades realizadas *</label><textarea id="actividades" name="actividades" required maxlength="5000" placeholder="Describe las actividades realizadas durante la jornada"><?= h($editar['actividades'] ?? '') ?></textarea></div>
<div class="buttons"><button class="btn primary" type="submit"><?= $editar ? 'Guardar modificaciones' : 'Guardar registro' ?></button><?php if ($editar): ?><a class="btn secondary" href="bitacora.php">Cancelar edición</a><?php else: ?><button class="btn secondary" type="reset">Limpiar formulario</button><?php endif; ?></div>
</form>
<?php endif; ?>
</section>

<?php if ($detalle): ?>
<section class="card" id="detalle">
<div class="card-head"><div><h2>Horas de <?= h($detalle['apellidos'] . ' ' . $detalle['nombre']) ?></h2><p class="hint"><?= h($detalle['matricula']) ?> · <?= h($detalle['tipo_programa']) ?> · <?= (int)$detalle['req'] ?> horas requeridas</p></div><a class="btn secondary" href="bitacora.php">Ver todos</a></div>
<div class="stats">
<div class="stat"><span>Jornadas registradas</span><strong><?= (int)$detalle['jornadas'] ?></strong></div>
<div class="stat"><span>Horas acumuladas</span><strong><?= h(duracion($detalle['hechos'])) ?></strong></div>
<div class="stat"><span>Horas restantes</span><strong><?= $detalle['rest'] === 0 ? 'Completado' : h(duracion($detalle['rest'])) ?></strong></div>
<div class="stat"><span>Avance</span><strong><?= $detalle['pct'] ?>%</strong></div>
</div>
<div class="barra grande"><span style="width:<?= $detalle['pct'] ?>%"></span></div>
</section>
<?php endif; ?>

<section class="card" id="reporte">
<div class="card-head"><div><h2>Reporte bimestral</h2><p class="hint">Cada periodo dura dos meses y comienza en la fecha de inicio de cada alumno. Se propone el estudiante que tengas filtrado.</p></div></div>
<form method="get" action="reportes.php" class="form-grid">
<div class="field"><label for="rep_estudiante">Estudiante</label>
<select id="rep_estudiante" name="estudiante">
<option value="0">Todos los estudiantes</option>
<?php foreach ($estudiantes as $e): ?>
<option value="<?= (int)$e['id'] ?>" data-inicio="<?= h((string)$e['fecha_inicio']) ?>" <?= $filtro === (int)$e['id'] ? 'selected' : '' ?>><?= h($e['matricula'] . ' — ' . $e['apellidos'] . ' ' . $e['nombre']) ?></option>
<?php endforeach; ?>
</select></div>
<div class="field"><label for="rep_periodo">Periodo</label><select id="rep_periodo" name="periodo" required></select></div>
<div class="buttons"><button class="btn primary" type="submit" name="formato" value="xlsx">Descargar Excel</button><button class="btn primary" type="submit" name="formato" value="pdf">Descargar PDF</button><button class="btn primary" type="submit" name="formato" value="docx">Descargar Word</button></div>
</form>
</section>

<section class="card" id="registro">
<div class="card-head"><div><h2>Registro de jornadas</h2><p class="hint">Consulta, modifica o elimina las jornadas registradas.</p></div>
<form method="get" class="tools">
<select name="estudiante" aria-label="Filtrar por estudiante" onchange="this.form.submit()">
<option value="0">Todos los estudiantes</option>
<?php foreach ($estudiantes as $e): ?>
<option value="<?= (int)$e['id'] ?>" <?= $filtro === (int)$e['id'] ? 'selected' : '' ?>><?= h($e['matricula'] . ' — ' . $e['apellidos'] . ' ' . $e['nombre']) ?></option>
<?php endforeach; ?>
</select>
<?php if ($filtro): ?><a class="btn secondary" href="bitacora.php#registro">Limpiar</a><?php endif; ?>
</form></div>
<div class="table-wrap"><table><thead><tr><th>Estudiante</th><th>Fecha</th><th>Entrada</th><th>Salida</th><th>Horas</th><th>Actividades</th><th>Acciones</th></tr></thead><tbody>
<?php if (!$registros): ?><tr><td colspan="7" class="empty">Aún no hay jornadas registradas.</td></tr>
<?php else: foreach ($registros as $r): ?>
<tr>
<td><?php if (!empty($r['matricula'])): ?><span class="matricula"><?= h($r['matricula']) ?></span><br><?= h($r['apellidos'] . ' ' . $r['nombre']) ?><?php else: ?><span class="sin">Sin asignar</span><?php endif; ?></td>
<td class="nowrap"><?= h(fechaMx($r['fecha'])) ?></td>
<td class="nowrap"><?= h(hora($r['hora_entrada'])) ?></td>
<td class="nowrap"><?= h(hora($r['hora_salida'])) ?></td>
<td class="horas"><?= h(duracion((int)$r['segundos'])) ?></td>
<td><?= nl2br(h($r['actividades'])) ?></td>
<td><div class="row-actions"><a class="btn edit" href="bitacora.php?editar=<?= (int)$r['id'] ?>#formulario">Modificar</a>
<form method="post" onsubmit="return confirm('¿Seguro que deseas eliminar el registro del <?= h(fechaMx($r['fecha'])) ?>? Esta acción no se puede deshacer.');">
<input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn delete" type="submit">Eliminar</button></form></div></td>
</tr>
<?php endforeach; endif; ?>
</tbody></table></div>
</section>
</main>
<?php require __DIR__ . '/config/footer.php'; ?>
<script>
const entrada = document.getElementById('hora_entrada');
const salida = document.getElementById('hora_salida');
if (entrada && salida) {
  entrada.addEventListener('change', () => { salida.min = entrada.value; });
  if (entrada.value) salida.min = entrada.value;
}
</script>
<script>
(function(){
  const est = document.getElementById('rep_estudiante'), per = document.getElementById('rep_periodo');
  if (!est || !per) return;
  const hoy = new Date();
  const dos = n => String(n).padStart(2, '0');
  const fmt = d => dos(d.getDate()) + '/' + dos(d.getMonth() + 1) + '/' + d.getFullYear();
  const leer = s => { const p = s.split('-').map(Number); return new Date(p[0], p[1] - 1, p[2]); };
  function suma(base, m){ // suma meses sin desbordar el día
    const y = base.getFullYear(), mes = base.getMonth() + m;
    return new Date(y, mes, Math.min(base.getDate(), new Date(y, mes + 1, 0).getDate()));
  }
  function periodo(base, n){ const h = suma(base, 2 * n); h.setDate(h.getDate() - 1); return [suma(base, 2 * (n - 1)), h]; }
  function actual(base){ let n = 1; while (n < 500 && suma(base, 2 * n) <= hoy) n++; return n; }
  function agregar(v, texto){ const o = document.createElement('option'); o.value = v; o.textContent = texto; per.appendChild(o); }
  function llenar(){
    per.innerHTML = '';
    const opt = est.options[est.selectedIndex], ini = opt ? (opt.dataset.inicio || '') : '';
    if (est.value !== '0' && ini) {
      const base = leer(ini);
      for (let i = actual(base); i >= 1; i--) { const p = periodo(base, i); agregar(i, 'Periodo ' + i + ': ' + fmt(p[0]) + ' al ' + fmt(p[1])); }
    } else {
      let max = 1;
      Array.from(est.options).forEach(o => { if (o.dataset.inicio) max = Math.max(max, actual(leer(o.dataset.inicio))); });
      agregar(0, 'Periodo actual de cada alumno');
      for (let i = max; i >= 1; i--) agregar(i, 'Periodo ' + i + ' de cada alumno');
    }
  }
  est.addEventListener('change', llenar); llenar();
})();
</script>
</body>
</html>