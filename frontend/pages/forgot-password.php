<?php
// Página pública: no requiere sesión
$base = '/RedSocial_BUAP';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Recuperar contraseña — UniLink BUAP</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= $base ?>/frontend/css/main.css">
  <link rel="stylesheet" href="<?= $base ?>/frontend/css/auth.css">
  <style>
    .recover-box { max-width:440px; margin:8vh auto; padding:32px; background:var(--white); border-radius:16px; box-shadow:var(--shadow-lg); position:relative; z-index:1; }
    .recover-box h1 { font-family:var(--font-display); font-size:24px; font-weight:800; margin-bottom:8px; }
    .recover-box p.sub { font-size:14px; color:var(--text-muted); margin-bottom:20px; }
    .form-ok { background:#E3F5F0; border:1px solid #9FE1CB; color:#0F6E56; padding:12px; border-radius:8px; font-size:14px; margin-top:14px; word-break:break-all; }
  </style>
</head>
<body class="auth-page">
  <div class="recover-box">
    <h1>¿Olvidaste tu contraseña?</h1>
    <p class="sub">Escribe tu correo institucional y te enviaremos un enlace para crear una nueva.</p>

    <form id="forgot-form" novalidate>
      <div class="form-group">
        <label for="forgot-email">Correo institucional BUAP</label>
        <input type="email" id="forgot-email" placeholder="tu.correo@alumno.buap.mx" autocomplete="email" required>
      </div>
      <button type="submit" class="btn-primary btn-full"><span class="btn-text">Enviar enlace</span></button>
      <div id="forgot-error" class="form-error hidden"></div>
      <div id="forgot-ok" class="form-ok hidden"></div>
    </form>

    <p style="margin-top:18px;font-size:14px;text-align:center">
      <a href="<?= $base ?>/index.php" class="link-small">← Volver a iniciar sesión</a>
    </p>
  </div>

<script>
(function () {
  const BASE = <?= json_encode($base) ?>;
  const RE   = /^[a-zA-Z0-9._%+\-]+@(alumno|correo)\.buap\.mx$/i;
  const form = document.getElementById('forgot-form');
  const err  = document.getElementById('forgot-error');
  const ok   = document.getElementById('forgot-ok');

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    err.classList.add('hidden'); ok.classList.add('hidden');
    const email = document.getElementById('forgot-email').value.trim();
    if (!RE.test(email)) {
      err.textContent = 'Usa tu correo @alumno.buap.mx o @correo.buap.mx';
      err.classList.remove('hidden');
      return;
    }
    const btn = form.querySelector('button');
    btn.disabled = true;
    try {
      const res  = await fetch(`${BASE}/backend/api-gateway/index.php?service=auth&path=auth/forgot-password`, {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email })
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.success) throw new Error(data.message || 'No se pudo procesar la solicitud.');

      ok.textContent = data.message;
      // Solo en desarrollo local (sin servidor de correo) el backend devuelve el enlace directamente
      if (data.data && data.data.dev_reset_link) {
        const a = document.createElement('a');
        a.href = data.data.dev_reset_link;
        a.textContent = 'Abrir enlace de recuperación (modo desarrollo)';
        ok.appendChild(document.createElement('br'));
        ok.appendChild(a);
      }
      ok.classList.remove('hidden');
    } catch (ex) {
      err.textContent = ex.message;
      err.classList.remove('hidden');
    } finally {
      btn.disabled = false;
    }
  });
})();
</script>
</body>
</html>
