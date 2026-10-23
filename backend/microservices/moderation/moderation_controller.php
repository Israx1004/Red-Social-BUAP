<?php
/**
 * UniLink — moderation/moderation_controller.php
 * Reportes, acciones, alertas de pánico, log
 */
require_once __DIR__ . '/../../shared/helpers.php';
require_once __DIR__ . '/../../shared/response.php';

$method = REQUEST_METHOD;
$path   = REQUEST_PATH;
$user   = CURRENT_USER;

// Moderators and admins only (except POST reports and POST panic — any user)
$mod_only_routes = ['moderation/reports', 'moderation/actions', 'moderation/flagged', 'moderation/stats', 'moderation/log',
                    'moderation/panic/', 'moderation/content-stats', 'moderation/system-alert'];
$is_mod_route    = array_reduce($mod_only_routes, fn($c, $r) => $c || str_starts_with($path, $r), false);

// Cualquier usuario autenticado puede ENVIAR un reporte (POST moderation/reports).
// Solo ver/gestionar reportes es exclusivo de moderadores.
if ($method === 'POST' && $path === 'moderation/reports') {
    $is_mod_route = false;
}

if ($is_mod_route && !in_array($user['role'], ['admin','moderator'])) {
    Response::error('Acceso restringido a moderadores.', 403);
}

// ============================================================
//  GET /moderation/stats
// ============================================================
if ($method === 'GET' && str_contains($path, 'moderation/stats')) {
    $db = getDB_mod();

    $facultyFilter = '';
    $params        = [];
    if ($user['role'] === 'moderator') {
        $facultyFilter = 'AND r.faculty_id = ?';
        $params[]      = (int)$user['faculty_id'];
    }

    $pending = $db->prepare("SELECT COUNT(*) FROM reports r WHERE r.status='pending' $facultyFilter");
    $pending->execute($params);

    $resolvedToday = $db->prepare("SELECT COUNT(*) FROM reports r WHERE r.status='resolved' AND DATE(r.reviewed_at)=CURDATE() $facultyFilter");
    $resolvedToday->execute($params);

    $panicActive = $db->prepare("SELECT COUNT(*) FROM panic_alerts WHERE status='active'");
    $panicActive->execute();

    $flagged = $db->prepare("SELECT COUNT(*) FROM posts p WHERE p.status='flagged' " . ($facultyFilter ? 'AND p.faculty_id = ?' : ''));
    $flagged->execute($params);

    Response::success(['stats' => [
        'pending_reports' => (int)$pending->fetchColumn(),
        'resolved_today'  => (int)$resolvedToday->fetchColumn(),
        'active_panic'    => (int)$panicActive->fetchColumn(),
        'flagged_posts'   => (int)$flagged->fetchColumn(),
    ]]);
}

// ============================================================
//  GET /moderation/reports
// ============================================================
if ($method === 'GET' && preg_match('#^moderation/reports$#', $path)) {
    $status    = $_GET['status'] ?? 'pending';
    $reason    = $_GET['reason'] ?? '';
    $limit     = min(50, max(1, (int)($_GET['limit'] ?? 20)));

    $db     = getDB_mod();
    $where  = ['1=1'];
    $params = [];

    if (in_array($status, ['pending','reviewed','resolved','dismissed'], true)) { $where[] = 'r.status = ?'; $params[] = $status; }
    if (in_array($reason, ['spam','harassment','hate_speech','false_info','inappropriate','other'], true)) { $where[] = 'r.reason = ?'; $params[] = $reason; }

    // Moderators only see their faculty's reports
    if ($user['role'] === 'moderator') {
        $where[]  = 'r.faculty_id = ?';
        $params[] = (int)$user['faculty_id'];
    }

    $whereSQL = implode(' AND ', $where);
    $stmt = $db->prepare("
        SELECT r.*,
               CONCAT(reporter.first_name,' ',reporter.last_name) AS reporter_name,
               p.content  AS post_content,
               p.status   AS post_status,
               p.user_id  AS author_id,
               p.created_at AS post_created_at,
               CONCAT(author.first_name,' ',author.last_name) AS author_name
        FROM reports r
        JOIN users reporter ON reporter.user_id = r.reporter_id
        LEFT JOIN posts p ON p.post_id = r.post_id
        LEFT JOIN users author ON author.user_id = p.user_id
        WHERE $whereSQL
        ORDER BY r.created_at DESC
        LIMIT ?");
    $params[] = $limit;
    ul_bind($stmt, $params);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    Response::success(['reports' => $reports]);
}

// ============================================================
//  POST /moderation/reports  (any authenticated user)
// ============================================================
if ($method === 'POST' && preg_match('#^moderation/reports$#', $path)) {
    $body      = REQUEST_BODY;
    $post_id   = (int)($body['post_id']   ?? 0);
    $reason    = sanitize($body['reason'] ?? '');
    $details   = sanitize($body['details'] ?? '');

    if (!$post_id || !$reason) Response::error('Datos incompletos.', 422);

    // Prevent self-reporting loop
    $allowedReasons = ['spam','harassment','hate_speech','false_info','inappropriate','other'];
    if (!in_array($reason, $allowedReasons, true)) $reason = 'other';

    $db = getDB_mod();
    $post = $db->prepare("SELECT user_id, faculty_id FROM posts WHERE post_id=?");
    $post->execute([$post_id]);
    $postData = $post->fetch();
    if (!$postData) Response::error('Publicación no encontrada.', 404);
    if ((int)$postData['user_id'] === (int)$user['user_id']) Response::error('No puedes reportarte a ti mismo.', 422);

    // Check duplicate report
    $dup = $db->prepare("SELECT 1 FROM reports WHERE reporter_id=? AND post_id=? AND status='pending'");
    $dup->execute([$user['user_id'], $post_id]);
    if ($dup->fetchColumn()) Response::error('Ya tienes un reporte pendiente para esta publicación.', 409);

    $stmt = $db->prepare("
        INSERT INTO reports (reporter_id, post_id, reason, details, faculty_id)
        VALUES (?,?,?,?,?)");
    $stmt->execute([(int)$user['user_id'], $post_id, $reason, $details, $postData['faculty_id']]);

    // Notify moderators via Redis queue
    // MessageQueue::publish('moderation', ['type'=>'new_report', 'faculty_id'=>$postData['faculty_id']]);

    Response::success(null, 'Reporte enviado.', 201);
}

// ============================================================
//  POST /moderation/actions
// ============================================================
if ($method === 'POST' && preg_match('#^moderation/actions$#', $path)) {
    $body      = REQUEST_BODY;
    $action    = sanitize($body['action']    ?? '');
    $report_id = (int)($body['report_id']   ?? 0);
    $target_id = (int)($body['target_id']   ?? 0);
    $reason    = sanitize($body['reason']   ?? '');

    $valid_actions = ['remove_post','warn_user','suspend_user','dismiss','restore_post'];
    if (!in_array($action, $valid_actions, true)) Response::error('Acción no válida.', 422);

    $db = getDB_mod();

    // Si viene de un reporte, el objetivo es SIEMPRE la publicación del reporte
    if ($report_id) {
        $rep = $db->prepare("SELECT post_id, faculty_id, status FROM reports WHERE report_id = ?");
        $rep->execute([$report_id]);
        $repRow = $rep->fetch();
        if (!$repRow) Response::error('Reporte no encontrado.', 404);
        if ($user['role'] === 'moderator' && (int)$repRow['faculty_id'] !== (int)$user['faculty_id']) {
            Response::error('Este reporte pertenece a otra facultad.', 403);
        }
        if ($action !== 'dismiss') $target_id = (int)$repRow['post_id'];
    }

    // Un moderador solo actúa sobre publicaciones de su facultad
    if ($target_id && $action !== 'dismiss' && $user['role'] === 'moderator') {
        $pf = $db->prepare("SELECT faculty_id FROM posts WHERE post_id = ?");
        $pf->execute([$target_id]);
        $postFaculty = $pf->fetchColumn();
        if ($postFaculty === false) Response::error('Publicación no encontrada.', 404);
        if ($postFaculty !== null && (int)$postFaculty !== (int)$user['faculty_id']) {
            Response::error('Esta publicación pertenece a otra facultad.', 403);
        }
    }

    // Execute action
    switch ($action) {
        case 'remove_post':
            if (!$target_id) Response::error('post_id requerido.', 422);
            $db->prepare("UPDATE posts SET status='removed' WHERE post_id=?")->execute([$target_id]);
            // Log
            logModAction($db, $user['user_id'], 'remove_post', $target_id, 'post', $reason ?: 'Reporte de usuario');
            break;

        case 'restore_post':
            if (!$target_id) Response::error('post_id requerido.', 422);
            $db->prepare("UPDATE posts SET status='published' WHERE post_id=?")->execute([$target_id]);
            logModAction($db, $user['user_id'], 'restore_post', $target_id, 'post', $reason ?: 'Restaurada por moderación');
            break;

        case 'warn_user':
        case 'suspend_user':
            $postAuthor = $db->prepare("SELECT user_id FROM posts WHERE post_id=?");
            $postAuthor->execute([$target_id]);
            $authorId = (int)$postAuthor->fetchColumn();
            if (!$authorId) Response::error('No se encontró al autor de la publicación.', 404);

            $authorRole = $db->prepare("SELECT role FROM users WHERE user_id=?");
            $authorRole->execute([$authorId]);
            if (in_array($authorRole->fetchColumn(), ['admin', 'moderator'], true) && $user['role'] !== 'admin') {
                Response::error('Solo un administrador puede sancionar a otro moderador.', 403);
            }

            if ($action === 'warn_user') {
                $msg = 'Has recibido una advertencia del equipo de moderación' . ($reason ? ": $reason" : '.');
                $db->prepare("INSERT INTO notifications (user_id, sender_id, type, message) VALUES (?,?,'moderation',?)")
                   ->execute([$authorId, (int)$user['user_id'], mb_substr($msg, 0, 300)]);
            } else {
                $db->prepare("UPDATE users SET status='suspended' WHERE user_id=?")->execute([$authorId]);
            }
            logModAction($db, $user['user_id'], $action, $authorId, 'user', $reason);
            break;

        case 'dismiss':
            // No action on content, just close the report
            logModAction($db, $user['user_id'], 'dismiss_report', $report_id ?: null, 'post', $reason ?: 'Reporte desestimado');
            break;
    }

    // Update report status
    if ($report_id) {
        $newStatus = $action === 'dismiss' ? 'dismissed' : 'resolved';
        $db->prepare("UPDATE reports SET status=?, moderator_id=?, reviewed_at=NOW() WHERE report_id=?")
           ->execute([$newStatus, $user['user_id'], $report_id]);
    }

    Response::success(null, 'Acción ejecutada y registrada en el log.');
}

// ============================================================
//  GET /moderation/flagged
// ============================================================
if ($method === 'GET' && str_contains($path, 'moderation/flagged')) {
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
    $db    = getDB_mod();

    $where  = ["p.status = 'flagged'"];
    $params = [];
    if ($user['role'] === 'moderator') {
        $where[]  = 'p.faculty_id = ?';
        $params[] = (int)$user['faculty_id'];
    }
    $whereSQL = implode(' AND ', $where);
    $params[] = $limit;

    $stmt = $db->prepare("
        SELECT p.post_id, p.content, p.created_at,
               CONCAT(u.first_name,' ',u.last_name) AS author_name
        FROM posts p
        JOIN users u ON u.user_id = p.user_id
        WHERE $whereSQL
        ORDER BY p.created_at DESC
        LIMIT ?");
    ul_bind($stmt, $params);
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    Response::success(['posts' => $posts]);
}

// ============================================================
//  PATCH /moderation/flagged/{id}/approve
// ============================================================
if ($method === 'PATCH' && preg_match('#^moderation/flagged/(\d+)/approve$#', $path, $m)) {
    $db = getDB_mod();
    $upd = $db->prepare("UPDATE posts SET status='published' WHERE post_id=? AND status='flagged'"
                        . ($user['role'] === 'moderator' ? ' AND (faculty_id = ? OR faculty_id IS NULL)' : ''));
    $upd->execute($user['role'] === 'moderator' ? [(int)$m[1], (int)$user['faculty_id']] : [(int)$m[1]]);
    if (!$upd->rowCount()) Response::error('Publicación no encontrada o fuera de tu facultad.', 404);
    logModAction($db, $user['user_id'], 'restore_post', (int)$m[1], 'post', 'Aprobado por moderador');
    Response::success(null, 'Publicación aprobada.');
}

// ============================================================
//  POST /moderation/panic  (any user)
// ============================================================
if ($method === 'POST' && preg_match('#^moderation/panic$#', $path)) {
    $body = REQUEST_BODY;
    $db   = getDB_mod();
    $stmt = $db->prepare("
        INSERT INTO panic_alerts (user_id, latitude, longitude, location_text)
        VALUES (?,?,?,?)");
    $lat = isset($body['lat']) && is_numeric($body['lat']) ? (float)$body['lat'] : null;
    $lng = isset($body['lng']) && is_numeric($body['lng']) ? (float)$body['lng'] : null;
    $stmt->execute([
        (int)$user['user_id'],
        $lat,
        $lng,
        sanitize($body['location'] ?? 'desconocida'),
    ]);
    $alertId = (int)$db->lastInsertId();

    // ── Notificar a TODOS los administradores activos ─────────────
    $who = $db->prepare("SELECT first_name, last_name, email FROM users WHERE user_id=?");
    $who->execute([(int)$user['user_id']]);
    $w = $who->fetch() ?: ['first_name'=>'Usuario','last_name'=>'','email'=>''];
    $name = trim($w['first_name'] . ' ' . $w['last_name']);

    $msg = "🚨 ALERTA DE PÁNICO: {$name} ({$w['email']}) presionó el botón de pánico";
    if ($lat !== null && $lng !== null) $msg .= ' · Ubicación: ' . round($lat, 5) . ', ' . round($lng, 5);
    $msg = mb_substr($msg, 0, 300);
    $link = ($lat !== null && $lng !== null)
        ? "https://www.google.com/maps?q={$lat},{$lng}"
        : BASE_PATH . '/frontend/pages/profile.php?id=' . (int)$user['user_id'];

    $admins = $db->query("SELECT user_id FROM users WHERE role='admin' AND status='active'")->fetchAll(PDO::FETCH_COLUMN);
    $ins = $db->prepare("INSERT INTO notifications (user_id, sender_id, type, message, link) VALUES (?,?,'moderation',?,?)");
    foreach ($admins as $adminId) {
        $ins->execute([(int)$adminId, (int)$user['user_id'], $msg, $link]);
    }

    logEvent('critical', 'PANIC ALERT', [
        'user_id'   => $user['user_id'],
        'timestamp' => date('c'),
        'lat'       => $body['lat'] ?? null,
        'lng'       => $body['lng'] ?? null,
    ]);

    Response::success(['alert_id' => $alertId, 'admins_notified' => count($admins)], 'Alerta enviada a seguridad del campus.', 201);
}

// ============================================================
//  GET /moderation/panic
// ============================================================
if ($method === 'GET' && preg_match('#^moderation/panic$#', $path)) {
    if (!in_array($user['role'], ['admin','moderator'])) Response::error('Acceso restringido.', 403);
    $db = getDB_mod();
    $stmt = $db->prepare("
        SELECT pa.*, CONCAT(u.first_name,' ',u.last_name) AS user_name, u.email
        FROM panic_alerts pa
        JOIN users u ON u.user_id = pa.user_id
        ORDER BY pa.created_at DESC LIMIT 20");
    $stmt->execute();
    Response::success(['alerts' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// PATCH /moderation/panic/{id}/acknowledge  &  /resolve
if ($method === 'PATCH' && preg_match('#^moderation/panic/(\d+)/(acknowledge|resolve)$#', $path, $m)) {
    // (el acceso ya quedó limitado a admin/moderador arriba, por 'moderation/panic/')
    $status = $m[2] === 'acknowledge' ? 'acknowledged' : 'resolved';
    $upd = getDB_mod()->prepare("UPDATE panic_alerts SET status=? WHERE alert_id=?");
    $upd->execute([$status, (int)$m[1]]);
    if (!$upd->rowCount()) Response::error('Alerta no encontrada.', 404);
    Response::success(null, 'Alerta actualizada.');
}

// ============================================================
//  GET /moderation/log
// ============================================================
if ($method === 'GET' && str_contains($path, 'moderation/log')) {
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));
    $db    = getDB_mod();
    $stmt  = $db->prepare("
        SELECT ml.*, CONCAT(u.first_name,' ',u.last_name) AS moderator_name
        FROM moderation_log ml
        LEFT JOIN users u ON u.user_id = ml.moderator_id
        ORDER BY ml.created_at DESC
        LIMIT ?");
    ul_bind($stmt, [$limit]);
    Response::success(['log' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// ============================================================
//  GET /moderation/content-stats  (admin)
// ============================================================
if ($method === 'GET' && $path === 'moderation/content-stats') {
    if ($user['role'] !== 'admin') Response::error('Solo administradores.', 403);
    $db = getDB_mod();
    $one = fn(string $sql) => (int)$db->query($sql)->fetchColumn();
    Response::success(['stats' => [
        'published_posts' => $one("SELECT COUNT(*) FROM posts WHERE status='published'"),
        'removed_posts'   => $one("SELECT COUNT(*) FROM posts WHERE status='removed'"),
        'flagged_posts'   => $one("SELECT COUNT(*) FROM posts WHERE status='flagged'"),
        'comments_today'  => $one("SELECT COUNT(*) FROM comments WHERE DATE(created_at)=CURDATE()"),
        'active_listings' => $one("SELECT COUNT(*) FROM listings WHERE status='active'"),
        'pending_reports' => $one("SELECT COUNT(*) FROM reports WHERE status='pending'"),
    ]]);
}

// ============================================================
//  POST /moderation/system-alert  (admin) — anuncio a todos
// ============================================================
if ($method === 'POST' && $path === 'moderation/system-alert') {
    if ($user['role'] !== 'admin') Response::error('Solo administradores.', 403);
    $message = mb_substr(sanitize(REQUEST_BODY['message'] ?? ''), 0, 290);
    if ($message === '') Response::error('Escribe el anuncio.', 422);

    $db = getDB_mod();
    $st = $db->prepare("INSERT INTO notifications (user_id, sender_id, type, message)
                        SELECT user_id, ?, 'system', ? FROM users WHERE status = 'active'");
    $st->execute([(int)$user['user_id'], '📢 ' . $message]);
    logEvent('info', 'System announcement', ['by' => $user['user_id'], 'recipients' => $st->rowCount()]);
    Response::success(['recipients' => $st->rowCount()], 'Anuncio enviado.');
}

Response::error('Ruta de moderación no encontrada.', 404);

// ============================================================
//  Helpers
// ============================================================
function logModAction(PDO $db, int $modId, string $action, ?int $targetId, string $targetType, string $reason = ''): void {
    $db->prepare("INSERT INTO moderation_log (moderator_id, action, target_id, target_type, reason) VALUES (?,?,?,?,?)")
       ->execute([$modId, $action, $targetId, $targetType, $reason]);
}

function getDB_mod(): PDO {
    return getDBConnection();
}
