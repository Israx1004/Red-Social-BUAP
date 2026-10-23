<?php
/**
 * UniLink — user_model.php
 * Data access layer for users table
 */
class UserModel {
    private static ?PDO $pdo = null;

    public static function getDB(): PDO {
        return self::$pdo ??= getDBConnection();
    }

    public static function findByEmail(string $email): ?array {
        $stmt = self::getDB()->prepare("
            SELECT u.*, f.name AS faculty_name
            FROM users u
            LEFT JOIN faculties f ON f.faculty_id = u.faculty_id
            WHERE u.email = ? LIMIT 1");
        $stmt->execute([$email]);
        return $stmt->fetch() ?: null;
    }

    public static function findById(int $id): ?array {
        $stmt = self::getDB()->prepare("
            SELECT u.*, f.name AS faculty_name
            FROM users u
            LEFT JOIN faculties f ON f.faculty_id = u.faculty_id
            WHERE u.user_id = ? LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function create(array $data): ?int {
        $db   = self::getDB();
        $stmt = $db->prepare("
            INSERT INTO users
              (email, password_hash, first_name, last_name, student_id, faculty_id, semester, role, status)
            VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $data['email'],
            $data['password'],
            $data['first_name'],
            $data['last_name'],
            $data['student_id'],
            $data['faculty_id'],
            $data['semester'],
            $data['role'],
            $data['status'],
        ]);
        return (int)$db->lastInsertId() ?: null;
    }

    public static function updateLastLogin(int $id): void {
        self::getDB()->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?")
            ->execute([$id]);
    }

    /** Guarda (reemplaza) el token de recuperación: solo existe uno vigente por usuario. */
    public static function storeResetToken(int $userId, string $token, int $expiresAt): void {
        $db = self::getDB();
        $db->beginTransaction();
        $db->prepare("DELETE FROM password_reset_tokens WHERE user_id = ?")->execute([$userId]);
        $db->prepare("INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (?,?,FROM_UNIXTIME(?))")
           ->execute([$userId, hash('sha256', $token), $expiresAt]);
        $db->commit();
    }

    /** Devuelve el user_id dueño de un token válido (no usado ni expirado), o null. */
    public static function findValidResetToken(string $token): ?int {
        $st = self::getDB()->prepare("
            SELECT user_id FROM password_reset_tokens
            WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()
            LIMIT 1");
        $st->execute([hash('sha256', $token)]);
        $id = $st->fetchColumn();
        return $id ? (int)$id : null;
    }

    public static function resetPassword(int $userId, string $newHash): void {
        $db = self::getDB();
        $db->beginTransaction();
        $db->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?")->execute([$newHash, $userId]);
        $db->prepare("UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")->execute([$userId]);
        $db->commit();
    }

    public static function getPublicProfile(int $id, int $requesterId): ?array {
        $stmt = self::getDB()->prepare("
            SELECT u.user_id,
                   CONCAT(u.first_name,' ',u.last_name) AS name,
                   u.first_name, u.last_name, u.bio, u.avatar, u.role, u.semester,
                   u.reputation_score, u.last_login,
                   f.name AS faculty_name, c.name AS career_name,
                   CASE WHEN u.show_phone=1 AND u.user_id != ? THEN u.phone ELSE NULL END AS phone,
                   u.email,
                   (SELECT COUNT(*) FROM user_follows WHERE following_id=u.user_id) AS followers_count,
                   (SELECT COUNT(*) FROM user_follows WHERE follower_id=u.user_id)  AS following_count,
                   (SELECT COUNT(*) FROM posts WHERE user_id=u.user_id AND status='published') AS posts_count,
                   EXISTS(SELECT 1 FROM user_follows WHERE follower_id=? AND following_id=u.user_id) AS is_following
            FROM users u
            LEFT JOIN faculties f ON f.faculty_id = u.faculty_id
            LEFT JOIN careers   c ON c.career_id  = u.career_id
            WHERE u.user_id = ? AND u.status = 'active'");
        $stmt->execute([$requesterId, $requesterId, $id]);
        return $stmt->fetch() ?: null;
    }

    public static function search(string $q, int $limit, int $excludeUserId): array {
        $db   = self::getDB();
        $like = "%$q%";

        $userStmt = $db->prepare("
            SELECT user_id AS id, CONCAT(first_name,' ',last_name) AS name,
                   'user' AS type, role AS subtitle, avatar
            FROM users
            WHERE (CONCAT(first_name,' ',last_name) LIKE ? OR student_id LIKE ?)
              AND status='active' AND user_id != ?
            LIMIT ?");
        $userStmt->bindValue(1, $like);
        $userStmt->bindValue(2, $like);
        $userStmt->bindValue(3, $excludeUserId, PDO::PARAM_INT);
        $userStmt->bindValue(4, max(1, (int)ceil($limit / 2)), PDO::PARAM_INT);
        $userStmt->execute();
        return $userStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

class UserStatusCheck {
    public static function isActive(int $userId): bool {
        static $cache = [];
        if (isset($cache[$userId])) return $cache[$userId];
        try {
            $stmt = UserModel::getDB()->prepare("SELECT status FROM users WHERE user_id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $row    = $stmt->fetch();
            $active = $row && $row['status'] === 'active';
            $cache[$userId] = $active;
            return $active;
        } catch (\Exception $e) {
            return true;
        }
    }
}
