<?php
declare(strict_types=1);
date_default_timezone_set('America/Mexico_City');
session_start();
require __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/qr.php';
require __DIR__ . '/config/clave.php';
require __DIR__ . '/config/reporte.php';
require __DIR__ . '/config/historial.php';
require __DIR__ . '/config/migraciones.php';
require __DIR__ . '/config/horario.php';
migrar($pdo);
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
    header('Location: alumno.php');
    exit;
}
function fechaMx(?string $fecha): string {
    if (!$fecha) return '—';
    $dt = DateTime::createFromFormat('!Y-m-d', $fecha);
    return $dt ? $dt->format('d/m/Y') : $fecha;
}
function hora(?string $valor): string {
    return $valor === null ? '—' : substr($valor, 0, 5);
}
function duracion(int $segundos): string {
    return sprintf('%dh %02dmin', intdiv($segundos, 3600), intdiv($segundos % 3600, 60));
}

// --- Acceso únicamente mediante el código QR dinámico ---
if (isset($_GET['t'])) {
    if (qr_validar_token((string)$_GET['t'])) {
        session_regenerate_id(true);
        $_SESSION['qr_acceso'] = time() + QR_SESION_SEGUNDOS;
        header('Location: alumno.php'); // quita el token de la URL
        exit;
    }
    unset($_SESSION['qr_acceso'], $_SESSION['alumno_id']);
    redirectTo('El código QR venció o no es válido. Escanea el código vigente.', 'error');
}
$accesoValido = (int)($_SESSION['qr_acceso'] ?? 0) > time();
if (!$accesoValido) {
    unset($_SESSION['qr_acceso'], $_SESSION['alumno_id']);
}

$alumnoId = (int)($_SESSION['alumno_id'] ?? 0);
// --- Ver un documento propio: se entrega desde aquí para comprobar que pertenece al alumno (sin enlaces directos) ---
$docsAbiertos = $alumnoId > 0 && (int)($_SESSION['docs_alumno'] ?? 0) === $alumnoId && (int)($_SESSION['docs_hasta'] ?? 0) > time();
if (isset($_GET['doc'])) {
    $docId = filter_input(INPUT_GET, 'doc', FILTER_VALIDATE_INT);
    if (!$accesoValido || !$docsAbiertos || !$docId) {
        redirectTo('Desbloquea tus documentos con la clave vigente.', 'error');
    }
    try {
        $stmt = $pdo->prepare('SELECT archivo_ruta FROM documentos_entregados WHERE id = ? AND estudiante_id = ?');
        $stmt->execute([$docId, $alumnoId]);
        $rutaDoc = $stmt->fetchColumn();
    } catch (PDOException $e) {
        $rutaDoc = false;
    }
    $archivoDoc = is_string($rutaDoc) ? docs_ruta_segura($rutaDoc) : null;
    if ($archivoDoc === null || !is_file($archivoDoc)) {
        redirectTo('No se encontró el archivo.', 'error');
    }
    $tiposDoc = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png'];
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: ' . $tiposDoc[strtolower(pathinfo($archivoDoc, PATHINFO_EXTENSION))]);
    header('Content-Disposition: inline; filename="' . basename($archivoDoc) . '"');
    header('Content-Length: ' . filesize($archivoDoc));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($archivoDoc);
    exit;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$accesoValido) {
        redirectTo('Tu acceso expiró. Escanea de nuevo el código QR.', 'error');
    }
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        redirectTo('La sesión expiró. Intenta nuevamente.', 'error');
    }
    $accion = (string)($_POST['accion'] ?? '');

    if ($accion === 'identificar') {
        $matricula = trim((string)($_POST['matricula'] ?? ''));
        if ($matricula === '') {
            redirectTo('Escribe tu matrícula.', 'error');
        }
        $stmt = $pdo->prepare('SELECT id, estado FROM estudiantes_practicas WHERE matricula = ?');
        $stmt->execute([$matricula]);
        $fila = $stmt->fetch();
        if (!$fila) {
            redirectTo('No encontramos esa matrícula. Verifica el número o consulta con tu coordinador.', 'error');
        }
        if ($fila['estado'] === 'Baja') {
            redirectTo('Tu registro está dado de baja. Consulta con tu coordinador.', 'error');
        }
        session_regenerate_id(true);
        unset($_SESSION['docs_alumno'], $_SESSION['docs_hasta']);
        $_SESSION['alumno_id'] = (int)$fila['id'];
        header('Location: alumno.php');
        exit;
    }

    if ($accion === 'cerrar') {
        unset($_SESSION['alumno_id'], $_SESSION['borrador'], $_SESSION['docs_alumno'], $_SESSION['docs_hasta']);
        redirectTo('Sesión cerrada.');
    }

    if (!$alumnoId) {
        redirectTo('Primero escribe tu matrícula.', 'error');
    }

    // --- Documentos entregados: se desbloquean con la clave vigente durante 5 minutos ---
    if ($accion === 'docs_abrir') {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $espera = clave_bloqueado($ip);
        if ($espera > 0) {
            redirectTo('Demasiados intentos incorrectos. Espera ' . (int)ceil($espera / 60) . ' minuto(s) e inténtalo de nuevo.', 'error');
        }
        if (!clave_validar((string)($_POST['clave'] ?? ''))) {
            clave_registrar_fallo($ip);
            redirectTo('La clave es incorrecta o venció. Escribe la clave vigente que aparece en la pantalla.', 'error');
        }
        clave_limpiar_fallos($ip);
        $_SESSION['docs_alumno'] = $alumnoId;
        $_SESSION['docs_hasta'] = time() + 300;
        header('Location: alumno.php#documentos');
        exit;
    }

    // --- El alumno sube un documento: queda "Pendiente" hasta que un administrador lo valide ---
    if ($accion === 'subir_doc') {
        if (!$docsAbiertos) {
            redirectTo('Desbloquea tus documentos con la clave vigente.', 'error');
        }
        $tipoDoc = trim((string)($_POST['tipo_documento'] ?? ''));
        $comentario = mb_substr(trim((string)($_POST['comentario'] ?? '')), 0, 500);
        if (!in_array($tipoDoc, DOCS_TIPOS, true)) {
            redirectTo('Elige un tipo de documento válido.', 'error');
        }
        if (!isset($_FILES['archivo']) || ($_FILES['archivo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            redirectTo('Selecciona el archivo que quieres subir.', 'error');
        }
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM documentos_entregados WHERE estudiante_id = ? AND revision = 'Pendiente'");
            $st->execute([$alumnoId]);
            if ((int)$st->fetchColumn() >= 10) {
                redirectTo('Tienes muchos documentos pendientes de revisión. Espera a que el administrador los valide.', 'error');
            }
            $st = $pdo->prepare('SELECT apellidos, nombre FROM estudiantes_practicas WHERE id = ?');
            $st->execute([$alumnoId]);
            $quien = $st->fetch();
            if (!$quien) {
                redirectTo('No se encontró tu registro.', 'error');
            }
            [$rutaDoc, $nombreDoc] = docs_guardar_archivo($_FILES['archivo'], $alumnoId, $quien['apellidos'] . ' ' . $quien['nombre'] . ' ' . $tipoDoc, (string)$quien['apellidos']);
        } catch (RuntimeException $e) {
            redirectTo($e->getMessage(), 'error');
        } catch (PDOException $e) {
            redirectTo('No se pudo subir el documento. Avisa a tu coordinador.', 'error');
        }
        try {
            $ins = $pdo->prepare("INSERT INTO documentos_entregados (estudiante_id, tipo_documento, fecha_entrega, observaciones, archivo_nombre, archivo_ruta, revision, origen) VALUES (?, ?, ?, ?, ?, ?, 'Pendiente', 'alumno')");
            $ins->execute([$alumnoId, $tipoDoc, date('Y-m-d'), $comentario !== '' ? $comentario : null, $nombreDoc, $rutaDoc]);
        } catch (PDOException $e) {
            docs_borrar_archivo($rutaDoc);
            redirectTo('No se pudo registrar el documento. Avisa a tu coordinador.', 'error');
        }
        historial_registrar($pdo, 'Documentos', 'subir', 'Documento subido por el alumno (pendiente de revisión): ' . $tipoDoc, historial_alumno($pdo, $alumnoId), 'alumno');
        redirectTo('Documento enviado. Quedó pendiente de revisión por el administrador.');
    }

    // --- Reporte bimestral del propio alumno: exige la clave vigente y solo entrega sus datos ---
    if ($accion === 'reporte') {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $espera = clave_bloqueado($ip);
        if ($espera > 0) {
            redirectTo('Demasiados intentos incorrectos. Espera ' . (int)ceil($espera / 60) . ' minuto(s) e inténtalo de nuevo.', 'error');
        }
        if (!clave_validar((string)($_POST['clave'] ?? ''))) {
            clave_registrar_fallo($ip);
            redirectTo('La clave es incorrecta o venció. Escribe la clave vigente que aparece en la pantalla.', 'error');
        }
        clave_limpiar_fallos($ip);
        $formato = (string)($_POST['formato'] ?? '');
        $periodoRep = filter_input(INPUT_POST, 'periodo', FILTER_VALIDATE_INT);
        if (!in_array($formato, ['xlsx', 'pdf', 'docx'], true) || !$periodoRep || $periodoRep < 1 || $periodoRep > 120) {
            redirectTo('Elige un formato y un periodo válidos.', 'error');
        }
        try {
            $reporte = reporte_armar($pdo, $programas, $periodoRep, $alumnoId);
            reporte_enviar($reporte, $formato);
        } catch (PDOException $e) {
            redirectTo('No se pudo generar tu reporte. Avisa a tu coordinador.', 'error');
        } catch (Throwable $e) {
            redirectTo('No se pudo generar tu reporte. Intenta de nuevo.', 'error');
        }
    }

    // --- La entrada y la salida exigen la clave de asistencia vigente ---
    if ($accion === 'entrada' || $accion === 'salida') {
        if (HORARIO_RESTRINGIR_ALUMNOS && !horario_abierto()) {
            redirectTo('El registro de asistencia está disponible ' . horario_texto() . '.', 'error');
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $espera = clave_bloqueado($ip);
        $motivo = null;
        if ($espera > 0) {
            $motivo = 'Demasiados intentos incorrectos. Espera ' . (int)ceil($espera / 60) . ' minuto(s) e inténtalo de nuevo.';
        } elseif (!clave_validar((string)($_POST['clave'] ?? ''))) {
            clave_registrar_fallo($ip);
            $motivo = 'La clave es incorrecta o venció. Escribe la clave vigente que aparece en la pantalla.';
        }
        if ($motivo !== null) {
            if ($accion === 'salida') {
                $_SESSION['borrador'] = mb_substr(trim((string)($_POST['actividades'] ?? '')), 0, 5000);
            }
            redirectTo($motivo, 'error');
        }
        clave_limpiar_fallos($ip);
    }

    if ($accion === 'entrada') {
        try {
            $stmt = $pdo->prepare('SELECT id FROM bitacora_horas WHERE estudiante_id = ? AND hora_salida IS NULL LIMIT 1');
            $stmt->execute([$alumnoId]);
            if ($stmt->fetch()) {
                redirectTo('Ya tienes una entrada abierta. Registra tu salida primero.', 'error');
            }
            $ahora = date('H:i');
            $ins = $pdo->prepare('INSERT INTO bitacora_horas (estudiante_id, fecha, hora_entrada, hora_salida, actividades) VALUES (?, ?, ?, NULL, NULL)');
            $ins->execute([$alumnoId, date('Y-m-d'), $ahora]);
            historial_registrar($pdo, 'Bitácora', 'entrada', 'Entrada registrada a las ' . $ahora . ' (QR y clave)', historial_alumno($pdo, $alumnoId), 'alumno');
        } catch (PDOException $e) {
            redirectTo('No se pudo registrar la entrada. Avisa a tu coordinador.', 'error');
        }
        unset($_SESSION['qr_acceso'], $_SESSION['alumno_id']); // un escaneo = un registro
        redirectTo('Entrada registrada a las ' . $ahora . '.');
    }

    if ($accion === 'salida') {
        $actividades = trim((string)($_POST['actividades'] ?? ''));
        if ($actividades === '') {
            redirectTo('Describe las actividades que realizaste.', 'error');
        }
        try {
            $stmt = $pdo->prepare('SELECT id, fecha, hora_entrada FROM bitacora_horas WHERE estudiante_id = ? AND hora_salida IS NULL ORDER BY fecha DESC, hora_entrada DESC LIMIT 1');
            $stmt->execute([$alumnoId]);
            $abierta = $stmt->fetch();
            if (!$abierta) {
                redirectTo('No tienes una entrada abierta.', 'error');
            }
            if ($abierta['fecha'] !== date('Y-m-d')) {
                redirectTo('Tu entrada abierta es del ' . fechaMx($abierta['fecha']) . '. Avisa a tu coordinador para corregirla.', 'error');
            }
            $ahora = date('H:i');
            if ($ahora <= substr($abierta['hora_entrada'], 0, 5)) {
                $_SESSION['borrador'] = mb_substr($actividades, 0, 5000);
                redirectTo('Debe pasar al menos un minuto desde tu entrada.', 'error');
            }
            $upd = $pdo->prepare('UPDATE bitacora_horas SET hora_salida = ?, actividades = ? WHERE id = ? AND hora_salida IS NULL');
            $upd->execute([$ahora, mb_substr($actividades, 0, 5000), $abierta['id']]);
            historial_registrar($pdo, 'Bitácora', 'salida', 'Salida registrada a las ' . $ahora . ' (QR y clave); entrada de las ' . substr((string)$abierta['hora_entrada'], 0, 5), historial_alumno($pdo, $alumnoId), 'alumno');
        } catch (PDOException $e) {
            redirectTo('No se pudo registrar la salida. Avisa a tu coordinador.', 'error');
        }
        unset($_SESSION['qr_acceso'], $_SESSION['alumno_id'], $_SESSION['borrador']); // un escaneo = un registro
        redirectTo('Salida registrada a las ' . $ahora . '.');
    }

    redirectTo('Acción no válida.', 'error');
}

$alumno = null;
$abierta = null;
$recientes = [];
$hechos = 0;
$errorTabla = null;

if ($alumnoId) {
    $stmt = $pdo->prepare('SELECT id, matricula, apellidos, nombre, tipo_programa, fecha_inicio FROM estudiantes_practicas WHERE id = ?');
    $stmt->execute([$alumnoId]);
    $alumno = $stmt->fetch() ?: null;
    if (!$alumno) {
        unset($_SESSION['alumno_id']);
        $alumnoId = 0;
    }
}
if ($alumno) {
    try {
        $stmt = $pdo->prepare('SELECT fecha, hora_entrada FROM bitacora_horas WHERE estudiante_id = ? AND hora_salida IS NULL ORDER BY fecha DESC, hora_entrada DESC LIMIT 1');
        $stmt->execute([$alumnoId]);
        $abierta = $stmt->fetch() ?: null;

        $stmt = $pdo->prepare('SELECT fecha, hora_entrada, hora_salida FROM bitacora_horas WHERE estudiante_id = ? ORDER BY fecha DESC, hora_entrada DESC LIMIT 5');
        $stmt->execute([$alumnoId]);
        $recientes = $stmt->fetchAll();

        $stmt = $pdo->prepare('SELECT COALESCE(SUM(TIME_TO_SEC(TIMEDIFF(hora_salida, hora_entrada))), 0) FROM bitacora_horas WHERE estudiante_id = ?');
        $stmt->execute([$alumnoId]);
        $hechos = (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        $errorTabla = 'No se pudo leer tu información. Avisa a tu coordinador.';
    }
}
$misDocs = [];
if ($alumno && $docsAbiertos) {
    try {
        $stmt = $pdo->prepare('SELECT id, tipo_documento, fecha_entrega, archivo_ruta, revision, motivo_revision FROM documentos_entregados WHERE estudiante_id = ? ORDER BY fecha_entrega DESC, id DESC');
        $stmt->execute([$alumnoId]);
        $misDocs = $stmt->fetchAll();
    } catch (PDOException $e) {
        $errorTabla = 'No se pudieron leer tus documentos. Avisa a tu coordinador.';
    }
}
$req = $alumno ? (int)($programas[$alumno['tipo_programa']] ?? 0) : 0;
$restantes = max(0, $req * 3600 - $hechos);

$borrador = (string)($_SESSION['borrador'] ?? '');
unset($_SESSION['borrador']);
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Registro de asistencia | Prácticas Profesionales</title>
<style>
:root{--navy:#142b4a;--blue:#2457a7;--bg:#f3f6fb;--text:#243247;--muted:#64748b;--border:#dbe3ee}
*{box-sizing:border-box}body{margin:0;font-family:"Segoe UI",Arial,sans-serif;background:var(--bg);color:var(--text)}
header{color:#fff;padding:20px;background:linear-gradient(120deg,#142b4a,#2457a7)}
.header-inner,main{max-width:520px;margin:auto}.brand{display:flex;gap:12px;align-items:center}
.marca-logo{height:46px;width:auto;border-radius:8px;object-fit:contain}
h1{font-size:1.15rem;margin:0}header p{color:#dbeafe;margin:4px 0 0;font-size:.85rem}
main{padding:18px 16px 30px}.card{background:#fff;border:1px solid var(--border);border-radius:14px;box-shadow:0 8px 28px #142b4a0d;padding:20px;margin-bottom:16px}
h2{font-size:1.1rem;color:var(--navy);margin:0 0 6px}.hint{color:var(--muted);font-size:.86rem;margin:0 0 14px}
label{display:block;font-weight:650;font-size:.9rem;color:#34445a;margin-bottom:7px}label.sep{margin-top:14px}
input,select,textarea{width:100%;padding:14px;border:1px solid #cbd5e1;border-radius:10px;background:#fff;color:var(--text);font:inherit;font-size:1rem}textarea{min-height:110px;resize:vertical}input:focus,textarea:focus{outline:0;border-color:var(--blue);box-shadow:0 0 0 3px #2457a71f}
input.clave{text-align:center;font-size:1.6rem;letter-spacing:.3em;font-weight:700}
.btn{display:block;width:100%;border:0;border-radius:10px;padding:15px;margin-top:12px;font:inherit;font-weight:700;font-size:1.02rem;cursor:pointer}
.primary{background:var(--blue);color:#fff}.salida{background:#16794b;color:#fff}.secondary{background:#edf2f8;color:#334155;font-size:.9rem;padding:11px}
.nombre{font-size:1.15rem;font-weight:700;color:var(--navy);margin:0}.sub{color:var(--muted);font-size:.86rem;margin:3px 0 0}
.stats{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:14px}.stat{background:#f8fbff;border:1px solid var(--border);border-radius:10px;padding:11px 13px}.stat span{color:var(--muted);font-size:.78rem}.stat strong{display:block;font-size:1.15rem;color:var(--navy);margin-top:3px}
.estado{padding:11px 13px;border-radius:10px;background:#dcfce7;color:#166534;font-weight:650;margin-bottom:6px}.aviso{background:#fef3c7;color:#92400e}
ul{list-style:none;margin:0;padding:0}li{padding:10px 0;border-bottom:1px solid #e8edf4;font-size:.9rem;display:flex;justify-content:space-between;gap:10px}li:last-child{border-bottom:0}
.flash{padding:13px 15px;border-radius:10px;margin-bottom:16px;font-weight:600}.success{background:#dcfce7;color:#166534}.error{background:#fee2e2;color:#991b1b}
.nota{color:var(--muted);font-size:.78rem;text-align:center;margin-top:6px}
.estado{display:inline-block;font-size:.72rem;font-weight:700;padding:2px 8px;border-radius:99px;vertical-align:middle}.e-Pendiente{background:#fef3c7;color:#92400e}.e-Aprobado{background:#dcfce7;color:#166534}.e-Rechazado{background:#fee2e2;color:#991b1b}.hint{color:var(--muted);font-size:.86rem;margin:0 0 10px}
a.enlace{color:var(--blue);font-weight:650;text-decoration:none;white-space:nowrap}
</style>
<link rel="stylesheet" href="assets/estilo.css?v=1">
</head>
<body class="pg-alumno">
<header><div class="header-inner"><div class="brand"><img class="marca-logo" src="https://esiima.uaa.mx/cursoextension/imgs/logouaa2.jpg" alt="Logo UAA"><div><h1>Registro de asistencia del Departamento de Sistemas de Información</h1><p>Prácticas Profesionales y Servicio Social</p></div></div></div></header>
<main>
<?php if (!horario_abierto()): ?><div class="flash" style="background:#fff7e6;color:#7a4b00">Fuera del horario de uso: <?= h(horario_texto()) ?>.<?= HORARIO_RESTRINGIR_ALUMNOS ? ' El registro de asistencia no está disponible ahora.' : '' ?></div><?php endif; ?>
<?php if ($flash): ?><div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
<?php if ($errorTabla): ?><div class="flash error"><?= h($errorTabla) ?></div><?php endif; ?>

<?php if (!$accesoValido): ?>
<section class="card">
<h2>Escanea el código QR</h2>
<p class="hint">Para registrar tu asistencia, escanea con la cámara de tu celular el código QR que aparece en la pantalla del Edificio Nº55. El código cambia constantemente, por lo que debes escanearlo en el momento.</p>
</section>

<?php elseif (!$alumno): ?>
<section class="card">
<h2>Identifícate</h2>
<p class="hint">Escribe tu matrícula para registrar tu entrada o salida.</p>
<form method="post" autocomplete="off">
<input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
<input type="hidden" name="accion" value="identificar">
<label for="matricula">Matrícula</label>
<input id="matricula" name="matricula" required maxlength="14" minlength="14" inputmode="numeric" placeholder="Tu matrícula">
<button class="btn primary" type="submit">Continuar</button>
</form>
</section>

<?php else: ?>
<section class="card">
<p class="nombre"><?= h($alumno['nombre'] . ' ' . $alumno['apellidos']) ?></p>
<p class="sub"><?= h($alumno['matricula']) ?> · <?= h($alumno['tipo_programa']) ?></p>
<div class="stats">
<div class="stat"><span>Horas acumuladas</span><strong><?= h(duracion($hechos)) ?></strong></div>
<div class="stat"><span>Horas restantes (de <?= $req ?> h)</span><strong><?= $restantes === 0 ? 'Completado' : h(duracion($restantes)) ?></strong></div>
</div>
</section>

<section class="card">
<?php if ($abierta): ?>
  <?php if ($abierta['fecha'] === date('Y-m-d')): ?>
  <div class="estado">Entrada registrada hoy a las <?= h(hora($abierta['hora_entrada'])) ?></div>
  <p class="hint">Cuando termines tu jornada, describe lo que hiciste, escribe la clave que aparece en la pantalla y registra tu salida.</p>
  <form method="post" autocomplete="off">
  <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
  <input type="hidden" name="accion" value="salida">
  <label for="actividades">Actividades realizadas</label>
  <textarea id="actividades" name="actividades" required maxlength="5000" placeholder="¿Qué actividades realizaste hoy?"><?= h($borrador) ?></textarea>
  <label class="sep" for="clave_salida">Clave de asistencia</label>
  <input id="clave_salida" class="clave" name="clave" required inputmode="numeric" pattern="[0-9 ]*" maxlength="<?= CLAVE_DIGITOS + 2 ?>" autocomplete="off" placeholder="<?= h(str_repeat('0', CLAVE_DIGITOS)) ?>">
  <button class="btn salida" type="submit">Registrar salida</button>
  </form>
  <?php else: ?>
  <div class="estado aviso">Tienes una entrada abierta del <?= h(fechaMx($abierta['fecha'])) ?>. Avisa a tu coordinador para corregirla.</div>
  <?php endif; ?>
<?php else: ?>
  <h2>Registrar entrada</h2>
  <p class="hint">Escribe la clave de asistencia que aparece en la pantalla. Se guardará con la fecha y hora actuales.</p>
  <form method="post" autocomplete="off">
  <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
  <input type="hidden" name="accion" value="entrada">
  <label for="clave_entrada">Clave de asistencia</label>
  <input id="clave_entrada" class="clave" name="clave" required inputmode="numeric" pattern="[0-9 ]*" maxlength="<?= CLAVE_DIGITOS + 2 ?>" autocomplete="off" placeholder="<?= h(str_repeat('0', CLAVE_DIGITOS)) ?>">
  <button class="btn primary" type="submit">Registrar entrada</button>
  </form>
<?php endif; ?>
<p class="nota">La hora la registra el servidor automáticamente.</p>
</section>

<?php if ($recientes): ?>
<section class="card">
<h2>Mis últimos registros</h2>
<ul>
<?php foreach ($recientes as $r): ?>
<li><strong><?= h(fechaMx($r['fecha'])) ?></strong><span><?= h(hora($r['hora_entrada'])) ?> – <?= $r['hora_salida'] === null ? 'en curso' : h(hora($r['hora_salida'])) ?></span></li>
<?php endforeach; ?>
</ul>
</section>
<?php endif; ?>

<section class="card" id="documentos">
<h2>Mis documentos entregados</h2>
<?php if (!$docsAbiertos): ?>
<p class="hint">Escribe la clave de asistencia que aparece en la pantalla para ver tus documentos.</p>
<form method="post" autocomplete="off">
<input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
<input type="hidden" name="accion" value="docs_abrir">
<label for="clave_docs">Clave de asistencia</label>
<input id="clave_docs" class="clave" name="clave" required inputmode="numeric" pattern="[0-9 ]*" maxlength="<?= CLAVE_DIGITOS + 2 ?>" autocomplete="off" placeholder="<?= h(str_repeat('0', CLAVE_DIGITOS)) ?>">
<button class="btn primary" type="submit">Ver mis documentos</button>
</form>
<?php elseif (!$misDocs): ?>
<p class="hint">Aún no tienes documentos registrados.</p>
<?php else: ?>
<ul>
<?php foreach ($misDocs as $d): ?>
<?php $rev = (string)($d['revision'] ?? 'Aprobado'); ?>
<li><span><strong><?= h($d['tipo_documento']) ?></strong> <span class="estado e-<?= h($rev) ?>"><?= h($rev) ?></span><br><span class="sub"><?= h(fechaMx($d['fecha_entrega'])) ?></span><?php if ($rev === 'Rechazado' && !empty($d['motivo_revision'])): ?><br><span class="sub">Motivo: <?= h($d['motivo_revision']) ?></span><?php endif; ?></span>
<?php if (!empty($d['archivo_ruta'])): ?><a class="enlace" href="alumno.php?doc=<?= (int)$d['id'] ?>" target="_blank" rel="noopener">Ver archivo</a><?php else: ?><span class="sub">Sin archivo</span><?php endif; ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<?php if ($docsAbiertos): ?>
<h3 style="margin:18px 0 8px;font-size:1rem;color:var(--navy)">Subir un documento</h3>
<p class="hint">PDF, JPG o PNG, máximo 10 MB. Un administrador lo revisará antes de darlo por entregado.</p>
<form method="post" enctype="multipart/form-data" autocomplete="off">
<input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
<input type="hidden" name="accion" value="subir_doc">
<label for="tipo_documento">Tipo de documento</label>
<select id="tipo_documento" name="tipo_documento" required>
<?php foreach (DOCS_TIPOS as $tipoOpcion): ?><option value="<?= h($tipoOpcion) ?>"><?= h($tipoOpcion) ?></option><?php endforeach; ?>
</select>
<label class="sep" for="archivo_doc">Archivo</label>
<input id="archivo_doc" type="file" name="archivo" accept="application/pdf,image/jpeg,image/png" required>
<label class="sep" for="comentario">Comentario (opcional)</label>
<input id="comentario" name="comentario" maxlength="500">
<button class="btn primary" type="submit">Enviar documento</button>
</form>
<?php endif; ?>
</section>

<?php
$iniRep = (string)($alumno['fecha_inicio'] ?? '');
$periodosRep = [];
if (rep_inicio_valido($iniRep)) {
    for ($i = rep_periodo_actual($iniRep, date('Y-m-d')); $i >= 1; $i--) {
        [$p1, $p2] = rep_periodo($iniRep, $i);
        $periodosRep[$i] = 'Periodo ' . $i . ': ' . rep_fecha($p1) . ' al ' . rep_fecha($p2);
    }
}
$avisoPeriodo = '';
if ($periodosRep) {
    $actualRep = (int)array_key_first($periodosRep);
    $finRep = rep_periodo($iniRep, $actualRep)[1];
    $diasRep = (int)(new DateTimeImmutable('today'))->diff(new DateTimeImmutable($finRep))->format('%r%a');
    if ($diasRep >= 0 && $diasRep <= 7) {
        $avisoPeriodo = 'Tu periodo ' . $actualRep . ' termina ' . ($diasRep === 0 ? 'hoy' : 'en ' . $diasRep . ' día(s)') . ' (' . rep_fecha($finRep) . '). Recuerda descargar tu reporte y entregarlo.';
    }
}
?>
<?php if ($periodosRep): ?>
<section class="card" id="reporte">
<h2>Mi reporte bimestral</h2>
<?php if ($avisoPeriodo !== ''): ?><div class="aviso-periodo"><?= h($avisoPeriodo) ?></div><?php endif; ?>
<p class="hint">Cada periodo dura dos meses y comienza en tu fecha de inicio. Escribe la clave de asistencia que aparece en la pantalla.</p>
<form method="post" autocomplete="off">
<input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
<input type="hidden" name="accion" value="reporte">
<label for="rep_periodo">Periodo</label>
<select id="rep_periodo" name="periodo" required>
<?php foreach ($periodosRep as $num => $texto): ?><option value="<?= (int)$num ?>"><?= h($texto) ?></option><?php endforeach; ?>
</select>
<label class="sep" for="clave_reporte">Clave de asistencia</label>
<input id="clave_reporte" class="clave" name="clave" required inputmode="numeric" pattern="[0-9 ]*" maxlength="<?= CLAVE_DIGITOS + 2 ?>" autocomplete="off" placeholder="<?= h(str_repeat('0', CLAVE_DIGITOS)) ?>">
<button class="btn primary" type="submit" name="formato" value="pdf">Descargar PDF</button>
<button class="btn secondary" type="submit" name="formato" value="xlsx">Descargar Excel</button>
<button class="btn secondary" type="submit" name="formato" value="docx">Descargar Word</button>
</form>
</section>
<?php endif; ?>

<form method="post">
<input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
<input type="hidden" name="accion" value="cerrar">
<button class="btn secondary" type="submit">No soy yo / Salir</button>
</form>
<?php endif; ?>
<p class="nota"><a href="manual.php#alumnos" style="color:var(--blue)">Manual de uso</a></p>
</main>
<?php require __DIR__ . '/config/footeralumno.php'; ?>
</body>
</html>