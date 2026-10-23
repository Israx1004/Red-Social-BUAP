<?php
/**
 * UniLink — migrate_unescape.php  (ejecutar UNA sola vez, desde consola)
 *
 * Antes, sanitize() guardaba el texto con htmlspecialchars (&quot; &#039; &amp; …)
 * y el frontend lo volvía a escapar → en pantalla se veía "&amp;quot;".
 * Ahora el texto se guarda limpio; este script convierte los datos viejos.
 *
 * Uso:   C:\xampp\php\php.exe database\migrate_unescape.php           (simulación)
 *        C:\xampp\php\php.exe database\migrate_unescape.php --apply   (aplica cambios)
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo desde consola.'); }

require_once __DIR__ . '/../backend/shared/helpers.php';

$apply = in_array('--apply', $argv, true);
$db    = getDBConnection();

// tabla => [clave primaria (array), columnas de texto]
$targets = [
    'users'             => [['user_id'],    ['first_name', 'last_name', 'bio', 'student_id']],
    'posts'             => [['post_id'],    ['content', 'event_location']],
    'comments'          => [['comment_id'], ['content']],
    'listings'          => [['listing_id'], ['title', 'description', 'lost_location']],
    'groups_table'      => [['group_id'],   ['name', 'description', 'icon', 'nrc_code']],
    'academic_calendar' => [['event_id'],   ['title', 'description', 'location']],
    'polls'             => [['poll_id'],    ['title', 'description']],
    'poll_options'      => [['option_id'],  ['text']],
    'reviews'           => [['review_id'],  ['comment']],
    'reports'           => [['report_id'],  ['details']],
    'notifications'     => [['notif_id'],   ['message']],
    'panic_alerts'      => [['alert_id'],   ['location_text']],
    'moderation_log'    => [['log_id'],     ['reason']],
    'messages'          => [['message_id'], ['context']],
    'post_tags'         => [['post_id', 'tag'], ['tag']],
];

$total = 0;
foreach ($targets as $table => [$pk, $cols]) {
    try {
        $db->query("SELECT 1 FROM `$table` LIMIT 1");
    } catch (PDOException $e) {
        echo "· $table: no existe, se omite\n";
        continue;
    }
    $like = implode(' OR ', array_map(fn($c) => "`$c` LIKE '%&%;%'", $cols));
    $sel  = implode(', ', array_map(fn($c) => "`$c`", array_unique(array_merge($pk, $cols))));
    $rows = $db->query("SELECT $sel FROM `$table` WHERE $like")->fetchAll();

    $set   = implode(', ', array_map(fn($c) => "`$c` = ?", $cols));
    $where = implode(' AND ', array_map(fn($c) => "`$c` = ?", $pk));
    $upd   = $db->prepare("UPDATE IGNORE `$table` SET $set WHERE $where");

    $n = 0;
    foreach ($rows as $r) {
        $vals = [];
        $changed = false;
        foreach ($cols as $c) {
            $v = $r[$c];
            $d = $v === null ? null : htmlspecialchars_decode($v, ENT_QUOTES | ENT_HTML5);
            if ($d !== $v) $changed = true;
            $vals[] = $d;
        }
        if (!$changed) continue;
        $n++;
        if ($apply) {
            $upd->execute(array_merge($vals, array_map(fn($c) => $r[$c], $pk)));
        }
    }
    $total += $n;
    echo sprintf("· %-18s %d fila(s) %s\n", $table, $n, $apply ? 'corregidas' : 'por corregir');
}

echo $apply
    ? "\nListo: $total fila(s) actualizadas.\n"
    : "\nSimulación: $total fila(s) cambiarían. Ejecuta con --apply para aplicar.\n";
