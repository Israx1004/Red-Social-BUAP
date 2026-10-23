<?php
// Página pública: llega desde el enlace del correo (?token=...)
$base  = '/RedSocial_BUAP';
$token = preg_match('/^[a-f0-9]{64}$/', $_GET['token'] ?? '') ? $_GET['token'] : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="referrer" content="no-referrer">
  <title>Nueva contraseña — UniLink BUAP</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= $base ?>/frontend/css/main.css">
  <link rel="stylesheet" href="<?= $base ?>/frontend/css/auth.css">
  <style>
    .recover-box { max-width:440px; margin:8vh auto; padding:32px; background:var(--white); border-radius:16px; box-shadow:var(--shadow-lg); position:relative; z-index:1; }
    .recover-box h1 { font-family:var(--font-display); font-size:24px; font-weight:800; margin-bottom:8px; }
    .recover-box p.sub { font-size:14px; color:var(--text-muted); margin-bottom:20px; }
    .form-ok { background:#E3F5F0; border:1px solid #9FE1CB; color:#0F6E56; padding:12px; border-radius:8px; font-size:14px; margin-top:14px; }
  </style>
</head>
<body class="auth-page">
  <div class="recover-box">
    <h1>Crea una nueva contraseña</h1>
    <?php if (!$token): ?>
      <p class="sub">El enlace no es válido. Solicita uno nuevo.</p>
      <a href="<?= $base ?>/frontend/pages/forgot-password.php" class="btn-primary btn-full" style="display:block;text-align:center">Solicitar enlace</a>
    <?php else: ?>
      <p class="sub">Mínimo 8 caracteres. Te recomendamos combinar mayúsculas, números y símbolos.</p>
      <form id="reset-form" novalidate>
        <div class="form-group">
          <label for="new-pass">Nueva contraseña</label>
          <input type="password" id="new-pass" autocomplete="new-password" minlength="8" required>
        </div>
        <div class="form-group">
          <label for="new-pass2">Repite la contraseña</label>
          <input type="password" id="new-pass2" autocomplete="new-password" minlength="8" required>
        </div>
        <button type="submit" class="btn-primary btn-full"><span class="btn-text">Guardar contraseña</span></button>
        <div id="reset-error" class="form-error hidden"></div>
        <div id="reset-ok" class="form-ok hidden"></div>
      </form>
    <?php endif; ?>
    <p style="margin-top:18px;font-size:14px;text-align:center">
      <a href="<?= $base ?>/index.php" class="link-small">← Volver a iniciar sesión</a>
    </p>
  </div>

<?php if ($token): ?>
<script>
(function () {
  const BASE  = <?= json_encode($base) ?>;
  const TOKEN = <?= json_encode($token) ?>;
  const form  = document.getElementById('reset-form');
  const err   = document.getElementById('reset-error');
  const ok    = document.getElementById('reset-ok');

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    err.classList.add('hidden');
    const p1 = document.getElementById('new-pass').value;
    const p2 = document.getElementById('new-pass2').value;
    if (p1.length < 8) { err.textContent = 'La contraseña debe tener al menos 8 caracteres.'; err.classList.remove('hidden'); return; }
    if (p1 !== p2)     { err.textContent = 'Las contraseñas no coinciden.'; err.classList.remove('hidden'); return; }

    const btn = form.querySelector('button');
    btn.disabled = true;
    try {
      const res  = await fetch(`${BASE}/backend/api-gateway/index.php?service=auth&path=auth/reset-password`, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ token: TOKEN, password: p1 })
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.success) throw new Error(data.message || 'No se pudo cambiar la contraseña.');
      form.querySelectorAll('input,button').forEach(el => el.disabled = true);
      ok.textContent = data.message + ' Redirigiendo...';
      ok.classList.remove('hidden');
      setTimeout(() => { window.location.href = `${BASE}/index.php`; }, 2000);
    } catch (ex) {
      err.textContent = ex.message;
      err.classList.remove('hidden');
      btn.disabled = false;
    }
  });
})();
</script>
<?php endif; ?>
</body>
</html>
