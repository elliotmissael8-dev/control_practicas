<?php
declare(strict_types=1);
require __DIR__ . '/config/auth.php';
require __DIR__ . '/config/database.php';
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
    header('Location: administradores.php');
    exit;
}
function fechaHoraMx(?string $f): string {
    $dt = $f ? DateTime::createFromFormat('Y-m-d H:i:s', $f) : false;
    return $dt ? $dt->format('d/m/Y H:i') : '—';
}

$miId = (int)($_SESSION['admin_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        redirectTo('La sesión del formulario expiró. Intenta nuevamente.', 'error');
    }
    $accion = (string)($_POST['accion'] ?? '');

    try {
        if ($accion === 'agregar') {
            $usuario = trim((string)($_POST['usuario'] ?? ''));
            $pass = (string)($_POST['password'] ?? '');
            $pass2 = (string)($_POST['password2'] ?? '');
            $correoNuevo = trim((string)($_POST['correo'] ?? ''));
            if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $usuario)) {
                redirectTo('El usuario debe tener de 3 a 50 caracteres: letras, números, punto, guion o guion bajo.', 'error');
            }
            if (strlen($pass) < 8) {
                redirectTo('La contraseña debe tener al menos 8 caracteres.', 'error');
            }
            if ($pass !== $pass2) {
                redirectTo('Las contraseñas no coinciden.', 'error');
            }
            if ($correoNuevo !== '' && (mb_strlen($correoNuevo) > 120 || !filter_var($correoNuevo, FILTER_VALIDATE_EMAIL))) {
                redirectTo('El correo no es válido.', 'error');
            }
            $stmt = $pdo->prepare('INSERT INTO administradores (usuario, password_hash, correo) VALUES (?, ?, ?)');
            $stmt->execute([$usuario, password_hash($pass, PASSWORD_DEFAULT), $correoNuevo !== '' ? $correoNuevo : null]);
            historial_registrar($pdo, 'Administradores', 'crear', 'Administrador agregado: ' . $usuario);
            redirectTo('Administrador agregado correctamente.');
        }

        if ($accion === 'correo') {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
            $correo = trim((string)($_POST['correo'] ?? ''));
            if (!$id) {
                redirectTo('Identificador no válido.', 'error');
            }
            if ($correo !== '' && (mb_strlen($correo) > 120 || !filter_var($correo, FILTER_VALIDATE_EMAIL))) {
                redirectTo('El correo no es válido.', 'error');
            }
            $stmt = $pdo->prepare('SELECT usuario FROM administradores WHERE id = ?');
            $stmt->execute([$id]);
            $usuarioCorreo = $stmt->fetchColumn();
            if ($usuarioCorreo === false) {
                redirectTo('No se encontró el administrador.', 'error');
            }
            $stmt = $pdo->prepare('UPDATE administradores SET correo = ? WHERE id = ?');
            $stmt->execute([$correo !== '' ? $correo : null, $id]);
            historial_registrar($pdo, 'Administradores', 'cambiar correo', 'Correo de contacto actualizado de: ' . $usuarioCorreo);
            redirectTo('Correo actualizado correctamente.');
        }

        if ($accion === 'password') {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
            $actual = (string)($_POST['actual'] ?? '');
            $nueva = (string)($_POST['nueva'] ?? '');
            $nueva2 = (string)($_POST['nueva2'] ?? '');
            if (!$id) {
                redirectTo('Identificador no válido.', 'error');
            }
            $stmt = $pdo->prepare('SELECT id, usuario, password_hash FROM administradores WHERE id = ?');
            $stmt->execute([$id]);
            $objetivo = $stmt->fetch();
            if (!$objetivo) {
                redirectTo('No se encontró el administrador.', 'error');
            }
            if (strlen($nueva) < 8) {
                redirectTo('La nueva contraseña debe tener al menos 8 caracteres.', 'error');
            }
            if ($nueva !== $nueva2) {
                redirectTo('Las contraseñas nuevas no coinciden.', 'error');
            }
            $esYo = $id === $miId;
            if ($esYo && !password_verify($actual, (string)$objetivo['password_hash'])) {
                redirectTo('La contraseña actual no es correcta.', 'error');
            }
            $stmt = $pdo->prepare('UPDATE administradores SET password_hash = ? WHERE id = ?');
            $stmt->execute([password_hash($nueva, PASSWORD_DEFAULT), $id]);
            historial_registrar($pdo, 'Administradores', 'cambiar contraseña', 'Contraseña cambiada de: ' . $objetivo['usuario'] . ($esYo ? ' (la propia)' : ''));
            if ($esYo) {
                session_regenerate_id(true);
            }
            redirectTo('Contraseña actualizada correctamente.');
        }

        if ($accion === 'eliminar') {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
            if (!$id) {
                redirectTo('Identificador no válido.', 'error');
            }
            if ($id === $miId) {
                redirectTo('No puedes eliminar tu propio usuario.', 'error');
            }
            if ((int)$pdo->query('SELECT COUNT(*) FROM administradores')->fetchColumn() < 2) {
                redirectTo('Debe quedar al menos un administrador.', 'error');
            }
            $stmt = $pdo->prepare('SELECT usuario FROM administradores WHERE id = ?');
            $stmt->execute([$id]);
            $usuarioEliminado = $stmt->fetchColumn();
            $stmt = $pdo->prepare('DELETE FROM administradores WHERE id = ?');
            $stmt->execute([$id]);
            if ($stmt->rowCount() > 0) {
                historial_registrar($pdo, 'Administradores', 'eliminar', 'Administrador eliminado: ' . (string)$usuarioEliminado);
                redirectTo('Administrador eliminado correctamente.');
            }
            redirectTo('No se encontró el administrador.', 'error');
        }
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            redirectTo('Ese usuario ya existe.', 'error');
        }
        redirectTo('No se pudo completar la operación. Verifica la tabla administradores.', 'error');
    }
    redirectTo('Acción no válida.', 'error');
}

$admins = [];
$errorTabla = null;
try {
    $admins = $pdo->query('SELECT id, usuario, correo, creado_en FROM administradores ORDER BY usuario')->fetchAll();
} catch (PDOException $e) {
    $errorTabla = 'No se pudo leer la tabla administradores.';
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Administradores | Prácticas Profesionales</title>
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
.form-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}.field{display:flex;flex-direction:column;gap:7px;min-width:0}label{font-weight:650;font-size:.88rem;color:#34445a}
input{width:100%;min-width:0;padding:11px 12px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:var(--text);font:inherit;font-size:.93rem}input:focus{outline:0;border-color:var(--blue);box-shadow:0 0 0 3px #2457a71f}
.buttons{grid-column:1/-1;display:flex;gap:8px;flex-wrap:wrap}.btn{display:inline-block;border:0;border-radius:8px;padding:10px 14px;font-weight:650;font-size:.88rem;text-decoration:none;cursor:pointer}.primary{background:var(--blue);color:#fff}.secondary{background:#edf2f8;color:#334155}.delete{background:#fff0ef;color:#b42318;padding:9px 12px}
.table-wrap{overflow-x:auto;border:1px solid var(--border);border-radius:10px}table{border-collapse:collapse;width:100%;min-width:640px}th,td{text-align:left;padding:12px 13px;border-bottom:1px solid #e8edf4;font-size:.88rem;vertical-align:top}th{background:#f0f4fa;color:#34445a;font-size:.76rem;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap}tbody tr:last-child td{border-bottom:0}
.yo{display:inline-block;margin-left:6px;padding:2px 8px;border-radius:99px;background:#dbeafe;color:#1e40af;font-size:.72rem;font-weight:700}
details{display:inline-block;margin:0 6px 6px 0;vertical-align:top}summary{cursor:pointer;list-style:none}summary::-webkit-details-marker{display:none}
.inline-form{display:grid;gap:8px;margin-top:8px;min-width:250px}
.flash{padding:12px 15px;border-radius:9px;margin-bottom:17px}.success{background:#dcfce7;color:#166534}.error{background:#fee2e2;color:#991b1b}.empty{text-align:center;color:var(--muted);padding:30px}
footer{text-align:center;color:var(--muted);padding:0 20px 25px;font-size:.82rem}
@media(max-width:760px){.form-grid{grid-template-columns:1fr}.card{padding:17px}}
</style>
<link rel="stylesheet" href="assets/estilo.css?v=1">
</head>
<body class="pg-administradores">
<?php $menuSeccion = 'administradores'; $menuSubtitulo = 'Administradores del sistema'; require __DIR__ . '/config/encabezado.php'; ?>
<main>
<?php if ($flash): ?><div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
<?php if ($errorTabla): ?><div class="flash error"><?= h($errorTabla) ?></div><?php endif; ?>

<section class="card">
<div class="card-head"><div><h2>Agregar administrador</h2><p class="hint">Todos los administradores tienen los mismos permisos. Contraseña de al menos 8 caracteres.</p></div></div>
<form method="post" class="form-grid" autocomplete="off">
<input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
<input type="hidden" name="accion" value="agregar">
<div class="field"><label for="usuario">Usuario *</label><input id="usuario" name="usuario" required maxlength="50" autocomplete="off"></div>
<div class="field"><label for="password">Contraseña *</label><input id="password" type="password" name="password" required minlength="8" autocomplete="new-password"></div>
<div class="field"><label for="password2">Repite la contraseña *</label><input id="password2" type="password" name="password2" required minlength="8" autocomplete="new-password"></div>
<div class="field"><label for="correo">Correo (para recuperar la contraseña)</label><input id="correo" type="email" name="correo" maxlength="120" autocomplete="off"></div>
<div class="buttons"><button class="btn primary" type="submit">Agregar administrador</button></div>
</form>
</section>

<section class="card">
<div class="card-head"><div><h2>Administradores registrados</h2><p class="hint">Para cambiar tu propia contraseña se pide la actual. No puedes eliminar tu propio usuario.</p></div></div>
<div class="table-wrap"><table><thead><tr><th>Usuario</th><th>Correo</th><th>Creado</th><th>Acciones</th></tr></thead><tbody>
<?php if (!$admins): ?><tr><td colspan="4" class="empty">No hay administradores.</td></tr>
<?php else: foreach ($admins as $a): $esYo = (int)$a['id'] === $miId; ?>
<tr>
<td><strong><?= h($a['usuario']) ?></strong><?php if ($esYo): ?><span class="yo">tú</span><?php endif; ?></td>
<td><?= h($a['correo'] ?? '') ?: '—' ?></td>
<td><?= h(fechaHoraMx($a['creado_en'])) ?></td>
<td>
<details><summary class="btn secondary">Correo</summary>
<form method="post" class="inline-form" autocomplete="off">
<input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><input type="hidden" name="accion" value="correo"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
<input type="email" name="correo" maxlength="120" value="<?= h($a['correo'] ?? '') ?>" placeholder="correo@institucion.mx">
<button class="btn primary" type="submit">Guardar</button>
</form></details>
<details><summary class="btn secondary">Cambiar contraseña</summary>
<form method="post" class="inline-form" autocomplete="off">
<input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><input type="hidden" name="accion" value="password"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
<?php if ($esYo): ?><input type="password" name="actual" placeholder="Contraseña actual" required autocomplete="current-password"><?php endif; ?>
<input type="password" name="nueva" placeholder="Nueva contraseña (mín. 8)" required minlength="8" autocomplete="new-password">
<input type="password" name="nueva2" placeholder="Repite la nueva contraseña" required minlength="8" autocomplete="new-password">
<button class="btn primary" type="submit">Guardar</button>
</form></details>
<?php if (!$esYo): ?>
<form method="post" style="display:inline-block" onsubmit="return confirm('¿Seguro que deseas eliminar a este administrador? Esta acción no se puede deshacer.');">
<input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="btn delete" type="submit">Eliminar</button></form>
<?php endif; ?>
</td>
</tr>
<?php endforeach; endif; ?>
</tbody></table></div>
</section>
</main>
<?php require __DIR__ . '/config/footer.php'; ?>
</body>
</html>
