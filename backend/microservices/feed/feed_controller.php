<?php
/**
 * UniLink — feed/feed_controller.php
 * Feed posts: list, create, like, comment, delete
 */
require_once __DIR__ . '/../../shared/helpers.php';
require_once __DIR__ . '/../../shared/response.php';
require_once __DIR__ . '/feed_model.php';

$method = REQUEST_METHOD;
$path   = REQUEST_PATH;
$user   = CURRENT_USER;

// ---- GET /feed/posts ----
if ($method === 'GET' && preg_match('#^feed/posts$#', $path)) {
    ['page'=>$page, 'limit'=>$limit, 'offset'=>$offset] = getPagination();
    $filter = sanitize($_GET['filter'] ?? 'all');
    $author = (int)($_GET['author'] ?? 0);
    $group  = (int)($_GET['group']  ?? 0);

    $opts = [
        'user_id'    => (int)$user['user_id'],
        'faculty_id' => $user['faculty_id'],
        'filter'     => $filter,
        'author'     => $author,
        'group'      => $group,
    ];
    $posts = FeedModel::getPosts($opts + ['limit' => $limit, 'offset' => $offset]);
    $total = FeedModel::countPosts($opts);

    Response::paginated($posts, $total, $page, $limit);
}

// ---- POST /feed/posts ---- (Create post)
if ($method === 'POST' && preg_match('#^feed/posts$#', $path)) {
    $in       = $_POST + (is_array(REQUEST_BODY) ? REQUEST_BODY : []);
    $content  = sanitize($in['content'] ?? '');
    $audience = in_array($in['audience'] ?? '', ['public', 'faculty'], true) ? $in['audience'] : 'public';
    $tagsRaw  = $in['tags'] ?? [];
    $tags     = is_array($tagsRaw) ? $tagsRaw : (json_decode((string)$tagsRaw, true) ?: []);
    $tags     = array_values(array_filter(array_map(fn($t) => mb_substr(sanitize($t), 0, 50), $tags)));

    // Normalizar tipo a valores válidos del enum
    $validTypes = ['general', 'academic', 'event', 'poll', 'lost_found', 'aviso'];
    $typeRaw = sanitize($in['type'] ?? 'general');
    $type = in_array($typeRaw, $validTypes, true) ? $typeRaw : 'general';

    if (!$content) Response::error('El contenido no puede estar vacío.', 422);
    if (mb_strlen($content) > 2000) Response::error('Máximo 2000 caracteres.', 422);

    $flagged = ContentFilter::check($content);
    $status  = $flagged ? 'flagged' : 'published';

    // Publicación dentro de un grupo: hay que ser miembro; en grupos privados solo la ven sus miembros
    $groupId = (int)($in['group_id'] ?? 0);
    if ($groupId) {
        $gm = getDBConnection()->prepare("
            SELECT g.is_private FROM groups_table g
            JOIN group_members gm ON gm.group_id = g.group_id AND gm.user_id = ?
            WHERE g.group_id = ?");
        $gm->execute([(int)$user['user_id'], $groupId]);
        $isPrivate = $gm->fetchColumn();
        if ($isPrivate === false) Response::error('Debes ser miembro del grupo para publicar.', 403);
        if ((int)$isPrivate === 1) $audience = 'group';
    }

    $media_urls = [];
    if (!empty($_FILES['media'])) {
        $files = normalizeFilesArray($_FILES['media']);
        foreach (array_slice($files, 0, 4) as $file) {
            $url = uploadFile($file, 'posts');
            if ($url) $media_urls[] = $url;
        }
    }

    try {
        $post_id = FeedModel::createPost([
            'user_id'        => $user['user_id'],
            'faculty_id'     => $user['faculty_id'],
            'content'        => $content,
            'audience'       => $audience,
            'type'           => $type,
            'tags'           => array_slice($tags, 0, 3),
            'media'          => $media_urls,
            'status'         => $status,
            'event_date'     => !empty($in['event_date']) && ($ts = strtotime((string)$in['event_date'])) ? date('Y-m-d H:i:s', $ts) : null,
            'event_location' => !empty($in['event_location']) ? mb_substr(sanitize($in['event_location']), 0, 200) : null,
            'group_id'       => $groupId ?: null,
        ]);

        if (!$post_id) Response::error('Error al crear la publicación.', 500);

        $post = FeedModel::getPostById($post_id, $user['user_id']);
        Response::success($post, 'Publicación creada.', 201);
    } catch (Throwable $e) {
        error_log('[UniLink feed] Error al crear post: ' . $e->getMessage());
        Response::error('Error interno al guardar la publicación.', 500);
    }
}

// ---- DELETE /feed/posts/{id} ----
if ($method === 'DELETE' && preg_match('#^feed/posts/(\d+)$#', $path, $m)) {
    $post_id = (int)$m[1];
    $post = FeedModel::getPostById($post_id, $user['user_id']);

    if (!$post) Response::error('Publicación no encontrada.', 404);

    $is_owner = (int)$post['user_id'] === (int)$user['user_id'];
    $is_mod   = in_array($user['role'], ['admin','moderator']);

    if (!$is_owner && !$is_mod) {
        Response::error('No tienes permiso para eliminar esta publicación.', 403);
    }

    FeedModel::deletePost($post_id);

    // Broadcast via WebSocket (Socket.io server handles this)
    // SocketBroadcast::emit('post_removed', ['post_id'=>$post_id, 'reason'=>$is_mod?'moderated':'deleted']);

    Response::success(null, 'Publicación eliminada.');
}

// ---- PUT /feed/posts/{id} ---- (Editar contenido, solo el autor)
if ($method === 'PUT' && preg_match('#^feed/posts/(\d+)$#', $path, $m)) {
    $post_id = (int)$m[1];
    $post    = FeedModel::getPostById($post_id, (int)$user['user_id']);
    if (!$post || $post['status'] === 'removed') Response::error('Publicación no encontrada.', 404);
    if ((int)$post['user_id'] !== (int)$user['user_id']) Response::error('Solo el autor puede editar.', 403);

    $content = sanitize(REQUEST_BODY['content'] ?? '');
    if (!$content)                  Response::error('El contenido no puede estar vacío.', 422);
    if (mb_strlen($content) > 2000) Response::error('Máximo 2000 caracteres.', 422);

    $status = ContentFilter::check($content) ? 'flagged' : $post['status'];
    FeedModel::updatePost($post_id, $content, $status);
    Response::success(FeedModel::getPostById($post_id, (int)$user['user_id']), 'Publicación actualizada.');
}

// ---- POST /feed/posts/{id}/like ----
if ($method === 'POST' && preg_match('#^feed/posts/(\d+)/like$#', $path, $m)) {
    $post_id = (int)$m[1];
    $target  = FeedModel::getPostById($post_id, (int)$user['user_id']);
    if (!$target || $target['status'] !== 'published') Response::error('Publicación no encontrada.', 404);
    FeedModel::addLike($post_id, $user['user_id']);
    $count = FeedModel::getLikesCount($post_id);
    Response::success(['likes_count' => $count]);
}

// ---- DELETE /feed/posts/{id}/like ----
if ($method === 'DELETE' && preg_match('#^feed/posts/(\d+)/like$#', $path, $m)) {
    $post_id = (int)$m[1];
    FeedModel::removeLike($post_id, $user['user_id']);
    $count = FeedModel::getLikesCount($post_id);
    Response::success(['likes_count' => $count]);
}

// ---- GET /feed/posts/{id}/comments ----
if ($method === 'GET' && preg_match('#^feed/posts/(\d+)/comments$#', $path, $m)) {
    $post_id  = (int)$m[1];
    $comments = FeedModel::getComments($post_id, 20);
    Response::success(['comments' => $comments]);
}

// ---- POST /feed/posts/{id}/comments ----
if ($method === 'POST' && preg_match('#^feed/posts/(\d+)/comments$#', $path, $m)) {
    $post_id = (int)$m[1];
    $content = sanitize(REQUEST_BODY['content'] ?? '');

    if (!$content || mb_strlen($content) > 500) {
        Response::error('Comentario inválido.', 422);
    }

    $post = FeedModel::getPostById($post_id, (int)$user['user_id']);
    if (!$post || $post['status'] === 'removed') Response::error('Publicación no encontrada.', 404);

    $flagged    = ContentFilter::check($content);
    $comment_id = FeedModel::addComment($post_id, (int)$user['user_id'], $content, $flagged);

    Response::success(['comment_id' => $comment_id, 'flagged' => $flagged], 'Comentario publicado.', 201);
}

Response::error('Ruta del feed no encontrada.', 404);

// ---- Helper: normalize $_FILES array ----
function normalizeFilesArray(array $files): array {
    $result = [];
    if (is_array($files['name'])) {
        foreach ($files['name'] as $i => $name) {
            $result[] = [
                'name'     => $name,
                'type'     => $files['type'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'error'    => $files['error'][$i],
                'size'     => $files['size'][$i],
            ];
        }
    } else {
        $result[] = $files;
    }
    return array_filter($result, fn($f) => $f['error'] === UPLOAD_ERR_OK);
}

// ---- Content Filter (basic) ----
class ContentFilter {
    private static array $blocklist = [
        // Add your institution's inappropriate words here
        'spam', 'promo', 'oferta falsa'
    ];

    public static function check(string $text): bool {
        $lower = mb_strtolower($text);
        foreach (self::$blocklist as $word) {
            if (str_contains($lower, $word)) return true;
        }
        return false;
    }
}
