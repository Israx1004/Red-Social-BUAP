<?php
session_start();
require_once '../../backend/shared/auth_check.php';
$user     = $_SESSION['user'];
$base     = BASE_PATH;
$group_id = (int)($_GET['id'] ?? 0);
if ($group_id <= 0) { header('Location: ' . $base . '/frontend/pages/groups.php'); exit; }
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Grupo — UniLink BUAP</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= $base ?>/frontend/css/main.css">
  <link rel="stylesheet" href="<?= $base ?>/frontend/css/dashboard.css">
  <link rel="stylesheet" href="<?= $base ?>/frontend/css/groups.css">
  <style>
    .gd-layout { display:grid; grid-template-columns: minmax(0,1fr) 300px; gap:20px; align-items:start; }
    @media (max-width: 900px) { .gd-layout { grid-template-columns: 1fr; } }
    .gd-header { display:flex; gap:16px; align-items:center; flex-wrap:wrap; }
    .gd-icon { font-size:40px; width:64px; height:64px; display:flex; align-items:center; justify-content:center;
               background:var(--gray-100); border-radius:var(--radius-lg); }
    .gd-member { display:flex; align-items:center; gap:10px; padding:8px 0; border-bottom:1px solid var(--border); font-size:14px; }
    .gd-member:last-child { border-bottom:none; }
    .gd-member a { color:inherit; text-decoration:none; flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .gd-composer textarea { width:100%; min-height:80px; padding:12px; border:1.5px solid var(--border);
                            border-radius:var(--radius-md); font:inherit; resize:vertical; outline:none; }
  </style>
</head>
<body class="dashboard-page">

<?php include '../../frontend/components/sidebar.php'; ?>
<?php include '../../frontend/components/topbar.php'; ?>

<main class="main-content" style="display:block;padding:24px;max-width:1200px;margin:0 auto">
  <a href="<?= $base ?>/frontend/pages/groups.php" class="link-small" style="display:inline-block;margin-bottom:12px">← Volver a grupos</a>

  <div class="card card-body" id="gd-info" style="margin-bottom:20px">
    <div class="skeleton-card" style="height:80px"></div>
  </div>

  <div class="gd-layout">
    <section>
      <div class="card card-body gd-composer hidden" id="gd-composer" style="margin-bottom:16px">
        <form id="gd-post-form">
          <textarea name="content" id="gd-post-content" maxlength="2000" placeholder="Escribe algo para el grupo..." required></textarea>
          <div style="display:flex;justify-content:flex-end;margin-top:10px">
            <button type="submit" class="btn-primary"><span class="btn-text">Publicar en el grupo</span></button>
          </div>
        </form>
      </div>
      <div id="group-posts"></div>
      <div id="gd-posts-empty" class="hidden" style="text-align:center;padding:40px;color:var(--text-muted)">
        <p style="font-size:32px">💬</p>
        <p>Aún no hay publicaciones en este grupo.</p>
      </div>
      <div style="text-align:center;margin:16px 0">
        <button class="btn-secondary hidden" id="gd-more" onclick="loadGroupPosts()">Cargar más</button>
      </div>
    </section>

    <aside class="card card-body">
      <h3 style="font-family:var(--font-display);font-weight:700;margin-bottom:10px">Miembros recientes</h3>
      <div id="gd-members"><p style="font-size:13px;color:var(--text-muted)">Cargando...</p></div>
    </aside>
  </div>
</main>

<div id="toast-container"></div>

<script>
const UL_USER  = <?= json_encode([
  'id'      => (int)$user['user_id'],
  'role'    => $user['role'] ?? 'student',
  'name'    => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
  'faculty' => $user['faculty_id'] ?? null,
]) ?>;
const UL_TOKEN = <?= json_encode($_SESSION['jwt_token'] ?? '') ?>;
const GROUP_ID = <?= $group_id ?>;
</script>
<script src="<?= $base ?>/frontend/js/utils.js"></script>
<script src="<?= $base ?>/frontend/js/feed.js"></script>
<script src="<?= $base ?>/frontend/js/groups.js"></script>
<script src="<?= $base ?>/frontend/js/notifications.js"></script>
<script>
let gdPage = 1, gdHasMore = true, gdLoading = false, gdGroup = null;

// groups.js llama a esta función después de unirse/salir
function onGroupMembershipChange() { loadGroupInfo(); resetGroupPosts(); }

async function loadGroupInfo() {
  const box = document.getElementById('gd-info');
  try {
    const res = await apiFetch(`academic/groups/${GROUP_ID}`);
    const g   = res?.data?.group;
    if (!g) throw new Error('Grupo no encontrado');
    gdGroup = g;
    document.title = `${g.name} — UniLink BUAP`;

    const isMember = Number(g.is_member) === 1;
    const isAdmin  = g.my_role === 'admin';
    box.innerHTML = `
      <div class="gd-header">
        <div class="gd-icon">${escHtml(g.icon || '👥')}</div>
        <div style="flex:1;min-width:200px">
          <h1 style="font-family:var(--font-display);font-size:24px;font-weight:800">${escHtml(g.name)}</h1>
          <p style="font-size:13px;color:var(--text-muted);margin-top:4px">
            ${escHtml(GROUP_TYPE_LABELS[g.type] || g.type || '')}
            ${g.nrc_code ? ` · NRC ${escHtml(g.nrc_code)}` : ''}
            ${g.faculty_name ? ` · ${escHtml(g.faculty_name)}` : ''}
            · 👥 ${Number(g.member_count) || 0} miembros
            ${Number(g.is_private) ? ' · 🔒 Privado' : ''}
          </p>
          ${g.description ? `<p style="margin-top:10px;font-size:14px">${escHtml(g.description)}</p>` : ''}
        </div>
        <div>
          ${isMember
            ? (isAdmin
                ? '<span class="badge badge-blue">Administras este grupo</span>'
                : `<button class="btn-leave" onclick="leaveGroup(${GROUP_ID})">Salir del grupo</button>`)
            : `<button class="btn-primary" onclick="joinGroup(${GROUP_ID}, this)">Unirme al grupo</button>`}
        </div>
      </div>`;

    document.getElementById('gd-composer').classList.toggle('hidden', !isMember);

    const members = g.members || [];
    document.getElementById('gd-members').innerHTML = members.length
      ? members.map(m => `
          <div class="gd-member">
            <div class="avatar avatar-sm">${escHtml(initialOf(m.name))}</div>
            <a href="${UL_BASE}/frontend/pages/profile.php?id=${Number(m.user_id)}">${escHtml(m.name)}</a>
            ${m.role !== 'member' ? `<span class="badge badge-gray">${escHtml(m.role)}</span>` : ''}
          </div>`).join('')
      : `<p style="font-size:13px;color:var(--text-muted)">${Number(g.is_private) && !isMember ? 'Únete para ver a los miembros.' : 'Sin miembros.'}</p>`;
  } catch (err) {
    box.innerHTML = `<p style="color:var(--text-muted)">${escHtml(err.message || 'No se pudo cargar el grupo.')}</p>`;
  }
}

function resetGroupPosts() {
  gdPage = 1; gdHasMore = true;
  document.getElementById('group-posts').innerHTML = '';
  loadGroupPosts();
}

async function loadGroupPosts() {
  if (gdLoading || !gdHasMore) return;
  gdLoading = true;
  try {
    const res   = await apiFetch(`feed/posts?group=${GROUP_ID}&page=${gdPage}&limit=10`);
    const posts = Array.isArray(res?.data) ? res.data : [];
    const box   = document.getElementById('group-posts');
    posts.forEach(p => box.appendChild(renderPost(p)));
    gdHasMore = !!res?.meta?.has_more;
    gdPage++;
    document.getElementById('gd-posts-empty').classList.toggle('hidden', box.children.length > 0);
    document.getElementById('gd-more').classList.toggle('hidden', !gdHasMore);
  } catch (err) {
    showToast(err.message || 'Error al cargar publicaciones', 'error');
  } finally {
    gdLoading = false;
  }
}

document.getElementById('gd-post-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const btn = e.target.querySelector('[type=submit]');
  const content = document.getElementById('gd-post-content').value.trim();
  if (!content) return;
  btn.disabled = true;
  try {
    const res = await apiFetch('feed/posts', {
      method: 'POST',
      body: JSON.stringify({ content, group_id: GROUP_ID })
    });
    e.target.reset();
    if (res?.data) {
      const post = { ...res.data, author_id: res.data.user_id, author_name: res.data.author_name || UL_USER.name };
      document.getElementById('group-posts').prepend(renderPost(post));
      document.getElementById('gd-posts-empty').classList.add('hidden');
    }
    showToast(res?.data?.status === 'flagged' ? 'Tu publicación quedó en revisión' : 'Publicado en el grupo 🎉', 'success');
  } catch (err) {
    showToast(err.message || 'Error al publicar', 'error');
  } finally {
    btn.disabled = false;
  }
});

document.addEventListener('DOMContentLoaded', () => { loadGroupInfo(); loadGroupPosts(); });
</script>
</body>
</html>
