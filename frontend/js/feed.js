/**
 * UniLink — feed.js
 * Feed loading, post creation, interactions
 */

let currentPage = 1;
let currentFilter = 'all';
let isLoading = false;
let hasMore = true;
const selectedTags = new Set();

/* ============ INIT ============ */
document.addEventListener('DOMContentLoaded', () => {
  // En otras páginas (p. ej. perfil) feed.js solo aporta renderPost(); no se inicializa el feed.
  if (document.getElementById('feed-container')) {
    loadFeed();
    loadWidgets();
    setupInfiniteScroll();
    setupFilterButtons();
    setupPostForm();
    setupTagButtons();
    setupMediaPreview();
  }
});

/* ============ FEED LOADING ============ */
async function loadFeed(reset = false) {
  if (isLoading || (!hasMore && !reset)) return;

  if (reset) {
    currentPage = 1;
    hasMore = true;
    document.getElementById('feed-container').innerHTML = `
      <div class="skeleton-post"></div>
      <div class="skeleton-post"></div>
      <div class="skeleton-post"></div>
    `;
    document.getElementById('feed-end').classList.add('hidden');
  }

  isLoading = true;
  document.getElementById('feed-loader').classList.remove('hidden');

  try {
    const res = await apiFetch(`feed/posts?page=${currentPage}&filter=${currentFilter}&limit=10`);
    const { data: posts, meta } = res;

    if (reset || currentPage === 1) {
      document.getElementById('feed-container').innerHTML = '';
    }

    if (posts.length === 0 && currentPage === 1) {
      document.getElementById('feed-container').innerHTML = `
        <div class="card card-body" style="text-align:center;padding:40px;color:var(--text-muted)">
          <p style="font-size:32px;margin-bottom:12px">👋</p>
          <p style="font-size:16px;font-weight:600">No hay publicaciones aún</p>
          <p style="font-size:14px;margin-top:6px">¡Sé el primero en compartir algo con tu comunidad!</p>
        </div>`;
    } else {
      posts.forEach(post => {
        document.getElementById('feed-container').appendChild(renderPost(post));
      });
    }

    hasMore = meta.has_more;
    currentPage++;

    if (!hasMore) {
      document.getElementById('feed-end').classList.remove('hidden');
    }
  } catch (err) {
    showToast('Error al cargar el feed. Intenta de nuevo.', 'error');
  } finally {
    isLoading = false;
    document.getElementById('feed-loader').classList.add('hidden');
  }
}

/* ============ RENDER POST ============ */
function renderPost(post) {
  const el = document.createElement('div');
  el.className = `post-card ${post.type ? 'post-type-' + post.type : ''}`;
  el.dataset.postId = post.post_id;
  el.id = `post-${post.post_id}`;

  const initials = (post.author_name || '?').split(' ').filter(Boolean).map(w => w[0]).join('').substring(0,2).toUpperCase();
  const tagsHtml = post.tags?.length
    ? `<div class="post-tags">${post.tags.map(t => `<span class="post-tag">#${escHtml(t)}</span>`).join('')}</div>`
    : '';

  const mediaHtml = buildMediaHtml(post.media);
  const contentTruncated = (post.content || '').length > 300;
  const contentPreview = contentTruncated
    ? `<div class="post-content truncated" id="content-${post.post_id}">${escHtml(post.content)}</div>
       <button class="read-more-btn" onclick="expandPost(${post.post_id})">Ver más</button>`
    : `<div class="post-content">${escHtml(post.content)}</div>`;

  const isMod = UL_USER.role === 'admin' || UL_USER.role === 'moderator';
  const isOwner = Number(post.author_id) === Number(UL_USER.id);

  el.innerHTML = `
    <div class="post-header">
      <div class="avatar" onclick="window.location.href='profile.php?id=${post.author_id}'"
           title="${escHtml(post.author_name)}"
           style="cursor:pointer">${escHtml(initials)}</div>
      <div class="post-meta">
        <a class="post-author" href="profile.php?id=${post.author_id}">${escHtml(post.author_name)}</a>
        <div class="post-info">
          <span>${escHtml(post.faculty_name || '')}</span>
          <span class="dot">·</span>
          <span>${timeAgo(post.created_at)}</span>
          ${post.audience !== 'public' ? `<span class="dot">·</span><span>${escHtml(audienceLabel(post.audience))}</span>` : ''}
        </div>
      </div>
      <div class="post-options-btn" onclick="togglePostMenu(event, ${post.post_id}, ${isOwner}, ${isMod})">⋮</div>
    </div>

    ${contentPreview}
    ${tagsHtml}
    ${mediaHtml}

    <div class="post-actions">
      <button class="post-action ${post.user_liked ? 'liked' : ''}"
              id="like-btn-${post.post_id}"
              onclick="toggleLike(${post.post_id})">
        ${post.user_liked ? '❤️' : '🤍'} <span class="like-count">${post.likes_count || 0}</span>
      </button>
      <button class="post-action" onclick="toggleComments(${post.post_id})">
        💬 <span>${post.comments_count || 0}</span>
      </button>
      <button class="post-action" onclick="sharePost(${post.post_id})">
        ↗️ Compartir
      </button>
      <button class="post-action" onclick="openReportModal(${post.post_id})">
        🚩
      </button>
    </div>

    <div class="post-comments hidden" id="comments-${post.post_id}">
      <div class="comments-loading">Cargando comentarios...</div>
    </div>
  `;

  return el;
}

function buildMediaHtml(media) {
  if (!media?.length) return '';
  const one = (m, cls = '') => m.media_type === 'video'
    ? `<video class="${cls}" src="${escHtml(m.url)}" controls preload="metadata"></video>`
    : `<img class="${cls}" src="${escHtml(m.url)}" alt="Imagen de la publicación" loading="lazy" onerror="this.remove()">`;
  if (media.length === 1) return one(media[0], 'post-media');
  return `<div class="post-media-grid">${media.slice(0, 4).map(m => one(m)).join('')}</div>`;
}

function audienceLabel(a) {
  const map = { faculty: '🏛 Facultad', career: '📚 Carrera', group: '👥 Grupo' };
  return map[a] || a;
}

/* ============ POST INTERACTIONS ============ */
async function toggleLike(postId) {
  const btn = document.getElementById(`like-btn-${postId}`);
  const liked = btn.classList.contains('liked');
  const countEl = btn.querySelector('.like-count');
  const count = parseInt(countEl.textContent);

  // Optimistic update
  btn.classList.toggle('liked');
  btn.innerHTML = `${liked ? '🤍' : '❤️'} <span class="like-count">${liked ? count - 1 : count + 1}</span>`;

  try {
    await apiFetch(`feed/posts/${postId}/like`, { method: liked ? 'DELETE' : 'POST' });
  } catch {
    // Revert on failure
    btn.classList.toggle('liked');
    btn.innerHTML = `${liked ? '❤️' : '🤍'} <span class="like-count">${count}</span>`;
    showToast('Error al procesar tu reacción', 'error');
  }
}

async function toggleComments(postId) {
  const container = document.getElementById(`comments-${postId}`);
  if (container.classList.contains('hidden')) {
    container.classList.remove('hidden');
    await loadComments(postId);
  } else {
    container.classList.add('hidden');
  }
}

async function loadComments(postId) {
  const container = document.getElementById(`comments-${postId}`);
  try {
    const res = await apiFetch(`feed/posts/${postId}/comments`);
    const comments = res?.data?.comments || [];
    container.innerHTML = comments.map(c => `
      <div class="comment-item">
        <div class="avatar avatar-sm">${escHtml((c.author_name || '?')[0].toUpperCase())}</div>
        <div class="comment-body">
          <span class="comment-author">${escHtml(c.author_name)}</span>
          <span class="comment-text">${escHtml(c.content)}</span>
          <span class="comment-time">${timeAgo(c.created_at)}</span>
        </div>
      </div>
    `).join('') + `
      <div class="comment-input-row">
        <div class="avatar avatar-sm">${escHtml((UL_USER.name || '?')[0].toUpperCase())}</div>
        <input type="text" class="comment-input" id="comment-input-${postId}"
               placeholder="Escribe un comentario..." maxlength="500"
               onkeydown="if(event.key==='Enter')submitComment(${postId})">
        <button class="btn-primary" style="padding:7px 14px;font-size:13px"
                onclick="submitComment(${postId})">Enviar</button>
      </div>`;
  } catch (err) {
    container.innerHTML = '<p style="padding:12px;font-size:13px;color:var(--text-muted)">Error al cargar comentarios: '
      + escHtml(err.message || '') + '</p>';
  }
}

async function submitComment(postId) {
  const input = document.getElementById(`comment-input-${postId}`);
  const content = input.value.trim();
  if (!content) return;

  input.disabled = true;
  try {
    await apiFetch(`feed/posts/${postId}/comments`, {
      method: 'POST',
      body: JSON.stringify({ content })
    });
    input.value = '';
    await loadComments(postId);
    // Increment comment count
    const btn = document.querySelector(`[onclick="toggleComments(${postId})"]`);
    if (btn) {
      const span = btn.querySelector('span');
      span.textContent = (parseInt(span.textContent) || 0) + 1;
    }
  } catch (err) {
    showToast(err.message || 'Error al publicar comentario', 'error');
  } finally {
    input.disabled = false;
    input.focus();
  }
}

function sharePost(postId) {
  const url = `${window.location.origin}${UL_BASE}/frontend/pages/feed.php#post-${postId}`;
  if (navigator.share) {
    navigator.share({ title: 'UniLink', url }).catch(() => {});
  } else if (navigator.clipboard) {
    navigator.clipboard.writeText(url).then(() => showToast('Enlace copiado 📋', 'info'));
  } else {
    prompt('Copia el enlace:', url);
  }
}

function expandPost(postId) {
  const el = document.getElementById(`content-${postId}`);
  el.classList.remove('truncated');
  el.nextElementSibling.remove();
}

/* ============ POST CREATION ============ */
function openPostModal(type = 'general') {
  document.getElementById('postModal').classList.remove('hidden');
  document.body.style.overflow = 'hidden';

  const typeMap = { image: 'general', photo: 'general', general: 'general',
                    event: 'event', poll: 'poll', lost: 'lost_found', academic: 'academic' };
  const typeInput = document.getElementById('post-type-input');
  if (typeInput) typeInput.value = typeMap[type] ?? 'general';

  const extras = document.getElementById('post-extra-fields');
  extras.innerHTML = '';

  if (type === 'event') {
    extras.innerHTML = `
      <div class="form-row">
        <div class="form-group">
          <label>Fecha del evento</label>
          <input type="datetime-local" name="event_date" required>
        </div>
        <div class="form-group">
          <label>Lugar</label>
          <input type="text" name="event_location" placeholder="Ej: Auditorio B">
        </div>
      </div>`;
    const audSel = document.querySelector('#post-form [name="audience"]');
    if (audSel) audSel.value = 'public';
  } else if (type === 'poll') {
    extras.innerHTML = `
      <div class="form-group">
        <label>Pregunta de la encuesta</label>
        <input type="text" name="poll_question" placeholder="¿Cuál es tu pregunta?" required>
      </div>
      <div id="poll-options">
        <div class="form-group"><input type="text" name="poll_option[]" placeholder="Opción 1" required></div>
        <div class="form-group"><input type="text" name="poll_option[]" placeholder="Opción 2" required></div>
      </div>
      <button type="button" class="btn-ghost" onclick="addPollOption()">+ Agregar opción</button>`;
  } else if (type === 'lost') {
    extras.innerHTML = `
      <div class="form-row">
        <div class="form-group">
          <label>¿Perdiste o encontraste?</label>
          <select name="lost_type">
            <option value="lost">Perdí un objeto</option>
            <option value="found">Encontré un objeto</option>
          </select>
        </div>
        <div class="form-group">
          <label>Lugar</label>
          <input type="text" name="lost_location" placeholder="Ej: Biblioteca central">
        </div>
      </div>`;
  }
}

function closePostModal() {
  document.getElementById('postModal').classList.add('hidden');
  document.body.style.overflow = '';
  document.getElementById('post-form').reset();
  document.getElementById('media-preview').innerHTML = '';
  document.getElementById('char-count').textContent = '0';
  selectedTags.clear();
  document.querySelectorAll('.tag-btn.selected').forEach(b => b.classList.remove('selected'));
}

function setupPostForm() {
  document.getElementById('openPostModal').addEventListener('click', () => openPostModal());

  // Character counter
  document.getElementById('post-content').addEventListener('input', function () {
    document.getElementById('char-count').textContent = this.value.length;
    if (this.value.length > 1800) {
      document.getElementById('char-count').style.color = 'var(--uni-red)';
    } else {
      document.getElementById('char-count').style.color = '';
    }
  });

  // Form submit
  document.getElementById('post-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = document.getElementById('submit-post');
    btn.disabled = true;
    btn.querySelector('.btn-text').textContent = 'Publicando...';

    const formData = new FormData(e.target);
    formData.append('tags', JSON.stringify([...selectedTags]));

    try {
      // Encuesta: se crea de verdad en el monitor de opinión (academic/polls)
      if (formData.get('poll_question')) {
        const options = formData.getAll('poll_option[]').map(o => String(o).trim()).filter(Boolean);
        await apiFetch('academic/polls', {
          method: 'POST',
          body: JSON.stringify({ title: formData.get('poll_question'), poll_type: 'options', options })
        });
      }
      // Objeto perdido/encontrado: también se publica en la sección de Objetos perdidos
      if (formData.get('lost_type')) {
        const content = String(formData.get('content') || '');
        const lf = new FormData();
        lf.append('title', content.split('\n')[0].slice(0, 80) || 'Objeto perdido');
        lf.append('description', content);
        lf.append('is_lost_found', '1');
        lf.append('lost_type', formData.get('lost_type'));
        lf.append('lost_location', formData.get('lost_location') || '');
        await apiFetch('marketplace/listings', { method: 'POST', body: lf });
      }

      const data = await apiFetch('feed/posts', { method: 'POST', body: formData });
      if (!data.success) throw new Error(data.message);

      closePostModal();
      showToast('¡Publicación creada! 🎉', 'success');
      loadFeed(true); // Reload feed
    } catch (err) {
      showToast(err.message || 'Error al publicar', 'error');
    } finally {
      btn.disabled = false;
      btn.querySelector('.btn-text').textContent = 'Publicar';
    }
  });
}

function setupTagButtons() {
  document.querySelectorAll('.tag-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const tag = btn.dataset.tag;
      if (selectedTags.has(tag)) {
        selectedTags.delete(tag);
        btn.classList.remove('selected');
      } else if (selectedTags.size < 3) {
        selectedTags.add(tag);
        btn.classList.add('selected');
      } else {
        showToast('Máximo 3 etiquetas', 'info');
      }
    });
  });
}

function setupMediaPreview() {
  document.getElementById('post-media')?.addEventListener('change', function () {
    const preview = document.getElementById('media-preview');
    preview.innerHTML = '';
    [...this.files].slice(0, 4).forEach(file => {
      if (!file.type.startsWith('image/')) return;
      const reader = new FileReader();
      reader.onload = e => {
        const img = document.createElement('img');
        img.src = e.target.result;
        img.className = 'media-preview-item';
        preview.appendChild(img);
      };
      reader.readAsDataURL(file);
    });
  });
}

/* ============ FILTERS ============ */
function setupFilterButtons() {
  document.querySelectorAll('.filter-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      currentFilter = btn.dataset.filter;
      loadFeed(true);
    });
  });
}

/* ============ INFINITE SCROLL ============ */
function setupInfiniteScroll() {
  const observer = new IntersectionObserver((entries) => {
    if (entries[0].isIntersecting && hasMore && !isLoading) {
      loadFeed();
    }
  }, { threshold: 0.5 });

  const loader = document.getElementById('feed-loader');
  if (loader) observer.observe(loader);
}

/* ============ WIDGETS ============ */
async function loadWidgets() {
  await Promise.all([loadMyGroups(), loadEvents(), loadMarketplaceQuick()]);
}

async function loadMyGroups() {
  try {
    const res = await apiFetch('academic/my-groups?limit=5');
    const groups = res?.data?.groups || [];
    const container = document.getElementById('my-groups-list');
    if (!groups.length) {
      container.innerHTML = `<p style="padding:16px;font-size:13px;color:var(--text-muted);text-align:center">Sin grupos activos</p>`;
      return;
    }
    container.innerHTML = groups.map(g => `
      <div class="group-item" onclick="window.location.href='group-detail.php?id=${Number(g.group_id)}'">
        <div class="group-icon">${escHtml(g.icon || '📚')}</div>
        <div class="group-info">
          <div class="group-name">${escHtml(g.name)}</div>
          <div class="group-count">${Number(g.member_count) || 0} miembros</div>
        </div>
        ${Number(g.unread) ? `<div class="group-unread">${Number(g.unread)}</div>` : ''}
      </div>`).join('');
  } catch { /* silent */ }
}

async function loadEvents() {
  try {
    const res = await apiFetch('academic/events?limit=4&upcoming=1');
    const events = res?.data || [];
    const container = document.getElementById('events-list');
    if (!events.length) {
      container.innerHTML = `<p style="padding:16px;font-size:13px;color:var(--text-muted);text-align:center">Sin eventos próximos</p>`;
      return;
    }
    container.innerHTML = events.map(e => {
      const d = new Date(e.event_date);
      return `
        <div class="event-item" onclick="window.location.href='calendar.php?event=${e.event_id}'">
          <div class="event-date-box">
            <div class="event-day">${d.getDate()}</div>
            <div class="event-month">${d.toLocaleString('es', {month:'short'}).toUpperCase()}</div>
          </div>
          <div class="event-info">
            <div class="event-name">${escHtml(e.title)}</div>
            <div class="event-loc">📍 ${escHtml(e.location || 'Campus')}</div>
          </div>
        </div>`;
    }).join('');
  } catch { /* silent */ }
}

async function loadMarketplaceQuick() {
  try {
    const res = await apiFetch('marketplace/listings?limit=4&sort=recent');
    const listings = res?.data || [];
    const container = document.getElementById('marketplace-quick');
    if (!listings.length) {
      container.innerHTML = `<p style="padding:16px;font-size:13px;color:var(--text-muted);text-align:center">Sin anuncios recientes</p>`;
      return;
    }
    container.innerHTML = listings.map(l => `
      <div class="mkt-item" onclick="window.location.href='marketplace.php?id=${l.listing_id}'">
        <div class="mkt-thumb" style="background:var(--gray-100)">
          ${l.thumbnail ? `<img src="${escHtml(l.thumbnail)}" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:var(--radius-md)">` : '📦'}
        </div>
        <div>
          <div class="mkt-name">${escHtml(l.title)}</div>
          <div class="mkt-price">${formatMXN(l.price)}</div>
        </div>
      </div>`).join('');
  } catch { /* silent */ }
}

/* ============ REPORT MODAL ============ */
function openReportModal(postId) {
  const modal = document.getElementById('reportModal');
  if (!modal) { return quickReport(postId); }   // páginas sin modal (p. ej. perfil)
  document.getElementById('report-post-id').value = postId;
  modal.classList.remove('hidden');
  document.body.style.overflow = 'hidden';
}

async function quickReport(postId) {
  const reasons = { 1: 'spam', 2: 'harassment', 3: 'hate_speech', 4: 'false_info', 5: 'inappropriate', 6: 'other' };
  const pick = prompt('Motivo del reporte:\n1) Spam\n2) Acoso\n3) Discurso de odio\n4) Información falsa\n5) Contenido inapropiado\n6) Otro');
  const reason = reasons[parseInt(pick || '', 10)];
  if (!reason) return;
  try {
    await apiFetch('moderation/reports', { method: 'POST', body: JSON.stringify({ post_id: postId, reason }) });
    showToast('Reporte enviado. Gracias por mantener UniLink seguro 🛡', 'success');
  } catch (err) { showToast(err.message || 'Error al enviar reporte', 'error'); }
}

document.getElementById('report-form')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const fd = new FormData(e.target);
  if (!fd.get('reason')) { showToast('Selecciona un motivo', 'info'); return; }
  try {
    await apiFetch(`moderation/reports`, {
      method: 'POST',
      body: JSON.stringify({
        post_id: parseInt(fd.get('post_id'), 10),
        reason: fd.get('reason'),
        details: fd.get('details')
      })
    });
    closeModal('reportModal');
    e.target.reset();
    showToast('Reporte enviado. Gracias por mantener UniLink seguro 🛡', 'success');
  } catch (err) { showToast(err.message || 'Error al enviar reporte', 'error'); }
});

/* ============ POST OPTIONS MENU ============ */
function togglePostMenu(event, postId, isOwner, isMod) {
  event.stopPropagation();
  const existing = document.querySelector('.post-options-menu');
  if (existing) existing.remove();

  const btn = event.currentTarget;
  const menu = document.createElement('div');
  menu.className = 'post-options-menu';
  menu.style.cssText = `position:absolute;right:0;top:100%;background:var(--white);border:1px solid var(--border);border-radius:var(--radius-md);box-shadow:var(--shadow-lg);min-width:160px;z-index:200;overflow:hidden`;

  const items = [];
  if (isOwner) {
    items.push({ label: '✏️ Editar', action: () => editPost(postId) });
    items.push({ label: '🗑 Eliminar', action: () => deletePost(postId), danger: true });
  }
  items.push({ label: '🚩 Reportar', action: () => openReportModal(postId) });
  if (isMod) {
    items.push({ label: '🛡 Moderar', action: () => moderatePost(postId), danger: true });
  }

  items.forEach(item => {
    const el = document.createElement('button');
    el.style.cssText = `display:flex;align-items:center;gap:8px;width:100%;padding:10px 16px;font-size:14px;background:none;border:none;cursor:pointer;text-align:left;color:${item.danger ? 'var(--uni-red)' : 'var(--text-primary)'};transition:background 150ms`;
    el.onmouseover = () => el.style.background = 'var(--gray-50)';
    el.onmouseout  = () => el.style.background = 'none';
    el.textContent = item.label;
    el.onclick = () => { menu.remove(); item.action(); };
    menu.appendChild(el);
  });

  btn.style.position = 'relative';
  btn.appendChild(menu);
  document.addEventListener('click', () => menu.remove(), { once: true });
}

async function deletePost(postId) {
  if (!confirm('¿Eliminar esta publicación?')) return;
  try {
    await apiFetch(`feed/posts/${postId}`, { method: 'DELETE' });
    document.querySelector(`[data-post-id="${postId}"]`)?.remove();
    showToast('Publicación eliminada', 'success');
  } catch (err) { showToast(err.message || 'Error al eliminar', 'error'); }
}

async function editPost(postId) {
  const card    = document.querySelector(`[data-post-id="${postId}"]`);
  const current = card?.querySelector('.post-content')?.textContent || '';
  const content = prompt('Editar publicación:', current);
  if (content === null || !content.trim() || content === current) return;
  try {
    const res  = await apiFetch(`feed/posts/${postId}`, { method: 'PUT', body: JSON.stringify({ content }) });
    const post = res?.data;
    if (card && post) {
      card.replaceWith(renderPost({ ...post, author_id: post.user_id }));
    }
    showToast('Publicación actualizada', 'success');
  } catch (err) { showToast(err.message || 'Error al editar', 'error'); }
}

async function moderatePost(postId) {
  const reason = prompt('Motivo de la moderación (se registrará en el log):', 'Contenido inapropiado');
  if (reason === null) return;
  try {
    await apiFetch('moderation/actions', {
      method: 'POST',
      body: JSON.stringify({ action: 'remove_post', target_id: postId, reason })
    });
    document.querySelector(`[data-post-id="${postId}"]`)?.remove();
    window.ULSocket?.emit?.('moderate_post', { post_id: postId, reason });
    showToast('Publicación retirada por moderación', 'success');
  } catch (err) { showToast(err.message || 'Error al moderar', 'error'); }
}

/* El botón de pánico (triggerPanic) vive en utils.js para que funcione igual en todas las páginas. */

/* ============ MOBILE SIDEBAR ============ */
document.getElementById('mobileMenuBtn')?.addEventListener('click', () => {
  document.getElementById('sidebar').classList.add('open');
});
document.getElementById('sidebarClose')?.addEventListener('click', () => {
  document.getElementById('sidebar').classList.remove('open');
});

/* La búsqueda global (setupSearch) vive en utils.js para que funcione en todas las páginas. */

/* ============ HELPERS ============ */
function addPollOption() {
  const container = document.getElementById('poll-options');
  const count = container.children.length + 1;
  if (count > 6) { showToast('Máximo 6 opciones', 'info'); return; }
  const div = document.createElement('div');
  div.className = 'form-group';
  div.innerHTML = `<input type="text" name="poll_option[]" placeholder="Opción ${count}">`;
  container.appendChild(div);
}

function closeModal(id) {
  document.getElementById(id).classList.add('hidden');
  document.body.style.overflow = '';
}
