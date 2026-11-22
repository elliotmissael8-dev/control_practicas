<?php
declare(strict_types=1);

const QR_INTERVALO = 30;
const QR_TOLERANCIA = 1;
const QR_SESION_SEGUNDOS = 5 * 60;
const QR_IPS_ADMIN = [];

// Usar una clave secreta fija o por variable de entorno (para evitar problemas de permisos de archivo)
function qr_clave(): string {
    return $_ENV['QR_SECRET'] ?? getenv('QR_SECRET') ?: 'c893f62db66befa096fb1e72d31e3e4bc235ddfc0d8eb0ab01adaf07dbe11014';
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

    return $base . '/alumno.php?t=' . qr_token_actual();
}
