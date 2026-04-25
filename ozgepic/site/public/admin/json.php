<?php
// json.php — Kese data API for Google Sheets sync
require_once __DIR__ . '/../../src/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$pdo = site_db();

// ─── AUTO-ADD COLUMNS IF MISSING ─────────────────────────────────────────────
// Add selected_box and box_selected_at to kese_attempts if they don't exist
$alterQueries = [
    "ALTER TABLE kese_attempts ADD COLUMN selected_box TINYINT NULL DEFAULT NULL COMMENT 'Which box (1-9) the user picked'",
    "ALTER TABLE kese_attempts ADD COLUMN box_selected_at DATETIME NULL DEFAULT NULL COMMENT 'When the user picked the box'",
];
foreach ($alterQueries as $q) {
    try { $pdo->exec($q); } catch (PDOException $e) { /* column already exists — ignore */ }
}

// ─── FETCH ALL DATA ───────────────────────────────────────────────────────────
try {
    $stmt = $pdo->query("
        SELECT
            -- kese_users fields
            u.id            AS user_id,
            u.userid        AS tg_user_id,
            u.name          AS name,
            u.surname       AS surname,
            u.phone         AS phone,
            u.address       AS address,
            u.postcode      AS postcode,
            u.city          AS city,

            -- kese_attempts fields
            a.id            AS attempt_id,
            a.userid        AS attempt_tg_user_id,
            a.transaction_number,
            a.token,
            a.numeric_token AS numeric_token_id,
            a.session_num,
            a.used,
            a.created_at    AS attempt_created_at,
            a.used_at,
            a.selected_box,
            a.box_selected_at,

            -- kese_prizes fields
            p.id            AS prize_id,
            p.name          AS prize_name,
            p.emoji         AS prize_emoji,
            p.session_num   AS prize_session

        FROM kese_attempts a
        LEFT JOIN kese_users  u ON u.userid  = a.userid
        LEFT JOIN kese_prizes p ON p.id      = a.prize_id
        ORDER BY a.id ASC
    ");

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Cast numeric fields properly
    foreach ($rows as &$r) {
        $r['user_id']           = $r['user_id']           !== null ? (int)$r['user_id']           : null;
        $r['tg_user_id']        = $r['tg_user_id']        !== null ? (int)$r['tg_user_id']        : null;
        $r['attempt_id']        = $r['attempt_id']        !== null ? (int)$r['attempt_id']        : null;
        $r['attempt_tg_user_id']= $r['attempt_tg_user_id']!== null ? (int)$r['attempt_tg_user_id']: null;
        $r['numeric_token_id']  = $r['numeric_token_id']  !== null ? (int)$r['numeric_token_id']  : null;
        $r['session_num']       = $r['session_num']       !== null ? (int)$r['session_num']       : null;
        $r['used']              = (int)$r['used'];
        $r['prize_id']          = $r['prize_id']          !== null ? (int)$r['prize_id']          : null;
        $r['prize_session']     = $r['prize_session']     !== null ? (int)$r['prize_session']     : null;
        $r['selected_box']      = $r['selected_box']      !== null ? (int)$r['selected_box']      : null;
    }
    unset($r);

    echo json_encode([
        'success' => true,
        'count'   => count($rows),
        'generated_at' => date('Y-m-d H:i:s'),
        'raw'     => $rows
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Query failed: ' . $e->getMessage()]);
}