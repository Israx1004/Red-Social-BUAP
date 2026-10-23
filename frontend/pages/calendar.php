<?php
session_start();
require_once '../../backend/shared/auth_check.php';
$user = $_SESSION['user'];
$base = '/RedSocial_BUAP';
$canCreate = true; // cualquier usuario puede agregar eventos
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Calendario — UniLink BUAP</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= $base ?>/frontend/css/main.css">
  <link rel="stylesheet" href="<?= $base ?>/frontend/css/dashboard.css">
  <link rel="stylesheet" href="<?= $base ?>/frontend/css/calendar.css">
</head>
<body class="dashboard-page">

<?php include '../../frontend/components/sidebar.php'; ?>
<?php include '../../frontend/components/topbar.php'; ?>

<main class="main-content main-content-wide cal-layout" style="display:grid;padding:24px;max-width:1300px;margin:0 auto">

  <section>
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:12px">
      <div>
        <h1 style="font-family:var(--font-display);font-size:26px;font-weight:800">📅 Calendario académico</h1>
        <p style="font-size:14px;color:var(--text-muted);margin-top:4px">Clases, exámenes, talleres y eventos de la BUAP</p>
      </div>
      <?php if ($canCreate): ?>
        <button class="btn-primary" onclick="openEventModal()">+ Nuevo evento</button>
      <?php endif; ?>
    </div>

    <div class="cal-toolbar">
      <div class="cal-nav">
        <button class="cal-nav-btn" id="cal-prev" aria-label="Anterior">‹</button>
        <span class="cal-month-label" id="cal-month-label"></span>
        <button class="cal-nav-btn" id="cal-next" aria-label="Siguiente">›</button>
        <button class="cal-today-btn btn-secondary" id="cal-today">Hoy</button>
      </div>
      <div class="cal-view-toggle">
        <button class="view-btn active" data-view="month" onclick="switchView('month')">Mes</button>
        <button class="view-btn" data-view="week" onclick="switchView('week')">Semana</button>
        <button class="view-btn" data-view="list" onclick="switchView('list')">Lista</button>
      </div>
      <div class="cal-filters">
        <select id="cal-type-filter" onchange="renderCalendar()">
          <option value="">Todos los tipos</option>
          <option value="clase">Clase</option>
          <option value="examen">Examen</option>
          <option value="taller">Taller</option>
          <option value="conferencia">Conferencia</option>
          <option value="deportivo">Deportivo</option>
          <option value="cultural">Cultural</option>
          <option value="institutional">Institucional</option>
          <option value="otro">Otro</option>
        </select>
      </div>
    </div>

    <div class="cal-container card">
      <div id="cal-view"><div class="cal-loading">Cargando calendario…</div></div>
    </div>
  </section>

  <aside>
    <div class="card cal-upcoming">
      <div class="card-header"><h3>Próximos eventos</h3></div>
      <div id="upcoming-list"><p style="padding:16px;font-size:13px;color:var(--text-muted);text-align:center">Cargando…</p></div>
    </div>
  </aside>
</main>

<!-- Detalle de evento -->
<div class="modal-backdrop hidden" id="eventDetailModal" onclick="if(event.target===this)closeModal('eventDetailModal')">
  <div class="modal" role="dialog" aria-modal="true" style="max-width:520px;padding:0;overflow:hidden">
    <div class="modal-header" style="justify-content:flex-end;border:0;padding-bottom:0">
      <button class="modal-close" onclick="closeModal('eventDetailModal')">✕</button>
    </div>
    <div id="event-detail-content"></div>
  </div>
</div>

<?php if ($canCreate): ?>
<!-- Crear evento -->
<div class="modal-backdrop hidden" id="createEventModal" onclick="if(event.target===this)closeModal('createEventModal')">
  <div class="modal" role="dialog" aria-modal="true">
    <div class="modal-header">
      <h2 class="modal-title">Nuevo evento</h2>
      <button class="modal-close" onclick="closeModal('createEventModal')">✕</button>
    </div>
    <form id="create-event-form">
      <div class="post-modal-body">
        <div class="form-group"><label>Título</label>
          <input type="text" name="title" required maxlength="200" placeholder="Ej: Examen parcial de Cálculo"></div>
        <div class="form-group"><label>Descripción</label>
          <textarea name="description" rows="2" maxlength="500"></textarea></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <div class="form-group"><label>Inicio</label>
            <input type="datetime-local" name="event_date" required></div>
          <div class="form-group"><label>Fin (opcional)</label>
            <input type="datetime-local" name="end_date"></div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <div class="form-group"><label>Lugar</label>
            <input type="text" name="location" maxlength="200" placeholder="Aula, edificio…"></div>
          <div class="form-group"><label>Tipo</label>
            <select name="type">
              <option value="clase">Clase</option><option value="examen">Examen</option>
              <option value="taller">Taller</option><option value="conferencia">Conferencia</option>
              <option value="deportivo">Deportivo</option><option value="cultural">Cultural</option>
              <option value="institutional">Institucional</option><option value="otro" selected>Otro</option>
            </select></div>
        </div>
        <div class="form-group"><label>¿Quién lo ve?</label>
          <select name="scope">
            <option value="all">🌐 Toda la BUAP</option>
            <option value="faculty">🏛 Solo mi facultad</option>
          </select></div>
        <input type="hidden" name="is_public" value="1">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-secondary" onclick="closeModal('createEventModal')">Cancelar</button>
        <button type="submit" class="btn-primary">Crear evento</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<div id="toast-container"></div>

<script>
const UL_USER  = <?= json_encode(['id' => (int)$user['user_id'], 'role' => $user['role'] ?? 'student', 'name' => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))]) ?>;
const UL_TOKEN = '<?= $_SESSION['jwt_token'] ?? '' ?>';
const CAN_CREATE_EVENTS = <?= $canCreate ? 'true' : 'false' ?>;
</script>
<script src="<?= $base ?>/frontend/js/utils.js"></script>
<script src="<?= $base ?>/frontend/js/calendar.js"></script>
<script src="<?= $base ?>/frontend/js/notifications.js"></script>
</body>
</html>
