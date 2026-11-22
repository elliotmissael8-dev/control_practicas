<?php
declare(strict_types=1);


function correo_config(): array {
    return [
        'host' => 'smtp.gmail.com',
        'puerto' => 465, 
        'usuario' => 'recuperacioncontrasena902@gmail.com',
        'clave' => (string)($_ENV['SMTP_PASS'] ?? getenv('SMTP_PASS') ?: ''),
        'remitente' => 'recuperacioncontrasena902@gmail.com',
    ];
}

$GLOBALS['correo_ultimo_error'] = '';

function correo_ultimo_error(): string {
    return (string)($GLOBALS['correo_ultimo_error'] ?? '');
}

function enviar_correo(string $para, string $asunto, string $texto): bool {
    $GLOBALS['correo_ultimo_error'] = '';
    $cfg = correo_config();

    if (!filter_var($para, FILTER_VALIDATE_EMAIL)) {
        $GLOBALS['correo_ultimo_error'] = 'El correo de destino no es válido.';
        return false;
    }

    if ($cfg['clave'] === '') {
        $GLOBALS['correo_ultimo_error'] = 'Falta configurar la contraseña de aplicación de Gmail (variable SMTP_PASS).';
        return false;
    }

    try {
        correo_smtp($para, $asunto, $texto, $cfg);
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
    [$codigo, $texto] = correo_smtp_leer($fp);
    if (!in_array($codigo, $esperados, true)) {
        throw new RuntimeException('Respuesta SMTP ' . $codigo . ': ' . preg_replace('/\s+/', ' ', $texto));
    }
}

function correo_smtp(string $para, string $asunto, string $texto, array $cfg): void {
    $ssl = [
        'verify_peer' => false,
        'verify_peer_name' => false,
        'allow_self_signed' => true,
        'peer_name' => $cfg['host'],
    ];

    $protocolo = ($cfg['puerto'] === 465) ? 'ssl://' : 'tcp://';
    $fp = @stream_socket_client($protocolo . $cfg['host'] . ':' . $cfg['puerto'], $errno, $errstr, 15, STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => $ssl]));

    if (!$fp) {
        throw new RuntimeException('No se pudo conectar a ' . $cfg['host'] . ':' . $cfg['puerto'] . ' (' . $errstr . ').');
    }

    stream_set_timeout($fp, 20);
    try {
        correo_smtp_cmd($fp, '', [220]);
        correo_smtp_cmd($fp, 'EHLO localhost', [250]);

        correo_smtp_cmd($fp, 'AUTH LOGIN', [334]);
        correo_smtp_cmd($fp, base64_encode($cfg['usuario']), [334]);
        correo_smtp_cmd($fp, base64_encode($cfg['clave']), [235]);
        correo_smtp_cmd($fp, 'MAIL FROM:<' . $cfg['remitente'] . '>', [250]);
        correo_smtp_cmd($fp, 'RCPT TO:<' . $para . '>', [250, 251]);
        correo_smtp_cmd($fp, 'DATA', [354]);

        $cabeceras = [
            'Date: ' . date('r'),
            'From: Control de Practicas <' . $cfg['remitente'] . '>',
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
