<?php
declare(strict_types=1);
require __DIR__ . '/config/auth.php';
require __DIR__ . '/config/database.php';
require __DIR__ . '/config/reporte.php';

$programas = [
    'Prácticas profesionales' => 240,
    'Servicio social' => 480,
];

function volver(string $mensaje): never {
    $_SESSION['flash'] = ['message' => $mensaje, 'type' => 'error'];
    header('Location: bitacora.php#reporte');
    exit;
}

// --- Validación de parámetros ---
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    volver('Solicitud no válida.');
}
$formato = (string)($_GET['formato'] ?? '');
if (!in_array($formato, ['xlsx', 'pdf', 'docx'], true)) {
    volver('Formato de reporte no válido.');
}
$periodo = filter_input(INPUT_GET, 'periodo', FILTER_VALIDATE_INT);
if ($periodo === null || $periodo === false || $periodo < 0 || $periodo > 120) {
    volver('El periodo seleccionado no es válido.');
}
$est = filter_input(INPUT_GET, 'estudiante', FILTER_VALIDATE_INT) ?: 0;

// --- Datos y entrega ---
try {
    $rep = reporte_armar($pdo, $programas, $periodo, $est);
} catch (PDOException $e) {
    volver('No se pudo generar el reporte. Verifica las tablas de la base de datos.');
} catch (RuntimeException $e) {
    volver($e->getMessage());
}
if (!$rep['alumnos']) {
    volver('No hay jornadas registradas en el periodo seleccionado.');
}
try {
    reporte_enviar($rep, $formato);
} catch (Throwable $e) {
    volver($e instanceof RuntimeException ? $e->getMessage() : 'No se pudo generar el reporte.');
}
