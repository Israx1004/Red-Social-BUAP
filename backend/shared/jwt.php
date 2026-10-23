<?php
/**
 * UniLink — jwt.php
 * Solo maneja JWT (HS256, base64url estándar → compatible con jsonwebtoken de Node).
 * Response y RateLimiter tienen sus propios archivos.
 */

class JWT {

    public static function generate(array $payload): string {
        $header  = self::b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload['iat'] = time();
        $payload['exp'] = time() + JWT_EXPIRY;
        $body    = self::b64url(json_encode($payload));
        $sig     = self::sign("$header.$body");
        return "$header.$body.$sig";
    }

    public static function verify(string $token): ?array {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;

        [$header, $body, $sig] = $parts;

        if (!hash_equals(self::sign("$header.$body"), $sig)) return null;

        $head = json_decode(self::b64urlDecode($header), true);
        if (!is_array($head) || ($head['alg'] ?? '') !== 'HS256') return null;

        $payload = json_decode(self::b64urlDecode($body), true);
        if (!is_array($payload) || !isset($payload['exp'], $payload['user_id'])) return null;
        if ((int)$payload['exp'] < time()) return null;

        return $payload;
    }

    private static function sign(string $data): string {
        return self::b64url(hash_hmac('sha256', $data, JWT_SECRET, true));
    }

    private static function b64url(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $data): string {
        return (string)base64_decode(strtr($data, '-_', '+/'));
    }
}
