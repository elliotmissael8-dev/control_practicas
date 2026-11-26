<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/correo.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function h(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$resultado = null;
$detalle = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    $para = trim((string) ($_POST['para'] ?? ''));

    if (!hash_equals((string) $_SESSION['csrf_token'], $token)) {
        $resultado = false;
        $detalle = 'La sesión del formulario expiró. Recarga la página.';
    } else {
        $resultado = enviar_correo(
            $para,
            'Prueba de correo - Control de Prácticas',
            "Si lees este mensaje, el envío de correo del sistema funciona.\r\n"
        );
        $detalle = $resultado ? '' : correo_ultimo_error();
    }
}

$modo = correo_smtp_usuario() === ''
    ? 'SMTP no configurado'
    : 'SMTP directo a ' . correo_smtp_host() . ':' . correo_smtp_puerto();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Probar correo | Prácticas Profesionales</title>
<link rel="stylesheet" href="assets/estilo.css?v=2">
<style>
:root{--navy:#142b4a;--blue:#2457a7;--bg:#f3f6fb;--text:#243247;--muted:#64748b;--border:#dbe3ee}
*{box-sizing:border-box}body{margin:0;font-family:"Segoe UI",Arial,sans-serif;background:var(--bg);color:var(--text)}
main{max-width:900px;margin:auto;padding:25px 20px}.card{background:#fff;border:1px solid var(--border);border-radius:14px;box-shadow:0 8px 28px #142b4a0d;padding:24px;margin-bottom:20px}
h1,h2{color:var(--navy)}label{display:block;font-weight:650;margin:12px 0 6px}input{width:100%;padding:11px 12px;border:1px solid #cbd5e1;border-radius:8px;font:inherit}
.btn{border:0;border-radius:8px;padding:11px 16px;margin-top:14px;font-weight:650;cursor:pointer;background:var(--blue);color:#fff}.ok{background:#dcfce7;color:#166534;padding:12px 15px;border-radius:9px}.fallo{background:#fee2e2;color:#991b1b;padding:12px 15px;border-radius:9px;word-break:break-word}
.hint{color:var(--muted)}
</style>
</head>
<body class="pg-administradores">
<?php
$menuSeccion = 'administradores';
$menuSubtitulo = 'Herramienta de administración';
require __DIR__ . '/config/encabezado.php';
?>
<main>
<?php if ($resultado === true): ?>
<div class="ok">Correo enviado. Revisa la bandeja de entrada y la carpeta de spam.</div>
<?php endif; ?>
<?php if ($resultado === false): ?>
<div class="fallo"><strong>No se pudo enviar.</strong> <?= h($detalle) ?></div>
<?php endif; ?>
<section class="card">
<h1>Enviar correo de prueba</h1>
<p class="hint">Modo actual: <strong><?= h($modo) ?></strong>. Remitente: <?= h(correo_remitente()) ?>.</p>
<form method="post" autocomplete="off">
<input type="hidden" name="csrf_token" value="<?= h((string) $_SESSION['csrf_token']) ?>">
<label for="para">Enviar a</label>
<input id="para" type="email" name="para" required maxlength="120" placeholder="tu-correo@ejemplo.com">
<button class="btn" type="submit">Enviar prueba</button>
</form>
</section>
</main>
</body>
</html>
