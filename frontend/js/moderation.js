/**
 * UniLink — moderation.js
 * Moderation panel: reports, flagged, panic, log
 * (la gestión de usuarios vive en el panel de administración: admin.js)
 */

let activeTab = 'reports';

const REASON_LABELS = {
  spam:'Spam', harassment:'Acoso', hate_speech:'Discurso de odio',
  false_info:'Info falsa', inappropriate:'Inapropiado', other:'Otro'
};
const REPORT_STATUS_LABELS = { pending:'Pendiente', reviewed:'Revisado', resolved:'Resuelto', dismissed:'Desestimado' };
const PANIC_STATUS_LABELS  = { active:'🚨 ACTIVA', acknowledged:'👮 Atendiendo', resolved:'Resuelta' };

document.addEventListener('DOMContentLoaded', () => {
  loadStats();
  loadReports();
  // Refresh stats every 30s
  setInterval(loadStats, 30000);
});

/* ====== TABS ====== */
function switchTab(tab) {
  activeTab = tab;
  document.querySelectorAll('.mod-tab').forEach(t => t.classList.toggle('active', t.dataset.tab === tab));
  document.querySelectorAll('.mod-panel').forEach(p => {
    p.classList.toggle('active', p.id === `tab-${tab}`);
    p.classList.toggle('hidden', p.id !== `tab-${tab}`);
  });

  const loaders = {
    reports: loadReports,
    flagged: loadFlagged,
    panic:   loadPanicAlerts,
    log:     loadModLog,
  };
  loaders[tab]?.();
}

/* ====== STATS ====== */
async function loadStats() {
  try {
    const res   = await apiFetch('moderation/stats');
    const stats = res?.data?.stats || {};
    const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = Number(v) || 0; };
    set('stat-pending', stats.pending_reports);
    set('stat-today',   stats.resolved_today);
    set('stat-panic',   stats.active_panic);
    set('stat-flagged', stats.flagged_posts);
    set('tab-badge-reports', stats.pending_reports);
    set('tab-badge-panic',   stats.active_panic);

    const banner = document.getElementById('panic-live-banner');
    if (banner) banner.style.display = Number(stats.active_panic) > 0 ? 'flex' : 'none';
  } catch { /* silent */ }
}

/* ====== REPORTS ====== */
async function loadReports() {
  const status = document.getElementById('rep-filter-status')?.value || 'pending';
  const reason = document.getElementById('rep-filter-reason')?.value || '';
  const container = document.getElementById('reports-list');
  container.innerHTML = '<div class="loading-placeholder">Cargando reportes...</div>';

  try {
    const params  = new URLSearchParams({ status, limit: 30, ...(reason && { reason }) });
    const res     = await apiFetch(`moderation/reports?${params}`);
    const reports = res?.data?.reports || [];
    if (!reports.length) {
      container.innerHTML = '<div class="empty-mod">✅ Sin reportes en esta categoría</div>';
      return;
    }
    container.innerHTML = reports.map(renderReport).join('');
  } catch (err) {
    container.innerHTML = `<div class="empty-mod" style="color:var(--uni-red)">Error al cargar reportes: ${escHtml(err.message || '')}</div>`;
  }
}

function renderReport(r) {
  const id     = Number(r.report_id);
  const postId = Number(r.post_id) || 'null';
  const content = r.post_content || '';
  return `
    <div class="report-item" id="report-${id}">
      <div class="report-header">
        <div class="report-meta">
          <span class="badge badge-${r.status==='pending'?'orange':'gray'}">${escHtml(REPORT_STATUS_LABELS[r.status] || r.status)}</span>
          <span class="badge badge-red">${escHtml(REASON_LABELS[r.reason] || r.reason)}</span>
          <span class="text-muted" style="font-size:12px">${timeAgo(r.created_at)}</span>
        </div>
        <span class="text-muted" style="font-size:12px">Reportado por: <strong>${escHtml(r.reporter_name||'')}</strong></span>
      </div>

      ${content ? `
        <div class="report-content-preview">
          <strong>Publicación reportada${r.post_status === 'removed' ? ' (ya eliminada)' : ''}:</strong>
          <p>${escHtml(content.substring(0, 200))}${content.length>200?'...':''}</p>
          <small>Por: ${escHtml(r.author_name||'?')} · ${timeAgo(r.post_created_at||r.created_at)}</small>
        </div>` : '<div class="report-details">La publicación ya no existe.</div>'}

      ${r.details ? `<div class="report-details"><strong>Detalles:</strong> ${escHtml(r.details)}</div>` : ''}

      ${r.status === 'pending' ? `
        <div class="report-actions">
          ${r.post_status !== 'removed' && content ? `
          <button class="btn-danger" onclick="takeAction('remove_post', ${id}, ${postId})">🗑 Eliminar publicación</button>` : ''}
          ${content ? `
          <button class="btn-secondary" onclick="takeAction('warn_user', ${id}, ${postId})">⚠️ Advertir usuario</button>
          <button class="btn-secondary" onclick="takeAction('suspend_user', ${id}, ${postId})">🔒 Suspender usuario</button>` : ''}
          <button class="btn-ghost" onclick="takeAction('dismiss', ${id}, null)">Desestimar</button>
        </div>` : ''}
    </div>`;
}

async function takeAction(action, reportId, targetId) {
  const confirmMessages = {
    remove_post:  '¿Eliminar la publicación? Esta acción es visible en el log.',
    warn_user:    '¿Enviar advertencia al usuario?',
    suspend_user: '¿Suspender la cuenta del usuario?',
    dismiss:      '¿Desestimar este reporte?',
  };
  if (!confirm(confirmMessages[action])) return;
  const reason = action === 'dismiss' ? '' : (prompt('Motivo (opcional, se guarda en el log):') || '');

  try {
    await apiFetch('moderation/actions', {
      method: 'POST',
      body: JSON.stringify({ action, report_id: reportId, target_id: targetId, reason })
    });

    document.getElementById(`report-${reportId}`)?.remove();
    showToast('Acción registrada en el log de moderación ✓', 'success');
    loadStats();

    if (action === 'remove_post') {
      // Aviso en tiempo real (solo si el servidor WebSocket está activo)
      window.ULSocket?.emit?.('moderate_post', { post_id: targetId, reason: 'moderated' });
    }
  } catch (e) {
    showToast(e.message || 'Error al ejecutar acción', 'error');
  }
}

/* ====== FLAGGED CONTENT ====== */
async function loadFlagged() {
  const container = document.getElementById('flagged-list');
  container.innerHTML = '<div class="loading-placeholder">Cargando contenido marcado...</div>';
  try {
    const res   = await apiFetch('moderation/flagged?limit=30');
    const posts = res?.data?.posts || [];
    if (!posts.length) {
      container.innerHTML = '<div class="empty-mod">✅ Sin contenido marcado pendiente</div>';
      return;
    }
    container.innerHTML = posts.map(p => `
      <div class="report-item" id="flagged-${Number(p.post_id)}">
        <div class="report-header">
          <span class="badge badge-orange">Marcado automáticamente</span>
          <span class="text-muted" style="font-size:12px">${timeAgo(p.created_at)}</span>
        </div>
        <div class="report-content-preview">
          <p>${escHtml((p.content || '').substring(0,300))}</p>
          <small>Por: ${escHtml(p.author_name||'?')}</small>
        </div>
        <div class="report-actions">
          <button class="btn-danger" onclick="removeFlaggedPost(${Number(p.post_id)})">Eliminar</button>
          <button class="btn-secondary" onclick="approveFlaggedPost(${Number(p.post_id)})">Aprobar</button>
        </div>
      </div>`).join('');
  } catch (err) {
    container.innerHTML = `<div class="empty-mod" style="color:var(--uni-red)">Error al cargar: ${escHtml(err.message || '')}</div>`;
  }
}

async function removeFlaggedPost(id) {
  if (!confirm('¿Eliminar esta publicación?')) return;
  try {
    await apiFetch('moderation/actions', {
      method: 'POST',
      body: JSON.stringify({ action: 'remove_post', target_id: id, reason: 'Contenido marcado automáticamente' })
    });
    document.getElementById(`flagged-${id}`)?.remove();
    showToast('Publicación eliminada', 'success');
    loadStats();
  } catch (e) { showToast(e.message || 'Error al eliminar', 'error'); }
}

async function approveFlaggedPost(id) {
  try {
    await apiFetch(`moderation/flagged/${id}/approve`, { method: 'PATCH' });
    document.getElementById(`flagged-${id}`)?.remove();
    showToast('Publicación aprobada y publicada', 'success');
    loadStats();
  } catch (e) { showToast(e.message || 'Error al aprobar', 'error'); }
}

/* ====== PANIC ALERTS ====== */
async function loadPanicAlerts() {
  const container = document.getElementById('panic-list');
  container.innerHTML = '<div class="loading-placeholder">Cargando alertas...</div>';
  try {
    const res    = await apiFetch('moderation/panic');
    const alerts = res?.data?.alerts || [];
    if (!alerts.length) {
      container.innerHTML = '<div class="empty-mod">✅ Sin alertas registradas</div>';
      return;
    }
    container.innerHTML = alerts.map(a => {
      const id  = Number(a.alert_id);
      const lat = a.latitude  !== null ? Number(a.latitude)  : null;
      const lng = a.longitude !== null ? Number(a.longitude) : null;
      const open = a.status !== 'resolved';
      return `
      <div class="report-item panic-item ${a.status==='active'?'panic-active':''}" id="panic-${id}">
        <div class="report-header">
          <div class="report-meta">
            <span class="badge badge-${a.status==='active'?'red':a.status==='acknowledged'?'orange':'gray'}">${escHtml(PANIC_STATUS_LABELS[a.status] || a.status)}</span>
            <strong>${escHtml(a.user_name||'Usuario')}</strong>
            <span class="text-muted" style="font-size:12px">${escHtml(a.email || '')} · ${timeAgo(a.created_at)}</span>
          </div>
        </div>
        ${lat !== null && lng !== null && !isNaN(lat) && !isNaN(lng)
          ? `<p style="font-size:13px;margin:8px 0">📍 <a href="https://www.google.com/maps?q=${lat},${lng}" target="_blank" rel="noopener">${lat.toFixed(5)}, ${lng.toFixed(5)}</a></p>`
          : `<p style="font-size:13px;margin:8px 0;color:var(--text-muted)">📍 ${escHtml(a.location_text || 'Ubicación no disponible')}</p>`}
        ${open ? `
          <div class="report-actions">
            ${a.status === 'active' ? `<button class="btn-primary" onclick="acknowledgePanic(${id})">✓ Atendiendo</button>` : ''}
            <button class="btn-secondary" onclick="resolvePanic(${id})">Marcar como resuelta</button>
          </div>` : ''}
      </div>`;
    }).join('');
  } catch (err) {
    container.innerHTML = `<div class="empty-mod" style="color:var(--uni-red)">Error al cargar alertas: ${escHtml(err.message || '')}</div>`;
  }
}

async function acknowledgePanic(id) {
  try {
    await apiFetch(`moderation/panic/${id}/acknowledge`, { method: 'PATCH' });
    showToast('Alerta atendida. El equipo de seguridad está respondiendo.', 'info');
    loadPanicAlerts();
    loadStats();
  } catch (e) { showToast(e.message || 'Error', 'error'); }
}

async function resolvePanic(id) {
  try {
    await apiFetch(`moderation/panic/${id}/resolve`, { method: 'PATCH' });
    showToast('Alerta marcada como resuelta', 'success');
    loadPanicAlerts();
    loadStats();
  } catch (e) { showToast(e.message || 'Error', 'error'); }
}

/* ====== MOD LOG ====== */
async function loadModLog() {
  const container = document.getElementById('mod-log-list');
  container.innerHTML = '<div class="loading-placeholder">Cargando historial...</div>';
  try {
    const res = await apiFetch('moderation/log?limit=50');
    const log = res?.data?.log || [];
    if (!log.length) {
      container.innerHTML = '<div class="empty-mod">Sin acciones registradas</div>';
      return;
    }
    const actionLabels = {
      remove_post:'Eliminó publicación', remove_comment:'Eliminó comentario',
      suspend_user:'Suspendió usuario', warn_user:'Advirtió usuario',
      dismiss_report:'Desestimó reporte', restore_post:'Restauró publicación'
    };
    container.innerHTML = log.map(l => `
      <div class="report-item">
        <div class="report-header">
          <strong>${escHtml(l.moderator_name||'Sistema')}</strong>
          <span class="badge badge-blue">${escHtml(actionLabels[l.action] || l.action)}${l.target_id ? ` #${Number(l.target_id)}` : ''}</span>
          <span class="text-muted" style="font-size:12px">${timeAgo(l.created_at)}</span>
        </div>
        ${l.reason ? `<p style="font-size:13px;color:var(--text-secondary);margin-top:4px">${escHtml(l.reason)}</p>` : ''}
      </div>`).join('');
  } catch (err) {
    container.innerHTML = `<div class="empty-mod" style="color:var(--uni-red)">Error: ${escHtml(err.message || '')}</div>`;
  }
}
