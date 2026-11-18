<?php
declare(strict_types=1);
require_once __DIR__ . '/horario.php';
$uaaPortal = 'https://www.uaa.mx/portal/';
$uaaRedes = 'https://www.uaa.mx/portal/social-media/';
$transparencia = [
    'Plataforma Nacional de Transparencia' => 'https://www.plataformadetransparencia.org.mx/',
    'Obligaciones de Transparencia' => 'https://www.uaa.mx/informacionpublica/', // CONFIRMAR
    'Centro Occidente - Consejo Regional' => $uaaPortal, // PENDIENTE
    'SEP' => 'https://www.gob.mx/sep',
    'Adquisiciones y Obra Pública' => $uaaPortal, // PENDIENTE
    'Transparencia y acceso a la información' => 'https://www.uaa.mx/informacionpublica/', // CONFIRMAR
    'Unidad Garante' => $uaaPortal, // PENDIENTE
    'Contraloría Social' => 'https://www.uaa.mx/csocial/', // CONFIRMAR
    'Defensoría de los Derechos Universitarios' => $uaaPortal, // PENDIENTE
    'Consorcio de Universidades Mexicanas' => 'https://www.cumex.org.mx/',
    'Avisos de Privacidad' => $uaaPortal, // PENDIENTE
];
$redes = [
    'Facebook' => 'https://www.facebook.com/uaa.mx/',
    'Twitter / X' => 'https://x.com/uaa_mx',
    'Instagram' => 'https://www.instagram.com/uaa_mx/',
    'YouTube' => 'https://www.youtube.com/user/UAAmx',
    'LinkedIn' => 'https://www.linkedin.com/school/universidad-aut-noma-de-aguascalientes/',
    'Google Maps' => 'https://g.page/r/CdstxDdmJKCJEAE',
    'TikTok' => $uaaRedes, // PENDIENTE
    'Threads' => $uaaRedes, // PENDIENTE
];
?>
<style>
.uaa-footer{background:#142b4a;color:#c9d6ea;margin-top:34px;padding:0;text-align:left;font-size:.9rem;line-height:1.55;border-top:4px solid #2457a7}
.uaa-footer a{color:#fff;text-decoration:none}.uaa-footer a:hover{text-decoration:underline}
.uaa-footer-inner{max-width:1200px;margin:auto;padding:34px 20px 24px;display:grid;grid-template-columns:1.2fr 1.6fr 1fr;gap:34px}
.uaa-footer h3{margin:0 0 12px;font-size:.95rem;color:#fff;text-transform:uppercase;letter-spacing:.06em}
.uaa-footer ul{list-style:none;margin:0;padding:0}.uaa-footer li{margin:0 0 7px}
.uaa-trans{column-count:2;column-gap:24px}.uaa-trans li{break-inside:avoid}
.uaa-logo{height:58px;width:auto;border-radius:10px;background:#fff;padding:4px;object-fit:contain;display:block;margin-bottom:14px}
.uaa-lema{font-style:italic;font-weight:700;color:#fff;font-size:1.05rem;margin:0 0 10px}
.uaa-footer p{margin:0 0 10px}
.uaa-footer-bottom{border-top:1px solid #ffffff26;text-align:center;padding:14px 20px;font-size:.8rem;color:#9fb3d1}
@media(max-width:860px){.uaa-footer-inner{grid-template-columns:1fr;gap:24px}.uaa-trans{column-count:1}}
</style>
<footer class="uaa-footer">
<div class="uaa-footer-inner">
  <div>
    <img class="uaa-logo" src="https://esiima.uaa.mx/cursoextension/imgs/logouaa2.jpg" alt="Universidad Autónoma de Aguascalientes">
    <p class="uaa-lema">Se Lumen Proferre</p>
    <p>Av. Universidad #940, Ciudad Universitaria,<br>C.P. 20100, Aguascalientes, Ags. México.</p>
    <p>Tel. conmutador: <a href="tel:+524499107400">449 910 74 00</a></p>
    <p><strong>Horario de uso:</strong><br><?= htmlspecialchars(horario_texto(), ENT_QUOTES, 'UTF-8') ?></p>
  </div>
  <div>
    <h3>Enlaces de transparencia</h3>
    <ul class="uaa-trans">
<?php foreach ($transparencia as $texto => $url): ?>
      <li><a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><?= htmlspecialchars($texto, ENT_QUOTES, 'UTF-8') ?></a></li>
<?php endforeach; ?>
    </ul>
  </div>
  <div>
    <h3>Redes sociales</h3>
    <ul>
<?php foreach ($redes as $texto => $url): ?>
      <li><a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><?= htmlspecialchars($texto, ENT_QUOTES, 'UTF-8') ?></a></li>
<?php endforeach; ?>
    </ul>
  </div>
</div>
<div class="uaa-footer-bottom">© 2026 · Control de Prácticas Profesionales y Servicio Social del edificio 55 · Departamento de Sistemas de Información</div>
</footer>
