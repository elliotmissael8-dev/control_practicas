<?php
declare(strict_types=1);
require __DIR__ . '/config/auth.php';
require __DIR__ . '/config/database.php';
require __DIR__ . '/config/archivos.php';
require __DIR__ . '/config/historial.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function h(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function redirectTo(string $message, string $type = 'success'): never {
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
    header('Location: respaldo.php');
    exit;
}
function tamanoLegible(int $bytes): string {
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1) . ' MB';
    return number_format(max(1, $bytes) / 1024, 0) . ' KB';
}

function respaldo_id(string $nombre): string {
    return '`' . str_replace('`', '``', $nombre) . '`';
}

function respaldo_cabecera($salida, string $titulo): void {
    fwrite($salida, "-- {$titulo}\n-- Generado: " . (new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City')))->format('Y-m-d H:i:s') . "\n\n");
    fwrite($salida, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");
}

// Estructura y datos de las tablas de una base. $bd = null usa la base actual (sin prefijo de base).
function respaldo_tablas(PDO $pdo, $salida, ?string $bd): void {
    $prefijo = $bd === null ? '' : respaldo_id($bd) . '.';
    $tablas = $pdo->query('SHOW FULL TABLES' . ($bd === null ? '' : ' FROM ' . respaldo_id($bd)) . " WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
    foreach ($tablas as $fila) {
        $tq = $prefijo . respaldo_id((string)$fila[0]);
        $crear = $pdo->query('SHOW CREATE TABLE ' . $tq)->fetch(PDO::FETCH_NUM);
        fwrite($salida, "DROP TABLE IF EXISTS {$tq};\n" . $crear[1] . ";\n\n");

        $st = $pdo->query('SELECT * FROM ' . $tq);
        $lote = [];
        while ($r = $st->fetch(PDO::FETCH_NUM)) {
            $vals = [];
            foreach ($r as $v) {
                $vals[] = $v === null ? 'NULL' : ((is_int($v) || is_float($v)) ? (string)$v : $pdo->quote((string)$v));
            }
            $lote[] = '(' . implode(',', $vals) . ')';
            if (count($lote) >= 100) {
                fwrite($salida, "INSERT INTO {$tq} VALUES\n" . implode(",\n", $lote) . ";\n");
                $lote = [];
            }
        }
        if ($lote) {
            fwrite($salida, "INSERT INTO {$tq} VALUES\n" . implode(",\n", $lote) . ";\n");
        }
        fwrite($salida, "\n");
    }
}

// Respaldo de la base de datos de este sistema (la que usa la conexión actual).
function respaldo_sql(PDO $pdo, $salida): void {
    $bd = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    respaldo_cabecera($salida, "Respaldo de la base de datos `{$bd}`");
    respaldo_tablas($pdo, $salida, null);
    fwrite($salida, "SET FOREIGN_KEY_CHECKS=1;\n");
}

// Bases del servidor que el usuario actual puede ver, sin las del sistema de MySQL.
function respaldo_bases(PDO $pdo): array {
    $sistema = ['information_schema', 'performance_schema', 'mysql', 'sys'];
    $bases = [];
    foreach ($pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN) as $bd) {
        if (!in_array(strtolower((string)$bd), $sistema, true)) {
            $bases[] = (string)$bd;
        }
    }
    return $bases;
}

// Respaldo de todas las bases del servidor. Devuelve los nombres de las que no se pudieron respaldar completas.
function respaldo_sql_todas(PDO $pdo, $salida): array {
    respaldo_cabecera($salida, 'Respaldo de todas las bases de datos del servidor (sin las tablas del sistema de MySQL)');
    $avisos = [];
    foreach (respaldo_bases($pdo) as $bd) {
        try {
            $crear = $pdo->query('SHOW CREATE DATABASE ' . respaldo_id($bd))->fetch(PDO::FETCH_NUM);
            fwrite($salida, "-- ------------------------------------------------\n-- Base de datos: {$bd}\n"
                . preg_replace('/^CREATE DATABASE/', 'CREATE DATABASE IF NOT EXISTS', (string)$crear[1], 1) . ";\nUSE " . respaldo_id($bd) . ";\n\n");
            respaldo_tablas($pdo, $salida, $bd);
        } catch (PDOException $e) {
            $avisos[] = $bd;
            fwrite($salida, "-- ATENCION: la base {$bd} no se pudo respaldar completa.\n\n");
        }
    }
    fwrite($salida, "SET FOREIGN_KEY_CHECKS=1;\n");
    return $avisos;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        redirectTo('La sesión del formulario expiró. Intenta nuevamente.', 'error');
    }
    $tipo = (string)($_POST['tipo'] ?? '');
    if (!in_array($tipo, ['sql', 'zip', 'todas'], true)) {
        redirectTo('Tipo de respaldo no válido.', 'error');
    }
    @set_time_limit(0);
    $sello = (new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City')))->format('Y-m-d_Hi');
    $tmpSql = null;
    $tmpZip = null;
    try {
        $tmpSql = tempnam(sys_get_temp_dir(), 'bd');
        $f = $tmpSql !== false ? fopen($tmpSql, 'wb') : false;
        if (!$f) {
            throw new RuntimeException('No se pudo crear el archivo temporal.');
        }
        $avisos = [];
        if ($tipo === 'todas') {
            $avisos = respaldo_sql_todas($pdo, $f);
        } else {
            respaldo_sql($pdo, $f);
        }
        fclose($f);

        if ($tipo === 'sql' || $tipo === 'todas') {
            $archivo = $tmpSql;
            $nombre = ($tipo === 'sql' ? 'respaldo_bd_' : 'respaldo_todas_las_bd_') . $sello . '.sql';
            $mime = 'application/sql';
        } else {
            if (!class_exists('ZipArchive')) {
                throw new RuntimeException('Falta la extensión zip de PHP. En XAMPP activa "extension=zip" en php.ini y reinicia Apache.');
            }
            $tmpZip = tempnam(sys_get_temp_dir(), 'zip');
            $zip = new ZipArchive();
            if ($tmpZip === false || $zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('No se pudo crear el archivo ZIP.');
            }
            $zip->addFile($tmpSql, 'base_de_datos.sql');
            foreach ([[docs_base(), 'uploads/documentos'], [docs_archivo_base(), 'uploads/archivo']] as [$base, $destinoZip]) {
                if (is_dir($base)) {
                    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
                    foreach ($it as $doc) {
                        if ($doc->isFile()) {
                            $rel = str_replace('\\', '/', substr($doc->getPathname(), strlen($base) + 1));
                            $zip->addFile($doc->getPathname(), $destinoZip . '/' . $rel);
                        }
                    }
                }
            }
            $zip->addFromString('LEEME.txt',
                "RESPALDO - Control de Prácticas Profesionales y Servicio Social\r\n"
                . 'Generado: ' . $sello . "\r\n\r\n"
                . "base_de_datos.sql: impórtalo en phpMyAdmin dentro de la base control_practicas (créala vacía si no existe).\r\n"
                . "uploads/documentos/ y uploads/archivo/: copia esas carpetas dentro de uploads/ del proyecto (documentos escaneados y archivo de alumnos eliminados, con su FICHA.txt).\r\n\r\n"
                . "No incluye la carpeta config/ (contiene claves y la contraseña de la base de datos).\r\n"
                . "Este respaldo contiene datos personales: guárdalo en un lugar seguro.\r\n");
            $zip->close();
            $archivo = $tmpZip;
            $nombre = 'respaldo_completo_' . $sello . '.zip';
            $mime = 'application/zip';
        }

        $detalleRespaldo = [
            'sql' => 'Respaldo de la base de datos (.sql) descargado',
            'zip' => 'Respaldo completo (.zip con documentos) descargado',
            'todas' => 'Respaldo de todas las bases de datos del servidor (.sql) descargado' . ($avisos ? ' (incompletas: ' . implode(', ', $avisos) . ')' : ''),
        ][$tipo];
        historial_registrar($pdo, 'Respaldo', 'descargar', $detalleRespaldo);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $nombre . '"');
        header('Content-Length: ' . filesize($archivo));
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($archivo);
        foreach ([$tmpSql, $tmpZip] as $t) {
            if (is_string($t) && is_file($t)) {
                unlink($t);
            }
        }
        exit;
    } catch (Throwable $e) {
        foreach ([$tmpSql, $tmpZip] as $t) {
            if (is_string($t) && is_file($t)) {
                unlink($t);
            }
        }
        redirectTo($e instanceof RuntimeException ? $e->getMessage() : 'No se pudo generar el respaldo.', 'error');
    }
}

// Resumen para mostrar en pantalla.
$tablasInfo = [];
$errorBd = null;
try {
    foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as $fila) {
        $tq = '`' . str_replace('`', '``', (string)$fila[0]) . '`';
        $tablasInfo[(string)$fila[0]] = (int)$pdo->query('SELECT COUNT(*) FROM ' . $tq)->fetchColumn();
    }
} catch (PDOException $e) {
    $errorBd = 'No se pudo leer la base de datos.';
}
$basesServidor = [];
try {
    $basesServidor = respaldo_bases($pdo);
} catch (PDOException $e) {
    $basesServidor = [];
}
$archivosDocs = 0;
$bytesDocs = 0;
foreach ([docs_base(), docs_archivo_base()] as $baseDocs) {
    if (is_dir($baseDocs)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($baseDocs, FilesystemIterator::SKIP_DOTS)) as $doc) {
            if ($doc->isFile()) {
                $archivosDocs++;
                $bytesDocs += $doc->getSize();
            }
        }
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Respaldo | Prácticas Profesionales</title>
<style>
:root{--navy:#142b4a;--blue:#2457a7;--bg:#f3f6fb;--text:#243247;--muted:#64748b;--border:#dbe3ee}
*{box-sizing:border-box}body{margin:0;font-family:"Segoe UI",Arial,sans-serif;background:var(--bg);color:var(--text)}
header{color:#fff;padding:26px 20px;background:linear-gradient(120deg,#142b4a,#2457a7)}
.header-inner,main{max-width:1200px;margin:auto}.brand{display:flex;gap:20px;align-items:center}
h1{font-size:clamp(1.3rem,3vw,1.9rem);margin:0}header p{color:#dbeafe;margin:6px 0 0}
.marca-logo{height:55px;width:auto;border-radius:10px;object-fit:contain}
nav{display:flex;gap:8px;margin-top:16px;flex-wrap:wrap}nav a{color:#fff;text-decoration:none;padding:8px 14px;border-radius:8px;background:#ffffff22;font-weight:600;font-size:.88rem}nav a.activo{background:#fff;color:var(--navy)}
main{padding:25px 20px}.card{background:#fff;border:1px solid var(--border);border-radius:14px;box-shadow:0 8px 28px #142b4a0d;padding:23px;margin-bottom:20px}
.card-head{margin-bottom:16px}h2{font-size:1.15rem;color:var(--navy);margin:0}.hint{color:var(--muted);font-size:.88rem;margin:6px 0 0}
.opciones{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}.opcion{border:1px solid var(--border);border-radius:12px;padding:18px;background:#f8fbff}.opcion h3{margin:0 0 6px;font-size:1rem;color:var(--navy)}.opcion p{margin:0 0 14px;color:var(--muted);font-size:.88rem}
.btn{display:inline-block;border:0;border-radius:8px;padding:11px 16px;font-weight:650;font-size:.9rem;cursor:pointer}.primary{background:var(--blue);color:#fff}
.table-wrap{overflow-x:auto;border:1px solid var(--border);border-radius:10px}table{border-collapse:collapse;width:100%;min-width:420px}th,td{text-align:left;padding:11px 13px;border-bottom:1px solid #e8edf4;font-size:.88rem}th{background:#f0f4fa;color:#34445a;font-size:.76rem;text-transform:uppercase;letter-spacing:.04em}tbody tr:last-child td{border-bottom:0}
.aviso{background:#fff7e6;border:1px solid #fde1a8;color:#7a4b00;border-radius:10px;padding:12px 15px;font-size:.88rem;margin-top:16px}
.flash{padding:12px 15px;border-radius:9px;margin-bottom:17px}.success{background:#dcfce7;color:#166534}.error{background:#fee2e2;color:#991b1b}
@media(max-width:760px){.opciones{grid-template-columns:1fr}.card{padding:17px}}
</style>
<link rel="stylesheet" href="assets/estilo.css?v=1">
</head>
<body class="pg-respaldo">
<?php $menuSeccion = 'respaldo'; $menuSubtitulo = 'Respaldo del sistema'; require __DIR__ . '/config/encabezado.php'; ?>
<main>
<?php if ($flash): ?><div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
<?php if ($errorBd): ?><div class="flash error"><?= h($errorBd) ?></div><?php endif; ?>

<section class="card">
<div class="card-head"><h2>Descargar respaldo</h2><p class="hint">Genera una copia en este momento. Hazlo con frecuencia (por ejemplo, cada semana) y guárdala fuera de esta computadora.</p></div>
<div class="opciones">
<div class="opcion"><h3>Base de datos (.sql)</h3><p>Estudiantes, bitácora, documentos registrados, administradores e historial. No incluye los archivos escaneados.</p>
<form method="post"><input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><input type="hidden" name="tipo" value="sql"><button class="btn primary" type="submit">Descargar .sql</button></form></div>
<div class="opcion"><h3>Respaldo completo (.zip)</h3><p>La base de datos, todos los documentos escaneados y el archivo de alumnos eliminados (<?= $archivosDocs ?> archivo(s), <?= h(tamanoLegible($bytesDocs)) ?>). Puede tardar si hay muchos archivos.</p>
<form method="post"><input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><input type="hidden" name="tipo" value="zip"><button class="btn primary" type="submit">Descargar .zip</button></form></div>
<div class="opcion"><h3>Todas las bases de datos (.sql)</h3><p>Copia de <strong>todas</strong> las bases del servidor MySQL<?php if ($basesServidor): ?>: <?= h(implode(', ', $basesServidor)) ?><?php endif; ?>. No incluye las tablas del sistema de MySQL (cuentas y contraseñas) ni los archivos escaneados.</p>
<form method="post"><input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><input type="hidden" name="tipo" value="todas"><button class="btn primary" type="submit">Descargar todas (.sql)</button></form></div>
</div>
<div class="aviso"><strong>Importante:</strong> el respaldo contiene datos personales de los alumnos. Guárdalo en un lugar seguro y no lo compartas por canales abiertos. No incluye la carpeta <code>config/</code> (claves y contraseña de la base).</div>
</section>

<section class="card">
<div class="card-head"><h2>Qué contiene la base de datos</h2></div>
<div class="table-wrap"><table><thead><tr><th>Tabla</th><th>Registros</th></tr></thead><tbody>
<?php if (!$tablasInfo): ?><tr><td colspan="2">Sin información.</td></tr>
<?php else: foreach ($tablasInfo as $nombreTabla => $filas): ?><tr><td><?= h($nombreTabla) ?></td><td><?= (int)$filas ?></td></tr><?php endforeach; endif; ?>
</tbody></table></div>
<p class="hint">Para restaurar: en phpMyAdmin, crea la base <code>control_practicas</code> vacía e importa el archivo .sql. Si usas el .zip, copia la carpeta <code>uploads/documentos</code> dentro de <code>uploads/</code> del proyecto. El archivo de <strong>todas las bases</strong> se importa en phpMyAdmin desde la pantalla de inicio, sin elegir ninguna base: él crea las bases que falten.</p>
</section>
</main>
<?php require __DIR__ . '/config/footer.php'; ?>
</body>
</html>
