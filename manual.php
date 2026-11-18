<?php
declare(strict_types=1);
define('ADMIN_PAGINA_LOGIN', true); // el manual de alumnos es público; la parte de administración solo se muestra con sesión
require __DIR__ . '/config/auth.php';
require __DIR__ . '/config/horario.php';
$esAdmin = admin_activo();
function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Manual de uso | Prácticas Profesionales</title>
<style>
:root{--navy:#142b4a;--blue:#2457a7;--bg:#f3f6fb;--text:#243247;--muted:#64748b;--border:#dbe3ee}
*{box-sizing:border-box}body{margin:0;font-family:"Segoe UI",Arial,sans-serif;background:var(--bg);color:var(--text);line-height:1.6}
header{color:#fff;padding:26px 20px;background:linear-gradient(120deg,#142b4a,#2457a7)}
.header-inner,main{max-width:900px;margin:auto}.brand{display:flex;gap:20px;align-items:center}
h1{font-size:clamp(1.3rem,3vw,1.9rem);margin:0}header p{color:#dbeafe;margin:6px 0 0}
.marca-logo{height:55px;width:auto;border-radius:10px;object-fit:contain}
nav{display:flex;gap:8px;margin-top:16px;flex-wrap:wrap}nav a{color:#fff;text-decoration:none;padding:8px 14px;border-radius:8px;background:#ffffff22;font-weight:600;font-size:.88rem}
main{padding:25px 20px}.card{background:#fff;border:1px solid var(--border);border-radius:14px;box-shadow:0 8px 28px #142b4a0d;padding:24px;margin-bottom:20px}
h2{font-size:1.25rem;color:var(--navy);margin:0 0 10px}h3{font-size:1.02rem;color:var(--navy);margin:20px 0 6px}
ol,ul{margin:6px 0 10px;padding-left:22px}li{margin-bottom:5px}
.aviso{background:#fff7e6;border:1px solid #fde1a8;color:#7a4b00;border-radius:10px;padding:12px 15px;font-size:.92rem;margin:12px 0}
.info{background:#eef5ff;border:1px solid #cfe0fb;color:#1e3a8a;border-radius:10px;padding:12px 15px;font-size:.92rem;margin:12px 0}
code{background:#edf2f8;padding:1px 6px;border-radius:5px;font-size:.9em}
@media print{header nav,.noprint{display:none}body{background:#fff}.card{box-shadow:none;break-inside:avoid}}
</style>
<link rel="stylesheet" href="assets/estilo.css?v=1">
</head>
<body class="pg-manual">
<header><div class="header-inner">
  <div class="brand"><img class="marca-logo" src="https://esiima.uaa.mx/cursoextension/imgs/logouaa2.jpg" alt="Logo UAA"><div><h1>Manual de uso</h1><p>Control de Prácticas Profesionales y Servicio Social · Departamento de Sistemas de Información</p></div></div>
  <nav><a href="#alumnos">Para alumnos</a><a href="#admin">Para administradores</a><a href="#faq">Preguntas frecuentes</a><?php if ($esAdmin): ?><a href="index.php">Volver al sistema</a><?php endif; ?></nav>
</div></header>
<main>
<div class="info"><strong>Horario de uso:</strong> <?= h(horario_texto()) ?>.</div>

<section class="card" id="alumnos">
<h2>Para alumnos</h2>
<h3>Registrar tu entrada</h3>
<ol>
<li>Con la cámara de tu celular, escanea el <strong>código QR</strong> que se proyecta en el Edificio 55. Cambia cada 30 segundos; si no abre, vuelve a escanearlo.</li>
<li>Escribe tu <strong>matrícula</strong> (14 dígitos) y pulsa continuar.</li>
<li>Escribe la <strong>clave de asistencia</strong> de 6 dígitos que aparece en la misma pantalla (cambia cada minuto).</li>
<li>Pulsa <strong>Registrar entrada</strong>. Verás tu nombre, tu programa y tus horas acumuladas.</li>
</ol>
<h3>Registrar tu salida</h3>
<ol>
<li>Repite el QR, la matrícula y la clave.</li>
<li>Escribe las <strong>actividades</strong> que realizaste (son obligatorias) y pulsa <strong>Registrar salida</strong>.</li>
</ol>
<div class="aviso">Cada escaneo del QR permite <strong>un solo registro</strong>. Para registrar la salida debes volver a escanear. No compartas tu matrícula ni la clave: tú eres responsable de tus registros.</div>
<h3>Tus documentos</h3>
<ul>
<li>En <strong>Mis documentos entregados</strong>, escribe la clave de asistencia para verlos. Se desbloquean 5 minutos.</li>
<li>Puedes <strong>subir un documento</strong> (PDF, JPG o PNG, máximo 10 MB). Queda como <em>Pendiente</em> hasta que un administrador lo revise.</li>
<li>Si lo <em>Rechazan</em>, verás el motivo: corrige el documento y súbelo de nuevo. Si lo <em>Aprueban</em>, queda como entregado.</li>
</ul>
<h3>Tu reporte bimestral</h3>
<p>En <strong>Mi reporte bimestral</strong> elige el periodo (cada uno dura dos meses desde tu fecha de inicio), escribe la clave y descarga el reporte en PDF, Excel o Word. Si entregas tu reporte como documento, súbelo en <strong>Mis documentos</strong> como <em>Reporte parcial</em>.</p>
<div class="info">Si tienes un correo registrado, recibirás un <strong>recordatorio</strong> cuando se acerque el fin de tu periodo (una vez por periodo). En tu pantalla también aparece un aviso los últimos 7 días.</div>
</section>

<?php if ($esAdmin): ?>
<section class="card" id="admin">
<h2>Para administradores</h2>
<h3>Acceso</h3>
<ul>
<li>Inicia sesión con tu usuario y contraseña. La sesión se cierra tras 30 minutos sin actividad.</li>
<li>Si olvidaste tu contraseña, usa <strong>¿Olvidaste tu contraseña?</strong> en la pantalla de inicio: llega un enlace al correo registrado en tu cuenta. Sin correo, otro administrador puede cambiártela en <strong>Administradores</strong>.</li>
</ul>
<h3>Estudiantes</h3>
<ul>
<li>Registra alumnos con sus datos, teléfono y correo de contacto. El <strong>código</strong> (por ejemplo <code>PELJ-483920</code>) se arma con su nombre y su ID.</li>
<li>El <strong>Estado</strong> puede ser Pendiente, En curso, Finalizado o Baja. Los alumnos en Baja no pueden registrar asistencia.</li>
<li>Al <strong>eliminar</strong> un alumno sus archivos <strong>no se borran</strong>: se mueven a <code>uploads/archivo</code> con una ficha de datos. Solo elimina cuando sea necesario.</li>
</ul>
<h3>Documentos</h3>
<ul>
<li>Registra documentos entregados y, si quieres, adjunta el escaneo (PDF, JPG o PNG, máx. 10 MB).</li>
<li>Los documentos que suben los alumnos aparecen como <strong>Pendiente</strong>: usa <strong>Aprobar</strong> o <strong>Rechazar</strong> (el motivo es obligatorio al rechazar).</li>
<li>Los documentos de alumnos en Baja o Finalizado no se pueden eliminar.</li>
</ul>
<h3>Bitácora y reportes</h3>
<ul>
<li>Consulta horas acumuladas y restantes por alumno, y registra o corrige jornadas manualmente.</li>
<li><strong>Entradas abiertas:</strong> si un alumno olvidó su salida, escribe la hora de salida y cierra la jornada.</li>
<li><strong>Reporte bimestral:</strong> elige al alumno (o todos), el periodo y el formato.</li>
</ul>
<h3>Recordatorios</h3>
<p>En <strong>Recordatorios</strong> ves a qué alumnos "En curso" les toca aviso de su reporte bimestral y los envías con un botón. También pueden salir solos cada día con el Programador de tareas de Windows (las instrucciones están en esa página). No se envía a quien ya entregó un reporte ni a quien no tiene correo.</p>
<h3>Código QR</h3>
<p>Proyecta la pantalla <strong>Código QR</strong> en el Edificio 55 con la sesión iniciada. Muestra el QR y la clave de asistencia, y se actualizan solos.</p>
<h3>Historial y administradores</h3>
<ul>
<li><strong>Historial:</strong> quién hizo cada cambio, cuándo y desde qué IP. Se puede filtrar.</li>
<li><strong>Administradores:</strong> agrega usuarios, cambia contraseñas y registra el correo de cada uno para la recuperación.</li>
</ul>
<h3>Respaldo</h3>
<ul>
<li>Descarga un respaldo <strong>cada semana</strong> y guárdalo fuera de la computadora del sistema.</li>
<li>El <code>.zip</code> incluye la base de datos, los documentos y el archivo de alumnos eliminados.</li>
<li>Para restaurar: importa el <code>.sql</code> en phpMyAdmin y copia las carpetas de <code>uploads</code> al proyecto.</li>
</ul>
<div class="aviso">Los respaldos contienen datos personales de los alumnos. Guárdalos en un lugar seguro.</div>
</section>
<?php else: ?>
<section class="card" id="admin">
<h2>Para administradores</h2>
<p>La guía de administración se muestra al <a href="login.php">iniciar sesión</a>.</p>
</section>
<?php endif; ?>

<section class="card" id="faq">
<h2>Preguntas frecuentes</h2>
<h3>El QR no abre o dice que venció</h3>
<p>El QR cambia cada 30 segundos y tu acceso dura unos minutos. Escanéalo de nuevo.</p>
<h3>La clave no funciona</h3>
<p>Cambia cada minuto y la anterior deja de servir. Escribe la que aparece ahora en la pantalla. Tras 5 intentos incorrectos hay que esperar 5 minutos.</p>
<h3>Mi matrícula no se reconoce</h3>
<p>Debe tener 14 dígitos. Si es correcta y no funciona, avisa a tu coordinador: puede que no estés registrado o estés en Baja.</p>
<h3>Olvidé registrar mi salida</h3>
<p>Avisa a tu coordinador. Un administrador puede cerrar la jornada con la hora correcta.</p>
<h3>El celular no abre la página</h3>
<p>Confirma que estás en la red Wi-Fi indicada para el servicio. Algunas redes institucionales no permiten conectarse a este sistema.</p>
</section>
</main>
<?php require __DIR__ . '/config/footer.php'; ?>
</body>
</html>
