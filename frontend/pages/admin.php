<?php
session_start();
require_once '../../backend/shared/auth_check.php';
$user = $_SESSION['user'];
$base = BASE_PATH;

// Solo administradores
if (($user['role'] ?? '') !== 'admin') {
    header('Location: ' . $base . '/frontend/pages/feed.php');
    exit;
}

$faculties = [];
try {
    $faculties = getDBConnection()->query("SELECT faculty_id, name FROM faculties ORDER BY name")->fetchAll();
} catch (Throwable $e) {
    error_log('[UniLink admin] ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Administración — UniLink BUAP</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= $base ?>/frontend/css/main.css">
  <link rel="stylesheet" href="<?= $base ?>/frontend/css/dashboard.css">
  <link rel="stylesheet" href="<?= $base ?>/frontend/css/moderation.css">
  <link rel="stylesheet" href="<?= $base ?>/frontend/css/admin.css">
</head>
<body class="dashboard-page">

<?php include '../../frontend/components/sidebar.php'; ?>
<?php include '../../frontend/components/topbar.php'; ?>

<main class="main-content" style="display:block;padding:24px;max-width:1280px;margin:0 auto">

  <div style="margin-bottom:20px">
    <h1 style="font-family:var(--font-display);font-size:26px;font-weight:800">⚙️ Administración</h1>
    <p style="font-size:14px;color:var(--text-muted);margin-top:4px">Usuarios, contenido, grupos y estado del sistema</p>
  </div>

  <div class="admin-stats-grid" id="admin-stats" style="margin-bottom:24px">
    <div class="loading-placeholder" style="grid-column:1/-1">Cargando estadísticas...</div>
  </div>

  <div class="mod-tabs">
    <button class="mod-tab active" data-tab="users" onclick="switchAdminTab('users')">👥 Usuarios</button>
    <button class="mod-tab" data-tab="content" onclick="switchAdminTab('content')">📝 Contenido</button>
    <button class="mod-tab" data-tab="groups" onclick="switchAdminTab('groups')">📚 Grupos</button>
    <button class="mod-tab" data-tab="system" onclick="switchAdminTab('system')">🖥 Sistema</button>
  </div>

  <section class="mod-panel active" id="admin-tab-users">
    <div class="mod-toolbar">
      <input type="text" id="admin-user-search" placeholder="🔍 Nombre, correo o matrícula..." oninput="adminSearchUsers()">
      <select id="admin-role-filter" onchange="loadAdminUsers()">
        <option value="">Todos los roles</option>
        <option value="student">Estudiantes</option>
        <option value="professor">Profesores</option>
        <option value="staff">Personal</option>
        <option value="moderator">Moderadores</option>
        <option value="admin">Administradores</option>
      </select>
      <select id="admin-status-filter" onchange="loadAdminUsers()">
        <option value="">Todos los estados</option>
        <option value="active">Activos</option>
        <option value="suspended">Suspendidos</option>
        <option value="inactive">Inactivos</option>
      </select>
    </div>
    <div id="admin-users-table"></div>
  </section>

  <section class="mod-panel hidden" id="admin-tab-content">
    <div id="admin-content-stats"></div>
    <p style="margin-top:16px;font-size:14px">
      Para revisar reportes y contenido marcado usa el
      <a href="<?= $base ?>/frontend/pages/moderation.php">panel de moderación</a>.
    </p>
  </section>

  <section class="mod-panel hidden" id="admin-tab-groups">
    <div id="admin-groups-list"></div>
  </section>

  <section class="mod-panel hidden" id="admin-tab-system">
    <div class="admin-system-grid">
      <div class="card card-body">
        <h3 style="font-family:var(--font-display);font-weight:700;margin-bottom:10px">Estado de servicios</h3>
        <div id="system-health"></div>
        <button class="btn-ghost" style="margin-top:10px" onclick="checkSystemHealth()">↻ Volver a verificar</button>
      </div>

      <div class="card card-body">
        <h3 style="font-family:var(--font-display);font-weight:700;margin-bottom:10px">📢 Anuncio para todos</h3>
        <textarea id="system-announcement" rows="3" maxlength="290"
                  placeholder="Ej: Mañana no habrá servicio de transporte por mantenimiento."
                  style="width:100%;padding:10px;border:1.5px solid var(--border);border-radius:var(--radius-md);font:inherit;resize:vertical"></textarea>
        <button class="btn-primary" style="margin-top:10px" onclick="sendSystemAnnouncement()">Enviar anuncio</button>
      </div>

      <div class="card card-body">
        <h3 style="font-family:var(--font-display);font-weight:700;margin-bottom:10px">🛡 Asignar moderador</h3>
        <div class="form-group">
          <label>Matrícula o correo</label>
          <input type="text" id="mod-assign-student" placeholder="Ej: 202312345 o nombre@alumno.buap.mx">
        </div>
        <div class="form-group">
          <label>Facultad que moderará</label>
          <select id="mod-assign-faculty">
            <?php foreach ($faculties as $f): ?>
              <option value="<?= (int)$f['faculty_id'] ?>"><?= htmlspecialchars($f['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button class="btn-primary" onclick="assignModerator()">Asignar</button>
      </div>
    </div>
  </section>
</main>

<div id="toast-container"></div>

<script>
const UL_USER  = <?= json_encode(['id' => (int)$user['user_id'], 'role' => $user['role'] ?? 'student']) ?>;
const UL_TOKEN = <?= json_encode($_SESSION['jwt_token'] ?? '') ?>;
</script>
<script src="<?= $base ?>/frontend/js/utils.js"></script>
<script src="<?= $base ?>/frontend/js/admin.js"></script>
<script src="<?= $base ?>/frontend/js/notifications.js"></script>
</body>
</html>
