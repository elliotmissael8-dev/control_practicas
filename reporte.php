<?php
declare(strict_types=1);

// Arma y entrega los reportes bimestrales de la bitácora.
// Lo usan reportes.php (administración) y alumno.php (cada alumno, solo con sus propios datos).
// Cada alumno tiene sus periodos de dos meses contados desde su fecha de inicio (estudiantes_practicas.fecha_inicio).
require_once __DIR__ . '/archivos.php';
require_once __DIR__ . '/exportar.php';

function rep_fecha(?string $fecha): string {
    if (!$fecha) return '—';
    $dt = DateTime::createFromFormat('!Y-m-d', $fecha);
    return $dt ? $dt->format('d/m/Y') : $fecha;
}
function rep_dur(int $segundos): string {
    return sprintf('%dh %02dmin', intdiv($segundos, 3600), intdiv($segundos % 3600, 60));
}

// Suma meses sin desbordar el día (31 de enero + 1 mes = 28/29 de febrero).
function rep_sumar_meses(DateTimeImmutable $f, int $meses): DateTimeImmutable {
    $total = (int)$f->format('n') - 1 + $meses;
    $anio = (int)$f->format('Y') + intdiv($total, 12);
    $mes = $total % 12 + 1;
    $ultimo = (int)(new DateTimeImmutable(sprintf('%04d-%02d-01', $anio, $mes)))->format('t');
    return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $anio, $mes, min((int)$f->format('j'), $ultimo)));
}

// Periodo n (1, 2, 3...) de un alumno: [desde, hasta] en Y-m-d. Inicia en su fecha de inicio y avanza de dos en dos meses.
function rep_periodo(string $inicio, int $n): array {
    $base = new DateTimeImmutable($inicio);
    $desde = rep_sumar_meses($base, 2 * ($n - 1));
    $hasta = rep_sumar_meses($base, 2 * $n)->modify('-1 day');
    return [$desde->format('Y-m-d'), $hasta->format('Y-m-d')];
}

// Número del periodo que contiene a $hoy (1 si el alumno aún no empieza).
function rep_periodo_actual(string $inicio, string $hoy): int {
    $n = 1;
    while ($n < 500 && rep_periodo($inicio, $n + 1)[0] <= $hoy) {
        $n++;
    }
    return $n;
}

function rep_inicio_valido(string $inicio): bool {
    return $inicio >= '2000-01-01' && strtotime($inicio) !== false;
}

// $periodo = 1, 2, 3... o 0 para el periodo actual de cada alumno. $est = 0 incluye a todos los alumnos con jornadas
// en su periodo; $est > 0 solo a ese alumno. Lanza PDOException si falla la base y RuntimeException si algo no es válido.
function reporte_armar(PDO $pdo, array $programas, int $periodo, int $est): array {
    if ($periodo < 0 || $periodo > 120) {
        throw new RuntimeException('El periodo no es válido.');
    }
    $hoy = (new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City')))->format('Y-m-d');

    $sql = 'SELECT id, matricula, apellidos, nombre, carrera, escuela_procedencia, tutor, tipo_programa, fecha_inicio, estado FROM estudiantes_practicas';
    if ($est) {
        $st = $pdo->prepare($sql . ' WHERE id = ?');
        $st->execute([$est]);
    } else {
        $st = $pdo->query($sql . ' ORDER BY apellidos, nombre');
    }
    $alumnos = $st->fetchAll();
    if ($est && !$alumnos) {
        throw new RuntimeException('El estudiante seleccionado no existe.');
    }

    // Horas acumuladas antes del periodo (para continuar el acumulado dentro del reporte).
    $qPrev = $pdo->prepare('SELECT COALESCE(SUM(TIME_TO_SEC(TIMEDIFF(hora_salida, hora_entrada))), 0) FROM bitacora_horas WHERE estudiante_id = ? AND fecha < ?');
    $qJor = $pdo->prepare('SELECT fecha, hora_entrada, hora_salida, actividades, TIME_TO_SEC(TIMEDIFF(hora_salida, hora_entrada)) AS segundos FROM bitacora_horas WHERE estudiante_id = ? AND fecha BETWEEN ? AND ? ORDER BY fecha, hora_entrada');

    $rep = [
        'titulo' => 'Reporte bimestral de bitácora',
        'subtitulo' => '',
        'cols' => ['Fecha', 'Entrada', 'Salida', 'Horas', 'Actividades', 'Horas acumuladas'],
        'archivo' => '',
        'alumnos' => [],
    ];
    $pie = ' · Departamento de Sistemas de Información';
    foreach ($alumnos as $a) {
        $inicio = (string)$a['fecha_inicio'];
        if (!rep_inicio_valido($inicio)) {
            if ($est) {
                throw new RuntimeException('El estudiante no tiene una fecha de inicio válida.');
            }
            continue;
        }
        $n = $periodo > 0 ? $periodo : rep_periodo_actual($inicio, $hoy);
        [$desde, $hasta] = rep_periodo($inicio, $n);
        $id = (int)$a['id'];

        $qJor->execute([$id, $desde, $hasta]);
        $js = $qJor->fetchAll();
        if (!$js && !$est) {
            continue; // en el reporte general solo aparecen alumnos con jornadas en su periodo
        }
        $qPrev->execute([$id, $desde]);
        $previo = (int)$qPrev->fetchColumn();

        $acum = $previo;
        $enPeriodo = 0;
        $filas = [];
        foreach ($js as $j) {
            $seg = $j['hora_salida'] ? max(0, (int)$j['segundos']) : 0;
            $acum += $seg;
            $enPeriodo += $seg;
            $filas[] = [
                rep_fecha($j['fecha']),
                substr((string)$j['hora_entrada'], 0, 5),
                $j['hora_salida'] ? substr((string)$j['hora_salida'], 0, 5) : 'En curso',
                $j['hora_salida'] ? rep_dur($seg) : '—',
                (string)$j['actividades'],
                rep_dur($acum),
            ];
        }
        $req = (int)($programas[$a['tipo_programa']] ?? 0);
        $rest = max(0, $req * 3600 - $acum);
        $pct = $req > 0 ? min(100, round($acum / ($req * 3600) * 100, 1)) : 0;
        $nombre = trim($a['apellidos'] . ' ' . $a['nombre']);
        $etiqueta = $n . ': ' . rep_fecha($desde) . ' al ' . rep_fecha($hasta);
        $rep['alumnos'][] = [
            'matricula' => (string)$a['matricula'],
            'nombre' => $nombre,
            'datos' => [
                'Código' => codigo_alumno($id, (string)$a['apellidos'], (string)$a['nombre']),
                'Programa' => (string)$a['tipo_programa'],
                'Periodo' => $etiqueta,
                'Horas requeridas' => $req . ' h',
                'Inicio' => rep_fecha($inicio),
                'Estado' => (string)$a['estado'],
                'Carrera' => (string)$a['carrera'],
                'Escuela' => (string)($a['escuela_procedencia'] ?? ''),
                'Tutor' => (string)($a['tutor'] ?? ''),
            ],
            'filas' => $filas,
            'resumen' => [
                'Horas antes del periodo' => rep_dur($previo),
                'Horas en el periodo' => rep_dur($enPeriodo),
                'Total acumulado' => rep_dur($acum),
                'Horas restantes' => $rest === 0 ? 'Completado' : rep_dur($rest),
                'Avance' => $pct . '%',
            ],
        ];
        if ($est) {
            $rep['subtitulo'] = 'Periodo ' . $etiqueta . $pie;
            $rep['archivo'] = 'reporte_bitacora_periodo' . $n . '_' . $desde . '_a_' . $hasta . '_' . docs_slug($nombre);
        }
    }
    if (!$est) {
        $rep['subtitulo'] = ($periodo > 0 ? 'Periodo ' . $periodo : 'Periodo actual') . ' de cada alumno (dos meses contados desde su fecha de inicio)' . $pie;
        $rep['archivo'] = 'reporte_bitacora_periodo-' . ($periodo > 0 ? $periodo : 'actual') . '_todos';
    }
    return $rep;
}

// Genera el archivo y lo envía al navegador. Si algo falla lanza la excepción antes de enviar cabeceras.
function reporte_enviar(array $rep, string $formato): never {
    $bytes = match ($formato) {
        'xlsx' => exp_xlsx($rep),
        'docx' => exp_docx($rep),
        'pdf' => exp_pdf($rep),
        default => throw new RuntimeException('Formato de reporte no válido.'),
    };
    $tipos = [
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'pdf' => 'application/pdf',
    ];
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: ' . $tipos[$formato]);
    header('Content-Disposition: attachment; filename="' . $rep['archivo'] . '.' . $formato . '"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $bytes;
    exit;
}
