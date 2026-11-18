<?php
declare(strict_types=1);
require __DIR__ . '/config/auth.php';
require __DIR__ . '/config/database.php';
require __DIR__ . '/config/archivos.php';
require __DIR__ . '/config/historial.php';
require __DIR__ . '/config/migraciones.php';
migrar($pdo);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function h(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function redirectTo(string $message, string $type = 'success'): never {
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
    header('Location: documentos.php');
    exit;
}
function fechaMx(?string $fecha): string {
    if (!$fecha) return '—';
    $dt = DateTime::createFromFormat('!Y-m-d', $fecha);
    return $dt ? $dt->format('d/m/Y') : $fecha;
}

$tiposDocumento = [
    'Carta de presentación',
    'Carta de aceptación',
    'Reporte parcial',
    'Reporte final',
    'Evaluación del tutor',
    'Carta de terminación',
    'Otro',
];

$carpetaRelativa = DOCS_RUTA_RELATIVA;
docs_preparar_base();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        redirectTo('La sesión del formulario expiró. Intenta nuevamente.', 'error');
    }

    $accion = (string)($_POST['accion'] ?? '');

    if ($accion === 'revisar') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        $decision = (string)($_POST['decision'] ?? '');
        $motivo = mb_substr(trim((string)($_POST['motivo'] ?? '')), 0, 255);
        if (!$id || !in_array($decision, ['Aprobado', 'Rechazado'], true)) {
            redirectTo('Datos de revisión no válidos.', 'error');
        }
        if ($decision === 'Rechazado' && $motivo === '') {
            redirectTo('Escribe el motivo del rechazo para que el alumno sepa qué corregir.', 'error');
        }
        $st = $pdo->prepare("SELECT d.tipo_documento, d.estudiante_id FROM documentos_entregados d WHERE d.id = ? AND d.revision = 'Pendiente'");
        $st->execute([$id]);
        $doc = $st->fetch();
        if (!$doc) {
            redirectTo('Ese documento ya fue revisado.', 'error');
        }
        $up = $pdo->prepare("UPDATE documentos_entregados SET revision = ?, revisado_por = ?, revisado_en = ?, motivo_revision = ? WHERE id = ? AND revision = 'Pendiente'");
        $up->execute([$decision, (string)($_SESSION['admin_usuario'] ?? ''), (new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City')))->format('Y-m-d H:i:s'), $decision === 'Rechazado' ? $motivo : null, $id]);
        historial_registrar($pdo, 'Documentos', $decision === 'Aprobado' ? 'aprobar' : 'rechazar', 'Documento ' . $doc['tipo_documento'] . ' de ' . historial_alumno($pdo, (int)$doc['estudiante_id']) . ': ' . $decision . ($decision === 'Rechazado' ? ' — ' . $motivo : ''));
        redirectTo($decision === 'Aprobado' ? 'Documento aprobado.' : 'Documento rechazado. El alumno verá el motivo.');
    }

    if ($accion === 'eliminar') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if (!$id) redirectTo('Identificador de registro no válido.', 'error');

        $stmt = $pdo->prepare('SELECT d.archivo_ruta, d.tipo_documento, e.matricula, e.apellidos, e.nombre, e.estado FROM documentos_entregados d JOIN estudiantes_practicas e ON e.id = d.estudiante_id WHERE d.id = ?');
        $stmt->execute([$id]);
        $docAntes = $stmt->fetch() ?: [];
        $rutaArchivo = $docAntes['archivo_ruta'] ?? false;
        if (in_array($docAntes['estado'] ?? '', ['Baja', 'Finalizado'], true)) {
            redirectTo('Los documentos de alumnos dados de baja o finalizados no se pueden eliminar: se conservan para el respaldo.', 'error');
        }

        $stmt = $pdo->prepare('DELETE FROM documentos_entregados WHERE id = ?');
        $stmt->execute([$id]);
        $eliminado = $stmt->rowCount() > 0;

        if ($eliminado && is_string($rutaArchivo)) {
            docs_borrar_archivo($rutaArchivo);
        }
        if ($eliminado) {
            historial_registrar($pdo, 'Documentos', 'eliminar', 'Documento eliminado: ' . ($docAntes['tipo_documento'] ?? '') . ' de ' . ($docAntes['matricula'] ?? '') . ' — ' . trim(($docAntes['apellidos'] ?? '') . ' ' . ($docAntes['nombre'] ?? '')) . (!empty($docAntes['archivo_ruta']) ? ' (con archivo)' : ''));
        }
        redirectTo($eliminado ? 'Documento eliminado correctamente.' : 'No se encontró el registro.', $eliminado ? 'success' : 'error');
    }

    if ($accion === 'guardar') {
        $estudianteId = filter_input(INPUT_POST, 'estudiante_id', FILTER_VALIDATE_INT);
        $tipo = trim((string)($_POST['tipo_documento'] ?? ''));
        $fecha = (string)($_POST['fecha_entrega'] ?? '');
        $observaciones = trim((string)($_POST['observaciones'] ?? ''));

        if (!$estudianteId || $tipo === '' || $fecha === '') {
            redirectTo('Completa todos los campos obligatorios.', 'error');
        }
        if (!in_array($tipo, $tiposDocumento, true)) {
            redirectTo('El tipo de documento no es válido.', 'error');
        }
        $dt = DateTime::createFromFormat('!Y-m-d', $fecha);
        if (!$dt || $dt->format('Y-m-d') !== $fecha) {
            redirectTo('La fecha de entrega no es válida.', 'error');
        }

        $stmt = $pdo->prepare('SELECT apellidos, nombre FROM estudiantes_practicas WHERE id = ?');
        $stmt->execute([$estudianteId]);
        $alumno = $stmt->fetch();
        if (!$alumno) {
            redirectTo('El estudiante seleccionado no existe.', 'error');
        }

        $archivoNombre = null;
        $archivoRuta = null;
        if (isset($_FILES['archivo']) && $_FILES['archivo']['error'] !== UPLOAD_ERR_NO_FILE) {
            try {
                [$archivoRuta, $archivoNombre] = docs_guardar_archivo($_FILES['archivo'], $estudianteId, $alumno['apellidos'] . ' ' . $alumno['nombre'] . ' ' . $tipo, (string)$alumno['apellidos']);
            } catch (RuntimeException $e) {
                redirectTo($e->getMessage(), 'error');
            }
        }

        try {
            $stmt = $pdo->prepare('INSERT INTO documentos_entregados (estudiante_id, tipo_documento, fecha_entrega, observaciones, archivo_nombre, archivo_ruta) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$estudianteId, $tipo, $fecha, $observaciones !== '' ? $observaciones : null, $archivoNombre, $archivoRuta]);
            historial_registrar($pdo, 'Documentos', 'crear', 'Documento registrado: ' . $tipo . ' de ' . historial_alumno($pdo, $estudianteId) . ($archivoRuta !== null ? ' (con archivo)' : ' (sin archivo)'));
            redirectTo('Documento registrado correctamente.');
        } catch (PDOException $e) {
            docs_borrar_archivo($archivoRuta);
            redirectTo('No se pudo guardar. Verifica que el estudiante exista y que la tabla tenga las columnas del archivo.', 'error');
        }
    }
}

$estudiantes = $pdo->query('SELECT id, matricula, apellidos, nombre FROM estudiantes_practicas ORDER BY apellidos, nombre')->fetchAll();

$filtro = filter_input(INPUT_GET, 'estudiante', FILTER_VALIDATE_INT) ?: 0;
$documentos = [];
$errorTabla = null;
try {
    if ($filtro) {
        $stmt = $pdo->prepare('SELECT d.*, e.matricula, e.apellidos, e.nombre FROM documentos_entregados d JOIN estudiantes_practicas e ON e.id = d.estudiante_id WHERE d.estudiante_id = ? ORDER BY d.fecha_entrega DESC, d.id DESC');
        $stmt->execute([$filtro]);
        $documentos = $stmt->fetchAll();
    } else {
        $documentos = $pdo->query('SELECT d.*, e.matricula, e.apellidos, e.nombre FROM documentos_entregados d JOIN estudiantes_practicas e ON e.id = d.estudiante_id ORDER BY d.fecha_entrega DESC, d.id DESC')->fetchAll();
    }
} catch (PDOException $e) {
    $errorTabla = 'No se pudo leer la tabla documentos_entregados. Detalle: ' . $e->getMessage();
}

$pendientesRevision = 0;
try {
    $pendientesRevision = (int)$pdo->query("SELECT COUNT(*) FROM documentos_entregados WHERE revision = 'Pendiente'")->fetchColumn();
} catch (PDOException $ex) {
    $pendientesRevision = 0;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Documentos entregados | Prácticas Profesionales</title>
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
.form-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}.field{display:flex;flex-direction:column;gap:7px;min-width:0}.wide{grid-column:1/-1}label{font-weight:650;font-size:.88rem;color:#34445a}
input,select,textarea{width:100%;min-width:0;padding:11px 12px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:var(--text);font:inherit;font-size:.93rem}textarea{min-height:85px;resize:vertical}input:focus,select:focus,textarea:focus{outline:0;border-color:var(--blue);box-shadow:0 0 0 3px #2457a71f}
.buttons,.tools{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.buttons{grid-column:1/-1}.btn{display:inline-block;border:0;border-radius:8px;padding:10px 14px;font-weight:650;font-size:.88rem;text-decoration:none;cursor:pointer}.primary{background:var(--blue);color:#fff}.secondary{background:#edf2f8;color:#334155}.delete{background:#fff0ef;color:#b42318;padding:7px 9px}.tools select{min-width:260px}
.table-wrap{overflow-x:auto;border:1px solid var(--border);border-radius:10px}table{border-collapse:collapse;width:100%;min-width:1000px}th,td{text-align:left;padding:12px 13px;border-bottom:1px solid #e8edf4;font-size:.86rem;vertical-align:top}th{background:#f0f4fa;color:#34445a;font-size:.76rem;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap}tbody tr:hover{background:#f8fbff}tbody tr:last-child td{border-bottom:0}.matricula{font-weight:700;color:var(--navy);white-space:nowrap}
a.enlace{color:var(--blue);font-weight:650;text-decoration:none;word-break:break-all}a.enlace:hover{text-decoration:underline}
.flash{padding:12px 15px;border-radius:9px;margin-bottom:17px}.success{background:#dcfce7;color:#166534}.error{background:#fee2e2;color:#991b1b}.empty{text-align:center;color:var(--muted);padding:30px}
.badge{display:inline-block;padding:2px 9px;border-radius:99px;font-size:.74rem;font-weight:700}.b-Pendiente{background:#fef3c7;color:#92400e}.b-Aprobado{background:#dcfce7;color:#166534}.b-Rechazado{background:#fee2e2;color:#991b1b}.sub{color:var(--muted);font-size:.78rem}.aviso{background:#fff7e6;border:1px solid #fde1a8;color:#7a4b00;border-radius:9px;padding:12px 15px;margin-bottom:17px}.rev-form{display:grid;gap:6px;margin-bottom:8px;min-width:210px}
footer{text-align:center;color:var(--muted);padding:0 20px 25px;font-size:.82rem}
@media(max-width:760px){.form-grid{grid-template-columns:1fr}.wide,.buttons{grid-column:auto}.card{padding:17px}.tools,.tools select{width:100%;min-width:0}}
</style>
<link rel="stylesheet" href="assets/estilo.css?v=1">
</head>
<body class="pg-documentos">
<?php $menuSeccion = 'documentos'; $menuSubtitulo = 'Documentos entregados por los estudiantes'; require __DIR__ . '/config/encabezado.php'; ?>
<main>
<?php if ($flash): ?><div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
<?php if ($errorTabla): ?><div class="flash error"><?= h($errorTabla) ?></div><?php endif; ?>
<?php if ($pendientesRevision > 0): ?><div class="aviso"><strong><?= $pendientesRevision ?></strong> documento(s) subido(s) por alumnos esperan tu revisión. Búscalos con el estado <em>Pendiente</em> en la tabla.</div><?php endif; ?>

<section class="card">
<div class="card-head"><div><h2>Registrar documento entregado</h2><p class="hint">Los campos marcados con * son obligatorios. El archivo escaneado es opcional.</p></div></div>
<?php if (!$estudiantes): ?>
  <p class="empty">Primero registra estudiantes en la sección <a href="index.php">Estudiantes</a>.</p>
<?php else: ?>
<form method="post" enctype="multipart/form-data" class="form-grid">
<input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
<input type="hidden" name="accion" value="guardar">
<div class="field"><label for="estudiante_id">Estudiante *</label>
<select id="estudiante_id" name="estudiante_id" required>
<option value="">Selecciona un estudiante</option>
<?php foreach ($estudiantes as $e): ?>
<option value="<?= (int)$e['id'] ?>"><?= h($e['matricula'] . ' — ' . $e['apellidos'] . ' ' . $e['nombre']) ?></option>
<?php endforeach; ?>
</select></div>
<div class="field"><label for="tipo_documento">Documento *</label>
<select id="tipo_documento" name="tipo_documento" required>
<?php foreach ($tiposDocumento as $t): ?><option value="<?= h($t) ?>"><?= h($t) ?></option><?php endforeach; ?>
</select></div>
<div class="field"><label for="fecha_entrega">Fecha de entrega *</label><input id="fecha_entrega" type="date" name="fecha_entrega" required value="<?= date('Y-m-d') ?>"></div>
<div class="field wide"><label for="archivo">Archivo escaneado (PDF, JPG o PNG, máx. 10 MB)</label><input id="archivo" type="file" name="archivo" accept="application/pdf,image/jpeg,image/png"></div>
<div class="field wide"><label for="observaciones">Observaciones</label><textarea id="observaciones" name="observaciones" maxlength="5000" placeholder="Notas sobre el documento (opcional)"></textarea></div>
<div class="buttons"><button class="btn primary" type="submit">Guardar documento</button><button class="btn secondary" type="reset">Limpiar formulario</button></div>
</form>
<?php endif; ?>
</section>

<section class="card">
<div class="card-head"><div><h2>Documentos registrados</h2><p class="hint">Consulta, abre o elimina los documentos entregados.</p></div>
<form method="get" class="tools">
<select name="estudiante" aria-label="Filtrar por estudiante" onchange="this.form.submit()">
<option value="0">Todos los estudiantes</option>
<?php foreach ($estudiantes as $e): ?>
<option value="<?= (int)$e['id'] ?>" <?= $filtro === (int)$e['id'] ? 'selected' : '' ?>><?= h($e['matricula'] . ' — ' . $e['apellidos'] . ' ' . $e['nombre']) ?></option>
<?php endforeach; ?>
</select>
<?php if ($filtro): ?><a class="btn secondary" href="documentos.php">Limpiar</a><?php endif; ?>
</form></div>
<div class="table-wrap"><table><thead><tr><th>Matrícula</th><th>Estudiante</th><th>Documento</th><th>Fecha de entrega</th><th>Archivo</th><th>Estado</th><th>Observaciones</th><th>Acciones</th></tr></thead><tbody>
<?php if (!$documentos): ?><tr><td colspan="8" class="empty">No hay documentos registrados.</td></tr>
<?php else: foreach ($documentos as $d): ?>
<tr>
<td class="matricula"><?= h($d['matricula']) ?></td>
<td><?= h($d['apellidos'] . ' ' . $d['nombre']) ?></td>
<td><?= h($d['tipo_documento']) ?></td>
<td><?= h(fechaMx($d['fecha_entrega'])) ?></td>
<td><?php if (!empty($d['archivo_ruta'])): ?><a class="enlace" href="<?= h($carpetaRelativa . '/' . $d['archivo_ruta']) ?>" target="_blank" rel="noopener"><?= h($d['archivo_nombre'] ?: 'Ver archivo') ?></a><?php else: ?>—<?php endif; ?></td>
<td><?php $rev = (string)($d['revision'] ?? 'Aprobado'); ?><span class="badge b-<?= h($rev) ?>"><?= h($rev) ?></span><?php if (($d['origen'] ?? 'admin') === 'alumno'): ?><br><span class="sub">Subido por el alumno</span><?php endif; ?><?php if ($rev === 'Rechazado' && !empty($d['motivo_revision'])): ?><br><span class="sub">Motivo: <?= h($d['motivo_revision']) ?></span><?php endif; ?><?php if (!empty($d['revisado_por'])): ?><br><span class="sub">Revisó: <?= h($d['revisado_por']) ?></span><?php endif; ?></td>
<td><?= nl2br(h($d['observaciones'] ?: '—')) ?></td>
<td><?php if (($d['revision'] ?? 'Aprobado') === 'Pendiente'): ?>
<form method="post" class="rev-form"><input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><input type="hidden" name="accion" value="revisar"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><input name="motivo" maxlength="255" placeholder="Motivo (obligatorio si rechazas)" aria-label="Motivo"><button class="btn primary" type="submit" name="decision" value="Aprobado">Aprobar</button><button class="btn delete" type="submit" name="decision" value="Rechazado">Rechazar</button></form>
<?php endif; ?><form method="post" onsubmit="return confirm('¿Seguro que deseas eliminar este documento y su archivo? Esta acción no se puede deshacer.');">
<input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><button class="btn delete" type="submit">Eliminar</button></form></td>
</tr>
<?php endforeach; endif; ?>
</tbody></table></div>
</section>
</main>
<?php require __DIR__ . '/config/footer.php'; ?>
</body>
</html>