/**
 * UniLink — messages.js
 * Chat 1 a 1: lista de conversaciones, hilo, envío y actualización automática.
 */

let peerId = 0;            // persona con la que se está chateando
let peerInfo = null;
let lastMsgId = 0;         // último mensaje pintado (para pedir solo los nuevos)
let pendingContext = '';   // producto/objeto desde el que se contactó (se adjunta al primer mensaje)

document.addEventListener('DOMContentLoaded', () => {
  const qs = new URLSearchParams(location.search);
  const to = parseInt(qs.get('to') || '0', 10);
  const ctx = qs.get('ctx') || '';
  const kind = qs.get('kind') || '';

  loadConversations();
  document.getElementById('chat-form').addEventListener('submit', sendMessage);

  const input = document.getElementById('chat-input');
  input.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); document.getElementById('chat-form').requestSubmit(); }
  });
  input.addEventListener('input', () => { input.style.height = 'auto'; input.style.height = Math.min(input.scrollHeight, 110) + 'px'; });

  if (to) {
    pendingContext = ctx;
    openChat(to, ctx ? (kind === 'lost'
      ? `Hola, vi tu publicación en Objetos perdidos: "${ctx}". `
      : `Hola, me interesa tu publicación: "${ctx}". `) : '');
  }

  // Actualización automática (sin WebSocket): cada 4 s
  setInterval(() => { if (peerId) pollThread(); }, 4000);
  setInterval(loadConversations, 10000);
});

/* ---------- Conversaciones ---------- */
async function loadConversations() {
  const box = document.getElementById('chat-convs');
  try {
    const res = await apiFetch('messages/conversations');
    const convs = res?.data?.conversations || [];
    if (!convs.length) {
      box.innerHTML = '<div class="chat-empty">Aún no tienes conversaciones.</div>';
      return;
    }
    box.innerHTML = convs.map(c => `
      <div class="chat-conv ${c.user_id === peerId ? 'active' : ''}" onclick="openChat(${c.user_id})">
        ${avatarHtml(c.name, c.avatar)}
        <div class="chat-conv-body">
          <div class="chat-conv-name">${escHtml(c.name)}</div>
          <div class="chat-conv-last">${c.last_sender === UL_USER.id ? 'Tú: ' : ''}${escHtml(c.last_body)}</div>
        </div>
        ${c.unread > 0 ? `<span class="chat-unread">${c.unread}</span>` : ''}
      </div>`).join('');
  } catch (err) {
    box.innerHTML = '<div class="chat-empty">No se pudieron cargar las conversaciones.</div>';
  }
}

function avatarHtml(name, avatar) {
  const initials = (name || '?').split(' ').filter(Boolean).map(w => w[0]).join('').substring(0, 2).toUpperCase();
  const inner = avatar
    ? `<img src="${escHtml(avatar)}" alt="" style="width:100%;height:100%;object-fit:cover" onerror="this.parentNode.textContent='${escHtml(initials)}'">`
    : escHtml(initials);
  return `<div class="avatar avatar-sm" style="overflow:hidden;flex-shrink:0">${inner}</div>`;
}

/* ---------- Hilo ---------- */
async function openChat(userId, prefill = '') {
  peerId = userId;
  lastMsgId = 0;
  document.getElementById('chat-wrap').classList.add('has-peer');
  document.getElementById('chat-placeholder').style.display = 'none';
  document.getElementById('chat-panel').style.display = 'flex';
  document.getElementById('chat-msgs').innerHTML = '';

  try {
    const res = await apiFetch(`messages/with/${userId}`);
    peerInfo = res.data.peer;
    document.getElementById('chat-peer-name').textContent = peerInfo.name;
    document.getElementById('chat-peer-name').href = `${UL_BASE}/frontend/pages/profile.php?id=${peerInfo.user_id}`;
    const av = document.getElementById('chat-peer-avatar');
    const ini = (peerInfo.name || '?').split(' ').filter(Boolean).map(w => w[0]).join('').substring(0, 2).toUpperCase();
    av.textContent = ini;
    if (peerInfo.avatar) {
      const img = new Image();
      img.alt = ''; img.style.cssText = 'width:100%;height:100%;object-fit:cover';
      img.onload = () => { av.textContent = ''; av.appendChild(img); };
      img.src = peerInfo.avatar;
    }

    const ctxEl = document.getElementById('chat-ctx');
    if (pendingContext) { ctxEl.textContent = '📦 Sobre: ' + pendingContext; ctxEl.style.display = 'block'; }
    else ctxEl.style.display = 'none';

    renderMessages(res.data.messages, true);
    if (prefill) document.getElementById('chat-input').value = prefill;
    document.getElementById('chat-input').focus();
    loadConversations();
    refreshMessageBadge?.();
  } catch (err) {
    showToast(err.message || 'No se pudo abrir la conversación', 'error');
    closeChat();
  }
}

function closeChat() {
  peerId = 0;
  document.getElementById('chat-wrap').classList.remove('has-peer');
  document.getElementById('chat-panel').style.display = 'none';
  document.getElementById('chat-placeholder').style.display = '';
  history.replaceState(null, '', location.pathname);
}

async function pollThread() {
  const id = peerId;
  try {
    const res = await apiFetch(`messages/with/${id}?after=${lastMsgId}`);
    if (id !== peerId) return;
    if (res.data.messages.length) {
      renderMessages(res.data.messages, false);
      loadConversations();
      refreshMessageBadge?.();
    }
  } catch { /* se reintenta en el siguiente ciclo */ }
}

function fmtTime(s) {
  const d = new Date(String(s).replace(' ', 'T'));
  return isNaN(d) ? '' : d.toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' });
}

function renderMessages(msgs, replace) {
  const box = document.getElementById('chat-msgs');
  if (replace) box.innerHTML = '';
  const nearBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 120;

  msgs.forEach(m => {
    if (m.message_id <= lastMsgId && !replace) return;
    lastMsgId = Math.max(lastMsgId, m.message_id);
    const mine = m.sender_id === UL_USER.id;
    const el = document.createElement('div');
    el.className = 'msg ' + (mine ? 'mine' : 'theirs');
    el.dataset.id = m.message_id;
    el.innerHTML =
      (m.context ? `<div class="msg-ctx">📦 ${escHtml(m.context)}</div>` : '') +
      escHtml(m.body) +
      `<div class="msg-meta">${fmtTime(m.created_at)}${mine ? ' · ✓ Enviado' : ''}</div>`;
    box.appendChild(el);
  });

  if (replace || nearBottom) box.scrollTop = box.scrollHeight;
}

/* ---------- Enviar ---------- */
async function sendMessage(e) {
  e.preventDefault();
  const input = document.getElementById('chat-input');
  const btn = document.getElementById('chat-send');
  const body = input.value.trim();
  if (!body || !peerId) return;

  btn.disabled = true;
  try {
    const res = await apiFetch('messages/send', {
      method: 'POST',
      body: JSON.stringify({ to: peerId, body, context: pendingContext })
    });
    input.value = '';
    input.style.height = 'auto';
    pendingContext = '';                      // el contexto solo se adjunta al primer mensaje
    renderMessages([res.data.message], false);
    const box = document.getElementById('chat-msgs');
    box.scrollTop = box.scrollHeight;
    showToast('Mensaje enviado ✓', 'success', 2000);
    loadConversations();
  } catch (err) {
    showToast(err.message || 'No se pudo enviar el mensaje', 'error');
  } finally {
    btn.disabled = false;
    input.focus();
  }
}
