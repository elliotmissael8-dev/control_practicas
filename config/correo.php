<?php
declare(strict_types=1);

// Configuración SMTP (soporta variables de entorno o valores directos)
define('CORREO_SMTP_HOST', $_ENV['SMTP_HOST'] ?? getenv('SMTP_HOST') ?: 'smtp.gmail.com');
define('CORREO_SMTP_PUERTO', (int)($_ENV['SMTP_PORT'] ?? getenv('SMTP_PORT') ?: 587));
define('CORREO_SMTP_USUARIO', $_ENV['SMTP_USER'] ?? getenv('SMTP_USER') ?: 'recuperacioncontrasena902@gmail.com');
const CORREO_SMTP_HOST = 'smtp.gmail.com';
const CORREO_SMTP_PUERTO = 587;
const CORREO_SMTP_USUARIO = 'recuperacioncontrasena902@gmail.com';
define('CORREO_SMTP_CLAVE', $_ENV['SMTP_PASS'] ?? getenv('SMTP_PASS') ?: '');
const CORREO_REMITENTE = 'recuperacioncontrasena902@gmail.com';
function correo_ultimo_error(): string {
    return (string)($GLOBALS['correo_ultimo_error'] ?? '');
}

function enviar_correo(string $para, string $asunto, string $texto): bool {
    $GLOBALS['correo_ultimo_error'] = '';
    if (!filter_var($para, FILTER_VALIDATE_EMAIL)) {
        $GLOBALS['correo_ultimo_error'] = 'El correo de destino no es válido.';
        return false;
    }
    if (CORREO_SMTP_CLAVE === '') {
        $cabeceras = 'From: Control de Practicas <' . CORREO_REMITENTE . ">\r\n"
            . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n";
        $ok = @mail($para, '=?UTF-8?B?' . base64_encode($asunto) . '?=', $texto, $cabeceras);
        if (!$ok) {
            $GLOBALS['correo_ultimo_error'] = 'mail() falló. Pon la contraseña de aplicación en CORREO_SMTP_CLAVE (config/correo.php) para enviar directo.';
        }
        return $ok;
    }
    try {
        correo_smtp($para, $asunto, $texto);
        return true;
    } catch (Throwable $e) {
        $GLOBALS['correo_ultimo_error'] = mb_substr($e->getMessage(), 0, 300);
        return false;
    }
}

// Lee una respuesta SMTP (puede ser de varias líneas). Devuelve [código, texto].
function correo_smtp_leer($fp): array {
    $texto = '';
    while (($linea = fgets($fp, 1024)) !== false) {
        $texto .= $linea;
        if (strlen($linea) < 4 || $linea[3] === ' ') {
            break;
        }
    }
    if ($texto === '') {
        throw new RuntimeException('El servidor de correo no respondió (tiempo agotado).');
    }
    return [(int)substr($texto, 0, 3), trim($texto)];
}

function correo_smtp_cmd($fp, string $comando, array $esperados): void {
    if ($comando !== '') {
        fwrite($fp, $comando . "\r\n");
    }
    [$codigo, $texto] = correo_smtp_leer($fp);
    if (!in_array($codigo, $esperados, true)) {
        throw new RuntimeException('Respuesta SMTP ' . $codigo . ': ' . preg_replace('/\s+/', ' ', $texto));
    }
}

function correo_smtp(string $para, string $asunto, string $texto): void {
    if (!extension_loaded('openssl')) {
        throw new RuntimeException('Falta la extensión openssl de PHP. En php.ini activa extension=openssl y reinicia Apache.');
    }
    $ssl = [
        'verify_peer' => CORREO_VERIFICAR_CERTIFICADO,
        'verify_peer_name' => CORREO_VERIFICAR_CERTIFICADO,
        'allow_self_signed' => !CORREO_VERIFICAR_CERTIFICADO,
        'peer_name' => CORREO_SMTP_HOST,
    ];
    $ca = (string)ini_get('openssl.cafile');
    if ($ca === '' || !is_file($ca)) {
        foreach (['C:/xampp/apache/bin/curl-ca-bundle.crt', 'C:/xampp/php/extras/ssl/cacert.pem'] as $candidato) {
            if (is_file($candidato)) {
                $ca = $candidato;
                break;
            }
        }
    }
    if ($ca !== '' && is_file($ca)) {
        $ssl['cafile'] = $ca;
    }

    $fp = @stream_socket_client('tcp://' . CORREO_SMTP_HOST . ':' . CORREO_SMTP_PUERTO, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => $ssl]));
    if (!$fp) {
        throw new RuntimeException('No se pudo conectar a ' . CORREO_SMTP_HOST . ':' . CORREO_SMTP_PUERTO . ' (' . $errstr . '). Revisa internet, el firewall o el antivirus.');
    }
    stream_set_timeout($fp, 20);
    try {
        correo_smtp_cmd($fp, '', [220]);
        correo_smtp_cmd($fp, 'EHLO localhost', [250]);
        correo_smtp_cmd($fp, 'STARTTLS', [220]);
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('No se pudo iniciar la conexión segura (TLS). Si el error menciona el certificado, prueba CORREO_VERIFICAR_CERTIFICADO = false solo para probar.');
        }
        correo_smtp_cmd($fp, 'EHLO localhost', [250]);
        correo_smtp_cmd($fp, 'AUTH LOGIN', [334]);
        correo_smtp_cmd($fp, base64_encode(CORREO_SMTP_USUARIO), [334]);
        correo_smtp_cmd($fp, base64_encode(CORREO_SMTP_CLAVE), [235]);
        correo_smtp_cmd($fp, 'MAIL FROM:<' . CORREO_REMITENTE . '>', [250]);
        correo_smtp_cmd($fp, 'RCPT TO:<' . $para . '>', [250, 251]);
        correo_smtp_cmd($fp, 'DATA', [354]);
        $cabeceras = [
            'Date: ' . date('r'),
            'From: Control de Practicas <' . CORREO_REMITENTE . '>',
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
