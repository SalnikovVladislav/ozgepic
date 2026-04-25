<?php
/**
 * Дамп всех attempt+user+prize в JSON для sheets-sync.
 * Всегда требует X-API-Token (уже проверен в public/index.php).
 */
$pdo = db();

try {
    $stmt = $pdo->query("
        SELECT
            u.id AS user_id, u.userid AS tg_user_id,
            u.name, u.surname, u.phone, u.address, u.postcode, u.city,
            a.id AS attempt_id, a.userid AS attempt_tg_user_id,
            a.transaction_number, a.token, a.numeric_token AS numeric_token_id,
            a.session_num, a.used, a.created_at AS attempt_created_at,
            a.used_at, a.selected_box, a.box_selected_at,
            p.id AS prize_id, p.name AS prize_name, p.emoji AS prize_emoji,
            p.session_num AS prize_session
        FROM kese_attempts a
        LEFT JOIN kese_users  u ON u.userid = a.userid
        LEFT JOIN kese_prizes p ON p.id     = a.prize_id
        ORDER BY a.id ASC
    ");
    $rows = $stmt->fetchAll();

    foreach ($rows as &$r) {
        foreach (['user_id','tg_user_id','attempt_id','attempt_tg_user_id',
                  'numeric_token_id','session_num','prize_id','prize_session','selected_box'] as $f) {
            $r[$f] = $r[$f] !== null ? (int)$r[$f] : null;
        }
        $r['used'] = (int)$r['used'];
    }
    unset($r);

    echo json_encode([
        'success' => true,
        'count'   => count($rows),
        'generated_at' => date('Y-m-d H:i:s'),
        'raw'     => $rows,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

