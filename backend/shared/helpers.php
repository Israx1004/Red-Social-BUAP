<?php
/**
 * UniLink — helpers.php
 * Proyecto: RedSocial_BUAP
 * Configuración: variables de entorno (Docker) con valores por defecto para XAMPP.
 */

/**
 * Secreto JWT: variable de entorno JWT_SECRET o, si no existe, un secreto aleatorio
 * que se genera una sola vez en backend/shared/.jwt_secret (bloqueado por .htaccess).
 * El servidor WebSocket lee el mismo archivo.
 */
function ul_jwt_secret(): string {
    $env = getenv('JWT_SECRET');
    if ($env) return $env;
    $file = __DIR__ . '/.jwt_secret';
    if (is_file($file)) {
        $s = trim((string)file_get_contents($file));
        if (strlen($s) >= 32) return $s;
    }
    $s = bin2hex(random_bytes(32));
    @file_put_contents($file, $s, LOCK_EX);
    return $s;
}

if (!defined('UL_ENV')) {
    define('UL_ENV', getenv('APP_ENV') ?: 'development');

    define('JWT_SECRET', ul_jwt_secret());
    define('JWT_EXPIRY',  60 * 60 * 24 * 7);

    // ── Base de datos ─────────────────────────────────────────
    define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
    define('DB_PORT', getenv('DB_PORT') ?: '3306');
    define('DB_USER', getenv('DB_USER') ?: 'root');
    define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '1004');
    define('DB_NAME', getenv('DB_NAME') ?: 'red_social');

    // ── Rutas del proyecto ────────────────────────────────────
    // BASE_PATH es relativo al host, así funciona en el puerto 80, 8012, etc.
    define('BASE_PATH',   getenv('BASE_PATH') !== false ? rtrim(getenv('BASE_PATH'), '/') : '/RedSocial_BUAP');
    define('BASE_URL',    BASE_PATH);

    // ── Almacenamiento ────────────────────────────────────────
    define('STORAGE_PATH', dirname(__DIR__, 2) . '/storage');
    define('STORAGE_URL',  BASE_PATH . '/storage');

    // ── Dominios BUAP ─────────────────────────────────────────
    define('DOMAIN_STUDENT',   'alumno.buap.mx');
    define('DOMAIN_PROFESSOR', 'correo.buap.mx');
}

/**
 * Limpia texto de entrada: quita etiquetas HTML y espacios.
 * NO escapa entidades: el texto se guarda tal cual y se escapa al MOSTRAR
 * (escHtml en JS, htmlspecialchars en PHP). Así no aparece &amp;quot; en pantalla.
 */
function sanitize($input): string {
    if (!is_scalar($input)) return '';
    $s = strip_tags(trim((string)$input));
    // Quitar caracteres de control (excepto salto de línea y tab)
    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
}

function isInstitutionalEmail(string $email): bool {
    return (bool) preg_match('/^[a-zA-Z0-9._%+\-]+@(alumno|correo)\.buap\.mx$/i', $email);
}

function detectAccountType(string $email): string {
    if (preg_match('/^[a-zA-Z0-9._%+\-]+@alumno\.buap\.mx$/i', $email)) return 'student';
    if (preg_match('/^[a-zA-Z0-9._%+\-]+@correo\.buap\.mx$/i', $email)) return 'professor';
    return 'unknown';
}

function getPagination(): array {
    $page   = max(1, (int)($_GET['page']  ?? 1));
    $limit  = min(50, max(1, (int)($_GET['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;
    return compact('page', 'limit', 'offset');
}

function uploadFile(array $file, string $subfolder = 'uploads'): ?string {
    // La extensión se decide por el tipo REAL del archivo (no por lo que dice el navegador)
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
        'video/mp4'  => 'mp4',
    ];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    if (!is_uploaded_file($file['tmp_name'] ?? ''))               return null;
    if ($file['size'] > 10 * 1024 * 1024)                          return null;
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!isset($allowed[$mime])) return null;
    $subfolder = preg_replace('/[^a-z0-9_-]/i', '', $subfolder) ?: 'uploads';
    $filename  = uniqid('ul_', true) . '.' . $allowed[$mime];
    $dir      = STORAGE_PATH . '/' . $subfolder;
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    if (move_uploaded_file($file['tmp_name'], "$dir/$filename")) {
        return STORAGE_URL . "/$subfolder/$filename";
    }
    return null;
}

/**
 * Encola un trabajo para backend/queue/worker.php (Redis si existe; si no, archivo).
 * Nunca rompe la petición: si falla, solo se registra en el log.
 */
function ul_queue_push(string $queue, array $payload): void {
    $payload['_queue'] = $queue;
    try {
        if (extension_loaded('redis')) {
            $r = new Redis();
            if (@$r->connect(getenv('REDIS_HOST') ?: '127.0.0.1', 6379, 0.3)) {
                if ($pass = getenv('REDIS_PASSWORD')) $r->auth($pass);
                $r->rPush("unilink:$queue", json_encode($payload));
                return;
            }
        }
        $dir = sys_get_temp_dir() . '/ul_queue';
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        @file_put_contents($dir . '/' . uniqid('', true) . '.job', json_encode($payload), LOCK_EX);
    } catch (Throwable $e) {
        logEvent('error', 'Queue push failed', ['queue' => $queue, 'error' => $e->getMessage()]);
    }
}

function logEvent(string $level, string $message, array $context = []): void {
    // Ruta relativa al proyecto (funciona en Windows, Linux y Mac).
    // El log NUNCA debe romper la respuesta de la API.
    try {
        $logDir = dirname(__DIR__, 2) . '/logs';
        if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
        @file_put_contents(
            "$logDir/app.log",
            json_encode(['ts'=>date('c'),'level'=>$level,'message'=>$message,'context'=>$context]) . "\n",
            FILE_APPEND | LOCK_EX
        );
    } catch (Throwable $e) { /* ignorar */ }
}

/**
 * Enlaza parámetros posicionales respetando el tipo (int → PARAM_INT),
 * necesario para LIMIT/OFFSET con ?. Devuelve el statement ya ejecutado.
 */
function ul_bind(PDOStatement $st, array $params): PDOStatement {
    $i = 1;
    foreach ($params as $v) {
        $st->bindValue($i++, $v, match (true) {
            is_int($v)  => PDO::PARAM_INT,
            is_null($v) => PDO::PARAM_NULL,
            default     => PDO::PARAM_STR,
        });
    }
    $st->execute();
    return $st;
}

/**
 * Conexión PDO única y compartida por todos los microservicios.
 * Si falla lanza PDOException: en la API la convierte json_guard.php en JSON,
 * y en las páginas auth_check.php la captura sin cerrar la sesión.
 */
function getDBConnection(?string $dbName = null): PDO {
    static $connections = [];
    $db = $dbName ?? DB_NAME;
    if (isset($connections[$db])) return $connections[$db];

    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname={$db};charset=utf8mb4";

    $connections[$db] = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        // Prepares nativos: LIMIT/OFFSET con ? funcionan y los tipos vuelven nativos
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
    ]);
    return $connections[$db];
}
