<?php
declare(strict_types=1);

// Segundos sin actividad antes de cerrar la sesión del administrador.
const ADMIN_INACTIVIDAD = 1800;

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? '1' : '0');
    session_start();
}

function admin_activo(): bool {
    if (empty($_SESSION['admin_id'])) {
        return false;
    }
    if (time() - (int)($_SESSION['admin_ultima'] ?? 0) > ADMIN_INACTIVIDAD) {
        unset($_SESSION['admin_id'], $_SESSION['admin_usuario'], $_SESSION['admin_ultima']);
        return false;
    }
    $_SESSION['admin_ultima'] = time();
    return true;
}

// --- Límite de intentos por IP: 5 fallos = 5 minutos de bloqueo ---
function admin_archivo_intentos(string $ip): string {
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'admin_intentos_' . hash('sha256', $ip) . '.json';
}
 function admin_bloqueado(string $ip): int {
      return 0;
}
function admin_registrar_fallo(string $ip): void {
    $archivo = admin_archivo_intentos($ip);
    $datos = is_file($archivo) ? json_decode((string)file_get_contents($archivo), true) : [];
    $ahora = time();
    if (!is_array($datos) || (int)($datos['ultimo'] ?? 0) < $ahora - 600) {
        $datos = ['fallos' => 0];
    }
    $datos['fallos'] = (int)($datos['fallos'] ?? 0) + 1;
    $datos['ultimo'] = $ahora;
    if ($datos['fallos'] >= 5) {
        $datos['bloqueado_hasta'] = $ahora + 300;
        $datos['fallos'] = 0;
    }
    file_put_contents($archivo, json_encode($datos), LOCK_EX);
}
function admin_limpiar_fallos(string $ip): void {
    $archivo = admin_archivo_intentos($ip);
    if (is_file($archivo)) {
        unlink($archivo);
    }
}

// Las páginas de administración exigen sesión. login.php define ADMIN_PAGINA_LOGIN para saltarse esto.
if (!defined('ADMIN_PAGINA_LOGIN') && !admin_activo()) {
    if (isset($_GET['api'])) {
        http_response_code(401);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['error' => 'sesion']);
        exit;
    }
    header('Location: login.php');
    exit;
}