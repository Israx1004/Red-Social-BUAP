<?php
/**
 * UniLink — users/users_controller.php
 * Directorio seguro, perfil, búsqueda, notificaciones, admin
 */
require_once __DIR__ . '/../../shared/helpers.php';
require_once __DIR__ . '/../../shared/response.php';
require_once __DIR__ . '/user_model.php';

$method = REQUEST_METHOD;
$path   = REQUEST_PATH;
$user   = CURRENT_USER;

// ============================================================
//  GET /users/search
// ============================================================
if ($method === 'GET' && str_contains($path, 'users/search')) {
    $q     = sanitize($_GET['q']     ?? '');
    $limit = min(20, (int)($_GET['limit'] ?? 8));

    if (mb_strlen($q) < 2) Response::success(['results' => []]);

    $results = UserModel::search($q, $limit, $user['user_id']);
    Response::success(['results' => $results]);
}

// ============================================================
//  GET /users/directory  — Protected directory
// ============================================================
if ($method === 'GET' && str_contains($path, 'users/directory')) {
    $limit      = min(50, max(1, (int)($_GET['limit'] ?? 12)));
    $page       = max(1, (int)($_GET['page'] ?? 1));
    $offset     = ($page - 1) * $limit;
    $faculty_id = (int)($_GET['faculty_id'] ?? 0);
    $career_id  = (int)($_GET['career_id']  ?? 0);
    $role       = $_GET['role'] ?? '';
    $q          = trim((string)($_GET['q'] ?? ''));

    $db     = UserModel::getDB();
    $where  = ["u.status = 'active'"];
    $params = [];

    if ($q !== '') {
        $where[]  = "(CONCAT(u.first_name,' ',u.last_name) LIKE ? OR u.student_id LIKE ? OR u.email LIKE ?)";
        $like     = '%' . $q . '%';
        $params[] = $like; $params[] = $like; $params[] = $like;
    }
    if ($faculty_id) { $where[] = 'u.faculty_id = ?'; $params[] = $faculty_id; }
    if ($career_id)  { $where[] = 'u.career_id = ?';  $params[] = $career_id; }
    if (in_array($role, ['student','professor','staff','moderator','admin'], true)) {
        $where[] = 'u.role = ?'; $params[] = $role;
    }

    $whereSQL = implode(' AND ', $where);

    // Con PDO emulado, LIMIT/OFFSET deben enlazarse como enteros (si no: error de sintaxis SQL).
    $stmt = $db->prepare("
        SELECT u.user_id,
               CONCAT(u.first_name,' ',u.last_name) AS name,
               u.student_id, u.semester, u.avatar, u.role, u.bio,
               f.name AS faculty_name, c.name AS career_name,
               CASE WHEN u.show_phone = 1 AND u.user_id <> ? THEN u.phone ELSE NULL END AS phone,
               u.email AS email
        FROM users u
        LEFT JOIN faculties f ON f.faculty_id = u.faculty_id
        LEFT JOIN careers   c ON c.career_id  = u.career_id
        WHERE $whereSQL
        ORDER BY u.first_name, u.last_name
        LIMIT ? OFFSET ?");
    $i = 1;
    $stmt->bindValue($i++, (int)$user['user_id'], PDO::PARAM_INT);
    foreach ($params as $pv) $stmt->bindValue($i++, $pv, is_int($pv) ? PDO::PARAM_INT : PDO::PARAM_STR);
    $stmt->bindValue($i++, $limit,  PDO::PARAM_INT);
    $stmt->bindValue($i++, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $countStmt = $db->prepare("SELECT COUNT(*) FROM users u WHERE $whereSQL");
    $j = 1;
    foreach ($params as $pv) $countStmt->bindValue($j++, $pv, is_int($pv) ? PDO::PARAM_INT : PDO::PARAM_STR);
    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();

    Response::paginated($users, $total, $page, $limit);
}

// ============================================================
//  GET /users/{id}  — Public profile
// ============================================================
if ($method === 'GET' && preg_match('#^users/(\d+)$#', $path, $m)) {
    $id      = (int)$m[1];
    $profile = UserModel::getPublicProfile($id, $user['user_id']);
    if (!$profile) Response::error('Usuario no encontrado.', 404);
    Response::success(['user' => $profile]);
}

// ============================================================
//  GET /users/{id}/groups  — Grupos a los que pertenece un perfil
//  (los grupos privados solo se muestran si quien mira también es miembro o es su propio perfil)
// ============================================================
if ($method === 'GET' && preg_match('#^users/(\d+)/groups$#', $path, $m)) {
    $target = (int)$m[1];
    $me     = (int)$user['user_id'];
    $db     = UserModel::getDB();
    $stmt = $db->prepare("
        SELECT g.group_id, g.name, g.type, g.icon, g.nrc_code, g.member_count, gm.role AS member_role
        FROM group_members gm
        JOIN groups_table g ON g.group_id = gm.group_id
        WHERE gm.user_id = ?
          AND (g.is_private = 0 OR ? = ?
               OR EXISTS (SELECT 1 FROM group_members v WHERE v.group_id = g.group_id AND v.user_id = ?))
        ORDER BY g.name
        LIMIT 50");
    $stmt->bindValue(1, $target, PDO::PARAM_INT);
    $stmt->bindValue(2, $target, PDO::PARAM_INT);
    $stmt->bindValue(3, $me,     PDO::PARAM_INT);
    $stmt->bindValue(4, $me,     PDO::PARAM_INT);
    $stmt->execute();
    Response::success(['groups' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// ============================================================
//  GET /users/{id}/contact  — Get protected contact info
// ============================================================
if ($method === 'GET' && preg_match('#^users/(\d+)/contact$#', $path, $m)) {
    $id = (int)$m[1];
    $db = UserModel::getDB();
    $stmt = $db->prepare("
        SELECT email,
               CASE WHEN show_phone=1 THEN phone ELSE NULL END AS phone
        FROM users WHERE user_id=? AND status='active'");
    $stmt->execute([$id]);
    $contact = $stmt->fetch();
    if (!$contact) Response::error('Usuario no encontrado.', 404);
    Response::success(['contact' => $contact]);
}

// ============================================================
//  POST /users/me/avatar  — Subir foto de perfil
//  (PHP no llena $_FILES en peticiones PUT, por eso va por POST)
// ============================================================
if ($method === 'POST' && $path === 'users/me/avatar') {
    if (empty($_FILES['avatar']) || ($_FILES['avatar']['error'] ?? 1) !== UPLOAD_ERR_OK) {
        Response::error('Selecciona una imagen válida.', 422);
    }
    $url = uploadFile($_FILES['avatar'], 'avatars');
    if (!$url) Response::error('Formato no permitido o archivo mayor a 10 MB.', 422);
    UserModel::getDB()->prepare("UPDATE users SET avatar=? WHERE user_id=?")->execute([$url, (int)$user['user_id']]);
    Response::success(['avatar' => $url], 'Foto actualizada.');
}

// ============================================================
//  POST/DELETE /users/{id}/follow
// ============================================================
if (in_array($method, ['POST','DELETE'], true) && preg_match('#^users/(\d+)/(follow|unfollow)$#', $path, $m)) {
    $target = (int)$m[1];
    $me     = (int)$user['user_id'];
    if ($target === $me) Response::error('No puedes seguirte a ti mismo.', 422);
    $db = UserModel::getDB();
    if ($m[2] === 'follow' && $method === 'POST') {
        $db->prepare("INSERT IGNORE INTO user_follows (follower_id, following_id) VALUES (?,?)")->execute([$me, $target]);
    } else {
        $db->prepare("DELETE FROM user_follows WHERE follower_id=? AND following_id=?")->execute([$me, $target]);
    }
    Response::success(null, 'OK');
}

// ============================================================
//  PUT /users/me  — Update own profile
// ============================================================
if ($method === 'PUT' && str_contains($path, 'users/me')) {
    $body = REQUEST_BODY;
    $allowed = ['bio', 'show_phone', 'phone', 'semester'];
    $updates = [];
    $params  = [];

    foreach ($allowed as $field) {
        if (!array_key_exists($field, $body)) continue;
        $v = $body[$field];
        if (is_array($v)) Response::error("Valor inválido para $field.", 422);
        $v = match ($field) {
            'bio'        => mb_substr(sanitize((string)$v), 0, 300),
            'phone'      => mb_substr(preg_replace('/[^0-9+\-\s()]/', '', (string)$v), 0, 20),
            'show_phone' => !empty($v) && $v !== '0' ? 1 : 0,
            'semester'   => min(12, max(1, (int)$v)),
        };
        $updates[] = "$field = ?";
        $params[]  = $v;
    }

    // Avatar upload
    if (!empty($_FILES['avatar'])) {
        $url = uploadFile($_FILES['avatar'], 'avatars');
        if ($url) { $updates[] = 'avatar = ?'; $params[] = $url; }
    }

    if (empty($updates)) Response::error('Sin cambios para guardar.', 422);

    $params[] = $user['user_id'];
    UserModel::getDB()->prepare("UPDATE users SET " . implode(', ', $updates) . " WHERE user_id=?")
        ->execute($params);

    Response::success(null, 'Perfil actualizado.');
}

// ============================================================
//  GET /users/notifications
// ============================================================
if ($method === 'GET' && str_contains($path, 'users/notifications')) {
    $limit = min(50, (int)($_GET['limit'] ?? 15));
    $db    = UserModel::getDB();

    $stmt = $db->prepare("
        SELECT n.*, CONCAT(u.first_name,' ',u.last_name) AS sender_name
        FROM notifications n
        LEFT JOIN users u ON u.user_id = n.sender_id
        WHERE n.user_id = ?
        ORDER BY n.created_at DESC
        LIMIT ?");
    $stmt->bindValue(1, (int)$user['user_id'], PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $notifs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $unread = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
    $unread->execute([$user['user_id']]);

    Response::success([
        'notifications' => $notifs,
        'unread_count'  => (int)$unread->fetchColumn(),
    ]);
}

// ============================================================
//  PATCH /users/notifications/read-all
// ============================================================
if ($method === 'PATCH' && $path === 'users/notifications/read-all') {
    UserModel::getDB()->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([(int)$user['user_id']]);
    Response::success(null, 'OK');
}

// ============================================================
//  PATCH /users/notifications/{id}/read
// ============================================================
if ($method === 'PATCH' && preg_match('#^users/notifications/(\d+)/read$#', $path, $m)) {
    UserModel::getDB()
        ->prepare("UPDATE notifications SET is_read=1 WHERE notif_id=? AND user_id=?")
        ->execute([(int)$m[1], $user['user_id']]);
    Response::success(null, 'Notificación marcada como leída.');
}

// ============================================================
//  PATCH /users/notifications/read-all
// ============================================================
if ($method === 'PATCH' && str_contains($path, 'notifications/read-all')) {
    UserModel::getDB()
        ->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")
        ->execute([$user['user_id']]);
    Response::success(null, 'Todas las notificaciones marcadas como leídas.');
}

// ============================================================
//  PATCH /users/{id}/role  (admin only)
// ============================================================
if ($method === 'PATCH' && preg_match('#^users/(\d+)/role$#', $path, $m)) {
    if ($user['role'] !== 'admin') Response::error('Solo administradores.', 403);
    $role = sanitize(REQUEST_BODY['role'] ?? '');
    $valid = ['student','professor','admin','moderator','staff'];
    if (!in_array($role, $valid)) Response::error('Rol inválido.', 422);
    if ((int)$m[1] === (int)$user['user_id']) Response::error('No puedes cambiar tu propio rol.', 422);
    UserModel::getDB()->prepare("UPDATE users SET role=? WHERE user_id=?")->execute([$role, (int)$m[1]]);
    Response::success(null, 'Rol actualizado.');
}

// ============================================================
//  PATCH /users/{id}/status  (admin only)
// ============================================================
if ($method === 'PATCH' && preg_match('#^users/(\d+)/status$#', $path, $m)) {
    if ($user['role'] !== 'admin') Response::error('Solo administradores.', 403);
    $status = sanitize(REQUEST_BODY['status'] ?? '');
    $valid  = ['active','inactive','suspended'];
    if (!in_array($status, $valid)) Response::error('Estado inválido.', 422);
    if ((int)$m[1] === (int)$user['user_id']) Response::error('No puedes cambiar tu propio estado.', 422);
    UserModel::getDB()->prepare("UPDATE users SET status=? WHERE user_id=?")->execute([$status, (int)$m[1]]);
    Response::success(null, 'Estado actualizado.');
}

// ============================================================
//  Rutas de administración (solo admin)
// ============================================================
if (str_starts_with($path, 'users/admin') && $user['role'] !== 'admin') {
    Response::error('Solo administradores.', 403);
}

// GET /users/admin/stats
if ($method === 'GET' && $path === 'users/admin/stats') {
    $db  = UserModel::getDB();
    $one = fn(string $sql) => (int)$db->query($sql)->fetchColumn();
    Response::success(['stats' => [
        'total_users'    => $one("SELECT COUNT(*) FROM users"),
        'active_users'   => $one("SELECT COUNT(*) FROM users WHERE status='active' AND last_login >= CURDATE()"),
        'total_posts'    => $one("SELECT COUNT(*) FROM posts WHERE status='published'"),
        'total_listings' => $one("SELECT COUNT(*) FROM listings WHERE status='active'"),
        'open_reports'   => $one("SELECT COUNT(*) FROM reports WHERE status='pending'"),
        'total_groups'   => $one("SELECT COUNT(*) FROM groups_table"),
    ]]);
}

// GET /users/admin/health — estado real de cada componente
if ($method === 'GET' && $path === 'users/admin/health') {
    $health = ['api' => 'ok'];
    try { UserModel::getDB()->query('SELECT 1'); $health['db'] = 'ok'; }
    catch (Throwable $e) { $health['db'] = 'error'; }

    $health['redis'] = 'warning';   // opcional: sin Redis se usa el modo archivo
    if (extension_loaded('redis')) {
        try {
            $r = new Redis();
            if (@$r->connect('127.0.0.1', 6379, 0.3)) $health['redis'] = 'ok';
        } catch (Throwable $e) { /* sigue en warning */ }
    }

    $storage = STORAGE_PATH;
    if (!is_dir($storage)) @mkdir($storage, 0755, true);
    $health['storage'] = is_dir($storage) && is_writable($storage) ? 'ok' : 'error';

    Response::success(['health' => $health]);
}

// POST /users/admin/assign-moderator   { student_id | email, faculty_id }
if ($method === 'POST' && $path === 'users/admin/assign-moderator') {
    $body      = REQUEST_BODY;
    $ident     = trim((string)($body['student_id'] ?? $body['email'] ?? ''));
    $facultyId = (int)($body['faculty_id'] ?? 0);
    if ($ident === '' || !$facultyId) Response::error('Indica la matrícula (o correo) y la facultad.', 422);

    $db = UserModel::getDB();
    $f  = $db->prepare("SELECT 1 FROM faculties WHERE faculty_id = ?");
    $f->execute([$facultyId]);
    if (!$f->fetchColumn()) Response::error('Facultad no encontrada.', 404);

    $st = $db->prepare("SELECT user_id, role FROM users WHERE (student_id = ? OR email = ?) AND status = 'active' LIMIT 1");
    $st->execute([$ident, $ident]);
    $target = $st->fetch();
    if (!$target) Response::error('No se encontró un usuario activo con esa matrícula o correo.', 404);
    if ($target['role'] === 'admin') Response::error('Ese usuario ya es administrador.', 422);

    $db->prepare("UPDATE users SET role='moderator', faculty_id=? WHERE user_id=?")
       ->execute([$facultyId, (int)$target['user_id']]);
    $db->prepare("INSERT INTO notifications (user_id, sender_id, type, message) VALUES (?,?,'system',?)")
       ->execute([(int)$target['user_id'], (int)$user['user_id'], '🛡 Ahora eres moderador de tu facultad en UniLink.']);
    Response::success(['user_id' => (int)$target['user_id']], 'Moderador asignado.');
}

// ============================================================
//  GET /users/admin  (admin only — user management table)
// ============================================================
if ($method === 'GET' && $path === 'users/admin') {
    ['page'=>$page,'limit'=>$limit,'offset'=>$offset] = getPagination();
    $role   = $_GET['role']   ?? '';
    $status = $_GET['status'] ?? '';
    $q      = sanitize($_GET['q'] ?? '');
    $db     = UserModel::getDB();
    $where  = ['1=1'];
    $params = [];

    if (in_array($role, ['student','professor','staff','moderator','admin'], true)) { $where[] = 'u.role = ?'; $params[] = $role; }
    if (in_array($status, ['active','inactive','suspended','pending_verification'], true)) { $where[] = 'u.status = ?'; $params[] = $status; }
    if ($q) {
        $where[] = "(CONCAT(u.first_name,' ',u.last_name) LIKE ? OR u.email LIKE ? OR u.student_id LIKE ?)";
        $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%";
    }
    $whereSQL = implode(' AND ', $where);

    $stmt = $db->prepare("
        SELECT u.user_id, u.first_name, u.last_name, u.email, u.student_id,
               u.role, u.status, u.last_login, u.created_at, u.faculty_id, f.name AS faculty_name
        FROM users u
        LEFT JOIN faculties f ON f.faculty_id = u.faculty_id
        WHERE $whereSQL ORDER BY u.created_at DESC LIMIT ? OFFSET ?");
    $i = 1;
    foreach ($params as $pv) $stmt->bindValue($i++, $pv);
    $stmt->bindValue($i++, $limit,  PDO::PARAM_INT);
    $stmt->bindValue($i++, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $countStmt = $db->prepare("SELECT COUNT(*) FROM users u WHERE $whereSQL");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    Response::paginated($users, $total, $page, $limit);
}

Response::error('Ruta de usuarios no encontrada.', 404);
