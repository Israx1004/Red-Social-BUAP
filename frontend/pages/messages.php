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
  <title>Mensajes — UniLink BUAP</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= $base ?>/frontend/css/main.css">
  <link rel="stylesheet" href="<?= $base ?>/frontend/css/dashboard.css">
  <link rel="stylesheet" href="<?= $base ?>/frontend/css/messages.css">
</head>
<body class="dashboard-page">

<?php include '../../frontend/components/sidebar.php'; ?>
<?php include '../../frontend/components/topbar.php'; ?>

<main class="main-content" style="display:block;padding:20px">
  <div class="chat-wrap" id="chat-wrap">

    <section class="chat-list">
      <div class="chat-list-head">💬 Mensajes</div>
      <div class="chat-convs" id="chat-convs">
        <div class="chat-empty">Cargando…</div>
      </div>
    </section>

    <section class="chat-main" id="chat-main">
      <div class="chat-empty" id="chat-placeholder">
        <div style="font-size:48px">💬</div>
        <p style="margin-top:8px">Elige una conversación o contacta a alguien desde el Marketplace, Objetos perdidos o su perfil.</p>
      </div>

      <div id="chat-panel" style="display:none;flex-direction:column;height:100%;min-height:0">
        <div class="chat-head">
          <button class="btn-ghost chat-back" onclick="closeChat()">‹</button>
          <div class="avatar avatar-sm" id="chat-peer-avatar" style="overflow:hidden"></div>
          <a id="chat-peer-name" href="#"></a>
        </div>
        <div class="chat-ctx" id="chat-ctx" style="display:none"></div>
        <div class="chat-msgs" id="chat-msgs"></div>
        <form class="chat-form" id="chat-form" autocomplete="off">
          <textarea id="chat-input" rows="1" maxlength="1000" placeholder="Escribe un mensaje…"></textarea>
          <button type="submit" class="btn-primary" id="chat-send">Enviar</button>
        </form>
      </div>
    </section>

  </div>
</main>

<div id="toast-container"></div>

<script>
const UL_USER  = <?= json_encode(['id' => (int)$user['user_id'], 'role' => $user['role'] ?? 'student', 'name' => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))]) ?>;
const UL_TOKEN = '<?= $_SESSION['jwt_token'] ?? '' ?>';
</script>
<script src="<?= $base ?>/frontend/js/utils.js"></script>
<script src="<?= $base ?>/frontend/js/messages.js"></script>
<script src="<?= $base ?>/frontend/js/notifications.js"></script>
</body>
</html>
