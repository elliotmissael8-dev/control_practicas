<?php
declare(strict_types=1);

const QR_INTERVALO = 30;
const QR_TOLERANCIA = 1;
const QR_SESION_SEGUNDOS = 5 * 60;
const QR_IPS_ADMIN = [];

/**
 * Obtiene una clave estable para firmar QR.
 * En producción/configuración multi-instancia, define QR_SECRET en el entorno.
 * Como alternativa local, guarda una clave generada en un archivo ignorado por Git.
 */
function qr_clave(): string {
    $clave = $_ENV['QR_SECRET'] ?? getenv('QR_SECRET');
    if (is_string($clave) && trim($clave) !== '') {
        $clave = trim($clave);
        if (strlen($clave) < 32) {
            throw new RuntimeException('QR_SECRET debe tener al menos 32 caracteres.');
        }
        return $clave;
    }

    $ruta = __DIR__ . '/qr_secret.key';
    $bloqueo = @fopen($ruta . '.lock', 'c');
    if ($bloqueo === false) {
        throw new RuntimeException('Configura QR_SECRET en el entorno: no se pudo crear el archivo local de clave QR.');
    }

    try {
        if (!flock($bloqueo, LOCK_EX)) {
            throw new RuntimeException('Configura QR_SECRET en el entorno: no se pudo bloquear el archivo de clave QR.');
        }

        $existente = is_file($ruta) ? trim((string)file_get_contents($ruta)) : '';
        if (strlen($existente) >= 32) {
            return $existente;
        }

        $nueva = bin2hex(random_bytes(32));
        $temporal = $ruta . '.' . bin2hex(random_bytes(8)) . '.tmp';
        if (file_put_contents($temporal, $nueva . PHP_EOL, LOCK_EX) === false
            || !@chmod($temporal, 0600)
            || !@rename($temporal, $ruta)) {
            @unlink($temporal);
            throw new RuntimeException('Configura QR_SECRET en el entorno: no se pudo guardar la clave QR local.');
        }

        return $nueva;
    } finally {
        flock($bloqueo, LOCK_UN);
        fclose($bloqueo);
    }
}

function qr_firma(int $ventana): string {
    return substr(hash_hmac('sha256', (string)$ventana, qr_clave()), 0, 24);
}

function qr_token_actual(): string {
    $ventana = intdiv(time(), QR_INTERVALO);
    return $ventana . '.' . qr_firma($ventana);
}

function qr_validar_token(string $token): bool {
    if (!preg_match('/^([0-9]{1,12})\.([a-f0-9]{24})$/', $token, $m)) {
        return false;
    }
    $ventana = (int)$m[1];
    $actual = intdiv(time(), QR_INTERVALO);
    if ($ventana > $actual || $ventana < $actual - QR_TOLERANCIA) {
        return false;
    }
    return hash_equals(qr_firma($ventana), $m[2]);
}

/** Genera el enlace al endpoint PHP alumno.php en el mismo despliegue que qr.php. */
function qr_url_alumno(): string {
    $appUrl = $_ENV['APP_URL'] ?? getenv('APP_URL');
    if (is_string($appUrl) && trim($appUrl) !== '') {
        // APP_URL debe ser la URL base de la aplicación PHP, no una página frontend aparte.
        $base = rtrim(trim($appUrl), '/');
        // Si APP_URL incluye una página (login.php, index.php…), una consulta o un fragmento, se descartan:
        // el QR siempre debe terminar en <base>/alumno.php y nunca en una página de administración.
        $base = preg_replace('/[?#].*$/', '', $base) ?? $base;
        $base = rtrim(preg_replace('#/[^/]+\.php(/.*)?$#i', '', $base) ?? $base, '/');
        if (!preg_match('#^https?://[^/\s]+#i', $base)) {
            throw new RuntimeException('APP_URL debe empezar con http:// o https:// (por ejemplo https://tu-dominio.up.railway.app).');
        }
    } else {
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '' || preg_match('/[\r\n\\\/]/', $host)) {
            throw new RuntimeException('Configura APP_URL con la URL pública de la aplicación PHP.');
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/qr.php'));
        $directorio = rtrim(str_replace('\\', '/', dirname($script)), '/.');
        $rutaBase = $directorio === '' ? '' : '/' . ltrim($directorio, '/');
        $base = ($https ? 'https://' : 'http://') . $host . $rutaBase;
    }

    return $base . '/alumno.php?t=' . rawurlencode(qr_token_actual());
}
