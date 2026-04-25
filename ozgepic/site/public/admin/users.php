<?php
// users.php — Все пользователилар (Users panel for Kese Cup Game)
@ini_set('memory_limit', '512M');
require_once __DIR__ . '/../../src/auth.php';

$pdo = site_db();

// ─── MIGRATION: Add gift_sent column if missing ───────────────────────────────
try {
    $pdo->exec("ALTER TABLE kese_attempts ADD COLUMN gift_sent TINYINT NOT NULL DEFAULT 0");
} catch (PDOException $e) { /* already exists */ }
try {
    $pdo->exec("ALTER TABLE kese_attempts ADD COLUMN gift_sent_at DATETIME NULL");
} catch (PDOException $e) { /* already exists */ }

// ─── AJAX: Toggle gift_sent ───────────────────────────────────────────────────
if (isset($_POST['ajax_toggle_gift'])) {
    header('Content-Type: application/json');
    $id = (int)($_POST['attempt_id'] ?? 0);
    if (!$id) { echo json_encode(['success'=>false]); exit; }
    $cur = (int)$pdo->query("SELECT gift_sent FROM kese_attempts WHERE id=$id")->fetchColumn();
    $new = $cur ? 0 : 1;
    $pdo->prepare("UPDATE kese_attempts SET gift_sent=?, gift_sent_at=".($new?"NOW()":"NULL")." WHERE id=?")->execute([$new, $id]);
    echo json_encode(['success'=>true, 'gift_sent'=>$new]);
    exit;
}

// ─── AJAX: Delete user (re-register) ─────────────────────────────────────────
if (isset($_POST['ajax_delete_user'])) {
    header('Content-Type: application/json');
    $uid = (int)($_POST['userid'] ?? 0);
    if (!$uid) { echo json_encode(['success'=>false,'error'=>'No userid']); exit; }
    try {
        // Собираем fp чеков пользователя перед удалением попыток
        $fpStmt = $pdo->prepare("SELECT DISTINCT SUBSTRING_INDEX(transaction_number, '_', 1) AS fp FROM kese_attempts WHERE userid = ?");
        $fpStmt->execute([$uid]);
        $fps = $fpStmt->fetchAll(PDO::FETCH_COLUMN);

        $pdo->prepare("DELETE FROM kese_users WHERE userid = ?")->execute([$uid]);
        $pdo->prepare("DELETE FROM kese_attempts WHERE userid = ?")->execute([$uid]);

        // Удаляем чеки из bot_used_transactions, чтобы можно было загрузить повторно
        if ($fps) {
            $placeholders = implode(',', array_fill(0, count($fps), '?'));
            $pdo->prepare("DELETE FROM bot_used_transactions WHERE fp IN ($placeholders)")->execute($fps);
        }

        // Сброс состояния бота (чтобы заново прошёл регистрацию)
        try { $pdo->prepare("DELETE FROM bot_user_states WHERE userid = ?")->execute([$uid]); } catch(PDOException $e) {}
        echo json_encode(['success'=>true]);
    } catch (PDOException $e) {
        echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

// ─── AJAX: Delete receipt (bot_used_transactions FP) ─────────────────────────
if (isset($_POST['ajax_delete_receipt'])) {
    header('Content-Type: application/json');
    $fp = trim($_POST['fp'] ?? '');
    if ($fp === '') { echo json_encode(['success'=>false,'error'=>'No fp']); exit; }
    try {
        $pdo->prepare("DELETE FROM bot_used_transactions WHERE fp = ?")->execute([$fp]);
        echo json_encode(['success'=>true]);
    } catch (PDOException $e) {
        echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

// ─── FILTERS ─────────────────────────────────────────────────────────────────
$search      = trim($_GET['search'] ?? '');
$filterSess  = (int)($_GET['session'] ?? 0);
$filterUsed  = $_GET['used'] ?? '';
$filterGift  = $_GET['gift'] ?? '';   // '' | '1' | '0'
$view        = in_array($_GET['view'] ?? '', ['attempts','users','receipts']) ? $_GET['view'] : 'attempts';
$page        = max(1, (int)($_GET['page'] ?? 1));
$perPage     = 50;
$offset      = ($page - 1) * $perPage;

// ─── WHERE BUILDER (attempts) ─────────────────────────────────────────────────
$where  = ['1=1'];
$params = [];
if ($search !== '') {
    $where[]  = '(a.userid LIKE ? OR a.transaction_number LIKE ? OR a.token LIKE ? OR u.name LIKE ? OR u.surname LIKE ? OR u.phone LIKE ?)';
    $like     = '%' . $search . '%';
    $params   = array_merge($params, [$like, $like, $like, $like, $like, $like]);
}
if ($filterSess > 0) { $where[] = 'a.session_num = ?'; $params[] = $filterSess; }
if ($filterUsed === '1') { $where[] = 'a.used = 1'; }
if ($filterUsed === '0') { $where[] = 'a.used = 0'; }
if ($filterGift === '1') { $where[] = 'a.gift_sent = 1'; }
if ($filterGift === '0') { $where[] = 'a.gift_sent = 0'; }
$whereSQL = implode(' AND ', $where);

// ─── WHERE BUILDER (users) ────────────────────────────────────────────────────
$uWhere  = ['1=1'];
$uParams = [];
if ($search !== '') {
    $uWhere[]  = '(u.userid LIKE ? OR u.name LIKE ? OR u.surname LIKE ? OR u.phone LIKE ? OR u.address LIKE ? OR u.postcode LIKE ? OR u.city LIKE ?)';
    $like      = '%' . $search . '%';
    $uParams   = array_merge($uParams, [$like, $like, $like, $like, $like, $like, $like]);
}
$uWhereSQL = implode(' AND ', $uWhere);

// ─── SUMMARY STATS ───────────────────────────────────────────────────────────
$totalAttempts   = (int)$pdo->query("SELECT COUNT(*) FROM kese_attempts")->fetchColumn();
$usedAttempts    = (int)$pdo->query("SELECT COUNT(*) FROM kese_attempts WHERE used=1")->fetchColumn();
$uniqueUsers     = (int)$pdo->query("SELECT COUNT(DISTINCT userid) FROM kese_attempts")->fetchColumn();
$registeredUsers = (int)$pdo->query("SELECT COUNT(*) FROM kese_users")->fetchColumn();
$todayAttempts   = (int)$pdo->query("SELECT COUNT(*) FROM kese_attempts WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$giftSentCount   = (int)$pdo->query("SELECT COUNT(*) FROM kese_attempts WHERE gift_sent=1")->fetchColumn();
$giftPendingCount= (int)$pdo->query("SELECT COUNT(*) FROM kese_attempts WHERE used=1 AND gift_sent=0")->fetchColumn();

// ─── PRIZES MAP (выгружаем один раз, чтобы не тянуть BLOB картинок в каждом JOIN) ──
$prizesMap = [];
foreach ($pdo->query("SELECT id, name, emoji, img_data FROM kese_prizes")->fetchAll(PDO::FETCH_ASSOC) as $pr) {
    $prizesMap[(int)$pr['id']] = $pr;
}

// ─── ATTEMPTS QUERY ───────────────────────────────────────────────────────────
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM kese_attempts a LEFT JOIN kese_users u ON u.userid = a.userid WHERE $whereSQL");
$countStmt->execute($params);
$total      = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

$dataStmt = $pdo->prepare("
    SELECT a.id, a.userid, a.token, a.numeric_token, a.transaction_number,
           a.session_num, a.used, a.created_at, a.used_at,
           a.gift_sent, a.gift_sent_at, a.prize_id,
           u.name AS uname, u.surname AS usurname, u.phone AS uphone,
           u.address AS uaddress, u.postcode AS upostcode, u.city AS ucity
    FROM kese_attempts a
    LEFT JOIN kese_users u ON u.userid = a.userid
    WHERE $whereSQL
    ORDER BY a.id DESC
    LIMIT $perPage OFFSET $offset
");
$dataStmt->execute($params);
$attempts = $dataStmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($attempts as &$a) {
    $pr = $prizesMap[(int)$a['prize_id']] ?? null;
    $a['prize_name']  = $pr['name']     ?? null;
    $a['prize_emoji'] = $pr['emoji']    ?? null;
    $a['prize_img']   = $pr['img_data'] ?? null;
}
unset($a);

// ─── USERS QUERY ──────────────────────────────────────────────────────────────
$uCountStmt = $pdo->prepare("SELECT COUNT(*) FROM kese_users u WHERE $uWhereSQL");
$uCountStmt->execute($uParams);
$uTotal      = (int)$uCountStmt->fetchColumn();
$uTotalPages = max(1, (int)ceil($uTotal / $perPage));

$uDataStmt = $pdo->prepare("
    SELECT u.*,
           COUNT(a.id)       AS total_tickets,
           SUM(a.used)       AS used_tickets,
           SUM(a.gift_sent)  AS gift_sent_tickets,
           MIN(a.created_at) AS first_at,
           MAX(a.created_at) AS last_at,
           GROUP_CONCAT(DISTINCT a.session_num ORDER BY a.session_num) AS sessions_used
    FROM kese_users u
    LEFT JOIN kese_attempts a ON a.userid = u.userid
    WHERE $uWhereSQL
    GROUP BY u.id
    ORDER BY u.id DESC
    LIMIT $perPage OFFSET $offset
");
$uDataStmt->execute($uParams);
$allUsers = $uDataStmt->fetchAll(PDO::FETCH_ASSOC);

// Load attempts for displayed users
$userIds      = array_column($allUsers, 'userid');
$userAttempts = [];
if ($userIds) {
    $inList  = implode(',', array_map('intval', $userIds));
    $attStmt = $pdo->query("
        SELECT a.userid, a.id AS attempt_id, a.numeric_token, a.session_num,
               a.used, a.created_at, a.used_at, a.transaction_number,
               a.gift_sent, a.gift_sent_at, a.prize_id
        FROM kese_attempts a
        WHERE a.userid IN ($inList)
        ORDER BY a.id ASC
    ");
    foreach ($attStmt->fetchAll(PDO::FETCH_ASSOC) as $att) {
        $pr = $prizesMap[(int)$att['prize_id']] ?? null;
        $att['prize_name']  = $pr['name']     ?? null;
        $att['prize_emoji'] = $pr['emoji']    ?? null;
        $att['prize_img']   = $pr['img_data'] ?? null;
        $userAttempts[$att['userid']][] = $att;
    }
}

// ─── RECEIPTS QUERY (bot_used_transactions + link to attempt) ─────────────────
$receipts       = [];
$receiptsTotal  = 0;
$receiptsPages  = 1;
$receiptsUsedCount   = 0;
$receiptsUnusedCount = 0;
if ($view === 'receipts') {
    // Бот сохраняет transaction_number как "{fp}_{N}" (напр. 562487471257_1),
    // а в bot_used_transactions.fp лежит чистый fp. Матчим по префиксу до '_'.
    $receiptsTotal     = (int)$pdo->query("SELECT COUNT(*) FROM bot_used_transactions")->fetchColumn();
    $receiptsUsedCount = (int)$pdo->query("
        SELECT COUNT(DISTINCT bt.fp)
        FROM bot_used_transactions bt
        JOIN kese_attempts a
          ON SUBSTRING_INDEX(a.transaction_number, '_', 1) = bt.fp
        WHERE a.used = 1
    ")->fetchColumn();
    $receiptsUnusedCount = $receiptsTotal - $receiptsUsedCount;

    // Для списка берём одну «лучшую» попытку на fp: приоритет used=1, затем последняя по id.
    $attemptPick = "
        LEFT JOIN kese_attempts a ON a.id = (
            SELECT a2.id FROM kese_attempts a2
            WHERE SUBSTRING_INDEX(a2.transaction_number, '_', 1) = bt.fp
            ORDER BY a2.used DESC, a2.id DESC
            LIMIT 1
        )
    ";

    $rWhere  = ['1=1'];
    $rParams = [];
    if ($search !== '') {
        $rWhere[]  = '(bt.fp LIKE ? OR a.userid LIKE ? OR u.name LIKE ? OR u.surname LIKE ? OR u.phone LIKE ?)';
        $like      = '%' . $search . '%';
        $rParams   = [$like, $like, $like, $like, $like];
    }
    if ($filterUsed === '1')      { $rWhere[] = 'a.used = 1'; }
    elseif ($filterUsed === '0')  { $rWhere[] = '(a.id IS NULL OR a.used = 0)'; }
    $rWhereSQL = implode(' AND ', $rWhere);

    $rCnt = $pdo->prepare("
        SELECT COUNT(*) FROM bot_used_transactions bt
        $attemptPick
        LEFT JOIN kese_users u ON u.userid = a.userid
        WHERE $rWhereSQL
    ");
    $rCnt->execute($rParams);
    $rTot = (int)$rCnt->fetchColumn();
    $receiptsPages = max(1, (int)ceil($rTot / $perPage));

    $rStmt = $pdo->prepare("
        SELECT bt.fp, bt.created_at AS receipt_at,
               a.id AS attempt_id, a.userid, a.numeric_token, a.session_num,
               a.used, a.used_at,
               u.name AS uname, u.surname AS usurname, u.phone AS uphone
        FROM bot_used_transactions bt
        $attemptPick
        LEFT JOIN kese_users u ON u.userid = a.userid
        WHERE $rWhereSQL
        ORDER BY bt.created_at DESC
        LIMIT $perPage OFFSET $offset
    ");
    $rStmt->execute($rParams);
    $receipts = $rStmt->fetchAll(PDO::FETCH_ASSOC);
}

// ─── CSV EXPORT ───────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="kese_users_'.date('Y-m-d').'.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['ID','User ID','Имя','Фамилия','Телефон','Адрес','Индекс','Город','Билет №','Сессия','Приз','Статус','Подарок отправлен','Транзакция','Выдан','Сыграл'], ';');
    $expStmt = $pdo->query("
        SELECT u.id, u.userid, u.name, u.surname, u.phone, u.address, u.postcode, u.city,
               a.numeric_token, a.session_num, p.name AS prize_name, a.used,
               a.gift_sent, a.transaction_number, a.created_at, a.used_at
        FROM kese_users u
        LEFT JOIN kese_attempts a ON a.userid = u.userid
        LEFT JOIN kese_prizes p ON a.prize_id = p.id
        ORDER BY u.id DESC, a.id ASC
    ");
    while ($r = $expStmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [
            $r['id'], $r['userid'], $r['name'], $r['surname'], $r['phone'],
            $r['address'], $r['postcode'], $r['city'],
            $r['numeric_token'] ?? '', $r['session_num'] ? $r['session_num'].'-я сессия' : '',
            $r['prize_name'] ?? '',
            $r['used'] ? 'Использован' : 'Ожидает',
            $r['gift_sent'] ? 'Отправлен' : 'Не отправлен',
            $r['transaction_number'] ?? '', $r['created_at'] ?? '', $r['used_at'] ?? ''
        ], ';');
    }
    fclose($out); exit;
}

function buildUrl($overrides = []) {
    $p = array_merge($_GET, $overrides);
    // Не отбрасываем '0' — иначе ломаются фильтры used=0 / gift=0.
    $p = array_filter($p, fn($v) => $v !== '' && $v !== null);
    return 'users.php?' . http_build_query($p);
}

function hl($str, $search) {
    if (!$search) return htmlspecialchars($str);
    return preg_replace('/(' . preg_quote(htmlspecialchars($search), '/') . ')/iu',
        '<span class="hl">$1</span>', htmlspecialchars($str));
}
?>
<!DOCTYPE html>
<html lang="kk">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>СИҚЫРЛЫ БОКС — Пользователи</title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Segoe UI',sans-serif; background:#0f0f0f; color:#e0e0e0; min-height:100vh; }

.topbar {
  background:linear-gradient(135deg,#1a0e03,#2a1a05);
  border-bottom:2px solid #d4a017;
  padding:14px 28px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;
}
.topbar h1 { font-size:1.2rem; letter-spacing:3px; color:#d4a017; text-transform:uppercase; }
.topbar-links { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
.topbar-links a { color:#888; font-size:0.82rem; text-decoration:none; padding:6px 14px; border:1px solid #333; border-radius:20px; transition:all 0.2s; }
.topbar-links a:hover { color:#d4a017; border-color:#d4a017; }
.topbar-links a.cur { color:#d4a017; border-color:rgba(212,160,23,0.5); background:rgba(212,160,23,0.08); }

.container { max-width:1600px; margin:0 auto; padding:24px 20px; }

.stats-row { display:grid; grid-template-columns:repeat(auto-fit,minmax(130px,1fr)); gap:14px; margin-bottom:24px; }
.stat-card { background:linear-gradient(135deg,#1a0e03,#2a1a05); border:1px solid rgba(212,160,23,0.2); border-radius:10px; padding:18px; text-align:center; position:relative; overflow:hidden; cursor:default; }
.stat-card::before { content:''; position:absolute; top:0; left:0; right:0; height:2px; background:linear-gradient(90deg,transparent,#d4a017,transparent); }
.stat-card .num { font-size:2rem; font-weight:700; color:#d4a017; line-height:1; }
.stat-card .lbl { font-size:0.7rem; color:#666; margin-top:6px; letter-spacing:1px; text-transform:uppercase; }
.stat-card.g  .num { color:#5cb85c; } .stat-card.g::before  { background:linear-gradient(90deg,transparent,#5cb85c,transparent); }
.stat-card.o  .num { color:#ffa500; } .stat-card.o::before  { background:linear-gradient(90deg,transparent,#ffa500,transparent); }
.stat-card.b  .num { color:#5b9bd5; } .stat-card.b::before  { background:linear-gradient(90deg,transparent,#5b9bd5,transparent); }
.stat-card.p  .num { color:#c084fc; } .stat-card.p::before  { background:linear-gradient(90deg,transparent,#c084fc,transparent); }
.stat-card.r  .num { color:#f87171; } .stat-card.r::before  { background:linear-gradient(90deg,transparent,#f87171,transparent); }

/* view toggle */
.view-toggle { display:flex; border-radius:6px; overflow:hidden; border:1px solid #2a2a2a; width:fit-content; margin-bottom:20px; }
.view-btn { padding:9px 22px; border:none; background:#181818; color:#555; cursor:pointer; font-size:0.85rem; font-weight:500; text-decoration:none; display:flex; align-items:center; gap:6px; transition:all 0.2s; }
.view-btn:hover { color:#ccc; background:#222; }
.view-btn.active { background:rgba(212,160,23,0.12); color:#d4a017; }

/* filter */
.filter-bar { background:#181818; border:1px solid #2a2a2a; border-radius:10px; padding:16px 20px; display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end; margin-bottom:20px; }
.filter-bar label { display:block; font-size:0.7rem; color:#555; margin-bottom:4px; letter-spacing:1px; text-transform:uppercase; }
.filter-bar input[type="text"], .filter-bar select { padding:8px 12px; background:#252525; border:1px solid #3a3a3a; border-radius:5px; color:#e0e0e0; font-size:0.88rem; outline:none; transition:border-color 0.2s; }
.filter-bar input[type="text"] { min-width:280px; }
.filter-bar input:focus, .filter-bar select:focus { border-color:#d4a017; }
.btn { padding:8px 18px; border:none; border-radius:5px; font-size:0.85rem; cursor:pointer; font-weight:500; transition:all 0.2s; }
.btn-gold  { background:linear-gradient(135deg,#d4a017,#f0c040); color:#0a0604; }
.btn-gold:hover { filter:brightness(1.1); }
.btn-ghost { background:rgba(255,255,255,0.04); border:1px solid #2d2d2d; color:#666; text-decoration:none; display:inline-block; }
.btn-ghost:hover { border-color:#d4a017; color:#d4a017; }

/* gift filter pills */
.gift-filter-pills { display:flex; gap:6px; align-items:center; flex-wrap:wrap; }
.gpill { padding:5px 12px; border-radius:20px; font-size:0.78rem; font-weight:500; text-decoration:none; border:1px solid #2a2a2a; color:#555; background:#1e1e1e; transition:all 0.2s; white-space:nowrap; }
.gpill:hover { color:#ccc; border-color:#444; }
.gpill.active-all    { background:rgba(212,160,23,0.12); border-color:rgba(212,160,23,0.35); color:#d4a017; }
.gpill.active-sent   { background:rgba(92,184,92,0.12);  border-color:rgba(92,184,92,0.35);  color:#5cb85c; }
.gpill.active-unsent { background:rgba(248,113,113,0.12);border-color:rgba(248,113,113,0.35);color:#f87171; }

/* section */
.section { background:#181818; border:1px solid #2a2a2a; border-radius:10px; overflow:hidden; margin-bottom:20px; }
.sec-hdr { padding:14px 20px; border-bottom:1px solid #2a2a2a; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; }
.sec-hdr h2 { color:#d4a017; font-size:0.88rem; letter-spacing:2px; text-transform:uppercase; }
.cnt-badge { font-size:0.78rem; color:#555; background:#1e1e1e; border:1px solid #2a2a2a; padding:4px 10px; border-radius:10px; }

/* table */
.tbl-wrap { overflow-x:auto; }
.dtable { width:100%; border-collapse:collapse; font-size:0.82rem; min-width:1100px; }
.dtable th { background:#141414; color:#555; padding:10px 12px; text-align:left; font-weight:500; border-bottom:1px solid #222; font-size:0.7rem; letter-spacing:1px; text-transform:uppercase; }
.dtable td { padding:9px 12px; border-bottom:1px solid #1c1c1c; vertical-align:middle; }
.dtable tr:last-child td { border-bottom:none; }
.dtable tr:hover td { background:rgba(212,160,23,0.02); }

.badge { padding:3px 8px; border-radius:10px; font-size:0.72rem; }
.b-used   { background:rgba(40,167,69,0.18); color:#5cb85c; }
.b-unused { background:rgba(100,100,100,0.15); color:#666; }
.s-badge  { display:inline-block; padding:2px 8px; border-radius:8px; font-size:0.72rem; background:rgba(212,160,23,0.1); color:#d4a017; border:1px solid rgba(212,160,23,0.2); }

/* ── GIFT BUTTON ─────────────────────────────────────────────────────────── */
.gift-btn {
  display:inline-flex; align-items:center; gap:5px;
  padding:5px 12px; border-radius:20px; border:none; cursor:pointer;
  font-size:0.75rem; font-weight:600; letter-spacing:0.3px;
  transition:all 0.18s ease; white-space:nowrap; user-select:none;
}
.gift-btn.not-sent {
  background:rgba(248,113,113,0.1); border:1px solid rgba(248,113,113,0.3);
  color:#f87171;
}
.gift-btn.not-sent:hover {
  background:rgba(248,113,113,0.22); border-color:rgba(248,113,113,0.6);
  transform:translateY(-1px);
}
.gift-btn.sent {
  background:rgba(92,184,92,0.12); border:1px solid rgba(92,184,92,0.35);
  color:#5cb85c;
}
.gift-btn.sent:hover {
  background:rgba(248,113,113,0.1); border-color:rgba(248,113,113,0.3);
  color:#f87171;
}
.gift-btn.loading { opacity:0.5; pointer-events:none; }

.gift-date { font-size:0.68rem; color:#4a7a4a; margin-top:2px; display:block; }

.uid-t   { font-family:monospace; font-size:0.88rem; color:#d4a017; font-weight:600; }
.name-t  { font-size:0.83rem; color:#ccc; }
.phone-t { font-size:0.78rem; color:#888; }
.date-t  { font-size:0.75rem; color:#555; white-space:nowrap; }
.tx-t    { font-family:monospace; font-size:0.72rem; color:#555; max-width:130px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; display:inline-block; }
.tok-t   { font-family:monospace; font-size:0.72rem; color:#444; }
.pc      { display:flex; align-items:center; gap:6px; }
.pc img  { width:24px; height:24px; border-radius:50%; object-fit:cover; border:1px solid #2d2d2d; }
.pc .em  { font-size:1.1rem; }
.hl      { background:rgba(212,160,23,0.22); color:#f0c040; padding:1px 3px; border-radius:2px; }

/* user cards */
.ucard { background:#1a1a1a; border:1px solid #262626; border-radius:10px; margin-bottom:16px; overflow:hidden; }
.ucard-hdr { background:linear-gradient(135deg,#1e1a10,#221e12); border-bottom:1px solid #2a2510; padding:14px 20px; display:flex; flex-wrap:wrap; align-items:center; gap:14px; }
.del-user-btn { margin-left:auto; padding:5px 12px; background:rgba(220,53,69,0.12); border:1px solid rgba(220,53,69,0.35); color:#dc3545; border-radius:5px; font-size:0.78rem; cursor:pointer; transition:all 0.2s; white-space:nowrap; }
.del-user-btn:hover { background:rgba(220,53,69,0.28); }
.uid-bdg   { background:rgba(212,160,23,0.12); border:1px solid rgba(212,160,23,0.3); color:#d4a017; padding:4px 12px; border-radius:20px; font-size:0.8rem; font-weight:700; font-family:monospace; }
.uname-lg  { font-size:1rem; font-weight:600; color:#e8e8e8; }
.uphone-hd { color:#888; font-size:0.85rem; }
.hbadges   { margin-left:auto; display:flex; gap:8px; flex-wrap:wrap; }
.hbg       { padding:3px 10px; border-radius:12px; font-size:0.75rem; }
.hbg-g     { background:rgba(212,160,23,0.1); border:1px solid rgba(212,160,23,0.25); color:#d4a017; }
.hbg-gr    { background:rgba(40,167,69,0.1);  border:1px solid rgba(40,167,69,0.25);  color:#5cb85c; }
.hbg-p     { background:rgba(92,184,92,0.1);  border:1px solid rgba(92,184,92,0.25);  color:#5cb85c; }

.uinfo-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(165px,1fr)); border-bottom:1px solid #222; }
.uinfo-item { padding:10px 16px; border-right:1px solid #1e1e1e; }
.uinfo-item:last-child { border-right:none; }
.ui-lbl { font-size:0.67rem; color:#454545; letter-spacing:1px; text-transform:uppercase; margin-bottom:3px; }
.ui-val { font-size:0.84rem; color:#ccc; word-break:break-word; }
.ui-val.gold  { color:#d4a017; font-weight:600; }
.ui-val.green { color:#5cb85c; }

.tkt-title { padding:10px 16px 6px; font-size:0.68rem; color:#454545; letter-spacing:2px; text-transform:uppercase; }
.tkt-tbl { width:100%; border-collapse:collapse; font-size:0.78rem; }
.tkt-tbl th { background:#141414; color:#444; padding:7px 12px; text-align:left; font-size:0.68rem; letter-spacing:1px; text-transform:uppercase; border-bottom:1px solid #1e1e1e; }
.tkt-tbl td { padding:8px 12px; border-bottom:1px solid #1c1c1c; vertical-align:middle; }
.tkt-tbl tr:last-child td { border-bottom:none; }
.tkt-tbl tr:hover td { background:rgba(212,160,23,0.02); }
.no-tkt { padding:14px 16px; color:#383838; font-size:0.82rem; font-style:italic; }

/* pagination */
.pager { display:flex; gap:6px; justify-content:center; flex-wrap:wrap; padding:20px; }
.pa { padding:6px 13px; border-radius:5px; font-size:0.82rem; text-decoration:none; background:#1a1a1a; border:1px solid #2a2a2a; color:#777; transition:all 0.2s; }
.pa:hover { border-color:#d4a017; color:#d4a017; }
.pa.cur { background:rgba(212,160,23,0.15); border-color:rgba(212,160,23,0.4); color:#d4a017; font-weight:700; }
.pa.off { opacity:0.25; pointer-events:none; }
.pi { font-size:0.78rem; color:#444; padding:6px 8px; }

.empty-s { text-align:center; padding:50px 20px; color:#3a3a3a; }
.empty-s .ico { font-size:3rem; margin-bottom:12px; }
.empty-s p { font-size:0.88rem; }

/* toast */
.toast {
  position:fixed; bottom:24px; right:24px; z-index:9999;
  background:#1e2a1e; border:1px solid rgba(92,184,92,0.4); color:#5cb85c;
  padding:10px 18px; border-radius:8px; font-size:0.85rem; font-weight:500;
  opacity:0; transform:translateY(10px); transition:all 0.25s ease;
  pointer-events:none;
}
.toast.show { opacity:1; transform:translateY(0); }
.toast.red  { background:#2a1e1e; border-color:rgba(248,113,113,0.4); color:#f87171; }

::-webkit-scrollbar { width:5px; height:5px; }
::-webkit-scrollbar-track { background:#111; }
::-webkit-scrollbar-thumb { background:#2e2e2e; border-radius:3px; }

@media(max-width:768px) {
  .topbar { padding:12px 16px; }
  .container { padding:14px; }
  .filter-bar { flex-direction:column; }
  .filter-bar input[type="text"] { min-width:100%; width:100%; }
}
</style>
</head>
<body>

<div class="toast" id="toast"></div>

<div class="topbar">
  <h1>👥 Пользователи</h1>
  <div class="topbar-links">
    <a href="index.php">⚙️ Админка</a>
    <a href="kese_stats.php">📊 Статистика</a>
    <a href="users.php" class="cur">👥 Пользователи</a>
    <a href="?export=csv<?= $search?"&search=".urlencode($search):'' ?>" style="background:rgba(40,167,69,0.12);border-color:rgba(40,167,69,0.35);color:#5cb85c;">⬇ CSV</a>
    <a href="?logout=1" style="background:rgba(220,50,50,0.12);border-color:rgba(220,80,80,0.35);color:#ff8080;" onclick="return confirm('Выйти?')">🔒 Выход</a>
  </div>
</div>

<div class="container">

  <div class="stats-row">
    <div class="stat-card b">  <div class="num"><?= number_format($registeredUsers) ?></div>  <div class="lbl">Зарегистр.</div></div>
    <div class="stat-card">    <div class="num"><?= number_format($uniqueUsers) ?></div>       <div class="lbl">Уникальных</div></div>
    <div class="stat-card">    <div class="num"><?= number_format($totalAttempts) ?></div>     <div class="lbl">Всего билетов</div></div>
    <div class="stat-card g">  <div class="num"><?= number_format($usedAttempts) ?></div>     <div class="lbl">Использован</div></div>
    <div class="stat-card o">  <div class="num"><?= number_format($todayAttempts) ?></div>    <div class="lbl">Сегодня</div></div>
    <div class="stat-card p">  <div class="num"><?= number_format($giftSentCount) ?></div>    <div class="lbl">Подарок отправлен</div></div>
    <div class="stat-card r">  <div class="num"><?= number_format($giftPendingCount) ?></div> <div class="lbl">Не отправлен</div></div>
  </div>

  <div class="view-toggle">
    <a class="view-btn <?= $view==='attempts'?'active':'' ?>" href="<?= buildUrl(['view'=>'attempts','page'=>1]) ?>">📋 Попытки</a>
    <a class="view-btn <?= $view==='users'?'active':'' ?>"    href="<?= buildUrl(['view'=>'users','page'=>1]) ?>">👤 Пользователи</a>
    <a class="view-btn <?= $view==='receipts'?'active':'' ?>" href="<?= buildUrl(['view'=>'receipts','page'=>1]) ?>">🧾 Чеки</a>
  </div>

  <form method="GET">
    <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
    <div class="filter-bar">
      <div>
        <label>🔍 Поиск</label>
        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
               placeholder="User ID · Имя · Фамилия · Телефон · Транзакция...">
      </div>
      <?php if ($view === 'attempts'): ?>
      <div>
        <label>Сессия</label>
        <select name="session">
          <option value="0" <?= $filterSess===0?'selected':'' ?>>— Всего —</option>
          <option value="1" <?= $filterSess===1?'selected':'' ?>>1-я сессия</option>
          <option value="2" <?= $filterSess===2?'selected':'' ?>>2-я сессия</option>
          <option value="3" <?= $filterSess===3?'selected':'' ?>>3-я сессия</option>
        </select>
      </div>
      <div>
        <label>Статус игры</label>
        <select name="used">
          <option value=""  <?= $filterUsed===''?'selected':'' ?>>— Всего —</option>
          <option value="1" <?= $filterUsed==='1'?'selected':'' ?>>✅ Использован</option>
          <option value="0" <?= $filterUsed==='0'?'selected':'' ?>>⏳ Ожидает</option>
        </select>
      </div>
      <div>
        <label>Подарок статусы</label>
        <select name="gift">
          <option value=""  <?= $filterGift===''?'selected':'' ?>>— Всего —</option>
          <option value="1" <?= $filterGift==='1'?'selected':'' ?>>🎁 Отправлен</option>
          <option value="0" <?= $filterGift==='0'?'selected':'' ?>>📦 Не отправлен</option>
        </select>
      </div>
      <?php endif; ?>
      <div style="display:flex;align-items:flex-end;gap:8px;">
        <button type="submit" class="btn btn-gold">Поиск</button>
        <a href="users.php?view=<?= $view ?>" class="btn btn-ghost">✕</a>
      </div>
    </div>
  </form>

  <?php if ($view === 'attempts'): ?>
  <!-- ════════════════ ATTEMPTS ════════════════ -->

  <!-- Quick gift filter pills -->
  <div style="margin-bottom:16px;">
    <div class="gift-filter-pills">
      <span style="font-size:0.75rem;color:#444;margin-right:4px;text-transform:uppercase;letter-spacing:1px;">Подарок:</span>
      <a class="gpill <?= $filterGift===''?'active-all':'' ?>"    href="<?= buildUrl(['gift'=>'',  'page'=>1]) ?>">📋 Всего</a>
      <a class="gpill <?= $filterGift==='0'?'active-unsent':'' ?>" href="<?= buildUrl(['gift'=>'0', 'page'=>1]) ?>">📦 Не отправлен <span style="background:rgba(248,113,113,0.15);color:#f87171;padding:1px 7px;border-radius:10px;font-size:0.7rem;margin-left:3px"><?= $giftPendingCount ?></span></a>
      <a class="gpill <?= $filterGift==='1'?'active-sent':'' ?>"   href="<?= buildUrl(['gift'=>'1', 'page'=>1]) ?>">🎁 Отправлен <span style="background:rgba(92,184,92,0.15);color:#5cb85c;padding:1px 7px;border-radius:10px;font-size:0.7rem;margin-left:3px"><?= $giftSentCount ?></span></a>
    </div>
  </div>

  <div class="section">
    <div class="sec-hdr">
      <h2>📋 Список попыток</h2>
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <span class="cnt-badge"><?= number_format($total) ?> жазба<?= $search ? ' · «'.htmlspecialchars($search).'»' : '' ?></span>
      </div>
    </div>
    <?php if (empty($attempts)): ?>
    <div class="empty-s"><div class="ico">📭</div><p>Результатов не найдено</p></div>
    <?php else: ?>
    <div class="tbl-wrap">
    <table class="dtable">
      <thead>
        <tr>
          <th>#</th><th>Билет №</th><th>User ID</th>
          <th>Имя</th><th>Фамилия</th><th>Телефон</th><th>Город</th>
          <th>Сессия</th><th>Приз</th><th>Игра</th>
          <th>🎁 Подарок</th>
          <th>Транзакция</th><th>Выдан</th><th>Сыграл</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($attempts as $a): ?>
        <tr id="row-<?= $a['id'] ?>">
          <td style="color:#383838"><?= $a['id'] ?></td>
          <td class="tok-t">#<?= $a['numeric_token'] ?></td>
          <td class="uid-t"><?= hl((string)$a['userid'], $search) ?></td>
          <td class="name-t"><?= $a['uname']    ? hl($a['uname'], $search)    : '<span style="color:#333">—</span>' ?></td>
          <td class="name-t"><?= $a['usurname'] ? hl($a['usurname'], $search) : '<span style="color:#333">—</span>' ?></td>
          <td class="phone-t"><?= $a['uphone']  ? hl($a['uphone'], $search)   : '<span style="color:#2a2a2a">—</span>' ?></td>
          <td style="color:#666;font-size:0.78rem"><?= $a['ucity'] ? htmlspecialchars($a['ucity']) : '<span style="color:#2a2a2a">—</span>' ?></td>
          <td><span class="s-badge"><?= $a['session_num'] ?>-с</span></td>
          <td>
            <div class="pc">
              <?php if ($a['prize_img']): ?><img src="<?= htmlspecialchars($a['prize_img']) ?>" alt=""><?php else: ?><span class="em"><?= htmlspecialchars($a['prize_emoji'] ?? '🎁') ?></span><?php endif; ?>
              <span style="color:#ccc;font-size:0.8rem"><?= htmlspecialchars($a['prize_name'] ?? '—') ?></span>
            </div>
          </td>
          <td><?= $a['used'] ? '<span class="badge b-used">✅ Пайд.</span>' : '<span class="badge b-unused">⏳ Ожидает</span>' ?></td>
          <!-- GIFT BUTTON -->
          <td>
            <div style="display:flex;flex-direction:column;align-items:flex-start;gap:2px;">
              <button
                class="gift-btn <?= $a['gift_sent'] ? 'sent' : 'not-sent' ?>"
                id="gift-btn-<?= $a['id'] ?>"
                onclick="toggleGift(<?= $a['id'] ?>, this)"
                title="<?= $a['gift_sent'] ? 'Отправлен — нажмите, чтобы отменить' : 'Не отправлен — нажмите, чтобы отметить' ?>">
                <?php if ($a['gift_sent']): ?>
                  <span>✅</span> Отправлен
                <?php else: ?>
                  <span>📦</span> Не отправлен
                <?php endif; ?>
              </button>
              <?php if ($a['gift_sent'] && $a['gift_sent_at']): ?>
              <span class="gift-date" id="gift-date-<?= $a['id'] ?>"><?= date('d.m.y H:i', strtotime($a['gift_sent_at'])) ?></span>
              <?php else: ?>
              <span class="gift-date" id="gift-date-<?= $a['id'] ?>" style="display:<?= $a['gift_sent']?'block':'none' ?>"></span>
              <?php endif; ?>
            </div>
          </td>
          <td><span class="tx-t" title="<?= htmlspecialchars($a['transaction_number']) ?>"><?= hl($a['transaction_number'], $search) ?></span></td>
          <td class="date-t"><?= date('d.m.Y H:i', strtotime($a['created_at'])) ?></td>
          <td class="date-t"><?= $a['used_at'] ? date('d.m.Y H:i', strtotime($a['used_at'])) : '<span style="color:#2a2a2a">—</span>' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php if ($totalPages > 1):
      $pg = fn($p) => buildUrl(['page'=>$p]);
    ?>
    <div class="pager">
      <a class="pa <?= $page<=1?'off':'' ?>" href="<?= $pg(1) ?>">«</a>
      <a class="pa <?= $page<=1?'off':'' ?>" href="<?= $pg($page-1) ?>">‹</a>
      <?php
      $s2=max(1,$page-3); $e2=min($totalPages,$page+3);
      if ($s2>1) echo '<span class="pi">…</span>';
      for ($i=$s2;$i<=$e2;$i++) printf('<a class="pa%s" href="%s">%d</a>',$i===$page?' cur':'',$pg($i),$i);
      if ($e2<$totalPages) echo '<span class="pi">…</span>';
      ?>
      <a class="pa <?= $page>=$totalPages?'off':'' ?>" href="<?= $pg($page+1) ?>">›</a>
      <a class="pa <?= $page>=$totalPages?'off':'' ?>" href="<?= $pg($totalPages) ?>">»</a>
      <span class="pi"><?= $page ?>/<?= $totalPages ?> стр. · <?= number_format($total) ?> жазба</span>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>

  <?php elseif ($view === 'receipts'): ?>
  <!-- ════════════════ RECEIPTS ════════════════ -->
  <div style="margin-bottom:16px;">
    <div class="gift-filter-pills">
      <span style="font-size:0.75rem;color:#444;margin-right:4px;text-transform:uppercase;letter-spacing:1px;">Статус:</span>
      <a class="gpill <?= $filterUsed===''?'active-all':'' ?>"     href="<?= buildUrl(['used'=>'',  'page'=>1]) ?>">📋 Всего <span style="background:rgba(212,160,23,0.15);color:#d4a017;padding:1px 7px;border-radius:10px;font-size:0.7rem;margin-left:3px"><?= $receiptsTotal ?></span></a>
      <a class="gpill <?= $filterUsed==='1'?'active-sent':'' ?>"   href="<?= buildUrl(['used'=>'1', 'page'=>1]) ?>">✅ Использовано <span style="background:rgba(92,184,92,0.15);color:#5cb85c;padding:1px 7px;border-radius:10px;font-size:0.7rem;margin-left:3px"><?= $receiptsUsedCount ?></span></a>
      <a class="gpill <?= $filterUsed==='0'?'active-unsent':'' ?>" href="<?= buildUrl(['used'=>'0', 'page'=>1]) ?>">⏳ Неиспользовано <span style="background:rgba(248,113,113,0.15);color:#f87171;padding:1px 7px;border-radius:10px;font-size:0.7rem;margin-left:3px"><?= $receiptsUnusedCount ?></span></a>
    </div>
  </div>
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px;">
    <span style="color:#555;font-size:0.85rem">
      Всего чеков: <strong style="color:#d4a017"><?= number_format($receiptsTotal) ?></strong> ·
      <span style="color:#5cb85c">✅ Использовано: <?= number_format($receiptsUsedCount) ?></span> ·
      <span style="color:#f87171">⏳ Неиспользовано: <?= number_format($receiptsUnusedCount) ?></span>
      <?= $search ? ' · «'.htmlspecialchars($search).'»' : '' ?>
    </span>
  </div>

  <div class="section">
    <div class="sec-hdr">
      <h2>🧾 Загруженные чеки (bot_used_transactions)</h2>
      <span class="cnt-badge">Страница <?= $page ?>/<?= $receiptsPages ?></span>
    </div>
    <?php if (empty($receipts)): ?>
    <div class="empty-s"><div class="ico">🧾</div><p>Чеки не найдены</p></div>
    <?php else: ?>
    <div class="tbl-wrap">
    <table class="dtable">
      <thead>
        <tr>
          <th>FP (чек)</th>
          <th>User ID</th>
          <th>Имя</th>
          <th>Телефон</th>
          <th>Билет №</th>
          <th>Сессия</th>
          <th>Сумма</th>
          <th>Статус</th>
          <th>Загружен</th>
          <th>Сыграл</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($receipts as $r): ?>
        <tr id="rcpt-<?= htmlspecialchars($r['fp']) ?>">
          <td><span class="tx-t" title="<?= htmlspecialchars($r['fp']) ?>"><?= hl($r['fp'], $search) ?></span></td>
          <td class="uid-t"><?= $r['userid'] ? hl((string)$r['userid'], $search) : '<span style="color:#333">—</span>' ?></td>
          <td class="name-t"><?= $r['uname'] ? hl(trim(($r['uname']??'').' '.($r['usurname']??'')), $search) : '<span style="color:#333">—</span>' ?></td>
          <td class="phone-t"><?= $r['uphone'] ? hl($r['uphone'], $search) : '<span style="color:#2a2a2a">—</span>' ?></td>
          <td class="tok-t"><?= $r['numeric_token'] ? '#'.$r['numeric_token'] : '<span style="color:#2a2a2a">—</span>' ?></td>
          <td><?= $r['session_num'] ? '<span class="s-badge">'.$r['session_num'].'-с</span>' : '<span style="color:#2a2a2a">—</span>' ?></td>
          <td style="color:#888;font-size:0.8rem"><?= $r['payment_amount'] ? number_format($r['payment_amount']).' ₸' : '<span style="color:#2a2a2a">—</span>' ?></td>
          <td>
            <?php if ($r['attempt_id']): ?>
              <?= $r['used'] ? '<span class="badge b-used">✅ Сыграл</span>' : '<span class="badge b-unused">⏳ Ожидает</span>' ?>
            <?php else: ?>
              <span class="badge" style="background:rgba(248,113,113,0.15);color:#f87171">⚠️ Без билета</span>
            <?php endif; ?>
          </td>
          <td class="date-t"><?= date('d.m.Y H:i', strtotime($r['receipt_at'])) ?></td>
          <td class="date-t"><?= $r['used_at'] ? date('d.m.Y H:i', strtotime($r['used_at'])) : '<span style="color:#2a2a2a">—</span>' ?></td>
          <td>
            <button class="del-user-btn" onclick="deleteReceipt('<?= htmlspecialchars(addslashes($r['fp'])) ?>', this)" title="Удалить чек (позволит загрузить его заново)">🗑</button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php if ($receiptsPages > 1):
      $pg = fn($p) => buildUrl(['page'=>$p]);
    ?>
    <div class="pager">
      <a class="pa <?= $page<=1?'off':'' ?>" href="<?= $pg(1) ?>">«</a>
      <a class="pa <?= $page<=1?'off':'' ?>" href="<?= $pg($page-1) ?>">‹</a>
      <?php
      $s2=max(1,$page-3); $e2=min($receiptsPages,$page+3);
      if ($s2>1) echo '<span class="pi">…</span>';
      for ($i=$s2;$i<=$e2;$i++) printf('<a class="pa%s" href="%s">%d</a>',$i===$page?' cur':'',$pg($i),$i);
      if ($e2<$receiptsPages) echo '<span class="pi">…</span>';
      ?>
      <a class="pa <?= $page>=$receiptsPages?'off':'' ?>" href="<?= $pg($page+1) ?>">›</a>
      <a class="pa <?= $page>=$receiptsPages?'off':'' ?>" href="<?= $pg($receiptsPages) ?>">»</a>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>

  <?php else: ?>
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px;">
    <span style="color:#555;font-size:0.85rem"><?= number_format($uTotal) ?> пользователей<?= $search ? ' · «'.htmlspecialchars($search).'»' : '' ?></span>
    <a href="?export=csv&view=users<?= $search?"&search=".urlencode($search):'' ?>"
       style="display:inline-flex;align-items:center;gap:6px;padding:7px 14px;background:rgba(40,167,69,0.12);border:1px solid rgba(40,167,69,0.3);color:#5cb85c;border-radius:5px;font-size:0.82rem;text-decoration:none;">
      ⬇ Скачать CSV
    </a>
  </div>

  <?php if (empty($allUsers)): ?>
  <div class="empty-s"><div class="ico">👤</div><p>Пользователь табылмады</p></div>
  <?php else: ?>

  <?php foreach ($allUsers as $u):
    $uid      = $u['userid'];
    $atts     = $userAttempts[$uid] ?? [];
    $wonCount = count(array_filter($atts, fn($a) => $a['used']));
    $sentCount= count(array_filter($atts, fn($a) => $a['gift_sent']));
  ?>
  <div class="ucard">
    <div class="ucard-hdr">
      <span class="uid-bdg">ID: <?= $uid ?></span>
      <span class="uname-lg">
        <?= ($u['name'] || $u['surname'])
            ? hl(trim(($u['name']??'').' '.($u['surname']??'')), $search)
            : '<span style="color:#555">—</span>' ?>
      </span>
      <?php if ($u['phone']): ?>
      <span class="uphone-hd">📞 <?= hl($u['phone'], $search) ?></span>
      <?php endif; ?>
      <div class="hbadges">
        <span class="hbg hbg-g">🎟 <?= count($atts) ?> билет</span>
        <?php if ($wonCount): ?><span class="hbg hbg-gr">✅ <?= $wonCount ?> ойнады</span><?php endif; ?>
        <?php if ($sentCount): ?><span class="hbg hbg-p">🎁 <?= $sentCount ?> отправлено</span><?php endif; ?>
      </div>
      <button
        class="del-user-btn"
        onclick="deleteUser(<?= $uid ?>, this)"
        title="Пайдаланушыны және барлық чектерін жою (қайта тіркелу үшін)">
        🗑 Жою
      </button>
    </div>

    <div class="uinfo-grid">
      <div class="uinfo-item"><div class="ui-lbl">Telegram ID</div><div class="ui-val gold"><?= $uid ?></div></div>
      <div class="uinfo-item"><div class="ui-lbl">Имя</div><div class="ui-val"><?= hl($u['name'] ?: '—', $search) ?></div></div>
      <div class="uinfo-item"><div class="ui-lbl">Фамилия</div><div class="ui-val"><?= hl($u['surname'] ?: '—', $search) ?></div></div>
      <div class="uinfo-item"><div class="ui-lbl">Телефон</div><div class="ui-val"><?= $u['phone'] ? hl($u['phone'], $search) : '—' ?></div></div>
      <div class="uinfo-item"><div class="ui-lbl">Адрес</div><div class="ui-val"><?= htmlspecialchars($u['address'] ?: '—') ?></div></div>
      <div class="uinfo-item"><div class="ui-lbl">Индекс</div><div class="ui-val"><?= htmlspecialchars($u['postcode'] ?: '—') ?></div></div>
      <div class="uinfo-item"><div class="ui-lbl">Город</div><div class="ui-val"><?= htmlspecialchars($u['city'] ?: '—') ?></div></div>
      <div class="uinfo-item"><div class="ui-lbl">Зарегистр.</div><div class="ui-val"><?= $u['created_at'] ? date('d.m.Y H:i', strtotime($u['created_at'])) : '—' ?></div></div>
      <div class="uinfo-item"><div class="ui-lbl">Всего билетов</div><div class="ui-val gold"><?= count($atts) ?></div></div>
      <div class="uinfo-item"><div class="ui-lbl">Сыграл</div><div class="ui-val green"><?= $wonCount ?></div></div>
      <div class="uinfo-item"><div class="ui-lbl">Подарок отправлен</div><div class="ui-val" style="color:<?= $sentCount?'#5cb85c':'#555' ?>"><?= $sentCount ?></div></div>
      <?php if ($u['first_at']): ?>
      <div class="uinfo-item"><div class="ui-lbl">Первый билет</div><div class="ui-val"><?= date('d.m.Y H:i', strtotime($u['first_at'])) ?></div></div>
      <div class="uinfo-item"><div class="ui-lbl">Последний билет</div><div class="ui-val"><?= date('d.m.Y H:i', strtotime($u['last_at'])) ?></div></div>
      <?php endif; ?>
      <?php if ($u['sessions_used']): ?>
      <div class="uinfo-item">
        <div class="ui-lbl">Сессиялар</div>
        <div class="ui-val" style="display:flex;gap:4px;flex-wrap:wrap;">
          <?php foreach (explode(',', $u['sessions_used']) as $s): ?><span class="s-badge"><?= trim($s) ?>-с</span><?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <?php if (empty($atts)): ?>
    <div class="no-tkt">Билетов нет</div>
    <?php else: ?>
    <div class="tkt-title">🎟 БИЛЕТТЕР</div>
    <table class="tkt-tbl">
      <thead>
        <tr>
          <th>Билет №</th><th>Сессия</th><th>Приз</th><th>Игра</th>
          <th>🎁 Подарок</th>
          <th>Транзакция</th><th>Выдан</th><th>Сыграл</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($atts as $att): ?>
        <tr id="row-<?= $att['attempt_id'] ?>">
          <td style="font-family:monospace;color:#d4a017;font-weight:700">#<?= $att['numeric_token'] ?></td>
          <td><span class="s-badge"><?= $att['session_num'] ?>-с</span></td>
          <td>
            <div class="pc">
              <?php if ($att['prize_img']): ?><img src="<?= htmlspecialchars($att['prize_img']) ?>" alt="" style="width:20px;height:20px;"><?php else: ?><span style="font-size:1rem"><?= htmlspecialchars($att['prize_emoji'] ?? '🎁') ?></span><?php endif; ?>
              <span style="color:<?= $att['used']?'#5cb85c':'#ccc' ?>;font-size:0.8rem"><?= htmlspecialchars($att['prize_name'] ?? '—') ?></span>
            </div>
          </td>
          <td><?= $att['used'] ? '<span class="badge b-used">✅ Сыграл</span>' : '<span class="badge b-unused">⏳ Ожидает</span>' ?></td>
          <!-- GIFT BUTTON in user card -->
          <td>
            <div style="display:flex;flex-direction:column;align-items:flex-start;gap:2px;">
              <button
                class="gift-btn <?= $att['gift_sent'] ? 'sent' : 'not-sent' ?>"
                id="gift-btn-<?= $att['attempt_id'] ?>"
                onclick="toggleGift(<?= $att['attempt_id'] ?>, this)"
                title="<?= $att['gift_sent'] ? 'Отправлен — нажмите, чтобы отменить' : 'Не отправлен — нажмите, чтобы отметить' ?>">
                <?php if ($att['gift_sent']): ?>
                  <span>✅</span> Отправлен
                <?php else: ?>
                  <span>📦</span> Не отправлен
                <?php endif; ?>
              </button>
              <?php if ($att['gift_sent'] && $att['gift_sent_at']): ?>
              <span class="gift-date" id="gift-date-<?= $att['attempt_id'] ?>"><?= date('d.m.y H:i', strtotime($att['gift_sent_at'])) ?></span>
              <?php else: ?>
              <span class="gift-date" id="gift-date-<?= $att['attempt_id'] ?>" style="display:none"></span>
              <?php endif; ?>
            </div>
          </td>
          <td><span class="tx-t" title="<?= htmlspecialchars($att['transaction_number']) ?>"><?= htmlspecialchars($att['transaction_number']) ?></span></td>
          <td class="date-t"><?= date('d.m.Y H:i', strtotime($att['created_at'])) ?></td>
          <td class="date-t"><?= $att['used_at'] ? date('d.m.Y H:i', strtotime($att['used_at'])) : '<span style="color:#2a2a2a">—</span>' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>

  <?php if ($uTotalPages > 1):
    $pg = fn($p) => buildUrl(['page'=>$p]);
  ?>
  <div class="pager">
    <a class="pa <?= $page<=1?'off':'' ?>" href="<?= $pg(1) ?>">«</a>
    <a class="pa <?= $page<=1?'off':'' ?>" href="<?= $pg($page-1) ?>">‹</a>
    <?php
    $s2=max(1,$page-3); $e2=min($uTotalPages,$page+3);
    if ($s2>1) echo '<span class="pi">…</span>';
    for ($i=$s2;$i<=$e2;$i++) printf('<a class="pa%s" href="%s">%d</a>',$i===$page?' cur':'',$pg($i),$i);
    if ($e2<$uTotalPages) echo '<span class="pi">…</span>';
    ?>
    <a class="pa <?= $page>=$uTotalPages?'off':'' ?>" href="<?= $pg($page+1) ?>">›</a>
    <a class="pa <?= $page>=$uTotalPages?'off':'' ?>" href="<?= $pg($uTotalPages) ?>">»</a>
    <span class="pi"><?= $page ?>/<?= $uTotalPages ?> стр. · <?= number_format($uTotal) ?> пользователей</span>
  </div>
  <?php endif; ?>
  <?php endif; ?>
  <?php endif; ?>

</div>

<script>
// ─── GIFT TOGGLE ──────────────────────────────────────────────────────────────
async function toggleGift(id, btn) {
  btn.classList.add('loading');
  try {
    const fd = new FormData();
    fd.append('ajax_toggle_gift', '1');
    fd.append('attempt_id', id);
    const r = await fetch(location.href, { method:'POST', body:fd });
    const d = await r.json();
    if (d.success) {
      const isSent = d.gift_sent === 1;
      btn.className = 'gift-btn ' + (isSent ? 'sent' : 'not-sent');
      btn.innerHTML = isSent
        ? '<span>✅</span> Отправлен'
        : '<span>📦</span> Не отправлен';
      btn.title = isSent
        ? 'Отправлен — нажмите, чтобы отменить'
        : 'Не отправлен — нажмите, чтобы отметить';

      // Update date label
      const dateEl = document.getElementById('gift-date-' + id);
      if (dateEl) {
        if (isSent) {
          const now = new Date();
          const pad = n => String(n).padStart(2,'0');
          dateEl.textContent = pad(now.getDate())+'.'+pad(now.getMonth()+1)+'.'+String(now.getFullYear()).slice(2)+' '+pad(now.getHours())+':'+pad(now.getMinutes());
          dateEl.style.display = 'block';
        } else {
          dateEl.textContent = '';
          dateEl.style.display = 'none';
        }
      }

      showToast(isSent ? '🎁 Подарок отмечен как отправлен!' : '📦 Отметка снята', !isSent);
    }
  } catch(e) {
    showToast('Ошибка орын алды!', true);
  }
  btn.classList.remove('loading');
}

// ─── TOAST ────────────────────────────────────────────────────────────────────
let toastTimer;
function showToast(msg, isRed=false) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = 'toast' + (isRed?' red':'') + ' show';
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { t.classList.remove('show'); }, 2800);
}
// ─── DELETE RECEIPT ──────────────────────────────────────────────────────────
async function deleteReceipt(fp, btn) {
  if (!confirm(`Удалить чек?\n\nFP: ${fp}\n\nПосле удаления этот чек можно будет загрузить заново.`)) return;
  btn.disabled = true;
  btn.textContent = '⏳';
  try {
    const fd = new FormData();
    fd.append('ajax_delete_receipt', '1');
    fd.append('fp', fp);
    const r = await fetch(location.href, { method:'POST', body:fd });
    const d = await r.json();
    if (d.success) {
      const row = document.getElementById('rcpt-' + fp);
      if (row) {
        row.style.transition = 'opacity 0.4s';
        row.style.opacity = '0';
        setTimeout(() => row.remove(), 420);
      }
      showToast('✅ Чек удалён — можно загружать заново');
    } else {
      showToast('❌ Ошибка: ' + (d.error || 'unknown'), true);
      btn.disabled = false;
      btn.textContent = '🗑';
    }
  } catch(e) {
    showToast('❌ Сетевая ошибка', true);
    btn.disabled = false;
    btn.textContent = '🗑';
  }
}

// ─── DELETE USER ─────────────────────────────────────────────────────────────
async function deleteUser(uid, btn) {
  if (!confirm(`Пайдаланушы ID ${uid} жойылсын ба?\n\nБарлық билеттер мен тіркелу деректері өшіріледі.\nЧектер (FP) сақталады — бір чек екі рет қолданылмайды.\n\nЖалғастырасыз ба?`)) return;
  btn.disabled = true;
  btn.textContent = '⏳';
  try {
    const fd = new FormData();
    fd.append('ajax_delete_user', '1');
    fd.append('userid', uid);
    const r = await fetch(location.href, { method:'POST', body:fd });
    const d = await r.json();
    if (d.success) {
      const card = btn.closest('.ucard');
      if (card) {
        card.style.transition = 'opacity 0.4s';
        card.style.opacity = '0';
        setTimeout(() => card.remove(), 420);
      }
      showToast('✅ Пайдаланушы жойылды — қайта тіркелуге болады!');
    } else {
      showToast('❌ Қате: ' + (d.error || 'unknown'), true);
      btn.disabled = false;
      btn.textContent = '🗑 Жою';
    }
  } catch(e) {
    showToast('❌ Желі қатесі', true);
    btn.disabled = false;
    btn.textContent = '🗑 Жою';
  }
}
</script>
