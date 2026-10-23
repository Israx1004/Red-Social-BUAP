<?php
/**
 * UniLink — academic/academic_controller.php
 * Grupos NRC, calendario de eventos, encuestas
 */
require_once __DIR__ . '/../../shared/helpers.php';
require_once __DIR__ . '/../../shared/response.php';

$method = REQUEST_METHOD;
$path   = REQUEST_PATH;
$user   = CURRENT_USER;

// ============================================================
//  GET /academic/my-groups
// ============================================================
if ($method === 'GET' && str_contains($path, 'my-groups')) {
    $limit = min(20, (int)($_GET['limit'] ?? 10));
    $db    = getDB_academic();

    $stmt = $db->prepare("
        SELECT g.group_id, g.name, g.type, g.icon, g.nrc_code,
               g.member_count, gm.role AS member_role, 0 AS unread
        FROM groups_table g
        JOIN group_members gm ON gm.group_id = g.group_id AND gm.user_id = ?
        ORDER BY g.name
        LIMIT ?");
    $stmt->bindValue(1, (int)$user['user_id'], PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

    Response::success(['groups' => $groups]);
}

// ============================================================
//  GET /academic/groups  — list all groups (with search)
// ============================================================
if ($method === 'GET' && preg_match('#^academic/groups$#', $path)) {
    ['page'=>$page,'limit'=>$limit,'offset'=>$offset] = getPagination();
    $q          = sanitize($_GET['q']          ?? '');
    $type       = sanitize($_GET['type']       ?? '');
    $faculty_id = (int)($_GET['faculty_id']   ?? 0);
    $joined_only = in_array($_GET['joined'] ?? '', ['1', 'true'], true);

    $db    = getDB_academic();
    // Los grupos privados solo aparecen a sus miembros o si se busca su NRC exacto
    $where  = ['(g.is_private = 0 OR EXISTS (SELECT 1 FROM group_members gmp WHERE gmp.group_id = g.group_id AND gmp.user_id = ?) OR (g.nrc_code IS NOT NULL AND g.nrc_code = ?))'];
    $params = [(int)$user['user_id'], $q];

    if ($q) {
        $where[]  = '(g.name LIKE ? OR g.nrc_code LIKE ?)';
        $params[] = "%$q%";
        $params[] = "%$q%";
    }
    if ($type)       { $where[] = 'g.type = ?';       $params[] = $type; }
    if ($faculty_id) { $where[] = 'g.faculty_id = ?'; $params[] = $faculty_id; }
    if ($joined_only) {
        $where[] = 'EXISTS (SELECT 1 FROM group_members gm2 WHERE gm2.group_id=g.group_id AND gm2.user_id=?)';
        $params[] = $user['user_id'];
    }

    $whereSQL = implode(' AND ', $where);
    $stmt = $db->prepare("
        SELECT g.*,
               f.name AS faculty_name,
               EXISTS (SELECT 1 FROM group_members gm WHERE gm.group_id=g.group_id AND gm.user_id=?) AS is_member,
               (SELECT role FROM group_members gr WHERE gr.group_id=g.group_id AND gr.user_id=?) AS member_role
        FROM groups_table g
        LEFT JOIN faculties f ON f.faculty_id = g.faculty_id
        WHERE $whereSQL
        ORDER BY g.member_count DESC
        LIMIT ? OFFSET ?");

    $i = 1;
    $stmt->bindValue($i++, (int)$user['user_id'], PDO::PARAM_INT);
    $stmt->bindValue($i++, (int)$user['user_id'], PDO::PARAM_INT);
    foreach ($params as $pv) $stmt->bindValue($i++, $pv, is_int($pv) ? PDO::PARAM_INT : PDO::PARAM_STR);
    $stmt->bindValue($i++, $limit,  PDO::PARAM_INT);
    $stmt->bindValue($i++, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $countStmt = $db->prepare("SELECT COUNT(*) FROM groups_table g WHERE $whereSQL");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    Response::paginated($groups, $total, $page, $limit);
}

// ============================================================
//  GET /academic/groups/{id}
// ============================================================
if ($method === 'GET' && preg_match('#^academic/groups/(\d+)$#', $path, $m)) {
    $id = (int)$m[1];
    $db = getDB_academic();

    $stmt = $db->prepare("
        SELECT g.*, f.name AS faculty_name,
               EXISTS (SELECT 1 FROM group_members gm WHERE gm.group_id=g.group_id AND gm.user_id=?) AS is_member,
               (SELECT role FROM group_members WHERE group_id=g.group_id AND user_id=?) AS my_role
        FROM groups_table g
        LEFT JOIN faculties f ON f.faculty_id = g.faculty_id
        WHERE g.group_id = ?");
    $stmt->execute([$user['user_id'], $user['user_id'], $id]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$group) Response::error('Grupo no encontrado.', 404);

    // Grupo privado: quien no es miembro solo ve la información básica
    if ((int)$group['is_private'] === 1 && !(int)$group['is_member']) {
        $group['members'] = [];
        Response::success(['group' => $group]);
    }

    // Recent members
    $members = $db->prepare("
        SELECT u.user_id, CONCAT(u.first_name,' ',u.last_name) AS name,
               u.avatar, gm.role, gm.joined_at
        FROM group_members gm
        JOIN users u ON u.user_id = gm.user_id
        WHERE gm.group_id = ?
        ORDER BY gm.joined_at DESC
        LIMIT 20");
    $members->execute([$id]);
    $group['members'] = $members->fetchAll(PDO::FETCH_ASSOC);

    Response::success(['group' => $group]);
}

// ============================================================
//  POST /academic/groups/{id}/join
// ============================================================
if ($method === 'POST' && preg_match('#^academic/groups/(\d+)/join$#', $path, $m)) {
    $id = (int)$m[1];
    $db = getDB_academic();

    $g = $db->prepare("SELECT group_id FROM groups_table WHERE group_id = ?");
    $g->execute([$id]);
    if (!$g->fetchColumn()) Response::error('Grupo no encontrado.', 404);

    // Check if already member
    $check = $db->prepare("SELECT 1 FROM group_members WHERE group_id=? AND user_id=?");
    $check->execute([$id, $user['user_id']]);
    if ($check->fetchColumn()) Response::error('Ya eres miembro de este grupo.', 409);

    $db->beginTransaction();
    $ins = $db->prepare("INSERT IGNORE INTO group_members (group_id, user_id) VALUES (?,?)");
    $ins->execute([$id, $user['user_id']]);
    if ($ins->rowCount()) {
        $db->prepare("UPDATE groups_table SET member_count = member_count + 1 WHERE group_id=?")
           ->execute([$id]);
    }
    $db->commit();

    Response::success(null, 'Te has unido al grupo.', 201);
}

// ============================================================
//  DELETE /academic/groups/{id}/leave
// ============================================================
if ($method === 'DELETE' && preg_match('#^academic/groups/(\d+)/leave$#', $path, $m)) {
    $id = (int)$m[1];
    $db = getDB_academic();

    $stmt = $db->prepare("DELETE FROM group_members WHERE group_id=? AND user_id=? AND role != 'admin'");
    $stmt->execute([$id, $user['user_id']]);
    if (!$stmt->rowCount()) Response::error('No puedes salir de este grupo (eres admin o no eres miembro).', 403);

    $db->prepare("UPDATE groups_table SET member_count = GREATEST(0, member_count - 1) WHERE group_id=?")
       ->execute([$id]);

    Response::success(null, 'Saliste del grupo.');
}

// ============================================================
//  DELETE /academic/groups/{id}  (admin del sitio o admin del grupo)
// ============================================================
if ($method === 'DELETE' && preg_match('#^academic/groups/(\d+)$#', $path, $m)) {
    $id = (int)$m[1];
    $db = getDB_academic();
    if ($user['role'] !== 'admin') {
        $r = $db->prepare("SELECT role FROM group_members WHERE group_id=? AND user_id=?");
        $r->execute([$id, (int)$user['user_id']]);
        if ($r->fetchColumn() !== 'admin') Response::error('Solo el administrador del grupo puede eliminarlo.', 403);
    }
    $del = $db->prepare("DELETE FROM groups_table WHERE group_id = ?");
    $del->execute([$id]);
    if (!$del->rowCount()) Response::error('Grupo no encontrado.', 404);
    Response::success(null, 'Grupo eliminado.');
}

// ============================================================
//  POST /academic/groups — Create group
// ============================================================
if ($method === 'POST' && preg_match('#^academic/groups$#', $path)) {
    $body = REQUEST_BODY;
    $name       = mb_substr(sanitize($body['name'] ?? ''), 0, 200);
    $description= sanitize($body['description']?? '');
    $types      = ['nrc','faculty','career','club','study','general'];
    $type       = in_array($body['type'] ?? '', $types, true) ? $body['type'] : 'general';
    $nrc        = mb_substr(preg_replace('/[^0-9A-Za-z\-]/', '', (string)($body['nrc_code'] ?? '')), 0, 20);
    $icon       = mb_substr(sanitize($body['icon'] ?? '👥'), 0, 10) ?: '👥';
    $private    = !empty($body['is_private']) && $body['is_private'] !== '0' ? 1 : 0;

    if (!$name) Response::error('El nombre del grupo es requerido.', 422);
    if ($type === 'nrc' && $nrc === '') Response::error('Los grupos de materia necesitan un código NRC.', 422);

    $db = getDB_academic();
    if ($nrc !== '') {
        $dup = $db->prepare("SELECT 1 FROM groups_table WHERE nrc_code = ?");
        $dup->execute([$nrc]);
        if ($dup->fetchColumn()) Response::error("Ya existe un grupo con el NRC $nrc. Búscalo en la pestaña Unirse por NRC.", 409);
    }

    $db->beginTransaction();
    $stmt = $db->prepare("
        INSERT INTO groups_table (name, description, type, nrc_code, faculty_id, icon, is_private, member_count, created_by)
        VALUES (?,?,?,?,?,?,?,1,?)");
    $stmt->execute([$name, $description, $type, $nrc ?: null,
                    !empty($user['faculty_id']) ? (int)$user['faculty_id'] : null,
                    $icon, $private, (int)$user['user_id']]);
    $gid = (int)$db->lastInsertId();

    // Creator becomes admin
    $db->prepare("INSERT INTO group_members (group_id, user_id, role) VALUES (?,?,'admin')")
       ->execute([$gid, $user['user_id']]);
    $db->commit();

    Response::success(['group_id' => $gid], 'Grupo creado.', 201);
}

// ============================================================
//  GET /academic/events/{id}
// ============================================================
if ($method === 'GET' && preg_match('#^academic/events/(\d+)$#', $path, $m)) {
    $db   = getDB_academic();
    $stmt = $db->prepare("
        SELECT e.*, CONCAT(u.first_name,' ',u.last_name) AS organizer_name, f.name AS faculty_name
        FROM academic_calendar e
        JOIN users u ON u.user_id = e.created_by
        LEFT JOIN faculties f ON f.faculty_id = e.faculty_id
        WHERE e.event_id = ?");
    $stmt->bindValue(1, (int)$m[1], PDO::PARAM_INT);
    $stmt->execute();
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$event) Response::error('Evento no encontrado.', 404);
    Response::success($event);
}

// ============================================================
//  GET /academic/events   (?year=&month=&upcoming=1&limit=)
// ============================================================
if ($method === 'GET' && preg_match('#^academic/events$#', $path)) {
    $limit      = min(200, max(1, (int)($_GET['limit'] ?? 100)));
    $page       = max(1, (int)($_GET['page'] ?? 1));
    $offset     = ($page - 1) * $limit;
    $upcoming   = !empty($_GET['upcoming']) && $_GET['upcoming'] !== '0';
    $year       = (int)($_GET['year']  ?? 0);
    $month      = (int)($_GET['month'] ?? 0);
    $faculty_id = (int)($user['faculty_id'] ?? 0);

    $db     = getDB_academic();
    $where  = ['e.is_public = 1'];
    $params = [];

    if ($upcoming) {
        $where[] = 'e.event_date >= NOW()';
    } elseif ($year >= 2000 && $month >= 1 && $month <= 12) {
        // Mes visible + margen de una semana a cada lado (vista de semana / días de otro mes)
        $from = sprintf('%04d-%02d-01 00:00:00', $year, $month);
        $where[]  = 'e.event_date >= DATE_SUB(?, INTERVAL 7 DAY)';
        $where[]  = 'e.event_date <  DATE_ADD(DATE_ADD(?, INTERVAL 1 MONTH), INTERVAL 7 DAY)';
        $params[] = $from;
        $params[] = $from;
    }
    if ($faculty_id) { $where[] = '(e.faculty_id = ? OR e.faculty_id IS NULL)'; $params[] = $faculty_id; }

    $whereSQL = implode(' AND ', $where);
    $stmt = $db->prepare("
        SELECT e.*, CONCAT(u.first_name,' ',u.last_name) AS organizer_name, f.name AS faculty_name
        FROM academic_calendar e
        JOIN users u ON u.user_id = e.created_by
        LEFT JOIN faculties f ON f.faculty_id = e.faculty_id
        WHERE $whereSQL
        ORDER BY e.event_date ASC
        LIMIT ? OFFSET ?");
    $i = 1;
    foreach ($params as $p) $stmt->bindValue($i++, $p);
    $stmt->bindValue($i++, $limit,  PDO::PARAM_INT);
    $stmt->bindValue($i++, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($events as &$ev) { $ev['event_id'] = (int)$ev['event_id']; }
    unset($ev);

    $countStmt = $db->prepare("SELECT COUNT(*) FROM academic_calendar e WHERE $whereSQL");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    Response::paginated($events, $total, $page, $limit);
}

// ============================================================
//  POST /academic/events
// ============================================================
if ($method === 'POST' && preg_match('#^academic/events$#', $path)) {
    $body = REQUEST_BODY;
    foreach (['title', 'event_date'] as $f) {
        if (empty($body[$f])) Response::error("$f es requerido.", 422);
    }

    $start = strtotime((string)$body['event_date']);
    if (!$start) Response::error('Fecha de inicio inválida.', 422);
    $end = !empty($body['end_date']) ? strtotime((string)$body['end_date']) : null;
    if (!empty($body['end_date']) && !$end) Response::error('Fecha de fin inválida.', 422);

    $validTypes = ['clase','examen','taller','conferencia','deportivo','cultural','institutional','otro'];
    $type = in_array($body['type'] ?? '', $validTypes, true) ? $body['type'] : 'otro';

    $db = getDB_academic();
    $stmt = $db->prepare("
        INSERT INTO academic_calendar
          (title, description, event_date, end_date, location, type, faculty_id, created_by, is_public)
        VALUES (?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        sanitize($body['title']),
        sanitize($body['description'] ?? ''),
        date('Y-m-d H:i:s', $start),
        $end ? date('Y-m-d H:i:s', $end) : null,
        sanitize($body['location'] ?? ''),
        $type,
        // scope=faculty → solo tu facultad; cualquier otro valor → toda la BUAP
        (($body['scope'] ?? 'all') === 'faculty' && !empty($user['faculty_id'])) ? (int)$user['faculty_id'] : null,
        (int)$user['user_id'],
        !empty($body['is_public']) || !array_key_exists('is_public', $body) ? 1 : 0,
    ]);

    Response::success(['event_id' => (int)$db->lastInsertId()], 'Evento creado.', 201);
}

// ============================================================
//  ENCUESTAS
// ============================================================
function ul_poll_load(PDO $db, int $pollId, int $userId): ?array {
    $st = $db->prepare("
        SELECT p.*, EXISTS(SELECT 1 FROM poll_votes v WHERE v.poll_id = p.poll_id AND v.user_id = ?) AS user_voted
        FROM polls p WHERE p.poll_id = ?");
    $st->bindValue(1, $userId, PDO::PARAM_INT);
    $st->bindValue(2, $pollId, PDO::PARAM_INT);
    $st->execute();
    $poll = $st->fetch(PDO::FETCH_ASSOC);
    if (!$poll) return null;

    $op = $db->prepare("SELECT option_id, text, votes FROM poll_options WHERE poll_id = ? ORDER BY sort_order, option_id");
    $op->bindValue(1, $pollId, PDO::PARAM_INT);
    $op->execute();
    $poll['options']     = array_map(fn($o) => ['option_id' => (int)$o['option_id'], 'text' => $o['text'], 'votes' => (int)$o['votes']], $op->fetchAll(PDO::FETCH_ASSOC));
    $poll['poll_id']     = (int)$poll['poll_id'];
    $poll['total_votes'] = (int)$poll['total_votes'];
    $poll['user_voted']  = (bool)$poll['user_voted'];
    return $poll;
}

// GET /academic/polls?category=&status=&limit=
if ($method === 'GET' && preg_match('#^academic/polls$#', $path)) {
    $limit    = min(50, max(1, (int)($_GET['limit'] ?? 30)));
    $category = $_GET['category'] ?? '';
    $status   = $_GET['status']   ?? '';
    $cats     = ['general','cafeteria','laboratorio','transporte','biblioteca','academico'];

    $db     = getDB_academic();
    $where  = ["p.status <> 'draft'"];
    $params = [];
    if (in_array($category, $cats, true))           { $where[] = 'p.category = ?'; $params[] = $category; }
    if (in_array($status, ['active','closed'], true)) { $where[] = 'p.status = ?';   $params[] = $status; }
    // Cierre automático por fecha
    $db->exec("UPDATE polls SET status='closed' WHERE status='active' AND closes_at IS NOT NULL AND closes_at < NOW()");

    $st = $db->prepare("SELECT p.poll_id FROM polls p WHERE " . implode(' AND ', $where) . " ORDER BY p.created_at DESC LIMIT ?");
    $i = 1;
    foreach ($params as $pv) $st->bindValue($i++, $pv);
    $st->bindValue($i, $limit, PDO::PARAM_INT);
    $st->execute();

    $polls = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $pid) {
        $row = ul_poll_load($db, (int)$pid, (int)$user['user_id']);
        if ($row) $polls[] = $row;
    }
    Response::success($polls);
}

// POST /academic/polls
if ($method === 'POST' && preg_match('#^academic/polls$#', $path)) {
    $body  = REQUEST_BODY;
    $title = sanitize($body['title'] ?? '');
    if (mb_strlen($title) < 5) Response::error('Escribe la pregunta de la encuesta (mínimo 5 caracteres).', 422);

    $type = in_array($body['poll_type'] ?? '', ['options','rating','yesno'], true) ? $body['poll_type'] : 'options';
    $cats = ['general','cafeteria','laboratorio','transporte','biblioteca','academico'];
    $cat  = in_array($body['category'] ?? '', $cats, true) ? $body['category'] : 'general';

    $options = [];
    if ($type === 'options') {
        foreach ((array)($body['options'] ?? []) as $o) {
            $o = sanitize((string)$o);
            if ($o !== '') $options[] = mb_substr($o, 0, 200);
        }
        $options = array_values(array_unique($options));
        if (count($options) < 2) Response::error('Agrega al menos 2 opciones.', 422);
        if (count($options) > 8) $options = array_slice($options, 0, 8);
    } elseif ($type === 'yesno') {
        $options = ['Sí', 'No'];
    }

    $closes = null;
    if (!empty($body['closes_at'])) {
        $ts = strtotime((string)$body['closes_at']);
        if ($ts) $closes = date('Y-m-d H:i:s', $ts);
    }

    $db = getDB_academic();
    $db->beginTransaction();
    try {
        $st = $db->prepare("INSERT INTO polls (creator_id, faculty_id, title, description, poll_type, category, status, closes_at)
                            VALUES (?,?,?,?,?,?, 'active', ?)");
        $st->execute([
            (int)$user['user_id'],
            !empty($user['faculty_id']) ? (int)$user['faculty_id'] : null,
            $title,
            sanitize($body['description'] ?? '') ?: null,
            $type, $cat, $closes,
        ]);
        $pollId = (int)$db->lastInsertId();
        $ins = $db->prepare("INSERT INTO poll_options (poll_id, text, sort_order) VALUES (?,?,?)");
        foreach ($options as $i => $text) $ins->execute([$pollId, $text, $i]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    Response::success(['poll_id' => $pollId], 'Encuesta publicada.', 201);
}

// POST /academic/polls/{id}/vote   body: { vote, type }
if ($method === 'POST' && preg_match('#^academic/polls/(\d+)/vote$#', $path, $m)) {
    $pollId = (int)$m[1];
    $uid    = (int)$user['user_id'];
    $vote   = REQUEST_BODY['vote'] ?? null;
    $db     = getDB_academic();

    $poll = ul_poll_load($db, $pollId, $uid);
    if (!$poll) Response::error('Encuesta no encontrada.', 404);
    if ($poll['status'] !== 'active') Response::error('Esta encuesta ya está cerrada.', 409);
    if ($poll['closes_at'] && strtotime($poll['closes_at']) < time()) Response::error('Esta encuesta ya está cerrada.', 409);
    if ($poll['user_voted']) Response::error('Ya votaste en esta encuesta.', 409);

    $optionId = null;
    $rating   = null;

    if ($poll['poll_type'] === 'rating') {
        $rating = (int)$vote;
        if ($rating < 1 || $rating > 5) Response::error('La calificación debe ser de 1 a 5.', 422);
    } elseif ($poll['poll_type'] === 'yesno') {
        $wanted = ($vote === 'yes' || $vote === 'si' || $vote === 'Sí') ? 'Sí' : (($vote === 'no' || $vote === 'No') ? 'No' : null);
        if (!$wanted) Response::error('Voto inválido.', 422);
        foreach ($poll['options'] as $o) if ($o['text'] === $wanted) $optionId = $o['option_id'];
        if (!$optionId) Response::error('Opción no encontrada.', 422);
    } else {
        $optionId = (int)$vote;
        $valid = array_column($poll['options'], 'option_id');
        if (!in_array($optionId, $valid, true)) Response::error('Opción inválida.', 422);
    }

    $db->beginTransaction();
    try {
        $db->prepare("INSERT INTO poll_votes (poll_id, user_id, option_id, rating) VALUES (?,?,?,?)")
           ->execute([$pollId, $uid, $optionId, $rating]);
        if ($optionId) {
            $db->prepare("UPDATE poll_options SET votes = votes + 1 WHERE option_id = ?")->execute([$optionId]);
        }
        if ($rating !== null) {
            // Promedio acumulado (respeta los votos históricos que no tienen fila en poll_votes)
            $db->prepare("UPDATE polls
                          SET avg_rating = ((COALESCE(avg_rating, 0) * total_votes) + ?) / (total_votes + 1)
                          WHERE poll_id = ?")->execute([$rating, $pollId]);
        }
        $db->prepare("UPDATE polls SET total_votes = total_votes + 1 WHERE poll_id = ?")->execute([$pollId]);
        $db->commit();
    } catch (PDOException $e) {
        $db->rollBack();
        if ($e->getCode() === '23000') Response::error('Ya votaste en esta encuesta.', 409);
        throw $e;
    }

    Response::success(['poll' => ul_poll_load($db, $pollId, $uid)], 'Voto registrado.');
}

Response::error('Ruta académica no encontrada.', 404);

// ============================================================
//  DB helper
// ============================================================
function getDB_academic(): PDO {
    return getDBConnection();
}
