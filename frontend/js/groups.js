/**
 * UniLink — groups.js
 * Grupos NRC, explorar, crear, unirse
 */

let groupTab = 'mine';
let groupSearchTimer;
const groupFilters = { q: '', type: '' };

document.addEventListener('DOMContentLoaded', () => {
  // En group-detail.php solo se reutilizan joinGroup/leaveGroup
  if (!document.getElementById('groups-grid')) return;
  loadGroups();
  setupCreateForm();
});

/* ====== TABS ====== */
function switchGroupTab(tab) {
  groupTab = tab;
  document.querySelectorAll('.mod-tab').forEach(t => {
    t.classList.toggle('active', t.dataset.tab === tab);
  });
  document.getElementById('tab-groups-explore').classList.toggle('hidden', tab !== 'explore');
  document.getElementById('tab-groups-nrc').classList.toggle('hidden', tab !== 'nrc');

  if (tab === 'nrc') {
    document.getElementById('groups-grid').innerHTML = '';
    document.getElementById('groups-empty').classList.add('hidden');
    return;
  }
  loadGroups();
}

/* ====== LOAD GROUPS ====== */
async function loadGroups() {
  const grid  = document.getElementById('groups-grid');
  const empty = document.getElementById('groups-empty');
  groupFilters.type = document.getElementById('groupType')?.value || '';

  grid.innerHTML = Array(6).fill('<div class="skeleton-card" style="height:160px"></div>').join('');
  empty.classList.add('hidden');

  try {
    const params = new URLSearchParams({
      limit: 24,
      ...(groupFilters.q    ? { q: groupFilters.q }       : {}),
      ...(groupFilters.type ? { type: groupFilters.type } : {}),
    });

    const endpoint = groupTab === 'mine'
      ? 'academic/my-groups?limit=20'
      : `academic/groups?${params}`;

    const res = await apiFetch(endpoint);
    // my-groups → data.groups ; groups (paginado) → data es el arreglo
    const groups = Array.isArray(res?.data) ? res.data : (res?.data?.groups || []);

    grid.innerHTML = '';
    if (!groups.length) {
      empty.classList.remove('hidden');
      return;
    }
    groups.forEach(g => grid.appendChild(renderGroupCard(g)));
  } catch (e) {
    grid.innerHTML = '';
    showToast(e.message || 'Error al cargar grupos', 'error');
  }
}

/* ====== RENDER GROUP CARD ====== */
const GROUP_TYPE_LABELS = {
  nrc: 'Materia NRC', faculty: 'Facultad', career: 'Carrera',
  club: 'Club', study: 'Grupo de estudio', general: 'General'
};

function renderGroupCard(g) {
  const el = document.createElement('div');
  el.className = 'group-card';

  const isMember = Number(g.is_member) === 1 || !!g.member_role;
  const isAdmin  = g.member_role === 'admin';
  const id       = Number(g.group_id);

  el.innerHTML = `
    <div class="group-card-header">
      <div class="group-card-icon">${escHtml(g.icon || '👥')}</div>
      <div style="flex:1;min-width:0">
        <div class="group-card-name">${escHtml(g.name)}</div>
        <div class="group-card-type">${escHtml(GROUP_TYPE_LABELS[g.type] || g.type || '')}${g.nrc_code ? ` · NRC ${escHtml(g.nrc_code)}` : ''}${Number(g.is_private) ? ' · 🔒 Privado' : ''}</div>
      </div>
    </div>
    ${g.description ? `<div class="group-card-desc">${escHtml(g.description)}</div>` : ''}
    <div class="group-card-footer">
      <div class="group-card-count">👥 ${Number(g.member_count) || 0} miembros</div>
      ${isMember
        ? `${isAdmin ? '' : `<button class="btn-leave" onclick="event.stopPropagation();leaveGroup(${id})">Salir</button>`}
           <button class="btn-joined">✓ ${isAdmin ? 'Admin' : 'Unido'}</button>`
        : `<button class="btn-join" onclick="event.stopPropagation();joinGroup(${id}, this)">Unirse</button>`
      }
    </div>`;

  el.onclick = () => openGroupDetail(id);
  return el;
}

/* ====== JOIN / LEAVE ====== */
async function joinGroup(id, btn) {
  if (btn) { btn.disabled = true; btn.textContent = 'Uniéndote...'; }
  try {
    await apiFetch(`academic/groups/${id}/join`, { method: 'POST' });
    showToast('Te uniste al grupo 🎉', 'success');
    if (typeof onGroupMembershipChange === 'function') onGroupMembershipChange();
    else loadGroups();
  } catch (e) {
    showToast(e.message || 'Error al unirse', 'error');
    if (btn) { btn.disabled = false; btn.textContent = 'Unirse'; }
  }
}

async function leaveGroup(id) {
  if (!confirm('¿Salir de este grupo?')) return;
  try {
    await apiFetch(`academic/groups/${id}/leave`, { method: 'DELETE' });
    showToast('Saliste del grupo', 'info');
    if (typeof onGroupMembershipChange === 'function') onGroupMembershipChange();
    else loadGroups();
  } catch (e) {
    showToast(e.message || 'Error al salir', 'error');
  }
}

/* ====== JOIN BY NRC ====== */
async function joinByNRC() {
  const nrc = document.getElementById('nrc-input').value.trim();
  const result = document.getElementById('nrc-result');
  if (!nrc) { result.innerHTML = '<p style="color:var(--uni-red);font-size:13px">Ingresa un código NRC.</p>'; return; }

  result.innerHTML = '<p style="font-size:13px;color:var(--text-muted)">Buscando...</p>';

  try {
    const res = await apiFetch(`academic/groups?q=${encodeURIComponent(nrc)}&type=nrc`);
    const groups = Array.isArray(res?.data) ? res.data : [];

    if (!groups.length) {
      result.innerHTML = `<p style="color:var(--uni-red);font-size:13px">No se encontró ningún grupo con NRC "${escHtml(nrc)}". Puede que aún no exista.</p>`;
      return;
    }

    const g = groups.find(x => x.nrc_code === nrc) || groups[0];
    const isMember = Number(g.is_member) === 1;
    result.innerHTML = `
      <div class="group-card" style="cursor:default;margin-top:8px">
        <div class="group-card-header">
          <div class="group-card-icon">${escHtml(g.icon || '📚')}</div>
          <div>
            <div class="group-card-name">${escHtml(g.name)}</div>
            <div class="group-card-type">NRC: ${escHtml(g.nrc_code || nrc)} · ${Number(g.member_count) || 0} miembros</div>
          </div>
        </div>
        <div class="modal-footer" style="padding-top:12px">
          ${isMember
            ? `<button class="btn-secondary" onclick="openGroupDetail(${Number(g.group_id)})">Ya eres miembro · Ver grupo</button>`
            : `<button class="btn-primary" onclick="joinGroup(${Number(g.group_id)}, this)">Unirme a este grupo</button>`}
        </div>
      </div>`;
  } catch {
    result.innerHTML = '<p style="color:var(--uni-red);font-size:13px">Error al buscar. Intenta de nuevo.</p>';
  }
}

/* ====== GROUP DETAIL ====== */
function openGroupDetail(id) {
  window.location.href = `${UL_BASE}/frontend/pages/group-detail.php?id=${Number(id)}`;
}

/* ====== SEARCH ====== */
function debounceGroupSearch(val) {
  clearTimeout(groupSearchTimer);
  groupSearchTimer = setTimeout(() => {
    groupFilters.q = val.trim();
    loadGroups();
  }, 400);
}

/* ====== CREATE GROUP ====== */
function openCreateGroupModal() {
  document.getElementById('createGroupModal').classList.remove('hidden');
  document.body.style.overflow = 'hidden';
}

function selectIcon(icon) {
  document.getElementById('selected-icon').value = icon;
  document.querySelectorAll('.icon-opt').forEach(b => b.classList.toggle('selected', b.dataset.icon === icon));
}

function setupCreateForm() {
  document.getElementById('create-group-form')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = e.target.querySelector('[type=submit]');
    btn.disabled = true;
    try {
      const res = await apiFetch('academic/groups', {
        method: 'POST',
        body: JSON.stringify(formToJSON(e.target))
      });
      closeModal('createGroupModal');
      e.target.reset();
      showToast('¡Grupo creado! 🎉', 'success');
      const gid = res?.data?.group_id;
      if (gid) openGroupDetail(gid); else loadGroups();
    } catch (err) {
      showToast(err.message || 'Error al crear grupo', 'error');
    } finally {
      btn.disabled = false;
    }
  });
}

function closeModal(id) {
  document.getElementById(id)?.classList.add('hidden');
  document.body.style.overflow = '';
}
