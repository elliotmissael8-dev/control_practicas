<?php
declare(strict_types=1);
require __DIR__ . '/config/auth.php';
require __DIR__ . '/config/database.php';
require __DIR__ . '/config/archivos.php';
require __DIR__ . '/config/historial.php';
require __DIR__ . '/config/migraciones.php';
migrar($pdo);
$programas = [
    'Prácticas profesionales' => 240,
    'Servicio social' => 480,
];
$niveles = ['Preparatoria', 'Universidad'];
$turnos = ['Matutino', 'Vespertino'];
$modalidades = ['Presencial', 'En línea'];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function h(?string $value): string { 
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function redirectTo(string $message, string $type = 'success'): never {
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
    header('Location: index.php');
    exit;
}


function siguienteIdEstudiante(PDO $pdo): int {
    $st = $pdo->prepare('SELECT (SELECT COUNT(*) FROM estudiantes_practicas WHERE id = ?) + (SELECT COUNT(*) FROM bitacora_horas WHERE estudiante_id = ?)');
    for ($i = 0; $i < 50; $i++) {
        $id = random_int(10000, 999999);
        $st->execute([$id, $id]);
        if ((int)$st->fetchColumn() === 0 && docs_id_libre($id)) {
            return $id;
        }
    }
    throw new RuntimeException('No se pudo asignar un ID único. Intenta de nuevo.');
}


function fichaAlumno(PDO $pdo, int $id): string {
    $nl = "\r\n";
    $st = $pdo->prepare('SELECT * FROM estudiantes_practicas WHERE id = ?');
    $st->execute([$id]);
    $t = 'FICHA DE ALUMNO ARCHIVADO' . $nl . 'Archivado: ' . (new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City')))->format('Y-m-d H:i:s') . $nl . $nl . '== DATOS DEL ALUMNO ==' . $nl;
    $datosAlumno = $st->fetch() ?: [];
    if ($datosAlumno) {
        $t .= 'codigo: ' . codigo_alumno($id, (string)$datosAlumno['apellidos'], (string)$datosAlumno['nombre']) . $nl;
    }
    foreach ($datosAlumno as $campo => $valor) {
        $t .= $campo . ': ' . ($valor ?? '') . $nl;
    }
    $st = $pdo->prepare('SELECT * FROM documentos_entregados WHERE estudiante_id = ? ORDER BY fecha_entrega, id');
    $st->execute([$id]);
    $t .= $nl . '== DOCUMENTOS ENTREGADOS ==' . $nl;
    foreach ($st->fetchAll() as $d) {
        $t .= '- ' . $d['tipo_documento'] . ' | ' . $d['fecha_entrega'] . ' | archivo: ' . ($d['archivo_ruta'] ?? 'sin archivo') . ' | ' . str_replace(["\r", "\n"], ' ', (string)($d['observaciones'] ?? '')) . $nl;
    }
    $st = $pdo->prepare('SELECT * FROM bitacora_horas WHERE estudiante_id = ? ORDER BY fecha, hora_entrada');
    $st->execute([$id]);
    $t .= $nl . '== JORNADAS DE LA BITACORA ==' . $nl;
    foreach ($st->fetchAll() as $j) {
        $t .= '- ' . $j['fecha'] . ' | ' . $j['hora_entrada'] . ' - ' . ($j['hora_salida'] ?? 'sin salida') . ' | ' . str_replace(["\r", "\n"], ' ', (string)($j['actividades'] ?? '')) . $nl;
    }
    return $t;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        redirectTo('La sesión del formulario expiró. Intenta nuevamente.', 'error');
    }

    $accion = (string)($_POST['accion'] ?? '');
    if ($accion === 'eliminar') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if (!$id) redirectTo('Identificador de registro no válido.', 'error');
        // Nada se destruye: los archivos y una ficha con los datos del alumno se mueven a uploads/archivo/.
        // Sus jornadas de la bitácora se conservan en la base de datos.
        $etiquetaAlumno = historial_alumno($pdo, $id);
        $stmt = $pdo->prepare('SELECT apellidos FROM estudiantes_practicas WHERE id = ?');
        $stmt->execute([$id]);
        $apellidosAlumno = (string)$stmt->fetchColumn();
        $ficha = fichaAlumno($pdo, $id);
        $stmt = $pdo->prepare('DELETE FROM estudiantes_practicas WHERE id = ?');
        $stmt->execute([$id]);
        $eliminado = $stmt->rowCount() > 0;
        if ($eliminado) {
            $carpetaArchivo = docs_archivar_alumno($id, $apellidosAlumno, $ficha);
            historial_registrar($pdo, 'Estudiantes', 'eliminar', 'Alumno eliminado: ' . $etiquetaAlumno
                . ($carpetaArchivo !== null ? ' (archivos y ficha guardados en uploads/archivo/' . basename($carpetaArchivo) . ')' : ' (ATENCIÓN: no se pudo archivar su carpeta; sus archivos siguen en uploads/documentos)'));
            if ($carpetaArchivo === null) {
                redirectTo('Registro eliminado, pero no se pudo mover su carpeta al archivo. Sus archivos NO se borraron y siguen en uploads/documentos.', 'error');
            }
        }
        redirectTo($eliminado ? 'Registro eliminado correctamente.' : 'No se encontró el registro.', $eliminado ? 'success' : 'error');
    }

    if ($accion === 'guardar') {
        $id = (int)($_POST['id'] ?? 0);
        $matricula = trim((string)($_POST['matricula'] ?? ''));
        $apellidos = trim((string)($_POST['apellidos'] ?? ''));
        $nombre = trim((string)($_POST['nombre'] ?? ''));
        $carrera = trim((string)($_POST['carrera'] ?? ''));
        $escuela = trim((string)($_POST['escuela_procedencia'] ?? ''));
        $tutor = trim((string)($_POST['tutor'] ?? ''));
        $telefono = trim((string)($_POST['telefono'] ?? ''));
        $correo = trim((string)($_POST['correo'] ?? ''));
        $nivel = (string)($_POST['nivel_academico'] ?? '');
        $turno = (string)($_POST['turno'] ?? '');
        $modalidad = (string)($_POST['modalidad'] ?? '');
        $programa = (string)($_POST['tipo_programa'] ?? '');
        $inicio = (string)($_POST['fecha_inicio'] ?? '');
        $estado = (string)($_POST['estado'] ?? 'Pendiente');
        $observaciones = trim((string)($_POST['observaciones'] ?? ''));
        $estadosValidos = ['Pendiente', 'En curso', 'Finalizado', 'Baja'];

        if ($matricula === '' || $apellidos === '' || $nombre === '' || $carrera === '' || $escuela === '' || $tutor === '' || $inicio === '') {
            redirectTo('Completa todos los campos obligatorios.', 'error');
        }
        if ($telefono !== '' && !preg_match('/^\+?[0-9][0-9 ()-]{6,18}$/', $telefono)) {
            redirectTo('El teléfono no es válido. Usa solo números, espacios, guiones o paréntesis (de 7 a 20 caracteres).', 'error');
        }
        if ($correo !== '' && (mb_strlen($correo) > 120 || !filter_var($correo, FILTER_VALIDATE_EMAIL))) {
            redirectTo('El correo de contacto no es válido.', 'error');
        }
        if (!in_array($nivel, $niveles, true)) {
            redirectTo('El nivel académico seleccionado no es válido.', 'error');
        }
        if (!in_array($turno, $turnos, true)) {
            redirectTo('El turno seleccionado no es válido.', 'error');
        }
        if (!in_array($modalidad, $modalidades, true)) {
            redirectTo('La modalidad seleccionada no es válida.', 'error');
        }
        if (!isset($programas[$programa])) {
            redirectTo('El programa seleccionado no es válido.', 'error');
        }
        if (!in_array($estado, $estadosValidos, true)) {
            redirectTo('El estado seleccionado no es válido.', 'error');
        }
        $fechaInicio = DateTime::createFromFormat('!Y-m-d', $inicio);
        if (!$fechaInicio || $fechaInicio->format('Y-m-d') !== $inicio) {
            redirectTo('La fecha de inicio no es válida.', 'error');
        }

        try {
            if ($id > 0) {
                $stAntes = $pdo->prepare('SELECT * FROM estudiantes_practicas WHERE id = ?');
                $stAntes->execute([$id]);
                $antes = $stAntes->fetch() ?: [];
                $stmt = $pdo->prepare('UPDATE estudiantes_practicas SET matricula=?, apellidos=?, nombre=?, carrera=?, escuela_procedencia=?, tutor=?, nivel_academico=?, turno=?, modalidad=?, tipo_programa=?, fecha_inicio=?, estado=?, observaciones=?, telefono=?, correo=? WHERE id=?');
                $stmt->execute([$matricula, $apellidos, $nombre, $carrera, $escuela, $tutor, $nivel, $turno, $modalidad, $programa, $inicio, $estado, $observaciones !== '' ? $observaciones : null, $telefono !== '' ? $telefono : null, $correo !== '' ? $correo : null, $id]);
                historial_registrar($pdo, 'Estudiantes', 'modificar', 'Alumno ' . $matricula . ' — ' . $apellidos . ' ' . $nombre . ': ' . historial_cambios($antes, [
                    'matricula' => $matricula, 'apellidos' => $apellidos, 'nombre' => $nombre, 'carrera' => $carrera,
                    'escuela_procedencia' => $escuela, 'tutor' => $tutor, 'nivel_academico' => $nivel, 'turno' => $turno,
                    'modalidad' => $modalidad, 'tipo_programa' => $programa, 'fecha_inicio' => $inicio, 'estado' => $estado,
                    'observaciones' => $observaciones, 'telefono' => $telefono, 'correo' => $correo,
                ], [
                    'matricula' => 'Matrícula', 'apellidos' => 'Apellidos', 'nombre' => 'Nombre', 'carrera' => 'Carrera',
                    'escuela_procedencia' => 'Escuela', 'tutor' => 'Tutor', 'nivel_academico' => 'Nivel', 'turno' => 'Turno',
                    'modalidad' => 'Modalidad', 'tipo_programa' => 'Programa', 'fecha_inicio' => 'Inicio', 'estado' => 'Estado',
                    'observaciones' => 'Observaciones', 'telefono' => 'Teléfono', 'correo' => 'Correo',
                ]));
                redirectTo('Registro modificado correctamente.');
            } else {
                $bloqueo = (int)$pdo->query("SELECT GET_LOCK('nuevo_estudiante', 5)")->fetchColumn();
                if ($bloqueo !== 1) {
                    redirectTo('El sistema está ocupado. Intenta de nuevo en unos segundos.', 'error');
                }
                try {
                    $nuevoId = siguienteIdEstudiante($pdo);
                    $stmt = $pdo->prepare('INSERT INTO estudiantes_practicas (id, matricula, apellidos, nombre, carrera, escuela_procedencia, tutor, nivel_academico, turno, modalidad, tipo_programa, fecha_inicio, estado, observaciones, telefono, correo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$nuevoId, $matricula, $apellidos, $nombre, $carrera, $escuela, $tutor, $nivel, $turno, $modalidad, $programa, $inicio, $estado, $observaciones !== '' ? $observaciones : null, $telefono !== '' ? $telefono : null, $correo !== '' ? $correo : null]);
                } catch (RuntimeException $e) {
                    redirectTo($e->getMessage(), 'error');
                } finally {
                    $pdo->query("SELECT RELEASE_LOCK('nuevo_estudiante')")->fetchColumn();
                }
                historial_registrar($pdo, 'Estudiantes', 'crear', 'Alumno nuevo: ' . $matricula . ' — ' . $apellidos . ' ' . $nombre . ' (' . $programa . ')');
                $carpetaCreada = docs_carpeta_alumno($nuevoId, $apellidos) !== null;
                if (!$carpetaCreada) {
                    redirectTo('Estudiante registrado, pero no se pudo crear su carpeta de documentos. Se intentará de nuevo al subir su primer documento.', 'error');
                }
                redirectTo('Estudiante registrado correctamente.');
            }
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                redirectTo('La matrícula ya existe. Cada estudiante debe tener una matrícula única.', 'error');
            }
            redirectTo('Ocurrió un error al guardar el registro. Verifica que la tabla tenga las columnas tipo_programa, escuela_procedencia, tutor, nivel_academico, turno y modalidad.', 'error');
        }
    }
}

$editar = null;
if (isset($_GET['editar'])) {
    $idEditar = filter_input(INPUT_GET, 'editar', FILTER_VALIDATE_INT);
    if ($idEditar) {
        $stmt = $pdo->prepare('SELECT * FROM estudiantes_practicas WHERE id = ?');
        $stmt->execute([$idEditar]);
        $editar = $stmt->fetch() ?: null;
    }
}

$q = trim((string)($_GET['q'] ?? ''));
if ($q !== '') {
    $buscar = '%' . $q . '%';
    $stmt = $pdo->prepare("SELECT * FROM estudiantes_practicas WHERE matricula LIKE ? OR apellidos LIKE ? OR nombre LIKE ? OR carrera LIKE ? OR escuela_procedencia LIKE ? OR tutor LIKE ? OR nivel_academico LIKE ? OR turno LIKE ? OR modalidad LIKE ? OR tipo_programa LIKE ? OR estado LIKE ? ORDER BY creado_en DESC");
    $stmt->execute(array_fill(0, 11, $buscar));
    $alumnos = $stmt->fetchAll();
} else {
    $alumnos = $pdo->query('SELECT * FROM estudiantes_practicas ORDER BY creado_en DESC')->fetchAll();
}
$total = (int)$pdo->query('SELECT COUNT(*) FROM estudiantes_practicas')->fetchColumn();
$enCurso = (int)$pdo->query("SELECT COUNT(*) FROM estudiantes_practicas WHERE estado='En curso'")->fetchColumn();
$finalizados = (int)$pdo->query("SELECT COUNT(*) FROM estudiantes_practicas WHERE estado='Finalizado'")->fetchColumn();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function claseEstado(string $estado): string {
    return match ($estado) {
        'En curso' => 'curso',
        'Finalizado' => 'finalizado',
        'Baja' => 'baja',
        default => 'pendiente',
    };
}
function fechaMx(?string $fecha): string {
    if (!$fecha) return '—';
    $dt = DateTime::createFromFormat('!Y-m-d', $fecha);
    return $dt ? $dt->format('d/m/Y') : $fecha;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Control de Prácticas Profesionales y servicio social de el Ed.55</title>
<style>
:root{--navy:#142b4a;--blue:#2457a7;--bg:#f3f6fb;--text:#243247;--muted:#64748b;--border:#dbe3ee;--white:#fff}
*{box-sizing:border-box}body{margin:0;font-family:"Segoe UI",Arial,sans-serif;background:var(--bg);color:var(--text)}
header{color:#fff;padding:26px 20px;background:linear-gradient(120deg,#142b4a,#2457a7)}
.header-inner,main{max-width:1200px;margin:auto}.brand{display:flex;gap:14px;align-items:center}.logo{display:grid;place-items:center;width:48px;height:48px;border:1px solid #ffffff55;border-radius:12px;background:#ffffff18;font-size:24px}
h1{font-size:clamp(1.3rem,3vw,1.9rem);margin:0}header p{color:#dbeafe;margin:6px 0 0}
main{padding:25px 20px}.stats{display:grid;grid-template-columns:repeat(3,1fr);gap:15px;margin-bottom:20px}.stat,.card{background:#fff;border:1px solid var(--border);border-radius:14px;box-shadow:0 8px 28px #142b4a0d}.stat{padding:17px 20px}.stat span{color:var(--muted);font-size:.86rem}.stat strong{display:block;font-size:1.8rem;color:var(--navy);margin-top:5px}
.card{padding:23px;margin-bottom:20px}.card-head{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:18px}h2{font-size:1.15rem;color:var(--navy);margin:0}.hint{color:var(--muted);font-size:.88rem;margin:6px 0 0}
.form-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}.field{display:flex;flex-direction:column;gap:7px;min-width:0}.wide{grid-column:1/-1}label{font-weight:650;font-size:.88rem;color:#34445a}
input,select,textarea{width:100%;min-width:0;padding:11px 12px;border:1px solid #cbd5e1;border-radius:8px;background:white;color:var(--text);font:inherit;font-size:.93rem}textarea{min-height:85px;resize:vertical}input:focus,select:focus,textarea:focus{outline:0;border-color:var(--blue);box-shadow:0 0 0 3px #2457a71f}
.buttons,.tools,.row-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.buttons{grid-column:1/-1}.btn{display:inline-block;border:0;border-radius:8px;padding:10px 14px;font-weight:650;font-size:.88rem;text-decoration:none;cursor:pointer}.primary{background:var(--blue);color:white}.secondary{background:#edf2f8;color:#334155}.edit{background:#eaf1fb;color:#1d4d91;padding:7px 9px}.delete{background:#fff0ef;color:#b42318;padding:7px 9px}.tools input{min-width:250px}
.table-wrap{overflow-x:auto;border:1px solid var(--border);border-radius:10px}table{border-collapse:collapse;width:100%;min-width:880px}th,td{text-align:left;padding:12px 13px;border-bottom:1px solid #e8edf4;font-size:.86rem;vertical-align:top}th{background:#f0f4fa;color:#34445a;font-size:.76rem;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap}tbody tr:hover{background:#f8fbff}tbody tr:last-child td{border-bottom:0}.matricula{font-weight:700;color:var(--navy);white-space:nowrap}.sub{color:var(--muted);font-size:.78rem}.pill{display:inline-block;border-radius:20px;padding:5px 9px;font-weight:700;font-size:.75rem;white-space:nowrap}.curso{background:#dcfce7;color:#166534}.pendiente{background:#fef3c7;color:#92400e}.finalizado{background:#dbeafe;color:#1e40af}.baja{background:#fee2e2;color:#991b1b}.flash{padding:12px 15px;border-radius:9px;margin-bottom:17px}.success{background:#dcfce7;color:#166534}.error{background:#fee2e2;color:#991b1b}.empty{text-align:center;color:var(--muted);padding:30px}.note{color:var(--muted);font-size:.8rem;margin-top:12px}footer{text-align:center;color:var(--muted);padding:0 20px 25px;font-size:.82rem}
@media(max-width:760px){.stats{grid-template-columns:1fr}.form-grid{grid-template-columns:1fr}.wide,.buttons{grid-column:auto}.card{padding:17px}.tools,.tools input{width:100%;min-width:0}}
.marca-logo { 
  height: 55px;
  width: auto;
  border-radius: 10px;
  object-fit: contain;
}
nav{display:flex;gap:8px;margin-top:16px;flex-wrap:wrap}nav a{color:#fff;text-decoration:none;padding:8px 14px;border-radius:8px;background:#ffffff22;font-weight:600;font-size:.88rem}nav a.activo{background:#fff;color:var(--navy)}
</style>
<link rel="stylesheet" href="assets/estilo.css?v=1">
</head>
<body class="pg-index">
<?php $menuSeccion = 'estudiantes'; $menuSubtitulo = 'Sistema de registro y seguimiento de estudiantes'; require __DIR__ . '/config/encabezado.php'; ?>
<main>
<?php if ($flash): ?><div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
<section class="stats">
<div class="stat"><span>Total de estudiantes</span><strong><?= $total ?></strong></div>
<div class="stat"><span>Prácticas en curso</span><strong><?= $enCurso ?></strong></div>
<div class="stat"><span>Prácticas finalizadas</span><strong><?= $finalizados ?></strong></div>
</section>

<details class="nuevo-panel" id="formulario"<?= $editar ? ' open' : '' ?>>
<summary><?= $editar ? 'Modificando alumno' : 'Nuevo alumno' ?></summary>
<section class="card">
<div class="card-head"><div><h2><?= $editar ? 'Modificar registro' : 'Registrar estudiante' ?></h2><p class="hint">Los campos marcados con * son obligatorios.</p></div></div>
<form method="post" class="form-grid">
<input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
<input type="hidden" name="accion" value="guardar">
<input type="hidden" name="id" value="<?= h((string)($editar['id'] ?? '0')) ?>">
<div class="field"><label for="matricula">Matrícula *</label><input id="matricula" name="matricula"  minlength="14" maxlength="14" required   oninput="this.value = this.value.replace(/[^0-9]/g,'')" value="<?= h($editar['matricula'] ?? '') ?>" placeholder="14 dígitos"></div>
<div class="field"><label for="apellidos">Apellidos *</label><input id="apellidos" name="apellidos" required maxlength="100" value="<?= h($editar['apellidos'] ?? '') ?>" placeholder="Apellidos del estudiante"></div>
<div class="field"><label for="nombre">Nombre(s) *</label><input id="nombre" name="nombre" required maxlength="100" value="<?= h($editar['nombre'] ?? '') ?>" placeholder="Nombre del estudiante"></div>
<div class="field"><label for="carrera">Carrera *</label><input id="carrera" name="carrera" required maxlength="120" value="<?= h($editar['carrera'] ?? '') ?>" placeholder="Programa académico"></div>
<div class="field"><label for="nivel_academico">Nivel académico *</label><select id="nivel_academico" name="nivel_academico" required><?php foreach ($niveles as $n): ?><option value="<?= h($n) ?>" <?= (($editar['nivel_academico'] ?? 'Universidad') === $n) ? 'selected' : '' ?>><?= h($n) ?></option><?php endforeach; ?></select></div>
<div class="field"><label for="escuela_procedencia">Escuela de procedencia *</label><input id="escuela_procedencia" name="escuela_procedencia" required maxlength="150" value="<?= h($editar['escuela_procedencia'] ?? '') ?>" placeholder="Institución de origen"></div>
<div class="field"><label for="tutor">Tutor a cargo *</label><input id="tutor" name="tutor" required maxlength="150" value="<?= h($editar['tutor'] ?? '') ?>" placeholder="Nombre del tutor"></div>
<div class="field"><label for="telefono">Teléfono de contacto</label><input id="telefono" name="telefono" type="tel" maxlength="20" value="<?= h($editar['telefono'] ?? '') ?>" placeholder="449 123 4567"></div>
<div class="field"><label for="correo">Correo de contacto</label><input id="correo" name="correo" type="email" maxlength="120" value="<?= h($editar['correo'] ?? '') ?>" placeholder="alumno@gmail.com"></div>
<div class="field"><label for="turno">Turno *</label><select id="turno" name="turno" required><?php foreach ($turnos as $t): ?><option value="<?= h($t) ?>" <?= (($editar['turno'] ?? 'Matutino') === $t) ? 'selected' : '' ?>><?= h($t) ?></option><?php endforeach; ?></select></div>
<div class="field"><label for="modalidad">Modalidad *</label><select id="modalidad" name="modalidad" required><?php foreach ($modalidades as $m): ?><option value="<?= h($m) ?>" <?= (($editar['modalidad'] ?? 'Presencial') === $m) ? 'selected' : '' ?>><?= h($m) ?></option><?php endforeach; ?></select></div>
<div class="field"><label for="tipo_programa">Programa *</label><select id="tipo_programa" name="tipo_programa" required><?php foreach ($programas as $nombrePrograma => $horasPrograma): ?><option value="<?= h($nombrePrograma) ?>" <?= (($editar['tipo_programa'] ?? 'Prácticas profesionales') === $nombrePrograma) ? 'selected' : '' ?>><?= h($nombrePrograma) ?> (<?= (int)$horasPrograma ?> h)</option><?php endforeach; ?></select></div>
<div class="field"><label for="fecha_inicio">Inicio *</label><input id="fecha_inicio" type="date" name="fecha_inicio" required value="<?= h($editar['fecha_inicio'] ?? '') ?>"></div>
<div class="field"><label for="estado">Estado *</label><select id="estado" name="estado" required><?php foreach (['Pendiente','En curso','Finalizado','Baja'] as $estado): ?><option value="<?= h($estado) ?>" <?= (($editar['estado'] ?? 'Pendiente') === $estado) ? 'selected' : '' ?>><?= h($estado) ?></option><?php endforeach; ?></select></div>
<div class="field wide"><label for="observaciones">Observaciones</label><textarea id="observaciones" name="observaciones" maxlength="5000" placeholder="Notas de seguimiento, incidencias o comentarios"><?= h($editar['observaciones'] ?? '') ?></textarea></div>
<div class="buttons"><button class="btn primary" type="submit"><?= $editar ? 'Guardar modificaciones' : 'Guardar registro' ?></button><?php if ($editar): ?><a class="btn secondary" href="index.php">Cancelar edición</a><?php else: ?><button class="btn secondary" type="reset">Limpiar formulario</button><?php endif; ?></div>
</form>
</section>
</details>

<section class="card">
<div class="card-head"><div><h2>Directorio de estudiantes</h2><p class="hint"></p></div>
<form method="get" class="tools"><input type="search" name="q" value="<?= h($q) ?>" placeholder="Buscar matrícula, nombre, carrera, escuela, tutor, turno o modalidad" aria-label="Buscar"><button class="btn primary" type="submit">Buscar</button><?php if ($q !== ''): ?><a class="btn secondary" href="index.php">Limpiar</a><?php endif; ?></form>
</div>
<div class="table-wrap"><table><thead><tr><th>Código</th><th>Matrícula</th><th>Estudiante</th><th>Programa</th><th>Estado</th><th>Contacto</th><th>Acciones</th></tr></thead><tbody>
<?php if (!$alumnos): ?><tr><td colspan="7" class="empty">No hay registros que mostrar.</td></tr><?php else: foreach ($alumnos as $a): ?>
<tr>
<td class="matricula"><?= h(codigo_alumno((int)$a['id'], (string)$a['apellidos'], (string)$a['nombre'])) ?></td>
<td class="matricula"><?= h($a['matricula']) ?></td>
<td><strong><?= h(trim($a['apellidos'] . ' ' . $a['nombre'])) ?></strong>
<details class="ver-mas"><summary>más datos</summary><dl>
<dt>Carrera</dt><dd><?= h($a['carrera']) ?></dd>
<dt>Escuela</dt><dd><?= h($a['escuela_procedencia'] ?? '') ?: '—' ?><?php if (!empty($a['nivel_academico'])): ?> (<?= h($a['nivel_academico']) ?>)<?php endif; ?></dd>
<dt>Tutor</dt><dd><?= h($a['tutor'] ?? '') ?: '—' ?></dd>
<dt>Turno</dt><dd><?= h($a['turno'] ?? '') ?: '—' ?></dd>
<dt>Modalidad</dt><dd><?= h($a['modalidad'] ?? '') ?: '—' ?></dd>
<dt>Inicio</dt><dd><?= h(fechaMx($a['fecha_inicio'])) ?></dd>
<dt>Observaciones</dt><dd><?= nl2br(h($a['observaciones'] ?: '—')) ?></dd>
</dl></details></td>
<td><?= h($a['tipo_programa']) ?><br><span class="sub"><?= (int)($programas[$a['tipo_programa']] ?? 0) ?> horas</span></td>
<td><span class="pill <?= h(claseEstado($a['estado'])) ?>"><?= h($a['estado']) ?></span></td>
<td><?= h($a['telefono'] ?? '') ?: '—' ?><br><span class="sub"><?= h($a['correo'] ?? '') ?></span></td>
<td><div class="row-actions"><a class="btn edit" href="index.php?editar=<?= (int)$a['id'] ?>#formulario">Modificar</a>
<form method="post" onsubmit="return confirm('¿Seguro que deseas eliminar el registro de <?= h($a['nombre'] . ' ' . $a['apellidos']) ?> (matrícula <?= h($a['matricula']) ?>)? Sus archivos y una ficha con sus datos se conservarán en uploads/archivo.');">
<input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="btn delete" type="submit">Eliminar</button></form></div></td>
</tr>
<?php endforeach; endif; ?>
</tbody></table></div>
</section>
</main>
<?php require __DIR__ . '/config/footer.php'; ?>
</body>
</html>
