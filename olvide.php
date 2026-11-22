<?php
declare(strict_types=1);
define('ADMIN_PAGINA_LOGIN', true);
require __DIR__ . '/config/database.php';
require __DIR__ . '/config/auth.php';
require __DIR__ . '/config/historial.php';
require __DIR__ . '/config/migraciones.php';
require __DIR__ . '/config/correo.php';
require __DIR__ . '/config/qr.php';
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
function redirectOlvide(string $message, string $type): never {
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
    header('Location: olvide.php');
    exit;
}
// Máximo 5 solicitudes por IP cada 15 minutos.
function olvide_permitido(string $ip): bool {
    $archivo = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'olvide_' . hash('sha256', $ip) . '.json';
    $ahora = time();
    $marcas = is_file($archivo) ? json_decode((string)file_get_contents($archivo), true) : [];
    $marcas = array_values(array_filter(is_array($marcas) ? $marcas : [], static fn($m): bool => is_int($m) && $m > $ahora - 900));
    if (count($marcas) >= 5) {
        return false;
    }
    $marcas[] = $ahora;
    file_put_contents($archivo, json_encode($marcas), LOCK_EX);
    return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        redirectOlvide('La sesión del formulario expiró. Intenta nuevamente.', 'error');
    }
    if (!olvide_permitido((string)($_SERVER['REMOTE_ADDR'] ?? ''))) {
        redirectOlvide('Demasiadas solicitudes. Espera unos minutos e inténtalo de nuevo.', 'error');
    }
    $dato = trim((string)($_POST['dato'] ?? ''));
    if ($dato !== '' && mb_strlen($dato) <= 120) {
        try {
            $st = $pdo->prepare("SELECT id, usuario, correo FROM administradores WHERE (usuario = ? OR correo = ?) AND correo IS NOT NULL AND correo <> '' LIMIT 1");
            $st->execute([$dato, $dato]);
            $adm = $st->fetch();
            if ($adm) {
                $ahora = new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City'));
                $clave = bin2hex(random_bytes(32));
                $pdo->prepare('UPDATE recuperacion_password SET usado = 1 WHERE admin_id = ? AND usado = 0')->execute([(int)$adm['id']]);
                $pdo->prepare('INSERT INTO recuperacion_password (admin_id, token_hash, expira, usado, creado_en) VALUES (?, ?, ?, 0, ?)')
                    ->execute([(int)$adm['id'], hash('sha256', $clave), $ahora->modify('+30 minutes')->format('Y-m-d H:i:s'), $ahora->format('Y-m-d H:i:s')]);
                $envUrl = $_ENV['APP_URL'] ?? getenv('APP_URL');
if (is_string($envUrl) && $envUrl !== '') {
    $base = rtrim($envUrl, '/');
} elseif (!empty($_SERVER['HTTP_HOST'])) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
             || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $base = ($https ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
} elseif ($railway = getenv('RAILWAY_PUBLIC_DOMAIN')) {
    $base = 'https://' . (string)$railway;
} else {
    $base = 'http://localhost';
}
                $enviado = enviar_correo((string)$adm['correo'], 'Recuperación de contraseña',
                    "Hola " . $adm['usuario'] . ",\r\n\r\nRecibimos una solicitud para restablecer tu contraseña del sistema de Prácticas Profesionales.\r\n"
                    . "Abre este enlace (vale 30 minutos):\r\n" . $base . '/restablecer.php?t=' . $clave . "\r\n\r\n"
                    . "Si no fuiste tú, ignora este mensaje: tu contraseña no cambiará.\r\n");
                historial_registrar($pdo, 'Sesión', 'recuperación solicitada', 'Se solicitó recuperar la contraseña de: ' . $adm['usuario'] . ($enviado ? '' : ' (no se pudo enviar el correo: ' . correo_ultimo_error() . ')'), 'Sin sesión', 'sistema');
            }
        } catch (Throwable $e) {
            // Se responde igual para no revelar si la cuenta existe.
        }
    }
    redirectOlvide('Si existe una cuenta con ese usuario o correo, enviamos las instrucciones al correo registrado. Revisa también la carpeta de spam.', 'success');
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
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
main{padding:30px 20px}.card{background:#fff;border:1px solid var(--border);border-radius:14px;box-shadow:0 8px 28px #142b4a0d;padding:28px;max-width:420px;margin:0 auto}
h2{font-size:1.25rem;color:var(--navy);margin:0 0 6px}.hint{color:var(--muted);font-size:.9rem;margin:0 0 18px}
label{display:block;font-weight:650;font-size:.88rem;color:#34445a;margin:14px 0 7px}
input{width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:var(--text);font:inherit;font-size:1rem}input:focus{outline:0;border-color:var(--blue);box-shadow:0 0 0 3px #2457a71f}
.btn{display:block;width:100%;border:0;border-radius:8px;padding:13px;margin-top:20px;font:inherit;font-weight:700;cursor:pointer;background:var(--blue);color:#fff}
.flash{padding:12px 15px;border-radius:9px;margin:0 auto 17px;max-width:420px}.success{background:#dcfce7;color:#166534}.error{background:#fee2e2;color:#991b1b}
.enlaces{text-align:center;margin-top:16px;font-size:.9rem}.enlaces a{color:var(--blue);font-weight:650;text-decoration:none}
</style>
<link rel="stylesheet" href="assets/estilo.css?v=1">
</head>
<body class="pg-login">
<header><div class="header-inner">
  <div class="brand"><img class="marca-logo" src="https://esiima.uaa.mx/cursoextension/imgs/logouaa2.jpg" alt="Logo UAA"><div><h1>Control de Prácticas Profesionales y Servicio Social</h1><p>Recuperar contraseña de administración</p></div></div>
</div></header>
<main>
<?php if ($flash): ?><div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
<section class="card">
<h2>¿Olvidaste tu contraseña?</h2>
<p class="hint">Escribe tu usuario o el correo registrado en tu cuenta. Te enviaremos un enlace para crear una contraseña nueva. Si tu cuenta no tiene correo, pide a otro administrador que te cambie la contraseña.</p>
<form method="post" autocomplete="off">
<input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
<label for="dato">Usuario o correo</label>
<input id="dato" name="dato" required maxlength="120" autofocus>
<button class="btn" type="submit">Enviar enlace</button>
</form>
<p class="enlaces"><a href="login.php">Volver a iniciar sesión</a></p>
</section>
</main>
<?php require __DIR__ . '/config/footer.php'; ?>
</body>
</html>
