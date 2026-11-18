<?php
declare(strict_types=1);
require __DIR__ . '/config/auth.php';
require __DIR__ . '/config/clave.php';

if (CLAVE_IPS_ADMIN && !in_array($_SERVER['REMOTE_ADDR'] ?? '', CLAVE_IPS_ADMIN, true)) {
    http_response_code(403);
    exit('Acceso no permitido. IP detectada: ' . ($_SERVER['REMOTE_ADDR'] ?? 'desconocida'));
}
// Endpoint JSON: el navegador lo consulta para refrescar la clave sin recargar la página.
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'clave' => clave_actual(),
        'restante' => clave_restante(),
        'intervalo' => CLAVE_INTERVALO,
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Clave de asistencia | Prácticas Profesionales</title>
<style>
:root{--navy:#142b4a;--blue:#2457a7;--bg:#f3f6fb;--text:#243247;--muted:#64748b;--border:#dbe3ee}
*{box-sizing:border-box}body{margin:0;font-family:"Segoe UI",Arial,sans-serif;background:var(--bg);color:var(--text)}
header{color:#fff;padding:26px 20px;background:linear-gradient(120deg,#142b4a,#2457a7)}
.header-inner,main{max-width:1200px;margin:auto}.brand{display:flex;gap:20px;align-items:center}
h1{font-size:clamp(1.3rem,3vw,1.9rem);margin:0}header p{color:#dbeafe;margin:6px 0 0}
.marca-logo{height:55px;width:auto;border-radius:10px;object-fit:contain}
nav{display:flex;gap:8px;margin-top:16px;flex-wrap:wrap}nav a{color:#fff;text-decoration:none;padding:8px 14px;border-radius:8px;background:#ffffff22;font-weight:600;font-size:.88rem}nav a.activo{background:#fff;color:var(--navy)}
main{padding:25px 20px}.card{background:#fff;border:1px solid var(--border);border-radius:14px;box-shadow:0 8px 28px #142b4a0d;padding:30px;margin:0 auto 20px;max-width:620px;text-align:center}
h2{font-size:1.25rem;color:var(--navy);margin:0 0 6px}.hint{color:var(--muted);font-size:.9rem;margin:0 0 18px}
.codigo{font-family:Consolas,"Courier New",monospace;font-weight:700;color:var(--navy);font-size:clamp(3rem,13vw,6rem);letter-spacing:.12em;line-height:1.1;padding:18px 10px;background:#f8fbff;border:1px solid var(--border);border-radius:12px;min-height:1.4em}
.barra{height:8px;background:#e8edf4;border-radius:99px;overflow:hidden;margin:20px auto 8px;max-width:320px}
.barra div{height:100%;width:100%;background:var(--blue);transition:width 1s linear}
.tiempo{color:var(--muted);font-size:.88rem}.error{color:#991b1b;font-weight:600;margin-top:14px}
footer{text-align:center;color:var(--muted);padding:0 20px 25px;font-size:.82rem}
</style>
<link rel="stylesheet" href="assets/estilo.css?v=1">
</head>
<body class="pg-qr">
<?php $menuSeccion = ''; $menuSubtitulo = 'Clave de asistencia'; require __DIR__ . '/config/encabezado.php'; ?>
<main>
<section class="card">
<h2>Escribe esta clave para registrar tu asistencia</h2>
<p class="hint">La clave cambia automáticamente y la anterior deja de servir. </p>
<div class="codigo" id="clave">------</div>
<div class="barra"><div id="progreso"></div></div>
<div class="tiempo">Cambia en <strong id="seg">--</strong> s</div>
<div class="error" id="error" hidden></div>
</section>
</main>
<?php require __DIR__ . '/config/footer.php'; ?>

<script>
(function(){
  var claveBox = document.getElementById('clave');
  var progreso = document.getElementById('progreso');
  var seg = document.getElementById('seg');
  var errorBox = document.getElementById('error');
  var restante = 0, intervalo = 60, cargando = false;

  function formatear(c){
    var mitad = Math.ceil(c.length / 2);
    return c.slice(0, mitad) + ' ' + c.slice(mitad);
  }

  function actualizar(){
    if (cargando) return;
    cargando = true;
    fetch('clave.php?api=1', {cache:'no-store'})
      .then(function(r){ if (r.status === 401) { location.href = 'login.php'; throw new Error('sesion'); } if(!r.ok) throw new Error(); return r.json(); })
      .then(function(d){
        claveBox.textContent = formatear(d.clave);
        restante = d.restante; intervalo = d.intervalo;
        errorBox.hidden = true;
      })
      .catch(function(){
        errorBox.textContent = 'No se pudo actualizar la clave. Reintentando…';
        errorBox.hidden = false;
        restante = 3;
      })
      .finally(function(){ cargando = false; pintar(); });
  }

  function pintar(){
    seg.textContent = Math.max(restante, 0);
    progreso.style.width = Math.max(0, Math.min(100, restante / intervalo * 100)) + '%';
  }

  setInterval(function(){
    restante--;
    if (restante <= 0) { actualizar(); } else { pintar(); }
  }, 1000);
  actualizar();
})();
</script>
</body>
</html>
