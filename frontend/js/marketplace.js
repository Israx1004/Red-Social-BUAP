/**
 * UniLink — marketplace.js
 */
let mktPage = 1, mktHasMore = true, mktLoading = false;
let activeCategory = '';

document.addEventListener('DOMContentLoaded', () => {
  loadListings(true);
  setupChips();
  setupListingForm();
  setupImagePreview();
  setupInfiniteScroll();
  const openId = parseInt(new URLSearchParams(location.search).get('id') || '0', 10);
  if (openId) openListingDetail(openId);
});

/* ---- Load listings ---- */
async function loadListings(reset = false) {
  if (mktLoading || (!mktHasMore && !reset)) return;
  if (reset) {
    mktPage = 1; mktHasMore = true;
    document.getElementById('listings-grid').innerHTML =
      Array(8).fill('<div class="listing-skeleton"></div>').join('');
    document.getElementById('listings-end').classList.add('hidden');
  }
  mktLoading = true;
  document.getElementById('listings-loader').classList.remove('hidden');

  const q       = document.getElementById('mkt-search').value;
  const sort    = document.getElementById('mkt-sort').value;
  const priceMin = document.getElementById('price-min').value;
  const priceMax = document.getElementById('price-max').value;

  const params = new URLSearchParams({
    page: mktPage, limit: 12,
    ...(activeCategory && { category: activeCategory }),
    ...(q && { search: q }),
    sort,
    ...(priceMin && { price_min: priceMin }),
    ...(priceMax && { price_max: priceMax }),
  });

  try {
    const res = await apiFetch(`marketplace/listings?${params}`);
    const { data: listings, meta } = res;

    if (reset) document.getElementById('listings-grid').innerHTML = '';

    if (!listings.length && mktPage === 1) {
      document.getElementById('listings-grid').innerHTML =
        `<div class="card card-body" style="grid-column:1/-1;text-align:center;padding:48px;color:var(--text-muted)">
           <p style="font-size:32px">🛒</p>
           <p style="font-size:16px;font-weight:600;margin-top:12px">Sin anuncios disponibles</p>
           <p style="font-size:14px;margin-top:6px">¡Sé el primero en publicar algo!</p>
         </div>`;
    } else {
      listings.forEach(l => {
        document.getElementById('listings-grid').appendChild(renderListing(l));
      });
    }

    mktHasMore = meta.has_more;
    mktPage++;
    if (!mktHasMore) document.getElementById('listings-end').classList.remove('hidden');
  } catch (err) {
    showToast('Error al cargar anuncios', 'error');
  } finally {
    mktLoading = false;
    document.getElementById('listings-loader').classList.add('hidden');
  }
}

/* ---- Render listing card ---- */
function renderListing(l) {
  const el = document.createElement('div');
  el.className = 'listing-card';
  el.onclick = () => openListingDetail(l.listing_id);

  const catIcons = { libros:'📚', calculadoras:'🔢', tutorias:'👨‍🏫', electronica:'💻', ropa:'👕', otros:'📦' };
  const condLabels = { nuevo:'✨ Nuevo', como_nuevo:'🌟 Como nuevo', buen_estado:'👍 Buen estado', usado:'📦 Usado' };
  const stars = '⭐'.repeat(Math.round(l.seller_rating || 0));

  el.innerHTML = `
    <div class="listing-thumb">
      ${l.thumbnail
        ? `<img src="${escHtml(l.thumbnail)}" alt="${escHtml(l.title)}" loading="lazy" onerror="this.parentNode.textContent='📦'">`
        : catIcons[l.category] || '📦'}
    </div>
    <div class="listing-body">
      <div class="listing-category">${catIcons[l.category] || ''} ${escHtml(l.category)}</div>
      <div class="listing-title">${escHtml(l.title)}</div>
      <div class="listing-price">$${parseFloat(l.price).toFixed(2)}</div>
      <div class="listing-meta">
        <div class="listing-seller">
          <div class="avatar avatar-sm">${escHtml(initialOf(l.seller_name))}</div>
          <span>${escHtml(l.seller_name || '')}</span>
          ${l.seller_rating ? `<span class="seller-rep">${stars}</span>` : ''}
        </div>
        <span class="listing-condition">${condLabels[l.condition_val] || escHtml(l.condition_val)}</span>
      </div>
    </div>`;
  return el;
}

/* ---- Open detail modal ---- */
async function openListingDetail(id) {
  document.getElementById('listingDetailModal').classList.remove('hidden');
  document.body.style.overflow = 'hidden';
  document.getElementById('listing-detail-body').innerHTML =
    '<div style="grid-column:1/-1;text-align:center;padding:40px"><div class="spinner" style="border-top-color:var(--uni-blue-mid);margin:0 auto"></div></div>';

  try {
    const res = await apiFetch(`marketplace/listings/${id}`);
    const l = res?.data?.listing || res?.data;
    if (!l) throw new Error('Anuncio no encontrado');
    document.getElementById('detail-title').textContent = l.title;

    const images = l.images?.length ? l.images : [null];
    const mainImg = images[0]?.url || null;
    const thumbsHtml = images.length > 1
      ? `<div class="detail-thumbs">${images.map((img, i) =>
          `<img src="${escHtml(img.url)}" class="detail-thumb${i===0?' active':''}"
               data-url="${escHtml(img.url)}" onclick="switchDetailImg(this, this.dataset.url)" alt="">`
        ).join('')}</div>` : '';

    const stars = '⭐'.repeat(Math.round(l.seller_rating || 0));
    const isOwner = Number(l.seller_id) === Number(UL_USER.id);

    document.getElementById('listing-detail-body').innerHTML = `
      <div class="detail-images">
        <div class="detail-main-img" id="detail-main-img">
          ${mainImg ? `<img src="${escHtml(mainImg)}" alt="" onerror="this.outerHTML='<div class=&quot;detail-noimg&quot;>📦</div>'">` : '<div class="detail-noimg">📦</div>'}
        </div>
        ${thumbsHtml}
      </div>

      <div class="detail-info">
        <div>
          <div class="listing-category" style="font-size:12px">${escHtml(l.category)}</div>
          <h3 style="font-family:var(--font-display);font-size:20px;font-weight:800;margin:6px 0">${escHtml(l.title)}</h3>
          <div class="detail-price">${formatMXN(l.price)} MXN</div>
          <span class="badge badge-gray">${escHtml((l.condition_val || '').replace(/_/g, ' '))}</span>
        </div>

        ${l.description ? `<p class="detail-desc">${escHtml(l.description)}</p>` : ''}

        <div class="detail-seller-card">
          <div class="avatar">${escHtml(initialOf(l.seller_name))}</div>
          <div class="detail-seller-info">
            <div class="detail-seller-name">${escHtml(l.seller_name || '')}</div>
            <div class="detail-seller-rep">${stars || 'Sin calificaciones aún'} (${l.reviews_count || 0} reseñas)</div>
          </div>
          <button class="btn-ghost" onclick="viewProfile(${l.seller_id})">Ver perfil</button>
        </div>

        <div class="detail-actions">
          ${isOwner
            ? `<button class="btn-secondary" onclick="editListing(${Number(l.listing_id)})">✏️ Editar</button>
               ${l.status === 'active' ? `<button class="btn-secondary" onclick="markSold(${Number(l.listing_id)})">✅ Marcar vendido</button>` : ''}
               <button class="btn-danger" onclick="deleteListing(${Number(l.listing_id)})">🗑 Eliminar</button>`
            : `<button class="btn-primary" onclick="contactSeller(${Number(l.seller_id)}, ${Number(l.listing_id)})">💬 Contactar vendedor</button>
               <button class="btn-secondary" onclick="makeOffer(${Number(l.seller_id)}, ${Number(l.listing_id)})">💰 Hacer oferta</button>
               <button class="btn-ghost" onclick="leaveReview(${Number(l.listing_id)})">⭐ Reseñar</button>`
          }
        </div>

        <div class="reviews-section">
          <h4 style="font-size:14px;font-weight:600;margin-bottom:12px">Reseñas del vendedor</h4>
          <div id="reviews-list">
            ${(l.reviews||[]).length
              ? l.reviews.map(r => `
                  <div class="review-item">
                    <div class="avatar avatar-sm">${escHtml(initialOf(r.reviewer_name))}</div>
                    <div>
                      <div style="font-size:13px;font-weight:600">${escHtml(r.reviewer_name||'')}</div>
                      <div class="review-stars">${'⭐'.repeat(Math.min(5, Math.max(0, Number(r.rating) || 0)))}</div>
                      ${r.comment ? `<div class="review-text">${escHtml(r.comment)}</div>` : ''}
                    </div>
                  </div>`).join('')
              : '<p style="font-size:13px;color:var(--text-muted)">Sin reseñas aún.</p>'
            }
          </div>
        </div>
      </div>`;
  } catch (err) {
    document.getElementById('listing-detail-body').innerHTML =
      '<p style="grid-column:1/-1;padding:32px;text-align:center;color:var(--text-muted)">No se pudo cargar el anuncio.</p>';
    showToast(err.message || 'Error al cargar el anuncio', 'error');
  }
}

function switchDetailImg(thumb, url) {
  document.querySelectorAll('.detail-thumb').forEach(t => t.classList.remove('active'));
  thumb.classList.add('active');
  const main = document.getElementById('detail-main-img');
  main.innerHTML = `<img src="${escHtml(url)}" alt="">`;
}

/* ---- Create listing ---- */
function openNewListingModal() {
  document.getElementById('newListingModal').classList.remove('hidden');
  document.body.style.overflow = 'hidden';
}

function setupListingForm() {
  document.getElementById('listing-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = e.target.querySelector('[type=submit]');
    btn.disabled = true;
    btn.textContent = 'Publicando...';

    const fd = new FormData(e.target);
    try {
      const res = await fetch(`${UL_BASE}/backend/api-gateway/index.php?service=marketplace&path=marketplace/listings`, {
        method: 'POST',
        headers: { 'Authorization': `Bearer ${UL_TOKEN}` },
        body: fd
      });
      const data = await res.json();
      if (!data.success) throw new Error(data.message);
      closeModal('newListingModal');
      e.target.reset();
      document.getElementById('listing-img-preview').innerHTML = '';
      showToast('¡Anuncio publicado! 🎉', 'success');
      loadListings(true);
    } catch (err) {
      showToast(err.message || 'Error al publicar', 'error');
    } finally {
      btn.disabled = false;
      btn.textContent = 'Publicar anuncio';
    }
  });
}

function setupImagePreview() {
  document.getElementById('listing-images')?.addEventListener('change', function () {
    const preview = document.getElementById('listing-img-preview');
    preview.innerHTML = '';
    [...this.files].slice(0, 4).forEach(file => {
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

/* ---- Filters ---- */
function setupChips() {
  document.querySelectorAll('#category-chips .chip').forEach(chip => {
    chip.addEventListener('click', () => {
      document.querySelectorAll('#category-chips .chip').forEach(c => c.classList.remove('active'));
      chip.classList.add('active');
      activeCategory = chip.dataset.cat;
      loadListings(true);
    });
  });
}

let filterTimeout;
function filterListings() {
  clearTimeout(filterTimeout);
  filterTimeout = setTimeout(() => loadListings(true), 400);
}

function setupInfiniteScroll() {
  const obs = new IntersectionObserver(entries => {
    if (entries[0].isIntersecting && mktHasMore && !mktLoading) loadListings();
  }, { threshold: 0.5 });
  const el = document.getElementById('listings-loader');
  if (el) obs.observe(el);
}

/* ---- Actions ---- */
function contactSeller(sellerId, listingId) {
  const title = document.getElementById('detail-title')?.textContent || '';
  window.location.href = `${UL_BASE}/frontend/pages/messages.php?to=${sellerId}&ctx=${encodeURIComponent(title)}`;
}

// La oferta se envía como mensaje directo al vendedor (no hay tabla de ofertas)
async function makeOffer(sellerId, listingId) {
  const amount = prompt('¿Cuánto ofreces? (MXN)');
  if (!amount || isNaN(amount) || Number(amount) <= 0) return;
  const title = document.getElementById('detail-title')?.textContent || 'tu anuncio';
  try {
    await apiFetch('messages/send', {
      method: 'POST',
      body: JSON.stringify({
        to: sellerId,
        body: `💰 Te ofrezco ${formatMXN(amount)} MXN por "${title}".`,
        context: `Marketplace: ${title}`
      })
    });
    showToast('¡Oferta enviada al vendedor por mensaje!', 'success');
  } catch (err) { showToast(err.message || 'Error al enviar oferta', 'error'); }
}

async function editListing(id) {
  const currentTitle = document.getElementById('detail-title')?.textContent || '';
  const title = prompt('Título del anuncio:', currentTitle);
  if (title === null) return;
  const price = prompt('Precio (MXN):');
  const body = { title: title.trim() };
  if (price !== null && price.trim() !== '') {
    if (isNaN(price) || Number(price) < 0) { showToast('Precio inválido', 'error'); return; }
    body.price = Number(price);
  }
  try {
    await apiFetch(`marketplace/listings/${id}`, { method: 'PUT', body: JSON.stringify(body) });
    showToast('Anuncio actualizado', 'success');
    openListingDetail(id);
    loadListings(true);
  } catch (err) { showToast(err.message || 'Error al editar', 'error'); }
}

async function markSold(id) {
  if (!confirm('¿Marcar este anuncio como vendido? Dejará de aparecer en el listado.')) return;
  try {
    await apiFetch(`marketplace/listings/${id}/sold`, { method: 'PATCH' });
    closeModal('listingDetailModal');
    showToast('Marcado como vendido', 'success');
    loadListings(true);
  } catch (err) { showToast(err.message || 'Error', 'error'); }
}

async function leaveReview(listingId) {
  const rating = parseInt(prompt('Calificación del vendedor (1 a 5):') || '', 10);
  if (!rating || rating < 1 || rating > 5) return;
  const comment = prompt('Comentario (opcional):') || '';
  try {
    await apiFetch('marketplace/reviews', {
      method: 'POST',
      body: JSON.stringify({ listing_id: listingId, rating, comment })
    });
    showToast('¡Reseña publicada!', 'success');
    openListingDetail(listingId);
  } catch (err) { showToast(err.message || 'Error al publicar reseña', 'error'); }
}

async function deleteListing(id) {
  if (!confirm('¿Eliminar este anuncio?')) return;
  try {
    await apiFetch(`marketplace/listings/${id}`, { method: 'DELETE' });
    closeModal('listingDetailModal');
    showToast('Anuncio eliminado', 'success');
    loadListings(true);
  } catch { showToast('Error al eliminar', 'error'); }
}

function viewProfile(userId) {
  window.location.href = `${UL_BASE}/frontend/pages/profile.php?id=${userId}`;
}

function closeModal(id) {
  document.getElementById(id).classList.add('hidden');
  document.body.style.overflow = '';
}
