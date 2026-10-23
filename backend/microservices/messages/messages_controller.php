<?php
/**
 * UniLink — messages/messages_controller.php
 * Mensajes directos (chat 1 a 1) para Marketplace, Objetos perdidos y perfiles.
 *
 *   GET  messages/conversations        → lista de chats (último mensaje + no leídos)
 *   GET  messages/with/{userId}        → hilo con una persona (marca como leídos)
 *   POST messages/send                 → { to, body, context? }
 *   GET  messages/unread-count         → total de mensajes sin leer
 */
require_once __DIR__ . '/../../shared/helpers.php';
require_once __DIR__ . '/../../shared/response.php';

$method = REQUEST_METHOD;
$path   = REQUEST_PATH;
$me     = (int)CURRENT_USER['user_id'];

function msgDB(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = getDBConnection();
    // La tabla se crea sola la primera vez (no hace falta importar nada extra)
    $pdo->exec("CREATE TABLE IF NOT EXISTS messages (
        message_id  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        sender_id   INT UNSIGNED NOT NULL,
        receiver_id INT UNSIGNED NOT NULL,
        body        VARCHAR(1000) NOT NULL,
        context     VARCHAR(200) DEFAULT NULL,
        is_read     TINYINT(1) NOT NULL DEFAULT 0,
        created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_pair (sender_id, receiver_id, message_id),
        KEY idx_receiver (receiver_id, is_read)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    return $pdo;
}

$db = msgDB();

// ---- GET messages/unread-count ----
if ($method === 'GET' && $path === 'messages/unread-count') {
    $st = $db->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
    $st->execute([$me]);
    Response::success(['unread' => (int)$st->fetchColumn()]);
}

// ---- GET messages/conversations ----
if ($method === 'GET' && $path === 'messages/conversations') {
    $st = $db->prepare("
        SELECT u.user_id, CONCAT(u.first_name,' ',u.last_name) AS name, u.avatar, u.role,
               m.body AS last_body, m.sender_id AS last_sender, m.created_at AS last_at,
               (SELECT COUNT(*) FROM messages x WHERE x.sender_id = u.user_id AND x.receiver_id = ? AND x.is_read = 0) AS unread
        FROM (
            SELECT IF(sender_id = ?, receiver_id, sender_id) AS peer_id, MAX(message_id) AS last_id
            FROM messages
            WHERE sender_id = ? OR receiver_id = ?
            GROUP BY peer_id
        ) c
        JOIN messages m ON m.message_id = c.last_id
        JOIN users u ON u.user_id = c.peer_id
        ORDER BY m.message_id DESC
        LIMIT 50");
    $st->execute([$me, $me, $me, $me]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['user_id'] = (int)$r['user_id']; $r['unread'] = (int)$r['unread'];
        $r['last_sender'] = (int)$r['last_sender'];
    }
    unset($r);
    Response::success(['conversations' => $rows]);
}

// ---- GET messages/with/{id}?after=ID ----
if ($method === 'GET' && preg_match('#^messages/with/(\d+)$#', $path, $m)) {
    $peerId = (int)$m[1];
    $after  = max(0, (int)($_GET['after'] ?? 0));

    $ps = $db->prepare("SELECT user_id, CONCAT(first_name,' ',last_name) AS name, avatar, role FROM users WHERE user_id = ? AND status = 'active'");
    $ps->execute([$peerId]);
    $peer = $ps->fetch();
    if (!$peer) Response::error('Usuario no encontrado.', 404);
    $peer['user_id'] = (int)$peer['user_id'];

    $st = $db->prepare("
        SELECT message_id, sender_id, receiver_id, body, context, is_read, created_at
        FROM messages
        WHERE message_id > ? AND ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?))
        ORDER BY message_id ASC
        LIMIT 200");
    $st->execute([$after, $me, $peerId, $peerId, $me]);
    $msgs = $st->fetchAll();
    foreach ($msgs as &$x) {
        $x['message_id'] = (int)$x['message_id'];
        $x['sender_id']  = (int)$x['sender_id'];
        $x['is_read']    = (int)$x['is_read'];
    }
    unset($x);

    $db->prepare("UPDATE messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ? AND is_read = 0")->execute([$peerId, $me]);

    Response::success(['peer' => $peer, 'messages' => $msgs]);
}

// ---- POST messages/send ----
if ($method === 'POST' && $path === 'messages/send') {
    $to      = (int)(REQUEST_BODY['to'] ?? 0);
    $body    = trim((string)(REQUEST_BODY['body'] ?? ''));
    $context = trim((string)(REQUEST_BODY['context'] ?? ''));

    if (!$to)                      Response::error('Falta el destinatario.', 422);
    if ($to === $me)               Response::error('No puedes enviarte mensajes a ti mismo.', 422);
    if ($body === '')              Response::error('Escribe un mensaje.', 422);
    if (mb_strlen($body) > 1000)   Response::error('Máximo 1000 caracteres.', 422);

    $ps = $db->prepare("SELECT first_name, last_name FROM users WHERE user_id = ? AND status = 'active'");
    $ps->execute([$to]);
    if (!$ps->fetch()) Response::error('Usuario no encontrado.', 404);

    // htmlspecialchars se aplica al MOSTRAR (escHtml), aquí se guarda el texto tal cual
    $st = $db->prepare("INSERT INTO messages (sender_id, receiver_id, body, context) VALUES (?,?,?,?)");
    $st->execute([$me, $to, $body, $context !== '' ? mb_substr($context, 0, 200) : null]);
    $id = (int)$db->lastInsertId();

    // Notificación en la campana del destinatario
    try {
        $mine = $db->prepare("SELECT first_name, last_name FROM users WHERE user_id = ?");
        $mine->execute([$me]);
        $r = $mine->fetch() ?: ['first_name' => 'Alguien', 'last_name' => ''];
        $db->prepare("INSERT INTO notifications (user_id, sender_id, type, message, link) VALUES (?,?,?,?,?)")
           ->execute([$to, $me, 'marketplace',
                      mb_substr('💬 Nuevo mensaje de ' . trim($r['first_name'] . ' ' . $r['last_name']), 0, 300),
                      BASE_PATH . '/frontend/pages/messages.php?to=' . $me]);
    } catch (Throwable $e) { error_log('[UniLink messages] notif: ' . $e->getMessage()); }

    $row = $db->prepare("SELECT message_id, sender_id, receiver_id, body, context, is_read, created_at FROM messages WHERE message_id = ?");
    $row->execute([$id]);
    $msg = $row->fetch();
    $msg['message_id'] = (int)$msg['message_id']; $msg['sender_id'] = (int)$msg['sender_id']; $msg['is_read'] = (int)$msg['is_read'];

    Response::success(['message' => $msg], 'Mensaje enviado.', 201);
}

Response::error('Ruta de mensajes no encontrada.', 404);
