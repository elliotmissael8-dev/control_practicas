<?php
declare(strict_types=1);
define('ADMIN_PAGINA_LOGIN', true);
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/historial.php';
require_once __DIR__ . '/config/migraciones.php';
migrar($pdo);

if (admin_activo()) {
    header('Location: index.php');
    exit;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function h(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$enlaceDirecto = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'La sesión del formulario expiró. Intenta nuevamente.';
    } else {
        $dato = trim((string)($_POST['dato'] ?? ''));
        if ($dato !== '' && mb_strlen($dato) <= 120) {
            try {
                $st = $pdo->prepare("SELECT id, usuario, correo FROM administradores WHERE usuario = ? OR correo = ? LIMIT 1");
                $st->execute([$dato, $dato]);
                $adm = $st->fetch();
                if ($adm) {
                    $ahora = new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City'));
                    $clave = bin2hex(random_bytes(32));
                    
                    // Invalidar tokens previos y crear uno nuevo válido por 30 min
                    $pdo->prepare('UPDATE recuperacion_password SET usado = 1 WHERE admin_id = ? AND usado = 0')->execute([(int)$adm['id']]);
                    $pdo->prepare('INSERT INTO recuperacion_password (admin_id, token_hash, expira, usado, creado_en) VALUES (?, ?, ?, 0, ?)')
                        ->execute([(int)$adm['id'], hash('sha256', $clave), $ahora->modify('+30 minutes')->format('Y-m-d H:i:s'), $ahora->format('Y-m-d H:i:s')]);
                    
                    historial_registrar($pdo, 'Sesión', 'recuperación generada', 'Se generó enlace para: ' . $adm['usuario'], 'Sin sesión', 'sistema');
                    
                    // Enlace directo generado
                    $enlaceDirecto = 'restablecer.php?t=' . $clave;
                } else {
                    $error = 'No se encontró ningún administrador con ese usuario o correo.';
                }
            } catch (Throwable $e) {
                $error = 'Ocurrió un error al procesar la solicitud.';
            }
        } else {
            $error = 'Por favor escribe tu usuario o correo.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Recuperar contraseña | Prácticas Profesionales</title>
<style>
:root{--navy:#142b4a;--blue:#2457a7;--bg:#f3f6fb;--text:#243247;--muted:#64748b;--border:#dbe3ee}
*{box-sizing:border-box}body{margin:0;font-family:"Segoe UI",Arial,sans-serif;background:var(--bg);color:var(--text)}
header{color:#fff;padding:26px 20px;background:linear-gradient(120deg,#142b4a,#2457a7)}
.header-inner,main{max-width:1200px;margin:auto}.brand{display:flex;gap:20px;align-items:center}
h1{font-size:clamp(1.3rem,3vw,1.9rem);margin:0}header p{color:#dbeafe;margin:6px 0 0}
.marca-logo{height:55px;width:auto;border-radius:10px;object-fit:contain}
main{padding:30px 20px}.card{background:#fff;border:1px solid var(--border);border-radius:14px;box-shadow:0 8px 28px #142b4a0d;padding:28px;max-width:440px;margin:0 auto}
h2{font-size:1.25rem;color:var(--navy);margin:0 0 6px}.hint{color:var(--muted);font-size:.9rem;margin:0 0 18px}
label{display:block;font-weight:650;font-size:.88rem;color:#34445a;margin:14px 0 7px}
input{width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:var(--text);font:inherit;font-size:1rem}input:focus{outline:0;border-color:var(--blue);box-shadow:0 0 0 3px #2457a71f}
.btn{display:block;width:100%;border:0;border-radius:8px;padding:13px;margin-top:20px;font:inherit;font-weight:700;cursor:pointer;background:var(--blue);color:#fff;text-align:center;text-decoration:none}
.btn-success{background:#16a34a;margin-top:14px}
.flash{padding:12px 15px;border-radius:9px;margin:0 auto 17px;max-width:440px}.error{background:#fee2e2;color:#991b1b}.success-box{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:18px;margin-top:16px;text-align:center}
.enlaces{text-align:center;margin-top:16px;font-size:.9rem}.enlaces a{color:var(--blue);font-weight:650;text-decoration:none}
</style>
<link rel="stylesheet" href="assets/estilo.css?v=1">
</head>
<body class="pg-login">
<header><div class="header-inner">
  <div class="brand"><img class="marca-logo" src="https://esiima.uaa.mx/cursoextension/imgs/logouaa2.jpg" alt="Logo UAA"><div><h1>Control de Prácticas Profesionales y Servicio Social</h1><p>Recuperar contraseña de administración</p></div></div>
</div></header>
<main>
<?php if ($error): ?><div class="flash error"><?= h($error) ?></div><?php endif; ?>

<section class="card">
<?php if ($enlaceDirecto): ?>
  <h2>✅ Enlace de restablecimiento listo</h2>
  <div class="success-box">
    <p style="margin:0 0 10px;color:#166534;font-size:.95rem">Se verificó tu cuenta. Haz clic en el botón de abajo para asignar tu nueva contraseña (enlace válido por 30 minutos):</p>
    <a class="btn btn-success" href="<?= h($enlaceDirecto) ?>">Cambiar contraseña ahora</a>
  </div>
  <p class="enlaces"><a href="login.php">Volver a iniciar sesión</a></p>
<?php else: ?>
  <h2>¿Olvidaste tu contraseña?</h2>
  <p class="hint">Escribe tu usuario o el correo de tu cuenta para generar un enlace de restablecimiento.</p>
  <form method="post" autocomplete="off">
  <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
  <label for="dato">Usuario o correo</label>
  <input id="dato" name="dato" required maxlength="120" autofocus placeholder="Ej. missael">
  <button class="btn" type="submit">Generar enlace de cambio</button>
  </form>
  <p class="enlaces"><a href="login.php">Volver a iniciar sesión</a></p>
<?php endif; ?>
</section>
</main>
<?php require_once __DIR__ . '/config/footer.php'; ?>
</body>
</html>
