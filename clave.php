<?php
declare(strict_types=1);

const CLAVE_INTERVALO = 60;
const CLAVE_SESION_SEGUNDOS = 300;
const CLAVE_DIGITOS = 6;
const CLAVE_IPS_ADMIN = [];

require_once __DIR__ . '/qr.php';

// Secreto de la clave numérica: CLAVE_SECRET (mín. 32 caracteres) o, si no existe, derivado de QR_SECRET / qr_secret.key.
function clave_secreto(): string {
    $env = $_ENV['CLAVE_SECRET'] ?? getenv('CLAVE_SECRET');
    if (is_string($env) && strlen(trim($env)) >= 32) {
        return trim($env);
    }
    return hash_hmac('sha256', 'clave-asistencia', qr_clave());
}

function clave_en(int $momento): string {
    $ventana = intdiv($momento, CLAVE_INTERVALO);
    $hash = hash_hmac('sha256', CLAVE_INTERVALO . ':' . $ventana, clave_secreto());
    return str_pad((string)(hexdec(substr($hash, 0, 8)) % (10 ** CLAVE_DIGITOS)), CLAVE_DIGITOS, '0', STR_PAD_LEFT);
}
function clave_actual(): string {
    return clave_en(time());
}
function clave_restante(): int {
    return CLAVE_INTERVALO - (time() % CLAVE_INTERVALO);
}
function clave_validar(string $ingresada): bool {
    $ingresada = preg_replace('/\D/', '', $ingresada) ?? '';
    return $ingresada !== '' && hash_equals(clave_actual(), $ingresada);
}

// --- Límite de intentos por IP: 5 fallos = 5 minutos de bloqueo ---
function clave_archivo_intentos(string $ip): string {
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'clave_intentos_' . hash('sha256', $ip) . '.json';
}
function clave_bloqueado(string $ip): int {
    $archivo = clave_archivo_intentos($ip);
    if (!is_file($archivo)) {
        return 0;
    }
    $datos = json_decode((string)file_get_contents($archivo), true);
    $hasta = is_array($datos) ? (int)($datos['bloqueado_hasta'] ?? 0) : 0;
    return max(0, $hasta - time());
}
function clave_registrar_fallo(string $ip): void {
    $archivo = clave_archivo_intentos($ip);
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
function clave_limpiar_fallos(string $ip): void {
    $archivo = clave_archivo_intentos($ip);
    if (is_file($archivo)) {
        unlink($archivo);
    }
}
