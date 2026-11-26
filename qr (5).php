<?php
declare(strict_types=1);
require __DIR__ . '/config/auth.php';
require __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/qr.php';
require __DIR__ . '/config/clave.php';

// Endpoint JSON para refrescar el QR y la clave
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    try {
        echo json_encode([
            'url' => qr_url_alumno(),
            'restante' => QR_INTERVALO - (time() % QR_INTERVALO),
            'intervalo' => QR_INTERVALO,
            'clave' => clave_actual(),
            'clave_restante' => clave_restante(),
            'clave_intervalo' => CLAVE_INTERVALO,
        ], JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Código QR y clave | Prácticas Profesionales</title>
<style>
:root{--navy:#142b4a;--blue:#2457a7;--bg:#f3f6fb;--text:#243247;--muted:#64748b;--border:#dbe3ee}
*{box-sizing:border-box}body{margin:0;font-family:"Segoe UI",Arial,sans-serif;background:var(--bg);color:var(--text)}
header{color:#fff;padding:26px 20px;background:linear-gradient(120deg,#142b4a,#2457a7)}
.header-inner,main{max-width:1200px;margin:auto}.brand{display:flex;gap:20px;align-items:center}
h1{font-size:clamp(1.3rem,3vw,1.9rem);margin:0}header p{color:#dbeafe;margin:6px 0 0}
.marca-logo{height:55px;width:auto;border-radius:10px;object-fit:contain}
nav{display:flex;gap:8px;margin-top:16px;flex-wrap:wrap}nav a{color:#fff;text-decoration:none;padding:8px 14px;border-radius:8px;background:#ffffff22;font-weight:600;font-size:.88rem}nav a.activo{background:#fff;color:var(--navy)}
main{padding:25px 20px}.duo{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:20px;max-width:1000px;margin:0 auto 20px}
.card{background:#fff;border:1px solid var(--border);border-radius:14px;box-shadow:0 8px 28px #142b4a0d;padding:30px;text-align:center}
h2{font-size:1.25rem;color:var(--navy);margin:0 0 6px}.hint{color:var(--muted);font-size:.9rem;margin:0 0 18px}
#qr{display:inline-block;padding:16px;background:#fff;border:1px solid var(--border);border-radius:12px;min-width:280px;min-height:280px}
#qr img,#qr canvas{display:block;margin:auto}
.codigo{font-family:Consolas,"Courier New",monospace;font-weight:700;color:var(--navy);font-size:clamp(2.6rem,8vw,4.5rem);letter-spacing:.1em;line-height:1.1;padding:18px 10px;background:#f8fbff;border:1px solid var(--border);border-radius:12px}
.barra{height:8px;background:#e8edf4;border-radius:99px;overflow:hidden;margin:20px auto 8px;max-width:280px}
.barra div{height:100%;width:100%;background:var(--blue);transition:width 1s linear}
.tiempo{color:var(--muted);font-size:.88rem}.error{color:#991b1b;background:#fee2e2;padding:10px;border-radius:8px;font-weight:600;margin:0 auto 14px;max-width:600px;text-align:center}
footer{text-align:center;color:var(--muted);padding:0 20px 25px;font-size:.82rem}
</style>
<link rel="stylesheet" href="assets/estilo.css?v=1">
</head>
<body class="pg-qr">
<?php $menuSeccion = 'qr'; $menuSubtitulo = 'Código QR y clave de asistencia'; require __DIR__ . '/config/encabezado.php'; ?>
<main>
<div class="error" id="error" hidden></div>
<div class="duo">
<section class="card">
<h2>1. Escanea para entrar</h2>
<p class="hint">Escanea el código con la cámara de tu celular. Cambia automáticamente.</p>
<div id="qr"></div>
<div class="barra"><div id="progresoQr"></div></div>
<div class="tiempo">Se actualiza en <strong id="segQr">--</strong> s</div>
<p class="hint" style="margin-top:10px;word-break:break-all">Abre: <span id="enlaceQr">--</span></p>
</section>
<section class="card">
<h2>2. Clave para registrar</h2>
<p class="hint">Escríbela en tu celular al registrar tu entrada o salida. La anterior deja de servir.</p>
<div class="codigo" id="clave">------</div>
<div class="barra"><div id="progresoClave"></div></div>
<div class="tiempo">Cambia en <strong id="segClave">--</strong> s</div>
</section>
</div>
</main>
<?php require __DIR__ . '/config/footer.php'; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
(function(){
  var contenedor = document.getElementById('qr');
  var claveBox = document.getElementById('clave');
  var progresoQr = document.getElementById('progresoQr');
  var progresoClave = document.getElementById('progresoClave');
  var segQr = document.getElementById('segQr');
  var segClave = document.getElementById('segClave');
  var errorBox = document.getElementById('error');
  var qr = null, cargando = false;
  var restanteQr = 0, intervaloQr = 30, restanteClave = 0, intervaloClave = 60;

  function dibujar(url){
    document.getElementById('enlaceQr').textContent = url;
    if (!qr) {
      qr = new QRCode(contenedor, {text:url, width:256, height:256, correctLevel:QRCode.CorrectLevel.M});
    } else {
      qr.clear();
      qr.makeCode(url);
    }
  }

  function formatear(c){
    var mitad = Math.ceil(c.length / 2);
    return c.slice(0, mitad) + ' ' + c.slice(mitad);
  }

  function actualizar(){
    if (cargando) return;
    cargando = true;
    fetch('qr.php?api=1', {cache:'no-store'})
      .then(function(r){ 
        if (r.status === 401) { location.href = 'login.php'; throw new Error('Sesión expirada'); } 
        return r.text().then(function(texto){
            try { return JSON.parse(texto); }
            catch(e) { throw new Error('Respuesta no válida del servidor: ' + texto.substring(0, 100)); }
        });
      })
      .then(function(d){
        if (d.error) throw new Error(d.error);
        dibujar(d.url);
        claveBox.textContent = formatear(d.clave);
        restanteQr = d.restante; intervaloQr = d.intervalo;
        restanteClave = d.clave_restante; intervaloClave = d.clave_intervalo;
        errorBox.hidden = true;
      })
      .catch(function(err){
        errorBox.textContent = err.message || 'Error al conectar';
        errorBox.hidden = false;
        restanteQr = 5; restanteClave = 5;
      })
      .finally(function(){ cargando = false; pintar(); });
  }

  function pintar(){
    segQr.textContent = Math.max(restanteQr, 0);
    segClave.textContent = Math.max(restanteClave, 0);
    progresoQr.style.width = Math.max(0, Math.min(100, restanteQr / intervaloQr * 100)) + '%';
    progresoClave.style.width = Math.max(0, Math.min(100, restanteClave / intervaloClave * 100)) + '%';
  }

  setInterval(function(){
    restanteQr--; restanteClave--;
    if (restanteQr <= 0 || restanteClave <= 0) { actualizar(); } else { pintar(); }
  }, 1000);
  actualizar();
})();
</script>
</body>
</html>
