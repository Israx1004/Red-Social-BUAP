/**
 * UniLink — admin.js
 * System administration panel
 */

const ROLE_LABELS   = { student:'Estudiante', professor:'Profesor', moderator:'Moderador', staff:'Personal', admin:'Administrador' };
const STATUS_LABELS = { active:'Activo', inactive:'Inactivo', suspended:'Suspendido', pending_verification:'Por verificar' };

document.addEventListener('DOMContentLoaded', () => {
  loadAdminStats();
  loadAdminUsers();
});

/* ====== STATS ====== */
async function loadAdminStats() {
  const grid = document.getElementById('admin-stats');
  try {
    const res   = await apiFetch('users/admin/stats');
    const stats = res?.data?.stats || {};
    const statDefs = [
      { icon:'👥', key:'total_users',    label:'Usuarios totales',     color:'var(--uni-blue)' },
      { icon:'✅', key:'active_users',   label:'Conectados hoy',       color:'var(--uni-green)' },
      { icon:'📝', key:'total_posts',    label:'Publicaciones',        color:'#7C3AED' },
      { icon:'🛒', key:'total_listings', label:'Anuncios marketplace', color:'var(--uni-orange)' },
      { icon:'🚩', key:'open_reports',   label:'Reportes abiertos',    color:'var(--uni-red)' },
      { icon:'📚', key:'total_groups',   label:'Grupos',               color:'#D97706' },
    ];
    grid.innerHTML = statDefs.map(s => `
      <div class="admin-stat-card">
        <span class="admin-stat-icon">${s.icon}</span>
        <span class="admin-stat-num" style="color:${s.color}">${(Number(stats[s.key]) || 0).toLocaleString('es-MX')}</span>
        <span class="admin-stat-label">${s.label}</span>
      </div>`).join('');
  } catch (err) {
    grid.innerHTML = `<div class="empty-mod" style="grid-column:1/-1">No se pudieron cargar las estadísticas: ${escHtml(err.message || '')}</div>`;
  }
}

/* ====== USERS ====== */
let adminUserSearchTimer;
function adminSearchUsers() {
  clearTimeout(adminUserSearchTimer);
  adminUserSearchTimer = setTimeout(loadAdminUsers, 400);
}

async function loadAdminUsers() {
  const q      = document.getElementById('admin-user-search')?.value.trim() || '';
  const role   = document.getElementById('admin-role-filter')?.value || '';
  const status = document.getElementById('admin-status-filter')?.value || '';
  const container = document.getElementById('admin-users-table');
  container.innerHTML = '<div class="loading-placeholder">Cargando...</div>';

  try {
    const params = new URLSearchParams({ limit: 50, ...(q&&{q}), ...(role&&{role}), ...(status&&{status}) });
    const res   = await apiFetch(`users/admin?${params}`);
    const users = Array.isArray(res?.data) ? res.data : [];
    const meta  = res?.meta || {};

    if (!users.length) {
      container.innerHTML = '<div class="empty-mod">Sin usuarios en esta búsqueda</div>';
      return;
    }

    container.innerHTML = `
      <div style="overflow-x:auto">
        <table class="users-table">
          <thead>
            <tr>
              <th>ID</th><th>Nombre</th><th>Email</th><th>Matrícula</th>
              <th>Facultad</th><th>Rol</th><th>Estado</th><th>Último acceso</th><th>Acciones</th>
            </tr>
          </thead>
          <tbody>
            ${users.map(u => {
              const id   = Number(u.user_id);
              const isMe = id === Number(UL_USER.id);
              return `
              <tr id="admin-user-row-${id}">
                <td style="color:var(--text-muted);font-size:12px">#${id}</td>
                <td>
                  <div style="display:flex;align-items:center;gap:8px">
                    <div class="avatar avatar-sm">${escHtml(initialOf(u.first_name))}</div>
                    <span style="font-weight:600">${escHtml(`${u.first_name || ''} ${u.last_name || ''}`)}</span>
                  </div>
                </td>
                <td style="font-size:13px">${escHtml(u.email)}</td>
                <td style="font-size:13px;font-family:monospace">${escHtml(u.student_id||'—')}</td>
                <td style="font-size:13px">${escHtml(u.faculty_name||'—')}</td>
                <td>
                  <select onchange="changeUserRole(${id}, this.value, this)" data-prev="${escHtml(u.role)}" ${isMe ? 'disabled title="No puedes cambiar tu propio rol"' : ''}
                    style="font-size:12px;padding:4px 8px;border:1px solid var(--border);border-radius:4px;background:var(--white)">
                    ${Object.keys(ROLE_LABELS).map(r =>
                      `<option value="${r}" ${u.role===r?'selected':''}>${ROLE_LABELS[r]}</option>`
                    ).join('')}
                  </select>
                </td>
                <td>
                  <span class="badge badge-${u.status==='active'?'green':u.status==='suspended'?'red':'gray'}">
                    ${escHtml(STATUS_LABELS[u.status] || u.status)}
                  </span>
                </td>
                <td style="font-size:12px;color:var(--text-muted)">${u.last_login ? timeAgo(u.last_login) : 'Nunca'}</td>
                <td style="display:flex;gap:6px;flex-wrap:wrap">
                  <button class="btn-ghost" style="font-size:12px;padding:4px 8px"
                    onclick="window.open('${UL_BASE}/frontend/pages/profile.php?id=${id}','_blank')">Ver</button>
                  ${isMe ? '' : (u.status === 'active'
                    ? `<button class="btn-ghost" style="font-size:12px;padding:4px 8px;color:var(--uni-red)"
                         onclick="adminSetStatus(${id},'suspended')">Suspender</button>`
                    : `<button class="btn-ghost" style="font-size:12px;padding:4px 8px;color:var(--uni-green)"
                         onclick="adminSetStatus(${id},'active')">Activar</button>`)
                  }
                </td>
              </tr>`; }).join('')}
          </tbody>
        </table>
      </div>
      <p style="font-size:12px;color:var(--text-muted);padding:10px">${(Number(meta.total) || users.length).toLocaleString('es-MX')} usuarios encontrados</p>`;
  } catch (err) {
    container.innerHTML = `<div class="empty-mod" style="color:var(--uni-red)">Error al cargar: ${escHtml(err.message || '')}</div>`;
  }
}

async function changeUserRole(userId, role, select) {
  if (!confirm(`¿Cambiar el rol a "${ROLE_LABELS[role] || role}"?`)) {
    if (select) select.value = select.dataset.prev;
    return;
  }
  try {
    await apiFetch(`users/${userId}/role`, { method: 'PATCH', body: JSON.stringify({ role }) });
    if (select) select.dataset.prev = role;
    showToast(`Rol cambiado a ${ROLE_LABELS[role] || role} ✓`, 'success');
  } catch (e) {
    if (select) select.value = select.dataset.prev;
    showToast(e.message || 'Error', 'error');
  }
}

async function adminSetStatus(userId, status) {
  const labels = { active: 'Activar', suspended: 'Suspender' };
  if (!confirm(`¿${labels[status]} a este usuario?`)) return;
  try {
    await apiFetch(`users/${userId}/status`, { method: 'PATCH', body: JSON.stringify({ status }) });
    showToast('Estado actualizado ✓', 'success');
    loadAdminUsers();
  } catch (e) { showToast(e.message || 'Error', 'error'); }
}

/* ====== TABS ====== */
function switchAdminTab(tab) {
  document.querySelectorAll('.mod-tab').forEach(t => t.classList.toggle('active', t.dataset.tab === tab));
  ['users','content','groups','system'].forEach(t => {
    const el = document.getElementById(`admin-tab-${t}`);
    el?.classList.toggle('active', t===tab);
    el?.classList.toggle('hidden', t!==tab);
  });
  if (tab === 'content') loadContentStats();
  if (tab === 'groups')  loadAdminGroups();
  if (tab === 'system')  checkSystemHealth();
}

/* ====== CONTENT STATS ====== */
async function loadContentStats() {
  const container = document.getElementById('admin-content-stats');
  container.innerHTML = '<div class="loading-placeholder">Cargando...</div>';
  try {
    const res   = await apiFetch('moderation/content-stats');
    const stats = res?.data?.stats || {};
    container.innerHTML = `
      <div class="admin-stats-grid">
        ${[
          { label:'Posts publicados',    val: stats.published_posts,  icon:'✅' },
          { label:'Posts removidos',     val: stats.removed_posts,    icon:'🗑' },
          { label:'Posts marcados',      val: stats.flagged_posts,    icon:'⚠️' },
          { label:'Comentarios hoy',     val: stats.comments_today,   icon:'💬' },
          { label:'Anuncios activos',    val: stats.active_listings,  icon:'🛒' },
          { label:'Reportes pendientes', val: stats.pending_reports,  icon:'🚩' },
        ].map(s => `
          <div class="admin-stat-card">
            <span class="admin-stat-icon">${s.icon}</span>
            <span class="admin-stat-num">${(Number(s.val) || 0).toLocaleString('es-MX')}</span>
            <span class="admin-stat-label">${s.label}</span>
          </div>`).join('')}
      </div>`;
  } catch (err) {
    container.innerHTML = `<div class="empty-mod">Error al cargar estadísticas: ${escHtml(err.message || '')}</div>`;
  }
}

/* ====== GROUPS ====== */
async function loadAdminGroups() {
  const container = document.getElementById('admin-groups-list');
  container.innerHTML = '<div class="loading-placeholder">Cargando...</div>';
  try {
    const res    = await apiFetch('academic/groups?limit=50');
    const groups = Array.isArray(res?.data) ? res.data : [];
    if (!groups.length) { container.innerHTML = '<div class="empty-mod">Sin grupos</div>'; return; }
    container.innerHTML = `
      <div style="overflow-x:auto">
        <table class="users-table">
          <thead><tr><th>Grupo</th><th>Tipo</th><th>NRC</th><th>Miembros</th><th>Facultad</th><th>Acciones</th></tr></thead>
          <tbody>
            ${groups.map(g => `
              <tr>
                <td><a href="${UL_BASE}/frontend/pages/group-detail.php?id=${Number(g.group_id)}" style="color:inherit"><strong>${escHtml(g.icon||'👥')} ${escHtml(g.name)}</strong></a></td>
                <td><span class="badge badge-blue" style="font-size:11px">${escHtml(g.type)}</span></td>
                <td style="font-family:monospace;font-size:13px">${escHtml(g.nrc_code||'—')}</td>
                <td>👥 ${Number(g.member_count)||0}</td>
                <td>${escHtml(g.faculty_name||'General')}</td>
                <td>
                  <button class="btn-ghost" style="font-size:12px;color:var(--uni-red)"
                    onclick="deleteGroup(${Number(g.group_id)})">Eliminar</button>
                </td>
              </tr>`).join('')}
          </tbody>
        </table>
      </div>`;
  } catch (err) {
    container.innerHTML = `<div class="empty-mod">Error al cargar grupos: ${escHtml(err.message || '')}</div>`;
  }
}

async function deleteGroup(id) {
  if (!confirm('¿Eliminar este grupo? Todos sus miembros serán desvinculados.')) return;
  try {
    await apiFetch(`academic/groups/${id}`, { method: 'DELETE' });
    showToast('Grupo eliminado', 'success');
    loadAdminGroups();
  } catch (e) { showToast(e.message || 'Error', 'error'); }
}

/* ====== SYSTEM HEALTH ====== */
async function checkWebSocket() {
  // El servidor Node no envía CORS en /health: con no-cors basta saber si responde
  const ctrl = new AbortController();
  const t = setTimeout(() => ctrl.abort(), 2500);
  try {
    await fetch(`${UL_WS_URL}/health`, { mode: 'no-cors', signal: ctrl.signal });
    return 'ok';
  } catch {
    return 'warning';   // opcional: la app funciona sin tiempo real
  } finally {
    clearTimeout(t);
  }
}

async function checkSystemHealth() {
  const container = document.getElementById('system-health');
  container.innerHTML = '<div class="loading-placeholder">Verificando...</div>';
  const services = [
    { key:'api',       name:'API Gateway' },
    { key:'db',        name:'Base de datos' },
    { key:'redis',     name:'Redis / Cache (opcional)' },
    { key:'websocket', name:'Servidor WebSocket (opcional)' },
    { key:'storage',   name:'Almacenamiento de archivos' },
  ];

  let health = {};
  try {
    const res = await apiFetch('users/admin/health');
    health = res?.data?.health || {};
  } catch {
    health = { api: 'error', db: 'error', storage: 'error', redis: 'error' };
  }
  health.websocket = await checkWebSocket();

  const meta = {
    ok:      { label:'Operativo',     color:'var(--uni-green)', cls:'health-ok' },
    warning: { label:'No disponible', color:'#D97706',          cls:'health-warning' },
    error:   { label:'Error',         color:'var(--uni-red)',   cls:'health-error' },
  };
  container.innerHTML = services.map(s => {
    const m = meta[health[s.key]] || meta.error;
    return `
      <div class="system-health-item">
        <span>${s.name}</span>
        <div style="display:flex;align-items:center;gap:6px">
          <span style="font-size:12px;color:${m.color}">${m.label}</span>
          <div class="health-indicator ${m.cls}"></div>
        </div>
      </div>`;
  }).join('');
}

/* ====== SYSTEM TOOLS ====== */
async function sendSystemAnnouncement() {
  const msg = document.getElementById('system-announcement').value.trim();
  if (!msg) { showToast('Escribe un mensaje', 'info'); return; }
  if (!confirm(`¿Enviar este anuncio a TODOS los usuarios?\n\n"${msg}"`)) return;
  try {
    const res = await apiFetch('moderation/system-alert', {
      method: 'POST',
      body: JSON.stringify({ message: msg })
    });
    document.getElementById('system-announcement').value = '';
    showToast(`Anuncio enviado a ${Number(res?.data?.recipients) || 0} usuarios ✓`, 'success');
  } catch (e) { showToast(e.message || 'Error', 'error'); }
}

async function assignModerator() {
  const studentId = document.getElementById('mod-assign-student').value.trim();
  const facultySel = document.getElementById('mod-assign-faculty');
  if (!studentId) { showToast('Ingresa la matrícula o el correo', 'info'); return; }
  try {
    await apiFetch('users/admin/assign-moderator', {
      method: 'POST',
      body: JSON.stringify({ student_id: studentId, faculty_id: facultySel.value })
    });
    const facName = facultySel.options[facultySel.selectedIndex]?.text || '';
    showToast(`Moderador asignado a ${facName} ✓`, 'success');
    document.getElementById('mod-assign-student').value = '';
    loadAdminUsers();
  } catch (e) { showToast(e.message || 'Error: verifica la matrícula', 'error'); }
}
