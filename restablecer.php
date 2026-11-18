<?php
declare(strict_types=1);
define('ADMIN_PAGINA_LOGIN', true);
require __DIR__ . '/config/database.php';
require __DIR__ . '/config/auth.php';
require __DIR__ . '/config/historial.php';
require __DIR__ . '/config/migraciones.php';
migrar($pdo);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function h(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$clave = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$ahoraMx = (new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City')))->format('Y-m-d H:i:s');
$fila = null;
if (preg_match('/^[a-f0-9]{64}$/', $clave) === 1) {
    try {
        $st = $pdo->prepare('SELECT r.id, r.admin_id, a.usuario FROM recuperacion_password r JOIN administradores a ON a.id = r.admin_id WHERE r.token_hash = ? AND r.usado = 0 AND r.expira > ? LIMIT 1');
        $st->execute([hash('sha256', $clave), $ahoraMx]);
        $fila = $st->fetch() ?: null;
    } catch (Throwable $e) {
        $fila = null;
    }
}

$error = null;
if ($fila && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    $pass = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['password2'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'La sesión del formulario expiró. Recarga la página e intenta nuevamente.';
    } elseif (strlen($pass) < 8) {
        $error = 'La contraseña debe tener al menos 8 caracteres.';
    } elseif ($pass !== $pass2) {
        $error = 'Las contraseñas no coinciden.';
    } else {
        $pdo->prepare('UPDATE administradores SET password_hash = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), (int)$fila['admin_id']]);
        $pdo->prepare('UPDATE recuperacion_password SET usado = 1 WHERE admin_id = ?')->execute([(int)$fila['admin_id']]);
        historial_registrar($pdo, 'Administradores', 'restablecer contraseña', 'Contraseña restablecida con el enlace de recuperación: ' . $fila['usuario'], (string)$fila['usuario']);
        session_regenerate_id(true);
        $_SESSION['flash'] = ['message' => 'Contraseña actualizada. Ya puedes iniciar sesión.', 'type' => 'success'];
        header('Location: login.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="no-referrer">
<title>Nueva contraseña | Prácticas Profesionales</title>
<style>
:root{--navy:#142b4a;--blue:#2457a7;--bg:#f3f6fb;--text:#243247;--muted:#64748b;--border:#dbe3ee}
*{box-sizing:border-box}body{margin:0;font-family:"Segoe UI",Arial,sans-serif;background:var(--bg);color:var(--text)}
header{color:#fff;padding:26px 20px;background:linear-gradient(120deg,#142b4a,#2457a7)}
.header-inner,main{max-width:1200px;margin:auto}.brand{display:flex;gap:20px;align-items:center}
h1{font-size:clamp(1.3rem,3vw,1.9rem);margin:0}header p{color:#dbeafe;margin:6px 0 0}
.marca-logo{height:55px;width:auto;border-radius:10px;object-fit:contain}
main{padding:30px 20px}.card{background:#fff;border:1px solid var(--border);border-radius:14px;box-shadow:0 8px 28px #142b4a0d;padding:28px;max-width:420px;margin:0 auto}
h2{font-size:1.25rem;color:var(--navy);margin:0 0 6px}.hint{color:var(--muted);font-size:.9rem;margin:0 0 18px}
label{display:block;font-weight:650;font-size:.88rem;color:#34445a;margin:14px 0 7px}
input{width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:var(--text);font:inherit;font-size:1rem}input:focus{outline:0;border-color:var(--blue);box-shadow:0 0 0 3px #2457a71f}
.btn{display:block;width:100%;border:0;border-radius:8px;padding:13px;margin-top:20px;font:inherit;font-weight:700;cursor:pointer;background:var(--blue);color:#fff}
.flash{padding:12px 15px;border-radius:9px;margin:0 auto 17px;max-width:420px}.error{background:#fee2e2;color:#991b1b}
.enlaces{text-align:center;margin-top:16px;font-size:.9rem}.enlaces a{color:var(--blue);font-weight:650;text-decoration:none}
</style>
<link rel="stylesheet" href="assets/estilo.css?v=1">
</head>
<body class="pg-login">
<header><div class="header-inner">
  <div class="brand"><img class="marca-logo" src="https://esiima.uaa.mx/cursoextension/imgs/logouaa2.jpg" alt="Logo UAA"><div><h1>Control de Prácticas Profesionales y Servicio Social</h1><p>Crear una contraseña nueva</p></div></div>
</div></header>
<main>
<?php if ($error): ?><div class="flash error"><?= h($error) ?></div><?php endif; ?>
<?php if ($fila): ?>
<section class="card">
<h2>Nueva contraseña</h2>
<p class="hint">Cuenta: <strong><?= h((string)$fila['usuario']) ?></strong>. Mínimo 8 caracteres.</p>
<form method="post" autocomplete="off">
<input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
<input type="hidden" name="t" value="<?= h($clave) ?>">
<label for="password">Contraseña nueva</label>
<input id="password" type="password" name="password" required minlength="8" autocomplete="new-password" autofocus>
<label for="password2">Repite la contraseña</label>
<input id="password2" type="password" name="password2" required minlength="8" autocomplete="new-password">
<button class="btn" type="submit">Guardar contraseña</button>
</form>
</section>
<?php else: ?>
<section class="card">
<h2>Enlace no válido</h2>
<p class="hint">El enlace no existe, ya se usó o venció (duran 30 minutos). Solicita uno nuevo.</p>
<p class="enlaces"><a href="olvide.php">Solicitar otro enlace</a> · <a href="login.php">Iniciar sesión</a></p>
</section>
<?php endif; ?>
</main>
<?php require __DIR__ . '/config/footer.php'; ?>
</body>
</html>
