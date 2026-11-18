<?php
declare(strict_types=1);


const QR_INTERVALO = 30;

const QR_TOLERANCIA = 1;

const QR_SESION_SEGUNDOS = 5 * 60;

const QR_BASE_URL = 'https://10.13.24.0/control_practicas_sql';
const QR_IPS_ADMIN = [];

function qr_clave(): string {
    $archivo = __DIR__ . '/qr_secret.key';
    if (!is_file($archivo)) {
        file_put_contents($archivo, bin2hex(random_bytes(32)), LOCK_EX);
    }
    return trim((string)file_get_contents($archivo));
}

function qr_firma(int $ventana): string {
    return substr(hash_hmac('sha256', (string)$ventana, qr_clave()), 0, 24);
}

function qr_token_actual(): string {
    $ventana = intdiv(time(), QR_INTERVALO);
    return $ventana . '.' . qr_firma($ventana);
}

function qr_validar_token(string $token): bool {
    if (!preg_match('/^(\d{1,12})\.([a-f0-9]{24})$/', $token, $m)) {
        return false;
    }
    $ventana = (int)$m[1];
    $actual = intdiv(time(), QR_INTERVALO);
    if ($ventana > $actual || $ventana < $actual - QR_TOLERANCIA) {
        return false;
    }
    return hash_equals(qr_firma($ventana), $m[2]);
}

function qr_url_alumno(): string {
    if (QR_BASE_URL !== '') {
        $base = rtrim(QR_BASE_URL, '/');
    } else {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        $base = ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir;
    }
    return $base . '/alumno.php?t=' . qr_token_actual();
}
