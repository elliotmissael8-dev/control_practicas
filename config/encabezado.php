<?php
declare(strict_types=1);
// Encabezado y menú comunes de las páginas de administración.
// Antes de incluirlo, cada página define $menuSeccion (apartado activo) y $menuSubtitulo (nombre de la sección en pantalla).
// Para agregar o quitar botones del menú, edita solo este archivo.
$menuSeccion = $menuSeccion ?? '';
$menuSubtitulo = $menuSubtitulo ?? '';
$menuPrincipal = [
    'estudiantes' => ['index.php', 'Estudiantes'],
    'documentos' => ['documentos.php', 'Documentos'],
    'bitacora' => ['bitacora.php', 'Bitácora'],
    'recordatorios' => ['recordatorios.php', 'Recordatorios'],
];
$menuAdmin = [
    'qr' => ['qr.php', 'Código QR'],
    'historial' => ['historial.php', 'Historial'],
    'administradores' => ['administradores.php', 'Administradores'],
    'respaldo' => ['respaldo.php', 'Respaldo'],
    'manual' => ['manual.php', 'Manual'],
];
$hm = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<header class="topbar">
<div class="topbar-inner">
  <a class="topbar-marca" href="index.php"><img class="marca-logo" src="https://esiima.uaa.mx/cursoextension/imgs/logouaa2.jpg" alt="Logo UAA"><span class="topbar-titulo"><small>Control de Prácticas Profesionales y Servicio Social</small><strong><?= $hm($menuSubtitulo) ?></strong></span></a>
  <nav class="menu" aria-label="Menú principal">
<?php foreach ($menuPrincipal as $clave => [$url, $texto]): ?>
    <a href="<?= $url ?>"<?= $clave === $menuSeccion ? ' class="activo"' : '' ?>><?= $texto ?></a>
<?php endforeach; ?>
    <details class="menu-grupo<?= isset($menuAdmin[$menuSeccion]) ? ' activo' : '' ?>">
      <summary>Administración</summary>
      <div class="menu-panel">
<?php foreach ($menuAdmin as $clave => [$url, $texto]): ?>
        <a href="<?= $url ?>"<?= $clave === $menuSeccion ? ' class="activo"' : '' ?>><?= $texto ?></a>
<?php endforeach; ?>
      </div>
    </details>
    <a class="menu-salir" href="login.php?salir=1">Cerrar sesión</a>
  </nav>
</div>
</header>
<script>
document.addEventListener('click', function (e) {
  document.querySelectorAll('.menu-grupo[open]').forEach(function (d) { if (!d.contains(e.target)) d.removeAttribute('open'); });
});
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') document.querySelectorAll('.menu-grupo[open]').forEach(function (d) { d.removeAttribute('open'); });
});
</script>
