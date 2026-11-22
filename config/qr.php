<?php
declare(strict_types=1);

// Intervalo en segundos que dura el QR antes de cambiar
const QR_INTERVALO = 30;

// Ventanas anteriores aceptadas como válidas (tolerancia por retrasos de red)
const QR_TOLERANCIA = 1;

// Tiempo que el alumno puede navegar tras escanear el QR (5 minutos)
const QR_SESION_SEGUNDOS = 5 * 60;

// IPs autorizadas para proyectar el QR (vacío = cualquier IP autorizada con login)
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
    // Si Railway o el servidor tiene definida APP_URL o RAILWAY_PUBLIC_DOMAIN, úsala:
    $envUrl = $_ENV['APP_URL'] ?? getenv('APP_URL') ?? '';
    if ($envUrl !== '') {
        $base = rtrim($envUrl, '/');
    } elseif (!empty($_SERVER['HTTP_HOST'])) {
        // Detección automática en base a la URL con la que accedes en el navegador
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                 || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $base = ($https ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
    } elseif (!empty(getenv('RAILWAY_PUBLIC_DOMAIN'))) {
        $base = 'https://' . getenv('RAILWAY_PUBLIC_DOMAIN');
    } else {
        $base = 'http://localhost';
    }

    return $base . '/alumno.php?t=' . qr_token_actual();
}
