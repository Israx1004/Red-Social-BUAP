/**
 * UniLink — directory.js
 * Directorio de la comunidad: búsqueda, filtros y paginación
 */

const ROLE_LABELS = { student: 'Estudiante', professor: 'Profesor', staff: 'Administrativo', moderator: 'Moderador', admin: 'Admin' };
const ROLE_BADGE  = { student: 'badge-blue', professor: 'badge-green', staff: 'badge-orange', moderator: 'badge-gray', admin: 'badge-red' };
const DIR_LIMIT   = 12;
let dirPage = 1;

document.addEventListener('DOMContentLoaded', () => {
  const reload = () => { dirPage = 1; loadDirectory(); };
  document.getElementById('dir-q').addEventListener('input', debounce(reload, 350));
  document.getElementById('dir-faculty').addEventListener('change', reload);
  document.getElementById('dir-role').addEventListener('change', reload);
  loadDirectory();
});

async function loadDirectory() {
  const grid = document.getElementById('dir-grid');
  grid.innerHTML = Array(6).fill('<div class="dir-card" style="height:120px;opacity:.5"></div>').join('');

  const params = new URLSearchParams({ page: dirPage, limit: DIR_LIMIT });
  const q = document.getElementById('dir-q').value.trim();
  const faculty = document.getElementById('dir-faculty').value;
  const role = document.getElementById('dir-role').value;
  if (q) params.set('q', q);
  if (faculty) params.set('faculty_id', faculty);
  if (role) params.set('role', role);

  try {
    const res = await apiFetch(`users/directory?${params.toString()}`);
    const users = res?.data || [];
    const meta  = res?.meta || { total: users.length, pages: 1 };

    document.getElementById('dir-count').textContent =
      `${meta.total} ${meta.total === 1 ? 'persona' : 'personas'}`;

    if (!users.length) {
      grid.innerHTML = '<div class="dir-empty"><div style="font-size:42px">🔎</div><p>No se encontró a nadie con esos filtros.</p></div>';
    } else {
      grid.innerHTML = users.map(renderUserCard).join('');
    }
    renderPager(meta);
  } catch (err) {
    grid.innerHTML = '';
    document.getElementById('dir-pager').innerHTML = '';
    showToast(err.message || 'Error al cargar el directorio', 'error');
  }
}

function renderUserCard(u) {
  const name = u.name || 'Usuario';
  const initials = name.split(' ').filter(Boolean).map(w => w[0]).join('').substring(0, 2).toUpperCase();
  const roleLabel = ROLE_LABELS[u.role] || u.role;
  const lines = [];
  if (u.faculty_name) lines.push(`🏛 ${escHtml(u.faculty_name)}`);
  if (u.career_name)  lines.push(`🎓 ${escHtml(u.career_name)}${u.semester && u.role === 'student' ? ` · Sem. ${escHtml(String(u.semester))}` : ''}`);
  if (u.email)        lines.push(`✉️ <a href="mailto:${escHtml(u.email)}">${escHtml(u.email)}</a>`);
  if (u.phone)        lines.push(`📞 ${escHtml(u.phone)}`);

  return `
    <div class="dir-card">
      <div class="dir-card-top">
        <div class="avatar avatar-lg">${escHtml(initials)}</div>
        <div>
          <a class="dir-name" href="profile.php?id=${parseInt(u.user_id, 10)}">${escHtml(name)}</a><br>
          <span class="badge ${ROLE_BADGE[u.role] || 'badge-gray'}">${escHtml(roleLabel)}</span>
        </div>
      </div>
      <div class="dir-meta">${lines.join('<br>')}</div>
      ${u.bio ? `<div class="dir-bio">${escHtml(u.bio)}</div>` : ''}
    </div>`;
}

function renderPager(meta) {
  const pager = document.getElementById('dir-pager');
  const pages = meta.pages || 1;
  if (pages <= 1) { pager.innerHTML = ''; return; }
  pager.innerHTML = `
    <button class="btn-secondary" ${dirPage <= 1 ? 'disabled' : ''} onclick="goDirPage(${dirPage - 1})">‹ Anterior</button>
    <span>Página ${dirPage} de ${pages}</span>
    <button class="btn-secondary" ${dirPage >= pages ? 'disabled' : ''} onclick="goDirPage(${dirPage + 1})">Siguiente ›</button>`;
}

function goDirPage(p) {
  dirPage = p;
  loadDirectory();
  window.scrollTo({ top: 0, behavior: 'smooth' });
}
