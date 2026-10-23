/**
 * UniLink — profile.js
 * Load user profile, own posts, follow/unfollow, edit profile
 */

let profilePostsPage = 1;
let profileHasMore   = true;
let profileLoading   = false;

document.addEventListener('DOMContentLoaded', () => {
  loadProfile();
  loadProfileGroups();
  loadProfilePosts();
});

/* ====== GRUPOS DEL PERFIL ====== */
async function loadProfileGroups() {
  const box = document.getElementById('profile-groups');
  if (!box) return;
  try {
    const res = await apiFetch(`users/${PROFILE_ID}/groups`);
    const groups = res?.data?.groups || [];
    const typeLabels = { nrc:'Materia NRC', faculty:'Facultad', career:'Carrera', club:'Club', study:'Estudio', general:'General' };
    box.innerHTML = `
      <h3 class="profile-groups-title">👥 Grupos (${groups.length})</h3>
      ${groups.length ? groups.map(g => `
        <a class="profile-group-item" href="${UL_BASE}/frontend/pages/group-detail.php?id=${Number(g.group_id)}">
          <span class="profile-group-icon">${escHtml(g.icon || '👥')}</span>
          <span class="profile-group-info">
            <span class="profile-group-name">${escHtml(g.name)}</span>
            <span class="profile-group-meta">${escHtml(typeLabels[g.type] || g.type)}${g.nrc_code ? ' · NRC ' + escHtml(g.nrc_code) : ''} · ${Number(g.member_count) || 0} miembros</span>
          </span>
        </a>`).join('') : '<p class="profile-groups-empty">Aún no pertenece a ningún grupo visible.</p>'}`;
  } catch {
    box.innerHTML = '';
  }
}

/* ====== LOAD PROFILE ====== */
async function loadProfile() {
  try {
    const res  = await apiFetch(`users/${PROFILE_ID}`);
    const user = res?.data?.user;
    if (!user) throw new Error('Usuario no encontrado');
    renderProfile(user);

    if (IS_OWN_PROFILE) {
      // Pre-fill edit form
      document.getElementById('edit-bio').value          = user.bio || '';
      document.getElementById('edit-semester').value     = user.semester || 1;
      document.getElementById('edit-phone').value        = user.phone || '';
      document.getElementById('edit-show-phone').checked = !!user.show_phone;
    }
  } catch (err) {
    document.getElementById('profile-info-section').innerHTML =
      '<p style="color:var(--uni-red);padding:20px">No se pudo cargar el perfil: ' + escHtml(err.message || '') + '</p>';
  }
}

function renderProfile(u) {
  const initials = (u.first_name?.[0] || '') + (u.last_name?.[0] || '');
  const avatarEl = document.getElementById('profile-avatar-el');
  if (u.avatar) {
    avatarEl.innerHTML = `<img src="${escHtml(u.avatar)}" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%">`;
  } else {
    avatarEl.textContent = initials.toUpperCase();
  }

  const roleLabels = {
    student:'Estudiante', professor:'Profesor', admin:'Administrador',
    moderator:'Moderador', staff:'Administrativo'
  };
  const roleBadgeClass = { admin:'badge-red', professor:'badge-orange', moderator:'badge-blue' };

  const starsHtml = Array.from({length:5}, (_,i) =>
    `<span style="color:${i < Math.round((u.reputation_score||0)/20) ? '#F59E0B' : 'var(--gray-300)'}">★</span>`
  ).join('');

  document.getElementById('profile-info-section').innerHTML = `
    <div class="profile-name">${escHtml(u.name || u.first_name+' '+u.last_name)}</div>
    <div class="profile-role-badge">
      <span class="badge ${roleBadgeClass[u.role] || 'badge-blue'}">${escHtml(roleLabels[u.role] || u.role)}</span>
    </div>
    <div class="profile-faculty-info">
      ${u.faculty_name ? `🏛 ${escHtml(u.faculty_name)}` : ''}
      ${u.career_name  ? ` · 📚 ${escHtml(u.career_name)}` : ''}
      ${u.semester     ? ` · ${Number(u.semester)}° semestre` : ''}
    </div>
    ${u.bio ? `<div class="profile-bio">${escHtml(u.bio)}</div>` : ''}

    <div class="profile-stats">
      <div class="profile-stat">
        <span class="profile-stat-num">${u.posts_count || 0}</span>
        <span class="profile-stat-label">Publicaciones</span>
      </div>
      <div class="profile-stat">
        <span class="profile-stat-num">${u.followers_count || 0}</span>
        <span class="profile-stat-label">Seguidores</span>
      </div>
      <div class="profile-stat">
        <span class="profile-stat-num">${u.following_count || 0}</span>
        <span class="profile-stat-label">Siguiendo</span>
      </div>
    </div>

    <div class="profile-actions">
      ${IS_OWN_PROFILE
        ? `<button class="btn-primary" onclick="document.getElementById('editProfileModal').classList.remove('hidden')">
             ✏️ Editar perfil
           </button>`
        : `<button class="btn-primary" id="follow-btn" onclick="toggleFollow(${Number(u.user_id)}, ${Number(u.is_following) === 1})">
             ${Number(u.is_following) === 1 ? '✓ Siguiendo' : '+ Seguir'}
           </button>
           <button class="btn-secondary" onclick="contactUser(${Number(u.user_id)})">💬 Enviar mensaje</button>`
      }
    </div>

    ${u.reputation_score > 0 ? `
      <div class="profile-reputation">
        <span class="rep-stars">${starsHtml}</span>
        <span style="font-weight:600">${Math.round(u.reputation_score / 20 * 10) / 10} / 5</span>
        <span style="color:var(--text-muted)">Reputación Marketplace</span>
      </div>` : ''}

    ${u.email ? `<div style="margin-top:12px;font-size:13px;color:var(--text-secondary)">✉️ ${escHtml(u.email)}</div>` : ''}
    ${u.phone ? `
      <div style="margin-top:6px;font-size:13px;color:var(--text-secondary)">
        📱 ${escHtml(u.phone)}
      </div>` : ''}`;

  document.getElementById('posts-section-title').textContent =
    IS_OWN_PROFILE ? 'Mis publicaciones' : `Publicaciones de ${u.first_name}`;
}

/* ====== LOAD PROFILE POSTS ====== */
async function loadProfilePosts() {
  if (!profileHasMore || profileLoading) return;
  profileLoading = true;
  document.getElementById('profile-feed-loader').classList.remove('hidden');
  try {
    const res    = await apiFetch(`feed/posts?author=${PROFILE_ID}&page=${profilePostsPage}&limit=10`);
    const posts  = Array.isArray(res?.data) ? res.data : [];
    const meta   = res.meta || {};
    const container = document.getElementById('profile-feed');

    if (profilePostsPage === 1 && !posts.length) {
      container.innerHTML = `
        <div class="card card-body" style="text-align:center;padding:40px;color:var(--text-muted)">
          <p style="font-size:32px;margin-bottom:8px">✍️</p>
          <p>${IS_OWN_PROFILE ? 'Aún no has publicado nada.' : 'Sin publicaciones aún.'}</p>
        </div>`;
      profileHasMore = false;
      return;
    }

    posts.forEach(post => {
      const el = renderPost(post); // from feed.js (loaded globally via utils)
      container.appendChild(el);
    });

    profileHasMore = meta.has_more || false;
    profilePostsPage++;
  } catch (err) {
    if (profilePostsPage === 1) {
      document.getElementById('profile-feed').innerHTML =
        '<div class="card card-body" style="text-align:center;color:var(--text-muted)">No se pudieron cargar las publicaciones.</div>';
    }
    profileHasMore = false;
  }
  finally {
    profileLoading = false;
    document.getElementById('profile-feed-loader').classList.add('hidden');
  }
}

/* ====== FOLLOW / UNFOLLOW ====== */
async function toggleFollow(userId, isFollowing) {
  const btn = document.getElementById('follow-btn');
  try {
    if (isFollowing) {
      await apiFetch(`users/${userId}/unfollow`, { method: 'DELETE' });
      btn.textContent = '+ Seguir';
      btn.onclick = () => toggleFollow(userId, false);
      updateFollowerCount(-1);
    } else {
      await apiFetch(`users/${userId}/follow`, { method: 'POST' });
      btn.textContent = '✓ Siguiendo';
      btn.onclick = () => toggleFollow(userId, true);
      updateFollowerCount(+1);
    }
  } catch (e) {
    showToast(e.message || 'Error', 'error');
  }
}

function updateFollowerCount(delta) {
  const el = document.querySelectorAll('.profile-stat-num')[1];
  if (el) el.textContent = Math.max(0, (parseInt(el.textContent, 10) || 0) + delta);
}

/* ====== EDIT PROFILE ====== */
document.getElementById('edit-profile-form')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const fd = formToJSON(e.target);
  fd.show_phone = !!document.getElementById('edit-show-phone').checked;
  try {
    await apiFetch('users/me', { method: 'PUT', body: JSON.stringify(fd) });
    closeModal('editProfileModal');
    showToast('Perfil actualizado ✓', 'success');
    loadProfile();
  } catch (err) {
    showToast(err.message || 'Error al guardar', 'error');
  }
});

/* ====== AVATAR UPLOAD ====== */
async function uploadAvatar(input) {
  const file = input.files[0];
  if (!file) return;
  const fd = new FormData();
  fd.append('avatar', file);
  try {
    await apiFetch('users/me/avatar', { method: 'POST', body: fd });
    showToast('Foto actualizada ✓', 'success');
    // Refresca la sesión PHP para que la foto también aparezca en la barra lateral
    await fetch(`${UL_BASE}/backend/api-gateway/auth.php?action=set_session`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'set_session', token: UL_TOKEN })
    }).catch(() => {});
    setTimeout(() => location.reload(), 600);
  } catch (err) {
    showToast(err.message || 'Error al subir foto', 'error');
  }
}

function contactUser(userId) {
  window.location.href = `${UL_BASE}/frontend/pages/messages.php?to=${userId}`;
}

function closeModal(id) {
  document.getElementById(id)?.classList.add('hidden');
  document.body.style.overflow = '';
}

/* Lazy load more posts on scroll */
window.addEventListener('scroll', () => {
  if ((window.innerHeight + window.scrollY) >= (document.body.offsetHeight - 400)) {
    loadProfilePosts();
  }
});
