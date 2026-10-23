<?php
/**
 * UniLink — marketplace/marketplace_controller.php
 * CRUD for listings, reviews
 */
require_once __DIR__ . '/../../shared/helpers.php';
require_once __DIR__ . '/../../shared/response.php';

$method = REQUEST_METHOD;
$path   = REQUEST_PATH;
$user   = CURRENT_USER;

// ---- GET /marketplace/listings ----
if ($method === 'GET' && preg_match('#^marketplace/listings$#', $path)) {
    $limit  = min(50, max(1, (int)($_GET['limit'] ?? 12)));
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $offset = ($page - 1) * $limit;

    $category   = $_GET['category']  ?? '';
    $sort       = $_GET['sort']      ?? 'recent';
    $search     = trim((string)($_GET['search'] ?? $_GET['q'] ?? ''));
    $condition  = $_GET['condition'] ?? '';
    $price_min  = (float)($_GET['price_min'] ?? 0);
    $price_max  = (float)($_GET['price_max'] ?? 0);
    $isLost     = !empty($_GET['is_lost_found']) && $_GET['is_lost_found'] !== '0' ? 1 : 0;
    $lostType   = $_GET['lost_type'] ?? '';

    $db     = getDB();
    $where  = ["l.status = 'active'", 'l.is_lost_found = ?'];
    $params = [$isLost];

    if ($category)  { $where[] = 'l.category = ?';      $params[] = $category; }
    if ($condition) { $where[] = 'l.condition_val = ?'; $params[] = $condition; }
    if ($price_min) { $where[] = 'l.price >= ?';        $params[] = $price_min; }
    if ($price_max) { $where[] = 'l.price <= ?';        $params[] = $price_max; }
    if (in_array($lostType, ['lost','found'], true)) { $where[] = 'l.lost_type = ?'; $params[] = $lostType; }
    if ($search !== '') {
        $where[]  = '(l.title LIKE ? OR l.description LIKE ?)';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }

    $order = match($sort) {
        'price_asc'  => 'l.price ASC',
        'price_desc' => 'l.price DESC',
        'popular'    => 'l.views DESC',
        default      => 'l.created_at DESC',
    };

    $whereSQL = implode(' AND ', $where);
    $sql = "SELECT l.*, CONCAT(u.first_name,' ',u.last_name) AS seller_name, u.avatar AS seller_avatar,
                   (SELECT AVG(r.rating) FROM reviews r WHERE r.seller_id = l.seller_id) AS seller_rating,
                   COALESCE(l.thumbnail,
                            (SELECT url FROM listing_images WHERE listing_id=l.listing_id ORDER BY sort_order LIMIT 1)) AS thumbnail
            FROM listings l
            JOIN users u ON l.seller_id = u.user_id
            WHERE $whereSQL
            ORDER BY $order
            LIMIT ? OFFSET ?";

    $stmt = $db->prepare($sql);
    $i = 1;
    foreach ($params as $pv) $stmt->bindValue($i++, $pv, is_int($pv) ? PDO::PARAM_INT : PDO::PARAM_STR);
    $stmt->bindValue($i++, $limit,  PDO::PARAM_INT);
    $stmt->bindValue($i++, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $listings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $countStmt = $db->prepare("SELECT COUNT(*) FROM listings l WHERE $whereSQL");
    $j = 1;
    foreach ($params as $pv) $countStmt->bindValue($j++, $pv, is_int($pv) ? PDO::PARAM_INT : PDO::PARAM_STR);
    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();

    Response::paginated($listings, $total, $page, $limit);
}

// ---- GET /marketplace/listings/{id} ----
if ($method === 'GET' && preg_match('#^marketplace/listings/(\d+)$#', $path, $m)) {
    $id = (int)$m[1];
    $db = getDB();

    $stmt = $db->prepare("
        SELECT l.*, CONCAT(u.first_name,' ',u.last_name) AS seller_name, u.avatar AS seller_avatar,
               (SELECT AVG(r.rating)   FROM reviews r WHERE r.seller_id = l.seller_id) AS seller_rating,
               (SELECT COUNT(*)        FROM reviews r WHERE r.seller_id = l.seller_id) AS reviews_count
        FROM listings l
        JOIN users u ON l.seller_id = u.user_id
        WHERE l.listing_id = ? AND l.status <> 'removed'");
    $stmt->execute([$id]);
    $listing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$listing) Response::error('Anuncio no encontrado.', 404);

    $db->prepare("UPDATE listings SET views = views + 1 WHERE listing_id = ?")->execute([$id]);

    // Fotos (si no hay en listing_images, usa la miniatura)
    $imgStmt = $db->prepare("SELECT url FROM listing_images WHERE listing_id = ? ORDER BY sort_order");
    $imgStmt->execute([$id]);
    $listing['images'] = $imgStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$listing['images'] && !empty($listing['thumbnail'])) {
        $listing['images'] = [['url' => $listing['thumbnail']]];
    }

    // Reseñas del vendedor
    $rv = $db->prepare("
        SELECT r.rating, r.comment, r.created_at, CONCAT(u.first_name,' ',u.last_name) AS reviewer_name
        FROM reviews r JOIN users u ON u.user_id = r.reviewer_id
        WHERE r.seller_id = ? ORDER BY r.created_at DESC LIMIT 10");
    $rv->execute([(int)$listing['seller_id']]);
    $listing['reviews'] = $rv->fetchAll(PDO::FETCH_ASSOC);

    Response::success($listing);
}

// ---- POST /marketplace/listings ----
if ($method === 'POST' && preg_match('#^marketplace/listings$#', $path)) {
    $in = $_POST + (is_array(REQUEST_BODY) ? REQUEST_BODY : []);

    $title       = sanitize($in['title']       ?? '');
    $description = sanitize($in['description'] ?? '');
    $price       = (float)($in['price'] ?? 0);
    $isLost      = !empty($in['is_lost_found']) && $in['is_lost_found'] !== '0' ? 1 : 0;

    $cats  = ['libros','calculadoras','tutorias','electronica','ropa','otros'];
    $conds = ['nuevo','como_nuevo','buen_estado','usado'];
    $category  = in_array($in['category'] ?? '', $cats, true)       ? $in['category']      : 'otros';
    $condition = in_array($in['condition_val'] ?? '', $conds, true) ? $in['condition_val'] : 'buen_estado';
    $lostType  = in_array($in['lost_type'] ?? '', ['lost','found'], true) ? $in['lost_type'] : null;
    $lostLoc   = $isLost ? sanitize($in['lost_location'] ?? '') : null;

    if (!$title) Response::error('El título es requerido.', 422);
    if ($price < 0) Response::error('El precio no puede ser negativo.', 422);
    if ($isLost && !$lostType) $lostType = 'lost';

    $db = getDB();
    $stmt = $db->prepare("
        INSERT INTO listings (seller_id, title, description, price, category, condition_val,
                              faculty_id, is_lost_found, lost_type, lost_location)
        VALUES (?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        (int)$user['user_id'], $title, $description, $price, $category, $condition,
        !empty($user['faculty_id']) ? (int)$user['faculty_id'] : null,
        $isLost, $isLost ? $lostType : null, $lostLoc,
    ]);
    $listing_id = (int)$db->lastInsertId();

    // Fotos: acepta images[] (varias) o images (una sola)
    $files = [];
    if (!empty($_FILES['images'])) {
        $f = $_FILES['images'];
        if (is_array($f['name'])) {
            foreach ($f['name'] as $i => $name) {
                $files[] = ['name'=>$name, 'type'=>$f['type'][$i], 'tmp_name'=>$f['tmp_name'][$i],
                            'error'=>$f['error'][$i], 'size'=>$f['size'][$i]];
            }
        } else {
            $files[] = $f;
        }
    }
    $imgStmt = $db->prepare("INSERT INTO listing_images (listing_id, url, sort_order) VALUES (?,?,?)");
    $first = null; $order = 0;
    foreach (array_slice($files, 0, 4) as $file) {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
        $url = uploadFile($file, 'marketplace');
        if ($url) {
            $imgStmt->execute([$listing_id, $url, $order++]);
            if ($first === null) $first = $url;
        }
    }
    if ($first) $db->prepare("UPDATE listings SET thumbnail=? WHERE listing_id=?")->execute([$first, $listing_id]);

    Response::success(['listing_id' => $listing_id], 'Anuncio publicado.', 201);
}

// ---- PUT /marketplace/listings/{id} ---- (editar, solo el vendedor)
if ($method === 'PUT' && preg_match('#^marketplace/listings/(\d+)$#', $path, $m)) {
    $id   = (int)$m[1];
    $body = REQUEST_BODY;
    $db   = getDB();

    $own = $db->prepare("SELECT seller_id FROM listings WHERE listing_id=? AND status <> 'removed'");
    $own->execute([$id]);
    $sellerId = (int)$own->fetchColumn();
    if (!$sellerId) Response::error('Anuncio no encontrado.', 404);
    if ($sellerId !== (int)$user['user_id']) Response::error('No tienes permiso.', 403);

    $sets = []; $params = [];
    if (array_key_exists('title', $body)) {
        $t = mb_substr(sanitize($body['title']), 0, 200);
        if ($t === '') Response::error('El título es requerido.', 422);
        $sets[] = 'title = ?'; $params[] = $t;
    }
    if (array_key_exists('description', $body)) { $sets[] = 'description = ?'; $params[] = sanitize($body['description']); }
    if (array_key_exists('price', $body)) {
        $p = (float)$body['price'];
        if ($p < 0) Response::error('El precio no puede ser negativo.', 422);
        $sets[] = 'price = ?'; $params[] = $p;
    }
    if (!$sets) Response::error('Sin cambios para guardar.', 422);

    $params[] = $id;
    $db->prepare("UPDATE listings SET " . implode(', ', $sets) . " WHERE listing_id = ?")->execute($params);
    Response::success(null, 'Anuncio actualizado.');
}

// ---- PATCH /marketplace/listings/{id}/sold ----
if ($method === 'PATCH' && preg_match('#^marketplace/listings/(\d+)/sold$#', $path, $m)) {
    $id = (int)$m[1];
    $db = getDB();
    $stmt = $db->prepare("UPDATE listings SET status='sold' WHERE listing_id=? AND seller_id=?");
    $stmt->execute([$id, $user['user_id']]);
    if (!$stmt->rowCount()) Response::error('No tienes permiso.', 403);
    Response::success(null, 'Marcado como vendido.');
}

// ---- DELETE /marketplace/listings/{id} ----
if ($method === 'DELETE' && preg_match('#^marketplace/listings/(\d+)$#', $path, $m)) {
    $id  = (int)$m[1];
    $db  = getDB();
    $isMod = in_array($user['role'], ['admin','moderator']);
    $sql = $isMod
        ? "UPDATE listings SET status='removed' WHERE listing_id=?"
        : "UPDATE listings SET status='removed' WHERE listing_id=? AND seller_id=?";
    $params = $isMod ? [$id] : [$id, $user['user_id']];
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    if (!$stmt->rowCount()) Response::error('No tienes permiso.', 403);
    Response::success(null, 'Anuncio eliminado.');
}

// ---- POST /marketplace/reviews ----
if ($method === 'POST' && preg_match('#^marketplace/reviews$#', $path)) {
    $body       = REQUEST_BODY;
    $listing_id = (int)($body['listing_id'] ?? 0);
    $rating     = (int)($body['rating'] ?? 0);
    $comment    = mb_substr(sanitize($body['comment'] ?? ''), 0, 500);

    if (!$listing_id || $rating < 1 || $rating > 5) Response::error('Datos incompletos.', 422);

    $db = getDB();
    // El vendedor se toma del anuncio, nunca del cliente
    $ls = $db->prepare("SELECT seller_id FROM listings WHERE listing_id = ? AND status <> 'removed'");
    $ls->execute([$listing_id]);
    $seller_id = (int)$ls->fetchColumn();
    if (!$seller_id) Response::error('Anuncio no encontrado.', 404);
    if ($seller_id === (int)$user['user_id']) Response::error('No puedes reseñarte a ti mismo.', 422);
    try {
        $stmt = $db->prepare("INSERT INTO reviews (listing_id, reviewer_id, seller_id, rating, comment) VALUES (?,?,?,?,?)");
        $stmt->execute([$listing_id, $user['user_id'], $seller_id, $rating, $comment]);
        // Update seller reputation
        $db->prepare("UPDATE users SET reputation_score = (SELECT COALESCE(AVG(rating)*20,0) FROM reviews WHERE seller_id=?) WHERE user_id=?")
           ->execute([$seller_id, $seller_id]);
        Response::success(null, 'Reseña publicada.', 201);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'unique_review')) {
            Response::error('Ya dejaste una reseña para este anuncio.', 409);
        }
        Response::error('Error al publicar reseña.', 500);
    }
}

Response::error('Ruta del marketplace no encontrada.', 404);

// ---- DB helper ----
function getDB(): PDO {
    return getDBConnection();
}
