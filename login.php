<?php
declare(strict_types=1);
define('ADMIN_PAGINA_LOGIN', true);
require __DIR__ . '/config/database.php';
require __DIR__ . '/config/auth.php';
require __DIR__ . '/config/historial.php';

if (isset($_GET['salir'])) {
    if (!empty($_SESSION['admin_usuario'])) {
        historial_registrar($pdo, 'Sesión', 'cierre de sesión', 'Cierre de sesión de administrador.');
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: login.php?cerrada=1');
    exit;
}

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
function redirectLogin(string $message, string $type = 'error'): never {
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
    header('Location: login.php');
    exit;
}

$hayAdmin = null;
$errorTabla = null;
try {
    $hayAdmin = (int)$pdo->query('SELECT COUNT(*) FROM administradores')->fetchColumn() > 0;
} catch (PDOException $e) {
    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS administradores (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario VARCHAR(50) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            correo VARCHAR(120) NULL,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_administradores_usuario (usuario)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $hayAdmin = false;
    } catch (PDOException $e2) {
        $errorTabla = 'Falta crear la tabla administradores en la base de datos.';
    }
}
$esLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hayAdmin !== null) {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        redirectLogin('La sesión del formulario expiró. Intenta nuevamente.');
    }
    $accion = (string)($_POST['accion'] ?? '');

    // Primera vez: crear al administrador.
    if ($accion === 'crear' && !$hayAdmin) {
        $usuario = trim((string)($_POST['usuario'] ?? ''));
        $pass = (string)($_POST['password'] ?? '');
        $pass2 = (string)($_POST['password2'] ?? '');
        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $usuario)) {
            redirectLogin('El usuario debe tener de 3 a 50 caracteres: letras, números, punto, guion o guion bajo.');
        }
        if (strlen($pass) < 8) {
            redirectLogin('La contraseña debe tener al menos 8 caracteres.');
        }
        if ($pass !== $pass2) {
            redirectLogin('Las contraseñas no coinciden.');
        }
        $stmt = $pdo->prepare('INSERT INTO administradores (usuario, password_hash) VALUES (?, ?)');
        $stmt->execute([$usuario, password_hash($pass, PASSWORD_DEFAULT)]);
        historial_registrar($pdo, 'Administradores', 'crear', 'Administrador inicial creado: ' . $usuario, $usuario);
        redirectLogin('Administrador creado. Ya puedes iniciar sesión.', 'success');
    }

    if ($accion === 'entrar' && $hayAdmin) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $espera = admin_bloqueado($ip);
        if ($espera > 0) {
            redirectLogin('Demasiados intentos. Espera ' . (int)ceil($espera / 60) . ' minuto(s) e inténtalo de nuevo.');
        }
        $usuario = trim((string)($_POST['usuario'] ?? ''));
        $pass = (string)($_POST['password'] ?? '');
        $stmt = $pdo->prepare('SELECT id, usuario, password_hash FROM administradores WHERE usuario = ? LIMIT 1');
        $stmt->execute([$usuario]);
        $fila = $stmt->fetch();
        $hash = $fila ? (string)$fila['password_hash'] : password_hash('relleno', PASSWORD_DEFAULT);
        $verificado = password_verify($pass, $hash);
        if (!$fila || !$verificado) {
            admin_registrar_fallo($ip);
            redirectLogin('Usuario o contraseña incorrectos.');
        }
        admin_limpiar_fallos($ip);
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int)$fila['id'];
        $_SESSION['admin_usuario'] = (string)$fila['usuario'];
        $_SESSION['admin_ultima'] = time();
        historial_registrar($pdo, 'Sesión', 'inicio de sesión', 'Inicio de sesión de administrador.');
        header('Location: index.php');
        exit;
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Iniciar sesión | Prácticas Profesionales</title>
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
footer{text-align:center;color:var(--muted);padding:0 20px 25px;font-size:.82rem}
</style>
<link rel="stylesheet" href="assets/estilo.css?v=1">
</head>
<body class="pg-login">
<header><div class="header-inner">
  <div class="brand"><img class="marca-logo" src="https://esiima.uaa.mx/cursoextension/imgs/logouaa2.jpg" alt="Logo UAA"><div><h1>Control de Prácticas Profesionales y Servicio Social</h1><p>Acceso de administración</p></div></div>
</div></header>
<main>
<?php if ($flash): ?><div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
<?php if (isset($_GET['cerrada']) && !$flash): ?><div class="flash success">Sesión cerrada.</div><?php endif; ?>
<?php if ($errorTabla): ?><div class="flash error"><?= h($errorTabla) ?></div><?php endif; ?>

<?php if ($hayAdmin === false): ?>
<section class="card">
<h2>Crear administrador</h2>
<p class="hint">Es la primera vez que se usa el sistema. Crea el usuario y la contraseña del administrador.</p>
<form method="post" autocomplete="off">
<input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
<input type="hidden" name="accion" value="crear">
<label for="usuario">Usuario</label>
<input id="usuario" name="usuario" required maxlength="50" autocomplete="off">
<label for="password">Contraseña (mínimo 8 caracteres)</label>
<input id="password" type="password" name="password" required minlength="8" autocomplete="new-password">
<label for="password2">Repite la contraseña</label>
<input id="password2" type="password" name="password2" required minlength="8" autocomplete="new-password">
<button class="btn" type="submit">Crear administrador</button>
</form>
</section>

<?php elseif ($hayAdmin === true): ?>
<section class="card">
<h2>Iniciar sesión</h2>
<p class="hint">Ingresa con tu usuario y contraseña de administrador.</p>
<form method="post">
<input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
<input type="hidden" name="accion" value="entrar">
<label for="usuario">Usuario</label>
<input id="usuario" name="usuario" required maxlength="50" autocomplete="username" autofocus>
<label for="password">Contraseña</label>
<input id="password" type="password" name="password" required autocomplete="current-password">
<button class="btn" type="submit">Entrar</button>
<p style="text-align:center;margin:16px 0 0;font-size:.9rem"><a href="olvide.php" style="color:var(--blue);font-weight:650;text-decoration:none">¿Olvidaste tu contraseña?</a></p>
</form>
</section>
<?php endif; ?>
</main>
<?php require __DIR__ . '/config/footer.php'; ?>
</body>
</html>
