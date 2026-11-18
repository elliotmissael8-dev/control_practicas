<?php
declare(strict_types=1);

// Historial de modificaciones: quién hizo qué y cuándo.
// La tabla `historial` se crea sola la primera vez. Registrar nunca debe impedir la operación principal.

function historial_asegurar(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS historial (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        fecha DATETIME NOT NULL,
        actor VARCHAR(80) NOT NULL,
        actor_tipo VARCHAR(10) NOT NULL DEFAULT \'admin\',
        modulo VARCHAR(30) NOT NULL,
        accion VARCHAR(30) NOT NULL,
        detalle VARCHAR(1000) NOT NULL,
        ip VARCHAR(45) NULL,
        INDEX idx_historial_fecha (fecha),
        INDEX idx_historial_actor (actor)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

// $actor = null usa al administrador con sesión iniciada. Para acciones de alumnos se pasa su nombre y tipo 'alumno'.
function historial_registrar(PDO $pdo, string $modulo, string $accion, string $detalle, ?string $actor = null, string $tipo = 'admin'): void {
    if ($actor === null) {
        $actor = (string)($_SESSION['admin_usuario'] ?? '');
        if ($actor === '') {
            $actor = 'Sistema';
            $tipo = 'sistema';
        }
    }
    $datos = [
        (new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City')))->format('Y-m-d H:i:s'),
        mb_substr($actor, 0, 80),
        $tipo,
        mb_substr($modulo, 0, 30),
        mb_substr($accion, 0, 30),
        mb_substr($detalle, 0, 1000),
        mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
    ];
    $sql = 'INSERT INTO historial (fecha, actor, actor_tipo, modulo, accion, detalle, ip) VALUES (?, ?, ?, ?, ?, ?, ?)';
    try {
        $pdo->prepare($sql)->execute($datos);
    } catch (Throwable $e) {
        try {
            historial_asegurar($pdo);
            $pdo->prepare($sql)->execute($datos);
        } catch (Throwable $e2) {
            // El historial no debe romper la operación que se estaba realizando.
        }
    }
}

// "matrícula — Apellidos Nombre" de un estudiante (para los detalles del historial).
function historial_alumno(PDO $pdo, int $id): string {
    try {
        $st = $pdo->prepare('SELECT matricula, apellidos, nombre FROM estudiantes_practicas WHERE id = ?');
        $st->execute([$id]);
        $a = $st->fetch();
    } catch (Throwable $e) {
        $a = false;
    }
    return $a ? $a['matricula'] . ' — ' . trim($a['apellidos'] . ' ' . $a['nombre']) : 'ID ' . $id;
}

// Compara valores antes/después. $etiquetas = ['campo_en_la_base' => 'Nombre para mostrar'].
function historial_cambios(array $antes, array $nuevos, array $etiquetas): string {
    $corto = static fn(string $v): string => mb_strlen($v) > 40 ? mb_substr($v, 0, 40) . '…' : $v;
    $cambios = [];
    foreach ($etiquetas as $campo => $etiqueta) {
        $a = (string)($antes[$campo] ?? '');
        $n = (string)($nuevos[$campo] ?? '');
        if ($a !== $n) {
            $cambios[] = $etiqueta . ': "' . $corto($a) . '" → "' . $corto($n) . '"';
        }
    }
    return $cambios ? implode('; ', $cambios) : 'sin cambios en los datos';
}
