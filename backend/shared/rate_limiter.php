<?php
/**
 * UniLink — rate_limiter.php
 * Limitador de peticiones por IP (Redis si está disponible; si no, archivos para XAMPP)
 */

class RateLimiter {

    /** null = no probado, false = no disponible */
    private static Redis|false|null $redis = null;

    /**
     * Verifica si el cliente está dentro del límite de peticiones.
     *
     * @param string $key    Identificador único (ej. IP del cliente)
     * @param int    $limit  Máximo de peticiones permitidas en la ventana
     * @param int    $window Tamaño de la ventana en segundos
     * @return bool  true = permitido | false = límite superado
     */
    public static function check(string $key, int $limit, int $window): bool {
        $redis = self::redis();
        if ($redis) {
            try {
                $k     = 'rl:' . md5($key);
                $count = $redis->incr($k);
                if ($count === 1) $redis->expire($k, $window);
                return $count <= $limit;
            } catch (Throwable $e) {
                self::$redis = false;   // caer al modo archivo
            }
        }
        return self::checkFile($key, $limit, $window);
    }

    private static function redis(): Redis|false {
        if (self::$redis !== null) return self::$redis;
        self::$redis = false;
        if (!extension_loaded('redis')) return false;
        try {
            $r = new Redis();
            // Timeout corto: si Redis no está encendido no debe frenar cada petición
            if (@$r->connect(getenv('REDIS_HOST') ?: '127.0.0.1', 6379, 0.2)) {
                if ($pass = getenv('REDIS_PASSWORD')) $r->auth($pass);
                $r->select(1);
                self::$redis = $r;
            }
        } catch (Throwable $e) { /* sin Redis */ }
        return self::$redis;
    }

    /** Fallback: contador en archivo temporal con bloqueo exclusivo (atómico). */
    private static function checkFile(string $key, int $limit, int $window): bool {
        $file = sys_get_temp_dir() . '/ul_rl_' . md5($key) . '.json';
        $fh   = @fopen($file, 'c+');
        if (!$fh) return true;   // si no se puede escribir, no bloquear al usuario

        try {
            flock($fh, LOCK_EX);
            $now    = time();
            $stored = json_decode(stream_get_contents($fh) ?: '', true);
            $data   = (is_array($stored) && ($stored['reset'] ?? 0) > $now)
                ? $stored
                : ['count' => 0, 'reset' => $now + $window];

            $data['count']++;
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($data));
            fflush($fh);
            return $data['count'] <= $limit;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
}
