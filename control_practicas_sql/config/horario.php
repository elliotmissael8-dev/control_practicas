<?php
declare(strict_types=1);

// Horario de uso del sistema. Edita estas constantes para cambiarlo.
const HORARIO_DIAS = [1, 2, 3, 4, 5]; // 1 = lunes ... 7 = domingo
const HORARIO_INICIO = '08:00';
const HORARIO_FIN = '20:00';
// true = los alumnos solo pueden registrar entrada o salida dentro del horario. false = el horario solo se muestra.
const HORARIO_RESTRINGIR_ALUMNOS = false;

function horario_texto(): string {
    $nombres = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado', 7 => 'domingo'];
    $dias = HORARIO_DIAS;
    sort($dias);
    $ultimo = end($dias);
    if (count($dias) > 1 && $dias === range($dias[0], $ultimo)) {
        $texto = ucfirst($nombres[$dias[0]]) . ' a ' . $nombres[$ultimo];
    } else {
        $texto = ucfirst(implode(', ', array_map(static fn(int $d): string => $nombres[$d], $dias)));
    }
    $hora = static fn(string $t): string => (int)substr($t, 0, 2) . ':' . substr($t, 3, 2);
    return $texto . ', de ' . $hora(HORARIO_INICIO) . ' a ' . $hora(HORARIO_FIN) . ' h';
}

function horario_abierto(): bool {
    $ahora = new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City'));
    $hm = $ahora->format('H:i');
    return in_array((int)$ahora->format('N'), HORARIO_DIAS, true) && $hm >= HORARIO_INICIO && $hm < HORARIO_FIN;
}
