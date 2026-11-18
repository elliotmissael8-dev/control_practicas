<?php
declare(strict_types=1);

// Documentos escaneados: cada estudiante tiene su carpeta uploads/documentos/<Apellidos>_<id>/
// El ID al final evita conflictos entre alumnos con los mismos apellidos y no cambia aunque se editen los apellidos
// (la carpeta ya creada se reutiliza; se busca por el ID). También se reconoce la carpeta "<id>" de la versión anterior.
// En MySQL (documentos_entregados.archivo_ruta) se guarda la ruta relativa: "<carpeta>/<Apellidos_Nombre_Documento_xxxxxxxx>.<ext>".
// Los registros antiguos (solo el nombre del archivo, sin carpeta) siguen funcionando.

const DOCS_RUTA_RELATIVA = 'uploads/documentos';
const DOCS_MAX_BYTES = 10 * 1024 * 1024;
const DOCS_TIPOS = ['Carta de presentación', 'Carta de aceptación', 'Reporte parcial', 'Reporte final', 'Evaluación del tutor', 'Carta de terminación', 'Otro'];

function docs_base(): string {
    return dirname(__DIR__) . '/' . DOCS_RUTA_RELATIVA;
}

// Crea uploads/documentos y su .htaccess (sin listado de carpetas y sin ejecutar scripts).
function docs_preparar_base(): bool {
    $base = docs_base();
    if (!is_dir($base) && !mkdir($base, 0755, true) && !is_dir($base)) {
        return false;
    }
    $ht = $base . '/.htaccess';
    if (!is_file($ht)) {
        file_put_contents($ht, implode("\n", [
            'Options -Indexes',
            '<FilesMatch "\.(php|phtml|phar|php[0-9])$">',
            '    Require all denied',
            '</FilesMatch>',
            '',
        ]), LOCK_EX);
    }
    return true;
}

// Busca la carpeta ya existente del estudiante ("<Apellidos>_<id>" o "<id>"). Null si no existe.
function docs_carpeta_existente(int $id): ?string {
    if ($id <= 0) {
        return null;
    }
    $base = docs_base();
    $encontradas = glob($base . '/*_' . $id, GLOB_ONLYDIR) ?: [];
    if ($encontradas) {
        return $encontradas[0];
    }
    return is_dir($base . '/' . $id) ? $base . '/' . $id : null;
}

// Devuelve la carpeta del estudiante (la crea si no existe) o null si falla.
function docs_carpeta_alumno(int $id, string $apellidos = ''): ?string {
    if ($id <= 0 || !docs_preparar_base()) {
        return null;
    }
    $carpeta = docs_carpeta_existente($id);
    if ($carpeta !== null) {
        return $carpeta;
    }
    $carpeta = docs_base() . '/' . substr(docs_slug($apellidos !== '' ? $apellidos : 'alumno'), 0, 60) . '_' . $id;
    if (!is_dir($carpeta) && !mkdir($carpeta, 0755) && !is_dir($carpeta)) {
        return null;
    }
    return $carpeta;
}

// Archivo permanente: cuando se elimina un alumno, sus archivos NO se borran; se mueven aquí junto con una ficha.
const DOCS_ARCHIVO_RELATIVA = 'uploads/archivo';

function docs_archivo_base(): string {
    return dirname(__DIR__) . '/' . DOCS_ARCHIVO_RELATIVA;
}

// Crea uploads/archivo con acceso web bloqueado (solo se consulta desde el servidor o con el respaldo).
function docs_preparar_archivo(): bool {
    $base = docs_archivo_base();
    if (!is_dir($base) && !mkdir($base, 0755, true) && !is_dir($base)) {
        return false;
    }
    $ht = $base . '/.htaccess';
    if (!is_file($ht)) {
        file_put_contents($ht, "Require all denied\n", LOCK_EX);
    }
    return true;
}

// True si en uploads/archivo ya hay una carpeta de ese ID (con o sin sufijo de fecha).
function docs_archivo_tiene_id(int $id): bool {
    $base = docs_archivo_base();
    if (!is_dir($base)) {
        return false;
    }
    foreach (scandir($base) ?: [] as $nombre) {
        if ($nombre !== '.' && $nombre !== '..' && preg_match('~(^|_)' . $id . '(_\d{14})?$~', $nombre)) {
            return true;
        }
    }
    return false;
}

// True si el ID no tiene carpeta de documentos (o está vacía) ni carpeta en el archivo: no hereda archivos ajenos.
function docs_id_libre(int $id): bool {
    $carpeta = docs_carpeta_existente($id);
    return ($carpeta === null || count(scandir($carpeta) ?: []) <= 2) && !docs_archivo_tiene_id($id);
}

// Mueve la carpeta del alumno (si existe) a uploads/archivo y guarda su ficha. Devuelve la carpeta final o null si falla.
// Nunca borra archivos.
function docs_archivar_alumno(int $id, string $apellidos, string $ficha): ?string {
    if ($id <= 0 || !docs_preparar_archivo()) {
        return null;
    }
    $origen = docs_carpeta_existente($id);
    $nombre = $origen !== null ? basename($origen) : substr(docs_slug($apellidos !== '' ? $apellidos : 'alumno'), 0, 60) . '_' . $id;
    $destino = docs_archivo_base() . '/' . $nombre;
    if (is_dir($destino)) {
        $destino .= '_' . date('YmdHis');
    }
    if ($origen !== null) {
        if (!rename($origen, $destino)) {
            return null;
        }
    } elseif (!mkdir($destino, 0755)) {
        return null;
    }
    file_put_contents($destino . '/FICHA.txt', $ficha, LOCK_EX);
    return $destino;
}

// Solo acepta rutas con el formato que genera el sistema; evita borrar o leer archivos ajenos.
function docs_ruta_segura(string $ruta): ?string {
    if (!preg_match('~^(?:(?:[A-Za-z0-9_]{1,120}_[0-9]{1,10}|[1-9][0-9]{0,9})/)?(?:[A-Za-z0-9_]{1,150}_[a-f0-9]{8}|[a-f0-9]{32})\.(?:pdf|jpg|png)$~', $ruta)) {
        return null;
    }
    return docs_base() . '/' . $ruta;
}

function docs_borrar_archivo(?string $ruta): void {
    if ($ruta === null || $ruta === '') {
        return;
    }
    $archivo = docs_ruta_segura($ruta);
    if ($archivo !== null && is_file($archivo)) {
        unlink($archivo);
    }
}

function docs_borrar_carpeta_alumno(int $id): void {
    if ($id <= 0) {
        return;
    }
    $carpeta = docs_carpeta_existente($id);
    if ($carpeta === null) {
        return;
    }
    foreach (scandir($carpeta) ?: [] as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        $p = $carpeta . '/' . $f;
        if (is_file($p)) {
            unlink($p);
        }
    }
    @rmdir($carpeta);
}

// Convierte un texto en un nombre de archivo seguro: sin acentos, sin espacios ni símbolos.
function docs_slug(string $texto): string {
    $texto = strtr($texto, [
        'á'=>'a','à'=>'a','ä'=>'a','â'=>'a','ã'=>'a','Á'=>'A','À'=>'A','Ä'=>'A','Â'=>'A','Ã'=>'A',
        'é'=>'e','è'=>'e','ë'=>'e','ê'=>'e','É'=>'E','È'=>'E','Ë'=>'E','Ê'=>'E',
        'í'=>'i','ì'=>'i','ï'=>'i','î'=>'i','Í'=>'I','Ì'=>'I','Ï'=>'I','Î'=>'I',
        'ó'=>'o','ò'=>'o','ö'=>'o','ô'=>'o','õ'=>'o','Ó'=>'O','Ò'=>'O','Ö'=>'O','Ô'=>'O','Õ'=>'O',
        'ú'=>'u','ù'=>'u','ü'=>'u','û'=>'u','Ú'=>'U','Ù'=>'U','Ü'=>'U','Û'=>'U',
        'ñ'=>'n','Ñ'=>'N','ç'=>'c','Ç'=>'C',
    ]);
    $texto = preg_replace('/[^A-Za-z0-9]+/', '_', $texto) ?? '';
    $texto = rtrim(substr(trim($texto, '_'), 0, 120), '_');
    return $texto !== '' ? $texto : 'documento';
}

// Código legible del alumno: letras de sus apellidos y nombre + su ID. Es solo visual, no cambia la base de datos.
// Ej.: Pérez López, Juan con ID 483920 => PELJ-483920 (2 letras del 1.er apellido, 1 del 2.º y 1 del nombre).
function codigo_alumno(int $id, string $apellidos, string $nombre): string {
    $palabras = static function (string $texto): array {
        $r = [];
        if (trim($texto) === '') {
            return $r;
        }
        foreach (explode('_', strtoupper(docs_slug($texto))) as $p) {
            $p = preg_replace('/[^A-Z]/', '', $p) ?? '';
            if ($p !== '' && !in_array($p, ['DE', 'DEL', 'LA', 'LAS', 'LOS', 'Y', 'E', 'DA', 'DI', 'SAN'], true)) {
                $r[] = $p;
            }
        }
        return $r;
    };
    $ap = $palabras($apellidos);
    $no = $palabras($nombre);
    $letras = substr($ap[0] ?? '', 0, 2) . substr($ap[1] ?? '', 0, 1) . substr($no[0] ?? '', 0, 1);
    return ($letras !== '' ? $letras : 'ALU') . '-' . $id;
}

// Valida y guarda el archivo en la carpeta del estudiante.
// Devuelve [ruta_relativa_para_MySQL, nombre_del_archivo]. Lanza RuntimeException con el mensaje para el usuario.
function docs_guardar_archivo(array $f, int $estudianteId, string $nombreBase, string $apellidos = ''): array {
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('No se pudo subir el archivo. Intenta de nuevo.');
    }
    $tmp = (string)($f['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('El archivo recibido no es válido.');
    }
    $tam = (int)($f['size'] ?? 0);
    if ($tam <= 0) {
        throw new RuntimeException('El archivo está vacío.');
    }
    if ($tam > DOCS_MAX_BYTES) {
        throw new RuntimeException('El archivo supera el límite de 10 MB.');
    }

    // MIME real => [extensión guardada, extensiones aceptadas en el nombre original]
    $permitidos = [
        'application/pdf' => ['pdf', ['pdf']],
        'image/jpeg' => ['jpg', ['jpg', 'jpeg']],
        'image/png' => ['png', ['png']],
    ];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    if (!is_string($mime) || !isset($permitidos[$mime])) {
        throw new RuntimeException('Solo se permiten archivos PDF, JPG o PNG.');
    }
    [$ext, $extValidas] = $permitidos[$mime];

    $extOriginal = strtolower(pathinfo((string)($f['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($extOriginal, $extValidas, true)) {
        throw new RuntimeException('La extensión del archivo no coincide con su contenido.');
    }
    if ($mime === 'application/pdf') {
        $fh = fopen($tmp, 'rb');
        $cabecera = $fh ? (string)fread($fh, 5) : '';
        if ($fh) {
            fclose($fh);
        }
        if ($cabecera !== '%PDF-') {
            throw new RuntimeException('El PDF no es válido.');
        }
    } else {
        $info = @getimagesize($tmp);
        if ($info === false || ($info['mime'] ?? '') !== $mime) {
            throw new RuntimeException('La imagen no es válida.');
        }
    }

    $carpeta = docs_carpeta_alumno($estudianteId, $apellidos);
    if ($carpeta === null) {
        throw new RuntimeException('No se pudo crear la carpeta del estudiante.');
    }
    $base = docs_slug($nombreBase);
    do {
        $nombreFinal = $base . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    } while (is_file($carpeta . '/' . $nombreFinal));
    if (!move_uploaded_file($tmp, $carpeta . '/' . $nombreFinal)) {
        throw new RuntimeException('No se pudo guardar el archivo en el servidor.');
    }

    return [basename($carpeta) . '/' . $nombreFinal, $nombreFinal];
}
