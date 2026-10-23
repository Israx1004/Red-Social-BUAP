/**
 * UniLink — utils.js
 * BASE_PATH apunta a /RedSocial_BUAP
 */

const UL_BASE = '/RedSocial_BUAP';
// Servidor de tiempo real (Node/Socket.io, opcional). Se puede cambiar con window.UL_WS_URL antes de cargar utils.js
const UL_WS_URL = window.UL_WS_URL || `${location.protocol}//${location.hostname}:3001`;

/* ── API FETCH ───────────────────────────────────────────────── */
async function apiFetch(endpoint, options = {}) {
  const token = typeof UL_TOKEN !== 'undefined' ? UL_TOKEN : (sessionStorage.getItem('ul_token') || '');

  const defaults = {
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${token}`,
      'X-Requested-With': 'XMLHttpRequest'
    }
  };

  const config = {
    ...defaults,
    ...options,
    headers: { ...defaults.headers, ...(options.headers || {}) }
  };

  if (config.body instanceof FormData) {
    delete config.headers['Content-Type'];
  }

  const [pathOnly, queryString] = endpoint.split('?');
  const service = pathOnly.split('/')[0];
  const querySuffix = queryString ? `&${queryString}` : '';
  const url = `${UL_BASE}/backend/api-gateway/index.php?service=${encodeURIComponent(service)}&path=${encodeURIComponent(pathOnly)}${querySuffix}`;

  const res  = await fetch(url, config);
  const text = await res.text();
  let data;
  try {
    data = text ? JSON.parse(text) : {};
  } catch {
    console.error('[apiFetch] Respuesta no JSON de', endpoint, text.slice(0, 500));
    throw new Error(`Error del servidor (HTTP ${res.status})`);
  }

  if (!res.ok || data.success === false) {
    if (res.status === 401) {
      window.location.href = `${UL_BASE}/index.php?expired=1`;
      throw new Error('Sesión expirada');
    }
    throw new Error(data.message || `HTTP ${res.status}`);
  }

  return data;
}

/* ── TOAST ───────────────────────────────────────────────────── */
function showToast(message, type = 'info', duration = 4000) {
  let container = document.getElementById('toast-container');
  if (!container) {
    container = document.createElement('div');
    container.id = 'toast-container';
    document.body.appendChild(container);
  }
  const toast = document.createElement('div');
  toast.className = `toast toast-${type}`;
  const icons = { success:'✓', error:'✕', info:'ℹ' };
  toast.innerHTML = `<span>${icons[type]||'ℹ'}</span><span>${escHtml(message)}</span>`;
  container.appendChild(toast);
  setTimeout(() => {
    toast.style.animation = 'slideInRight 300ms ease reverse forwards';
    setTimeout(() => toast.remove(), 300);
  }, duration);
}

/* ── HELPERS ─────────────────────────────────────────────────── */
function escHtml(str) {
  if (str === null || str === undefined) return '';
  return String(str)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;')
    .replace(/'/g,'&#039;');
}

/** Primera letra (en mayúscula) de un nombre, segura para nombres vacíos. */
function initialOf(name) {
  const s = String(name || '').trim();
  return s ? s[0].toUpperCase() : '?';
}

function timeAgo(dateStr) {
  const date = new Date(dateStr);
  const diff = Math.floor((new Date() - date) / 1000);
  if (diff < 60)    return 'ahora';
  if (diff < 3600)  return `hace ${Math.floor(diff/60)}min`;
  if (diff < 86400) return `hace ${Math.floor(diff/3600)}h`;
  if (diff < 604800)return `hace ${Math.floor(diff/86400)}d`;
  return date.toLocaleDateString('es-MX', { day:'numeric', month:'short' });
}

function togglePassword(inputId, btn) {
  const input = document.getElementById(inputId);
  if (!input) return;
  input.type      = input.type === 'password' ? 'text' : 'password';
  btn.textContent = input.type === 'password' ? '👁' : '🙈';
}

function formatMXN(amount) {
  return new Intl.NumberFormat('es-MX', { style:'currency', currency:'MXN' }).format(amount);
}

function debounce(fn, delay) {
  let timer;
  return (...args) => { clearTimeout(timer); timer = setTimeout(() => fn(...args), delay); };
}

function formToJSON(form) {
  const data = {};
  new FormData(form).forEach((value, key) => {
    if (data[key] !== undefined) {
      if (!Array.isArray(data[key])) data[key] = [data[key]];
      data[key].push(value);
    } else {
      data[key] = value;
    }
  });
  return data;
}

function logout() {
  if (confirm('¿Cerrar sesión?')) {
    window.location.href = `${UL_BASE}/backend/api-gateway/auth.php?action=logout`;
  }
}

async function triggerPanic() {
  if (!confirm('⚠️ ¿Confirmas que necesitas asistencia de seguridad del campus?')) return;

  // Intenta obtener la ubicación (máx. 4 s); si no se puede, envía la alerta igual
  const coords = await new Promise(resolve => {
    if (!navigator.geolocation) return resolve(null);
    navigator.geolocation.getCurrentPosition(
      pos => resolve({ lat: pos.coords.latitude, lng: pos.coords.longitude }),
      () => resolve(null),
      { timeout: 4000, maximumAge: 60000 }
    );
  });

  try {
    await apiFetch('moderation/panic', {
      method: 'POST',
      body: JSON.stringify({ timestamp: new Date().toISOString(), ...(coords || {}) })
    });
    alert('🚨 Alerta enviada. Los administradores ya fueron notificados.');
  } catch (err) {
    alert('No se pudo enviar la alerta: ' + (err.message || 'error de conexión') + '\nSi es una emergencia, llama a seguridad del campus.');
  }
}

/* ── BÚSQUEDA GLOBAL (topbar, todas las páginas) ─────────────── */
function setupSearch() {
  const input   = document.getElementById('globalSearch');
  const results = document.getElementById('searchResults');
  if (!input || !results || input.dataset.ready) return;
  input.dataset.ready = '1';
  let searchTimeout;

  input.addEventListener('focus', () => {
    if (input.value.trim().length >= 2 && results.innerHTML) results.classList.add('show');
  });
  input.addEventListener('input', () => {
    clearTimeout(searchTimeout);
    if (input.value.trim().length < 2) { results.classList.remove('show'); return; }
    searchTimeout = setTimeout(() => doSearch(input.value.trim()), 400);
  });
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') results.classList.remove('show');
  });
  document.addEventListener('click', (e) => {
    if (!input.contains(e.target) && !results.contains(e.target)) results.classList.remove('show');
  });
}

async function doSearch(q) {
  const results = document.getElementById('searchResults');
  results.innerHTML = '<div style="padding:16px;font-size:13px;color:var(--text-muted)">Buscando...</div>';
  results.classList.add('show');

  const roleLabels = { student: 'Estudiante', professor: 'Profesor', staff: 'Personal', moderator: 'Moderador', admin: 'Administrador' };
  try {
    const res  = await apiFetch(`users/search?q=${encodeURIComponent(q)}&limit=8`);
    const list = res?.data?.results || [];
    if (!list.length) {
      results.innerHTML = '<div style="padding:16px;font-size:13px;color:var(--text-muted);text-align:center">Sin resultados</div>';
      return;
    }
    results.innerHTML = list.map(r => `
      <div class="search-result-item" onclick="window.location.href='${UL_BASE}/frontend/pages/profile.php?id=${Number(r.id)}'">
        <div class="avatar avatar-sm">${escHtml(initialOf(r.name))}</div>
        <div>
          <div style="font-size:14px;font-weight:600">${escHtml(r.name)}</div>
          <div class="search-result-meta">${escHtml(roleLabels[r.subtitle] || r.subtitle || '')}</div>
        </div>
        <span class="badge badge-blue" style="margin-left:auto">Usuario</span>
      </div>`).join('');
  } catch {
    results.innerHTML = '<div style="padding:16px;font-size:13px;color:var(--uni-red)">Error en búsqueda</div>';
  }
}

document.addEventListener('DOMContentLoaded', setupSearch);

const Storage = {
  get: (key, fallback = null) => {
    try { return JSON.parse(localStorage.getItem(key)) ?? fallback; } catch { return fallback; }
  },
  set: (key, value) => {
    try { localStorage.setItem(key, JSON.stringify(value)); } catch {}
  },
  remove: (key) => localStorage.removeItem(key)
};
