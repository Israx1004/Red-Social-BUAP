<?php
session_start();
require_once '../../backend/shared/auth_check.php';
$user = $_SESSION['user'];
$base = '/RedSocial_BUAP';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Encuestas — UniLink BUAP</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= $base ?>/frontend/css/main.css">
  <link rel="stylesheet" href="<?= $base ?>/frontend/css/dashboard.css">
  <link rel="stylesheet" href="<?= $base ?>/frontend/css/polls.css">
  <link rel="stylesheet" href="<?= $base ?>/frontend/css/moderation.css">
</head>
<body class="dashboard-page">

<?php include '../../frontend/components/sidebar.php'; ?>
<?php include '../../frontend/components/topbar.php'; ?>

<main class="main-content" style="display:block;padding:24px;max-width:1200px;margin:0 auto">

  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px">
    <div>
      <h1 style="font-family:var(--font-display);font-size:26px;font-weight:800">📊 Encuestas</h1>
      <p style="font-size:14px;color:var(--text-muted);margin-top:4px">Opina sobre los servicios del campus</p>
    </div>
    <button class="btn-primary" onclick="openCreatePollModal()">+ Nueva encuesta</button>
  </div>

  <div class="mod-tabs" style="margin-bottom:20px;flex-wrap:wrap">
    <button class="mod-tab cat-tab active" onclick="filterPolls('')">Todas</button>
    <button class="mod-tab cat-tab" onclick="filterPolls('activa')">Activas</button>
    <button class="mod-tab cat-tab" onclick="filterPolls('cafeteria')">🍽 Cafetería</button>
    <button class="mod-tab cat-tab" onclick="filterPolls('laboratorio')">🔬 Laboratorios</button>
    <button class="mod-tab cat-tab" onclick="filterPolls('transporte')">🚌 Transporte</button>
    <button class="mod-tab cat-tab" onclick="filterPolls('biblioteca')">📚 Biblioteca</button>
    <button class="mod-tab cat-tab" onclick="filterPolls('academico')">🎓 Académico</button>
  </div>

  <div class="polls-summary-banner card card-body" id="polls-banner" style="display:none">
    <h3 style="font-family:var(--font-display);font-weight:700;margin-bottom:12px">⭐ Calificaciones de servicios</h3>
    <div class="polls-summary-grid" id="polls-summary-grid"></div>
  </div>

  <div class="polls-grid" id="polls-grid"></div>
  <div id="polls-empty" class="hidden" style="text-align:center;padding:48px;color:var(--text-muted)">
    <div style="font-size:42px">🗳</div>
    <p style="margin-top:8px">No hay encuestas en esta categoría todavía.</p>
  </div>
</main>

<!-- Crear encuesta -->
<div class="modal-backdrop hidden" id="createPollModal" onclick="if(event.target===this)closeModal('createPollModal')">
  <div class="modal" role="dialog" aria-modal="true">
    <div class="modal-header">
      <h2 class="modal-title">Nueva encuesta</h2>
      <button class="modal-close" onclick="closeModal('createPollModal')">✕</button>
    </div>
    <form id="create-poll-form">
      <div class="post-modal-body">
        <div class="form-group"><label>Pregunta</label>
          <input type="text" name="title" required minlength="5" maxlength="300" placeholder="¿Qué quieres preguntar?"></div>
        <div class="form-group"><label>Descripción (opcional)</label>
          <textarea name="description" rows="2" maxlength="500"></textarea></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <div class="form-group"><label>Categoría</label>
            <select name="category">
              <option value="general">General</option><option value="cafeteria">Cafetería</option>
              <option value="laboratorio">Laboratorios</option><option value="transporte">Transporte</option>
              <option value="biblioteca">Biblioteca</option><option value="academico">Académico</option>
            </select></div>
          <div class="form-group"><label>Cierra el (opcional)</label>
            <input type="datetime-local" name="closes_at"></div>
        </div>
        <div class="form-group">
          <label>Tipo de respuesta</label>
          <div style="display:flex;gap:16px;flex-wrap:wrap;font-size:14px">
            <label><input type="radio" name="poll_type" value="options" checked onchange="setPollType('options')"> Opciones</label>
            <label><input type="radio" name="poll_type" value="rating" onchange="setPollType('rating')"> Calificación ★</label>
            <label><input type="radio" name="poll_type" value="yesno" onchange="setPollType('yesno')"> Sí / No</label>
          </div>
        </div>
        <div id="poll-options-section">
          <label class="field-label" style="font-size:13px;font-weight:600">Opciones</label>
          <div id="poll-options-list">
            <div class="form-group"><input type="text" name="option[]" placeholder="Opción 1"></div>
            <div class="form-group"><input type="text" name="option[]" placeholder="Opción 2"></div>
          </div>
          <button type="button" class="btn-ghost" onclick="addPollOptionField()">+ Agregar opción</button>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-secondary" onclick="closeModal('createPollModal')">Cancelar</button>
        <button type="submit" class="btn-primary">Publicar encuesta</button>
      </div>
    </form>
  </div>
</div>

<div id="toast-container"></div>

<script>
const UL_USER  = <?= json_encode(['id' => (int)$user['user_id'], 'role' => $user['role'] ?? 'student']) ?>;
const UL_TOKEN = '<?= $_SESSION['jwt_token'] ?? '' ?>';
</script>
<script src="<?= $base ?>/frontend/js/utils.js"></script>
<script src="<?= $base ?>/frontend/js/polls.js"></script>
<script src="<?= $base ?>/frontend/js/notifications.js"></script>
</body>
</html>
