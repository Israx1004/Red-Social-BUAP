<?php
/**
 * UniLink — json_guard.php
 * Garantiza que los endpoints de la API SIEMPRE respondan JSON válido.
 *
 * Sin esto, cualquier warning/notice/fatal de PHP (BD apagada, ruta
 * inexistente, etc.) se imprime como HTML en XAMPP (display_errors=On)
 * y el frontend muestra: "Error del servidor (respuesta no es JSON)".
 */

// Nunca imprimir errores en la respuesta; se mandan al log de PHP/Apache
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
error_reporting(E_ALL);

// Capturar cualquier salida accidental (espacios, warnings, echo sueltos)
if (ob_get_level() === 0) ob_start();

function ul_json_fail(string $message, int $code = 500, ?string $detail = null): void {
    while (ob_get_level() > 0) ob_end_clean();
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=UTF-8');
    }
    $out = ['success' => false, 'message' => $message];
    // En desarrollo mostramos el detalle para depurar fácilmente
    if ($detail !== null && (!defined('UL_ENV') || UL_ENV !== 'production')) {
        $out['detail'] = $detail;
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

// Excepciones no capturadas (PDOException por BD apagada, etc.)
set_exception_handler(function (Throwable $e) {
    error_log('[UniLink] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $isDb = $e instanceof PDOException;
    ul_json_fail(
        $isDb ? 'No se pudo conectar con la base de datos. Verifica que MySQL esté encendido en XAMPP y que la base "red_social" esté importada.'
              : 'Error interno del servidor.',
        500,
        $e->getMessage()
    );
});

// Warnings/notices: registrarlos, no imprimirlos
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    error_log("[UniLink] PHP[$severity] $message @ $file:$line");
    return true;
});

// Errores fatales (parse/compile/memory)
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        ul_json_fail('Error fatal del servidor.', 500, $err['message'] . ' @ ' . basename($err['file']) . ':' . $err['line']);
    }
});
