<?php
declare(strict_types=1);

// Configuración SMTP mediante variables de entorno.
// Gmail requiere una contraseña de aplicación (no la contraseña normal).

function correo_env(string $nombre, string $alternativo = ''): string {
    $valor = $_ENV[$nombre] ?? getenv($nombre);
    if (is_string($valor) && $valor !== '') {
        return trim($valor);
    }
    if ($alternativo !== '') {
        $valor = $_ENV[$alternativo] ?? getenv($alternativo);
        if (is_string($valor) && $valor !== '') {
            return trim($valor);
        }
    }
    return '';
}

const CORREO_SMTP_HOST_DEFECTO = 'smtp.gmail.com';
const CORREO_SMTP_PUERTO_DEFECTO = 587;

$GLOBALS['correo_ultimo_error'] = '';
$GLOBALS['correo_smtp_host'] = correo_env('SMTP_HOST') ?: CORREO_SMTP_HOST_DEFECTO;
$GLOBALS['correo_smtp_puerto'] = (int)(correo_env('SMTP_PORT') ?: CORREO_SMTP_PUERTO_DEFECTO);
$GLOBALS['correo_smtp_usuario'] = correo_env('SMTP_USER', 'GMAIL_USER');
$GLOBALS['correo_smtp_clave'] = correo_env('SMTP_PASS', 'GMAIL_APP_PASSWORD');
$GLOBALS['correo_remitente'] = correo_env('SMTP_FROM', 'GMAIL_FROM') ?: $GLOBALS['correo_smtp_usuario'];
$GLOBALS['correo_nombre'] = correo_env('SMTP_NAME') ?: 'Control de Prácticas';
$GLOBALS['correo_verificar_certificado'] = !in_array(strtolower(correo_env('SMTP_INSECURE') ?: 'false'), ['1', 'true', 'yes'], true);

function correo_smtp_host(): string { return (string)($GLOBALS['correo_smtp_host'] ?? CORREO_SMTP_HOST_DEFECTO); }
function correo_smtp_puerto(): int { return (int)($GLOBALS['correo_smtp_puerto'] ?? CORREO_SMTP_PUERTO_DEFECTO); }
function correo_smtp_usuario(): string { return (string)($GLOBALS['correo_smtp_usuario'] ?? ''); }
function correo_smtp_clave(): string { return (string)($GLOBALS['correo_smtp_clave'] ?? ''); }
function correo_remitente(): string { return (string)($GLOBALS['correo_remitente'] ?? correo_smtp_usuario()); }
function correo_nombre(): string { return (string)($GLOBALS['correo_nombre'] ?? 'Control de Prácticas'); }

// Compatibilidad temporal con el nombre usado por probar_correo.php.
const CORREO_SMTP_HOST = CORREO_SMTP_HOST_DEFECTO;
const CORREO_SMTP_PUERTO = CORREO_SMTP_PUERTO_DEFECTO;
const CORREO_SMTP_USUARIO = '';
const CORREO_SMTP_CLAVE = '';
const CORREO_REMITENTE = '';

function correo_ultimo_error(): string {
    return (string)($GLOBALS['correo_ultimo_error'] ?? '');
}

function enviar_correo(string $para, string $asunto, string $texto): bool {
    $GLOBALS['correo_ultimo_error'] = '';
    if (!filter_var($para, FILTER_VALIDATE_EMAIL)) {
        $GLOBALS['correo_ultimo_error'] = 'El correo de destino no es válido.';
        return false;
    }
    if (correo_smtp_usuario() === '' || correo_smtp_clave() === '') {
        $GLOBALS['correo_ultimo_error'] = 'Faltan SMTP_USER y SMTP_PASS en las variables de entorno.';
        return false;
    }
    try {
        correo_smtp($para, $asunto, $texto);
        return true;
    } catch (Throwable $e) {
        $GLOBALS['correo_ultimo_error'] = mb_substr($e->getMessage(), 0, 300);
        return false;
    }
}

function correo_smtp_leer($fp): array {
    $texto = '';
    while (($linea = fgets($fp, 1024)) !== false) {
        $texto .= $linea;
        if (strlen($linea) < 4 || $linea[3] === ' ') {
            break;
        }
    }
    if ($texto === '') {
        throw new RuntimeException('El servidor de correo no respondió a tiempo.');
    }
    return [(int)substr($texto, 0, 3), trim($texto)];
}

function correo_smtp_cmd($fp, string $comando, array $esperados): void {
    if ($comando !== '') {
        fwrite($fp, $comando . "\r\n");
    }
    [$codigo, $respuesta] = correo_smtp_leer($fp);
    if (!in_array($codigo, $esperados, true)) {
        throw new RuntimeException('Respuesta SMTP ' . $codigo . ': ' . preg_replace('/\s+/', ' ', $respuesta));
    }
}

function correo_smtp(string $para, string $asunto, string $texto): void {
    $host = correo_smtp_host();
    $puerto = correo_smtp_puerto();
    $usuario = correo_smtp_usuario();
    $clave = correo_smtp_clave();
    $remitente = correo_remitente();

    $ssl = [
        'verify_peer' => (bool)($GLOBALS['correo_verificar_certificado'] ?? true),
        'verify_peer_name' => (bool)($GLOBALS['correo_verificar_certificado'] ?? true),
        'allow_self_signed' => !(bool)($GLOBALS['correo_verificar_certificado'] ?? true),
        'peer_name' => $host,
    ];

    $fp = @stream_socket_client(
        'tcp://' . $host . ':' . $puerto,
        $errno,
        $errstr,
        15,
        STREAM_CLIENT_CONNECT,
        stream_context_create(['ssl' => $ssl])
    );
    if (!$fp) {
        throw new RuntimeException('No se pudo conectar a ' . $host . ':' . $puerto . ' (' . $errstr . ').');
    }

    stream_set_timeout($fp, 20);
    try {
        correo_smtp_cmd($fp, '', [220]);
        correo_smtp_cmd($fp, 'EHLO localhost', [250]);
        correo_smtp_cmd($fp, 'STARTTLS', [220]);
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('No se pudo iniciar la conexión segura TLS.');
        }
        correo_smtp_cmd($fp, 'EHLO localhost', [250]);
        correo_smtp_cmd($fp, 'AUTH LOGIN', [334]);
        correo_smtp_cmd($fp, base64_encode($usuario), [334]);
        correo_smtp_cmd($fp, base64_encode($clave), [235]);
        correo_smtp_cmd($fp, 'MAIL FROM:<' . $remitente . '>', [250]);
        correo_smtp_cmd($fp, 'RCPT TO:<' . $para . '>', [250, 251]);
        correo_smtp_cmd($fp, 'DATA', [354]);

        $cabeceras = [
            'Date: ' . date('r'),
            'From: ' . correo_nombre() . ' <' . $remitente . '>',
            'To: <' . $para . '>',
            'Subject: =?UTF-8?B?' . base64_encode($asunto) . '?=',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        fwrite($fp, implode("\r\n", $cabeceras) . "\r\n\r\n" . chunk_split(base64_encode($texto)) . ".\r\n");
        correo_smtp_cmd($fp, '', [250]);
        @fwrite($fp, "QUIT\r\n");
    } finally {
        fclose($fp);
    }
}
