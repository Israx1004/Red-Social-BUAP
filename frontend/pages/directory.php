<?php
session_start();
require_once '../../backend/shared/auth_check.php';
$user = $_SESSION['user'];
$base = '/RedSocial_BUAP';

// Lista de facultades para el filtro (si la BD falla, el filtro queda vacío pero la página carga)
$faculties = [];
try {
    require_once '../../backend/microservices/users/user_model.php';
    $faculties = UserModel::getDB()->query("SELECT faculty_id, name FROM faculties ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[UniLink directory] ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Directorio — UniLink BUAP</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= $base ?>/frontend/css/main.css">
  <link rel="stylesheet" href="<?= $base ?>/frontend/css/dashboard.css">
  <style>
    .dir-filters{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px}
    .dir-filters input,.dir-filters select{padding:10px 14px;border:1.5px solid var(--border);border-radius:var(--radius-md);font-size:14px;background:var(--white);outline:none}
    .dir-filters input{flex:1;min-width:220px}
    .dir-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px}
    .dir-card{background:var(--white);border:1px solid var(--border);border-radius:var(--radius-lg,14px);padding:18px;display:flex;flex-direction:column;gap:10px}
    .dir-card-top{display:flex;gap:12px;align-items:center}
    .dir-name{font-weight:700;font-size:15px;color:var(--text-primary);text-decoration:none}
    .dir-name:hover{text-decoration:underline}
    .dir-meta{font-size:13px;color:var(--text-secondary);line-height:1.5;word-break:break-word}
    .dir-bio{font-size:13px;color:var(--text-muted)}
    .dir-pager{display:flex;gap:12px;align-items:center;justify-content:center;margin:24px 0;font-size:14px}
    .dir-empty{text-align:center;padding:48px;color:var(--text-muted);grid-column:1/-1}
  </style>
</head>
<body class="dashboard-page">

<?php include '../../frontend/components/sidebar.php'; ?>
<?php include '../../frontend/components/topbar.php'; ?>

<main class="main-content" style="display:block;padding:24px;max-width:1200px;margin:0 auto">
  <div style="margin-bottom:20px">
    <h1 style="font-family:var(--font-display);font-size:26px;font-weight:800">🗂 Directorio</h1>
    <p style="font-size:14px;color:var(--text-muted);margin-top:4px">Encuentra a alumnos y profesores de la comunidad BUAP</p>
  </div>

  <div class="dir-filters">
    <input type="search" id="dir-q" placeholder="🔍 Buscar por nombre, matrícula o correo…" autocomplete="off">
    <select id="dir-faculty">
      <option value="">Todas las facultades</option>
      <?php foreach ($faculties as $f): ?>
        <option value="<?= (int)$f['faculty_id'] ?>"><?= htmlspecialchars($f['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select id="dir-role">
      <option value="">Todos los roles</option>
      <option value="student">Alumnos</option>
      <option value="professor">Profesores</option>
      <option value="staff">Administrativos</option>
      <option value="moderator">Moderadores</option>
    </select>
  </div>

  <div id="dir-count" style="font-size:13px;color:var(--text-muted);margin-bottom:12px"></div>
  <div class="dir-grid" id="dir-grid"></div>
  <div class="dir-pager" id="dir-pager"></div>
</main>

<div id="toast-container"></div>

<script>
const UL_USER  = <?= json_encode(['id' => (int)$user['user_id'], 'role' => $user['role'] ?? 'student']) ?>;
const UL_TOKEN = '<?= $_SESSION['jwt_token'] ?? '' ?>';
</script>
<script src="<?= $base ?>/frontend/js/utils.js"></script>
<script src="<?= $base ?>/frontend/js/directory.js"></script>
<script src="<?= $base ?>/frontend/js/notifications.js"></script>
</body>
</html>
