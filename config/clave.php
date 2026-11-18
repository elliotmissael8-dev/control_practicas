<?php
declare(strict_types=1);

const CLAVE_INTERVALO = 60;
const CLAVE_SESION_SEGUNDOS = 300;
const CLAVE_DIGITOS = 6;
const CLAVE_IPS_ADMIN = ['10.13.24.0','10.13.24.45', '127.0.0.1', '::1'];
const CLAVE_SECRETO = 'c893f62db66befa096fb1e72d31e3e4bc235ddfc0d8eb0ab01adaf07dbe11014';

function clave_en(int $momento): string {
    $ventana = intdiv($momento, CLAVE_INTERVALO);
    $hash = hash_hmac('sha256', CLAVE_INTERVALO . ':' . $ventana, CLAVE_SECRETO);
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
      return 0;
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