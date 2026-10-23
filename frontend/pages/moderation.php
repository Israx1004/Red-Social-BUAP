<?php
session_start();
require_once '../../backend/shared/auth_check.php';
$user = $_SESSION['user'];
$base = BASE_PATH;

// Solo moderadores y administradores
if (!in_array($user['role'] ?? '', ['admin', 'moderator'], true)) {
    header('Location: ' . $base . '/frontend/pages/feed.php');
    exit;
}
$isAdmin = ($user['role'] ?? '') === 'admin';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Moderación — UniLink BUAP</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= $base ?>/frontend/css/main.css">
  <link rel="stylesheet" href="<?= $base ?>/frontend/css/dashboard.css">
  <link rel="stylesheet" href="<?= $base ?>/frontend/css/moderation.css">
</head>
<body class="dashboard-page">

<?php include '../../frontend/components/sidebar.php'; ?>
<?php include '../../frontend/components/topbar.php'; ?>

<main class="main-content" style="display:block;padding:24px;max-width:1200px;margin:0 auto">

  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px">
    <div>
      <h1 style="font-family:var(--font-display);font-size:26px;font-weight:800">🛡 Moderación</h1>
      <p style="font-size:14px;color:var(--text-muted);margin-top:4px">
        <?= $isAdmin ? 'Todas las facultades' : 'Reportes de tu facultad: ' . htmlspecialchars($user['faculty_name'] ?? '') ?>
      </p>
    </div>
    <div class="mod-stats">
      <div class="mod-stat"><span class="mod-stat-num" id="stat-pending">0</span><span class="mod-stat-label">Pendientes</span></div>
      <div class="mod-stat"><span class="mod-stat-num" id="stat-today">0</span><span class="mod-stat-label">Resueltos hoy</span></div>
      <div class="mod-stat"><span class="mod-stat-num" id="stat-flagged">0</span><span class="mod-stat-label">Marcados</span></div>
      <div class="mod-stat"><span class="mod-stat-num" id="stat-panic" style="color:var(--uni-red)">0</span><span class="mod-stat-label">Pánico</span></div>
    </div>
  </div>

  <div class="panic-alert-banner" id="panic-live-banner" style="display:none;cursor:pointer" onclick="switchTab('panic')">
    🚨 Hay alertas de pánico activas. Haz clic para atenderlas.
  </div>

  <div class="mod-tabs">
    <button class="mod-tab active" data-tab="reports" onclick="switchTab('reports')">🚩 Reportes <span class="nav-badge danger" id="tab-badge-reports">0</span></button>
    <button class="mod-tab" data-tab="flagged" onclick="switchTab('flagged')">⚠️ Contenido marcado</button>
    <button class="mod-tab" data-tab="panic" onclick="switchTab('panic')">🚨 Alertas de pánico <span class="nav-badge danger" id="tab-badge-panic">0</span></button>
    <button class="mod-tab" data-tab="log" onclick="switchTab('log')">📜 Historial</button>
  </div>

  <section class="mod-panel active" id="tab-reports">
    <div class="mod-toolbar">
      <select id="rep-filter-status" onchange="loadReports()">
        <option value="pending">Pendientes</option>
        <option value="resolved">Resueltos</option>
        <option value="dismissed">Desestimados</option>
      </select>
      <select id="rep-filter-reason" onchange="loadReports()">
        <option value="">Todos los motivos</option>
        <option value="spam">Spam</option>
        <option value="harassment">Acoso</option>
        <option value="hate_speech">Discurso de odio</option>
        <option value="false_info">Información falsa</option>
        <option value="inappropriate">Inapropiado</option>
        <option value="other">Otro</option>
      </select>
    </div>
    <div class="reports-list" id="reports-list"></div>
  </section>

  <section class="mod-panel hidden" id="tab-flagged">
    <div class="reports-list" id="flagged-list"></div>
  </section>

  <section class="mod-panel hidden" id="tab-panic">
    <div class="reports-list" id="panic-list"></div>
  </section>

  <section class="mod-panel hidden" id="tab-log">
    <div class="reports-list" id="mod-log-list"></div>
  </section>
</main>

<div id="toast-container"></div>

<script>
const UL_USER  = <?= json_encode(['id' => (int)$user['user_id'], 'role' => $user['role'] ?? 'student']) ?>;
const UL_TOKEN = <?= json_encode($_SESSION['jwt_token'] ?? '') ?>;
</script>
<script src="<?= $base ?>/frontend/js/utils.js"></script>
<script src="<?= $base ?>/frontend/js/moderation.js"></script>
<script src="<?= $base ?>/frontend/js/notifications.js"></script>
</body>
</html>
