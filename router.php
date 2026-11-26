<?php
declare(strict_types=1);

// Enrutador para `php -S`: el servidor integrado no lee .htaccess, así que aquí se bloquea
// el acceso web a configuración, claves, esquemas SQL, documentos subidos y archivos de despliegue.
$ruta = rawurldecode((string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/'));

$directoriosPrivados = '#(^|/)(config|uploads|docs|mnt|\.git)(/|$)#i';
$archivosPrivados = '#(^|/)(\.[^/]*|Dockerfile|docker-compose\.ya?ml|router\.php)$|\.(sql|key|lock|md|ya?ml|env|log)$#i';

if (preg_match($directoriosPrivados, $ruta) || preg_match($archivosPrivados, $ruta)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'No encontrado';
    return true;
}

return false;
