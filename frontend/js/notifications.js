/**
 * UniLink — notifications.js
 * Notification bell, panel, mark-as-read
 */

document.addEventListener('DOMContentLoaded', () => {
  loadNotifications();
  setupNotifPanel();
  refreshMessageBadge();
  // Refresco automático: el admin ve las alertas de pánico sin recargar la página
  setInterval(() => { loadNotifications(); refreshMessageBadge(); }, 15000);
});

async function refreshMessageBadge() {
  const badge = document.getElementById('badge-messages');
  if (!badge) return;
  try {
    const res = await apiFetch('messages/unread-count');
    const n = res?.data?.unread || 0;
    badge.textContent = n > 99 ? '99+' : n;
    badge.style.display = n > 0 ? '' : 'none';
  } catch { /* silencioso */ }
}

async function loadNotifications() {
  try {
    const res = await apiFetch('users/notifications?limit=15');
    const { notifications, unread_count } = res.data ?? res;
    window.__ulUnread = unread_count;

    // Dot indicator
    const dot = document.getElementById('notifDot');
    if (dot) dot.classList.toggle('show', unread_count > 0);

    // Render list
    const list = document.getElementById('notif-list');
    if (!list) return;

    if (!notifications?.length) {
      list.innerHTML = '<p style="padding:20px;text-align:center;font-size:14px;color:var(--text-muted)">Sin notificaciones</p>';
      return;
    }

    list.innerHTML = notifications.map(n => `
      <div class="notif-item ${Number(n.is_read) ? '' : 'unread'}"
           data-id="${Number(n.notif_id)}" data-link="${escHtml(n.link || '')}"
           onclick="handleNotifClick(Number(this.dataset.id), this.dataset.link)">
        <div class="avatar avatar-sm" style="flex-shrink:0">
          ${notifIcon(n.type)}
        </div>
        <div>
          <div class="notif-text">${escHtml(n.message)}</div>
          <div class="notif-time">${timeAgo(n.created_at)}</div>
        </div>
      </div>`
    ).join('');
  } catch { /* silent */ }
}

function notifIcon(type) {
  const icons = {
    like:'❤️', comment:'💬', follow:'👤', mention:'@', 
    group_invite:'👥', marketplace:'🛒', moderation:'🛡', 
    system:'🔔', event:'📅'
  };
  return icons[type] || '🔔';
}

async function handleNotifClick(id, link) {
  // Mark as read
  apiFetch(`users/notifications/${id}/read`, { method: 'PATCH' }).catch(() => {});
  document.getElementById('notifPanel')?.classList.remove('show');
  // Solo enlaces internos (/...) o http(s); nunca javascript:
  if (link && (/^\/(?!\/)/.test(link) || /^https?:\/\//i.test(link))) window.location.href = link;
}

async function markAllRead() {
  try {
    await apiFetch('users/notifications/read-all', { method: 'PATCH' });
    document.querySelectorAll('.notif-item.unread').forEach(el => el.classList.remove('unread'));
    document.getElementById('notifDot')?.classList.remove('show');
    showToast('Notificaciones marcadas como leídas', 'success');
  } catch (err) {
    showToast(err.message || 'No se pudieron marcar las notificaciones', 'error');
  }
}

function setupNotifPanel() {
  const btn   = document.getElementById('notifBtn');
  const panel = document.getElementById('notifPanel');
  if (!btn || !panel) return;

  btn.addEventListener('click', (e) => {
    e.stopPropagation();
    panel.classList.toggle('show');
    if (panel.classList.contains('show')) loadNotifications();
  });

  document.addEventListener('click', () => panel.classList.remove('show'));
  panel.addEventListener('click', e => e.stopPropagation());
}
