<?php
// kese_stats.php — Payment & Prize Statistics Dashboard
@ini_set('memory_limit', '512M');
require_once __DIR__ . '/../../src/auth.php';

$pdo = site_db();

// ─── ENSURE columns EXIST ────────────────────────────────────────────────────
try { $pdo->exec("ALTER TABLE kese_attempts ADD COLUMN payment_amount DECIMAL(10,2) NULL DEFAULT NULL AFTER session_num"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE kese_attempts ADD COLUMN payment_currency VARCHAR(10) NULL DEFAULT 'KZT' AFTER payment_amount"); } catch (PDOException $e) {}

// ─── FILTERS (attempts table) ────────────────────────────────────────────────
$filterSession  = $_GET['session']    ?? '';
$filterUsed     = $_GET['used']       ?? '';
$filterDateFrom = $_GET['date_from']  ?? '';
$filterDateTo   = $_GET['date_to']    ?? '';
$filterSearch   = trim($_GET['search'] ?? '');
$page           = max(1, (int)($_GET['page'] ?? 1));
$perPage        = 50;
$offset         = ($page - 1) * $perPage;

// ─── USERS TAB FILTERS ───────────────────────────────────────────────────────
$uPage      = max(1, (int)($_GET['upage'] ?? 1));
$uSearch    = trim($_GET['usearch'] ?? '');
$uPerPage   = 50;
$uOffset    = ($uPage - 1) * $uPerPage;

// ─── ACTIVE TAB ──────────────────────────────────────────────────────────────
$activeTab = $_GET['tab'] ?? 'stats';

// ─── WHERE BUILDER (attempts) ─────────────────────────────────────────────────
$where  = ['1=1'];
$params = [];
if ($filterSession !== '') { $where[] = 'a.session_num = ?'; $params[] = (int)$filterSession; }
if ($filterUsed === '1')   { $where[] = 'a.used = 1'; }
if ($filterUsed === '0')   { $where[] = 'a.used = 0'; }
if ($filterDateFrom)       { $where[] = 'DATE(a.created_at) >= ?'; $params[] = $filterDateFrom; }
if ($filterDateTo)         { $where[] = 'DATE(a.created_at) <= ?'; $params[] = $filterDateTo; }
if ($filterSearch)         { $where[] = '(a.userid LIKE ? OR a.transaction_number LIKE ? OR p.name LIKE ?)';
                             $s = "%$filterSearch%"; $params[] = $s; $params[] = $s; $params[] = $s; }
$whereSQL = implode(' AND ', $where);

// ─── WHERE BUILDER (users) ────────────────────────────────────────────────────
$uWhere  = ['1=1'];
$uParams = [];
if ($uSearch) {
    $uWhere[] = '(u.userid LIKE ? OR u.name LIKE ? OR u.surname LIKE ? OR u.phone LIKE ? OR u.address LIKE ? OR u.postcode LIKE ?)';
    $us = "%$uSearch%";
    $uParams = array_fill(0, 6, $us);
}
$uWhereSQL = implode(' AND ', $uWhere);

// ─── GLOBAL STATS ────────────────────────────────────────────────────────────
$totalAttempts  = (int)$pdo->query("SELECT COUNT(*) FROM kese_attempts")->fetchColumn();
$usedAttempts   = (int)$pdo->query("SELECT COUNT(*) FROM kese_attempts WHERE used=1")->fetchColumn();
$uniqueUsers    = (int)$pdo->query("SELECT COUNT(DISTINCT userid) FROM kese_attempts")->fetchColumn();
$registeredUsers= (int)$pdo->query("SELECT COUNT(*) FROM kese_users")->fetchColumn();

// ─── PER-SESSION STATS ───────────────────────────────────────────────────────
$sessionStats = $pdo->query("
    SELECT session_num, COUNT(*) AS total, SUM(used) AS used_count, COUNT(DISTINCT userid) AS unique_users
    FROM kese_attempts GROUP BY session_num ORDER BY session_num
")->fetchAll(PDO::FETCH_ASSOC);

// ─── PRIZE DISTRIBUTION ──────────────────────────────────────────────────────
$prizeDist = $pdo->query("
    SELECT p.name, p.emoji, p.img_data, p.session_num,
           COUNT(a.id) AS total_given, SUM(a.used) AS used_count
    FROM kese_attempts a JOIN kese_prizes p ON a.prize_id = p.id
    GROUP BY a.prize_id ORDER BY total_given DESC LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

// ─── TOP USERS ───────────────────────────────────────────────────────────────
$topUsers = $pdo->query("
    SELECT a.userid, COUNT(a.id) AS attempts, SUM(a.used) AS used_count,
           MIN(a.created_at) AS first_at, MAX(a.created_at) AS last_at,
           u.name, u.surname, u.phone
    FROM kese_attempts a LEFT JOIN kese_users u ON u.userid = a.userid
    GROUP BY a.userid ORDER BY attempts DESC LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

// ─── DAILY / HOURLY ──────────────────────────────────────────────────────────
$dailyStats = $pdo->query("
    SELECT DATE(created_at) AS day, COUNT(*) AS attempts, SUM(used) AS used_count, COUNT(DISTINCT userid) AS unique_users
    FROM kese_attempts WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DATE(created_at) ORDER BY day ASC
")->fetchAll(PDO::FETCH_ASSOC);

$hourlyStats = $pdo->query("
    SELECT HOUR(created_at) AS hr, COUNT(*) AS cnt FROM kese_attempts
    GROUP BY HOUR(created_at) ORDER BY hr ASC
")->fetchAll(PDO::FETCH_ASSOC);
$hourlyMap = [];
foreach ($hourlyStats as $h) $hourlyMap[(int)$h['hr']] = (int)$h['cnt'];
$maxHourly = max(array_values($hourlyMap) ?: [1]);

// ─── PRIZES MAP (грузим BLOB один раз, чтобы не тянуть в каждом JOIN) ─────────
$prizesMap = [];
foreach ($pdo->query("SELECT id, name, emoji, img_data, session_num FROM kese_prizes")->fetchAll(PDO::FETCH_ASSOC) as $pr) {
    $prizesMap[(int)$pr['id']] = $pr;
}

// ─── FILTERED ATTEMPTS TABLE ─────────────────────────────────────────────────
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM kese_attempts a LEFT JOIN kese_prizes p ON a.prize_id = p.id WHERE $whereSQL");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($totalRows / $perPage));

$dataStmt = $pdo->prepare("
    SELECT a.id, a.userid, a.token, a.numeric_token, a.transaction_number,
           a.session_num, a.used, a.created_at, a.used_at, a.prize_id,
           p.name AS prize_name, p.emoji AS prize_emoji,
           u.name AS user_name, u.surname AS user_surname, u.phone AS user_phone
    FROM kese_attempts a
    LEFT JOIN kese_prizes p ON a.prize_id = p.id
    LEFT JOIN kese_users u ON u.userid = a.userid
    WHERE $whereSQL ORDER BY a.id DESC LIMIT $perPage OFFSET $offset
");
$dataStmt->execute($params);
$rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as &$r) {
    $pr = $prizesMap[(int)$r['prize_id']] ?? null;
    $r['prize_img'] = $pr['img_data'] ?? null;
}
unset($r);

// ─── ALL USERS WITH FULL INFO ─────────────────────────────────────────────────
$uCountStmt = $pdo->prepare("SELECT COUNT(DISTINCT u.id) FROM kese_users u WHERE $uWhereSQL");
$uCountStmt->execute($uParams);
$uTotalRows  = (int)$uCountStmt->fetchColumn();
$uTotalPages = max(1, ceil($uTotalRows / $uPerPage));

// Each user + all their attempts + prizes
$uMainStmt = $pdo->prepare("
    SELECT u.*,
           COUNT(a.id) AS total_tickets,
           SUM(a.used) AS used_tickets,
           MIN(a.created_at) AS first_ticket_at,
           MAX(a.created_at) AS last_ticket_at
    FROM kese_users u
    LEFT JOIN kese_attempts a ON a.userid = u.userid
    WHERE $uWhereSQL
    GROUP BY u.id
    ORDER BY u.id DESC
    LIMIT $uPerPage OFFSET $uOffset
");
$uMainStmt->execute($uParams);
$allUsers = $uMainStmt->fetchAll(PDO::FETCH_ASSOC);

// Load all attempts for these users to show prizes
$userIds = array_column($allUsers, 'userid');
$userAttempts = [];
if ($userIds) {
    $inList = implode(',', array_map('intval', $userIds));
    $attStmt = $pdo->query("
        SELECT a.userid, a.id AS attempt_id, a.numeric_token, a.session_num,
               a.used, a.created_at, a.used_at, a.transaction_number, a.prize_id,
               p.name AS prize_name, p.emoji AS prize_emoji
        FROM kese_attempts a
        LEFT JOIN kese_prizes p ON a.prize_id = p.id
        WHERE a.userid IN ($inList)
        ORDER BY a.id ASC
    ");
    foreach ($attStmt->fetchAll(PDO::FETCH_ASSOC) as $att) {
        $pr = $prizesMap[(int)$att['prize_id']] ?? null;
        $att['prize_img'] = $pr['img_data'] ?? null;
        $userAttempts[$att['userid']][] = $att;
    }
}

// ─── CSV EXPORT (users) ───────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'users_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="kese_users_full_'.date('Y-m-d').'.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['User ID','Имя','Фамилия','Телефон','Адрес','Индекс','Город','Зарегистр.','Билет №','Сессия','Приз','Статус','Транзакция','Выдан','Время использ.'], ';');
    $expStmt = $pdo->query("
        SELECT u.userid, u.name, u.surname, u.phone, u.address, u.postcode, u.city, u.created_at AS reg_at,
               a.numeric_token, a.session_num, p.name AS prize_name, a.used,
               a.transaction_number, a.created_at AS ticket_at, a.used_at
        FROM kese_users u
        LEFT JOIN kese_attempts a ON a.userid = u.userid
        LEFT JOIN kese_prizes p ON a.prize_id = p.id
        ORDER BY u.id DESC, a.id ASC
    ");
    while ($r = $expStmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [
            $r['userid'], $r['name'], $r['surname'], $r['phone'], $r['address'], $r['postcode'], $r['city'], $r['reg_at'],
            $r['numeric_token'] ?? '', $r['session_num'] ? $r['session_num'].'-я сессия' : '',
            $r['prize_name'] ?? '', $r['used'] ? 'Использован' : 'Ожидает',
            $r['transaction_number'] ?? '', $r['ticket_at'] ?? '', $r['used_at'] ?? ''
        ], ';');
    }
    fclose($out); exit;
}

// ─── CSV EXPORT (attempts) ────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="kese_stats_'.date('Y-m-d').'.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['ID','User ID','Имя','Фамилия','Телефон','Сессия','Приз','Статус','Транзакция','Создан','Время использ.'], ';');
    $expSQL = "SELECT a.id, a.userid, u.name, u.surname, u.phone, a.session_num, p.name AS prize_name, a.used, a.transaction_number, a.created_at, a.used_at FROM kese_attempts a LEFT JOIN kese_prizes p ON a.prize_id = p.id LEFT JOIN kese_users u ON u.userid = a.userid WHERE $whereSQL ORDER BY a.id DESC";
    $expStmt = $pdo->prepare($expSQL); $expStmt->execute($params);
    while ($r = $expStmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [$r['id'], $r['userid'], $r['name'], $r['surname'], $r['phone'], $r['session_num'].'-я сессия', $r['prize_name'], $r['used'] ? 'Использован' : 'Ожидает', $r['transaction_number'], $r['created_at'], $r['used_at'] ?: '—'], ';');
    }
    fclose($out); exit;
}

function qp($extra = []) {
    $p = array_merge($_GET, $extra);
    return '?' . http_build_query($p);
}
?>
<!DOCTYPE html>
<html lang="kk">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>СИҚЫРЛЫ БОКС — Статистика</title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Segoe UI',sans-serif; background:#0f0f0f; color:#e0e0e0; min-height:100vh; }

.topbar {
  background:linear-gradient(135deg,#1a0e03,#2a1a05);
  border-bottom:2px solid #d4a017;
  padding:14px 28px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;
}
.topbar h1 { font-size:1.2rem; letter-spacing:3px; color:#d4a017; text-transform:uppercase; }
.topbar .subtitle { font-size:0.75rem; color:rgba(212,160,23,0.6); margin-top:2px; }
.topbar .links a { color:rgba(212,160,23,0.7); text-decoration:none; font-size:0.82rem; margin-left:16px; border:1px solid rgba(212,160,23,0.3); padding:5px 14px; border-radius:20px; transition:all 0.2s; }
.topbar .links a:hover { color:#d4a017; border-color:#d4a017; }

/* TABS */
.tabs-bar {
  background:#141414; border-bottom:1px solid #2a2a2a;
  padding:0 28px; display:flex; gap:0;
}
.tab-btn {
  padding:14px 24px; font-size:0.85rem; letter-spacing:1.5px; text-transform:uppercase;
  color:#555; border:none; background:transparent; cursor:pointer; border-bottom:2px solid transparent;
  transition:all 0.2s; text-decoration:none; display:inline-block;
}
.tab-btn:hover { color:#d4a017; }
.tab-btn.active { color:#d4a017; border-bottom-color:#d4a017; }

.container { max-width:1500px; margin:0 auto; padding:28px 20px; }
.tab-content { display:none; }
.tab-content.active { display:block; }

.stats-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:16px; margin-bottom:28px; }
.stat-card { background:linear-gradient(135deg,#1a1a1a,#222); border:1px solid rgba(212,160,23,0.2); border-radius:10px; padding:20px 16px; text-align:center; position:relative; overflow:hidden; }
.stat-card::before { content:''; position:absolute; top:0; left:0; right:0; height:2px; background:linear-gradient(90deg,transparent,#d4a017,transparent); }
.stat-card .num { font-size:2rem; font-weight:700; color:#d4a017; line-height:1; }
.stat-card .lbl { font-size:0.72rem; color:#666; margin-top:6px; letter-spacing:1px; text-transform:uppercase; }
.stat-card.green .num { color:#5cb85c; }
.stat-card.green::before { background:linear-gradient(90deg,transparent,#5cb85c,transparent); }
.stat-card.orange .num { color:#ffa500; }
.stat-card.orange::before { background:linear-gradient(90deg,transparent,#ffa500,transparent); }
.stat-card.blue .num { color:#5b9bd5; }
.stat-card.blue::before { background:linear-gradient(90deg,transparent,#5b9bd5,transparent); }
.stat-card.purple .num { color:#b06dff; }
.stat-card.purple::before { background:linear-gradient(90deg,transparent,#b06dff,transparent); }

.section { background:#181818; border:1px solid #2a2a2a; border-radius:10px; padding:22px 20px; margin-bottom:22px; }
.section-title { font-size:0.82rem; letter-spacing:2.5px; color:#d4a017; text-transform:uppercase; margin-bottom:18px; display:flex; align-items:center; gap:8px; }
.section-title::after { content:''; flex:1; height:1px; background:linear-gradient(90deg,rgba(212,160,23,0.3),transparent); }
.two-col { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
@media(max-width:900px) { .two-col { grid-template-columns:1fr; } }

.session-row { display:grid; grid-template-columns:repeat(3,1fr); gap:14px; }
@media(max-width:700px) { .session-row { grid-template-columns:1fr; } }
.session-card { background:#1e1e1e; border:1px solid #2d2d2d; border-radius:8px; padding:16px; }
.session-card h3 { color:#d4a017; font-size:0.88rem; letter-spacing:2px; margin-bottom:12px; }
.session-card .row { display:flex; justify-content:space-between; padding:5px 0; border-bottom:1px solid #252525; font-size:0.82rem; }
.session-card .row:last-child { border-bottom:none; }
.session-card .row span:first-child { color:#666; }
.session-card .row span:last-child  { color:#ccc; font-weight:500; }

.bar-chart { display:flex; align-items:flex-end; gap:3px; height:80px; }
.bar-col { display:flex; flex-direction:column; align-items:center; gap:3px; flex:1; }
.bar-fill { width:100%; border-radius:2px 2px 0 0; background:linear-gradient(180deg,#d4a017,#8a6510); min-height:2px; }
.bar-label { font-size:0.52rem; color:#555; }
.daily-chart { display:flex; align-items:flex-end; gap:2px; height:100px; overflow-x:auto; padding-bottom:4px; }
.day-col { display:flex; flex-direction:column; align-items:center; gap:2px; flex-shrink:0; width:28px; }
.day-fill { width:100%; border-radius:2px 2px 0 0; background:linear-gradient(180deg,#d4a017,#8a6510); min-height:2px; }
.day-label { font-size:0.48rem; color:#444; text-align:center; }

.prize-dist-list { display:flex; flex-direction:column; gap:8px; }
.prize-dist-item { display:flex; align-items:center; gap:10px; }
.prize-dist-item .pname { flex:0 0 160px; font-size:0.8rem; color:#ccc; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.prize-dist-item .bar-wrap { flex:1; background:#222; border-radius:20px; height:16px; overflow:hidden; }
.prize-dist-item .bar-inner { height:100%; background:linear-gradient(90deg,#d4a017,#f0c040); border-radius:20px; min-width:4px; }
.prize-dist-item .pcount { flex:0 0 40px; text-align:right; font-size:0.8rem; color:#d4a017; font-weight:600; }
.prize-dist-item .pimg { width:28px; height:28px; border-radius:50%; object-fit:cover; flex-shrink:0; border:1px solid #333; }
.prize-dist-item .pem { font-size:1.3rem; flex-shrink:0; }

.top-users-table { width:100%; border-collapse:collapse; font-size:0.82rem; }
.top-users-table th { background:#1a1a1a; color:#666; padding:8px 12px; text-align:left; font-weight:500; border-bottom:1px solid #2a2a2a; font-size:0.72rem; letter-spacing:1px; text-transform:uppercase; }
.top-users-table td { padding:9px 12px; border-bottom:1px solid #1e1e1e; vertical-align:middle; }
.top-users-table tr:hover td { background:rgba(212,160,23,0.03); }
.rank-badge { display:inline-flex; width:24px; height:24px; border-radius:50%; background:rgba(212,160,23,0.15); color:#d4a017; align-items:center; justify-content:center; font-size:0.7rem; font-weight:700; }
.rank-badge.gold   { background:rgba(255,215,0,0.2); color:#ffd700; }
.rank-badge.silver { background:rgba(192,192,192,0.2); color:#c0c0c0; }
.rank-badge.bronze { background:rgba(205,127,50,0.2); color:#cd7f32; }

.filter-bar { background:#181818; border:1px solid #2a2a2a; border-radius:10px; padding:16px 20px; margin-bottom:20px; display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end; }
.filter-bar label { display:block; font-size:0.7rem; color:#666; margin-bottom:4px; letter-spacing:1px; text-transform:uppercase; }
.filter-bar input, .filter-bar select { padding:7px 10px; background:#252525; border:1px solid #3a3a3a; border-radius:5px; color:#e0e0e0; font-size:0.85rem; outline:none; transition:border-color 0.2s; }
.filter-bar input:focus, .filter-bar select:focus { border-color:#d4a017; }
.filter-bar .btn { padding:7px 18px; border:none; border-radius:5px; font-size:0.85rem; cursor:pointer; font-weight:500; }
.btn-gold { background:linear-gradient(135deg,#d4a017,#f0c040); color:#0a0604; }
.btn-gold:hover { filter:brightness(1.1); }
.btn-secondary { background:rgba(212,160,23,0.1); border:1px solid rgba(212,160,23,0.25); color:#d4a017; cursor:pointer; padding:7px 14px; border-radius:5px; font-size:0.85rem; text-decoration:none; display:inline-block; }
.btn-secondary:hover { background:rgba(212,160,23,0.2); }

.data-table-wrap { overflow-x:auto; }
.data-table { width:100%; border-collapse:collapse; font-size:0.82rem; min-width:900px; }
.data-table th { background:#1a1a1a; color:#666; padding:9px 12px; text-align:left; font-weight:500; border-bottom:1px solid #2a2a2a; font-size:0.72rem; letter-spacing:1px; text-transform:uppercase; position:sticky; top:0; }
.data-table td { padding:9px 12px; border-bottom:1px solid #1e1e1e; vertical-align:middle; }
.data-table tr:hover td { background:rgba(212,160,23,0.03); }
.badge { padding:3px 8px; border-radius:10px; font-size:0.72rem; }
.badge-used   { background:rgba(40,167,69,0.2);  color:#5cb85c; }
.badge-unused { background:rgba(100,100,100,0.2); color:#666; }
.prize-cell { display:flex; align-items:center; gap:6px; }
.prize-cell img { width:24px; height:24px; border-radius:50%; object-fit:cover; border:1px solid #333; }
.tx-code { font-family:monospace; font-size:0.75rem; color:#666; max-width:140px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

/* ── USER CARDS ── */
.user-card {
  background:#1a1a1a; border:1px solid #272727; border-radius:10px;
  margin-bottom:16px; overflow:hidden;
}
.user-card-header {
  background:linear-gradient(135deg,#1e1a10,#221e12);
  border-bottom:1px solid #2d2a1e;
  padding:14px 20px; display:flex; flex-wrap:wrap; gap:16px; align-items:center;
}
.user-card-header .uid-badge {
  background:rgba(212,160,23,0.15); border:1px solid rgba(212,160,23,0.3);
  color:#d4a017; padding:4px 12px; border-radius:20px; font-size:0.8rem; font-weight:700;
  font-family:monospace;
}
.user-card-header .uname-big {
  font-size:1rem; font-weight:600; color:#e8e8e8;
}
.user-card-header .uphone {
  color:#888; font-size:0.85rem;
}
.user-info-grid {
  display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:0;
  padding:0; border-bottom:1px solid #232323;
}
.user-info-item {
  padding:10px 16px; border-right:1px solid #232323;
}
.user-info-item:last-child { border-right:none; }
.user-info-item .lbl { font-size:0.68rem; color:#555; letter-spacing:1px; text-transform:uppercase; margin-bottom:3px; }
.user-info-item .val { font-size:0.85rem; color:#ccc; word-break:break-word; }
.user-info-item .val.gold { color:#d4a017; font-weight:600; }
.user-info-item .val.green { color:#5cb85c; }

/* tickets table inside user card */
.tickets-wrap { padding:0 0 4px; }
.tickets-title { padding:10px 16px 6px; font-size:0.7rem; color:#555; letter-spacing:2px; text-transform:uppercase; }
.tickets-table { width:100%; border-collapse:collapse; font-size:0.78rem; }
.tickets-table th { background:#151515; color:#444; padding:7px 12px; text-align:left; font-size:0.68rem; letter-spacing:1px; text-transform:uppercase; border-bottom:1px solid #1e1e1e; }
.tickets-table td { padding:8px 12px; border-bottom:1px solid #1c1c1c; vertical-align:middle; }
.tickets-table tr:last-child td { border-bottom:none; }
.tickets-table tr:hover td { background:rgba(212,160,23,0.02); }
.no-tickets { padding:14px 16px; color:#444; font-size:0.82rem; font-style:italic; }

.pagination { display:flex; gap:6px; justify-content:center; flex-wrap:wrap; margin-top:20px; }
.pagination a, .pagination span { padding:6px 12px; border-radius:5px; font-size:0.82rem; text-decoration:none; background:#1e1e1e; border:1px solid #2d2d2d; color:#888; }
.pagination a:hover { border-color:#d4a017; color:#d4a017; }
.pagination .current { background:rgba(212,160,23,0.15); border-color:#d4a017; color:#d4a017; font-weight:700; }
.pagination .disabled { opacity:0.3; pointer-events:none; }

.export-btn { display:inline-flex; align-items:center; gap:6px; padding:7px 16px; background:rgba(40,167,69,0.15); border:1px solid rgba(40,167,69,0.35); color:#5cb85c; border-radius:5px; font-size:0.82rem; text-decoration:none; transition:all 0.2s; cursor:pointer; }
.export-btn:hover { background:rgba(40,167,69,0.25); }
.empty-state { text-align:center; padding:40px; color:#444; }
.empty-state .icon { font-size:3rem; margin-bottom:12px; }

::-webkit-scrollbar { width:6px; height:6px; }
::-webkit-scrollbar-track { background:#111; }
::-webkit-scrollbar-thumb { background:#333; border-radius:3px; }
::-webkit-scrollbar-thumb:hover { background:#555; }
</style>
</head>
<body>

<div class="topbar">
  <div>
    <h1>📊 СИҚЫРЛЫ БОКС — Статистика</h1>
    <div class="subtitle">Билеты, пользователи, призы</div>
  </div>
  <div class="links">
    <a href="index.php">⚙️ Админка</a>
    <a href="users.php">👥 Пользователи</a>
    <a href="kese_stats.php?export=csv<?= $filterSession ? '&session='.$filterSession : '' ?><?= $filterUsed !== '' ? '&used='.$filterUsed : '' ?><?= $filterDateFrom ? '&date_from='.$filterDateFrom : '' ?><?= $filterDateTo ? '&date_to='.$filterDateTo : '' ?>" class="export-btn">⬇ CSV Билеты</a>
    <a href="kese_stats.php?export=users_csv" class="export-btn" style="background:rgba(91,155,213,0.15);border-color:rgba(91,155,213,0.35);color:#5b9bd5;">⬇ CSV Польз.</a>
    <a href="?logout=1" class="export-btn" style="background:rgba(220,50,50,0.12);border-color:rgba(220,80,80,0.35);color:#ff8080;" onclick="return confirm('Выйти?')">🔒 Выход</a>
  </div>
</div>

<!-- TABS -->
<div class="tabs-bar">
  <a href="?tab=stats" class="tab-btn <?= $activeTab==='stats'?'active':'' ?>">📊 Статистика</a>
  <a href="?tab=attempts" class="tab-btn <?= $activeTab==='attempts'?'active':'' ?>">🎟 Билеттер</a>
 
</div>

<div class="container">

<!-- ═══════════════ TAB: STATS ═══════════════ -->
<div class="tab-content <?= $activeTab==='stats'?'active':'' ?>" id="tab-stats">

  <div class="stats-grid">
    <div class="stat-card"><div class="num"><?= number_format($totalAttempts) ?></div><div class="lbl">Всего билетов</div></div>
    <div class="stat-card green"><div class="num"><?= number_format($usedAttempts) ?></div><div class="lbl">Использован</div></div>
    <div class="stat-card orange"><div class="num"><?= number_format($totalAttempts - $usedAttempts) ?></div><div class="lbl">Ожидает</div></div>
    <div class="stat-card blue"><div class="num"><?= number_format($uniqueUsers) ?></div><div class="lbl">Уникальных</div></div>
    <div class="stat-card purple"><div class="num"><?= number_format($registeredUsers) ?></div><div class="lbl">Зарегистр.</div></div>
    <div class="stat-card"><div class="num"><?= $totalAttempts > 0 ? round($usedAttempts / $totalAttempts * 100) : 0 ?>%</div><div class="lbl">Конверсия</div></div>
  </div>

  <div class="section">
    <div class="section-title">📁 По сессиям</div>
    <div class="session-row">
      <?php
      $sessionTotals = [];
      foreach ($sessionStats as $ss) $sessionTotals[$ss['session_num']] = $ss;
      for ($s = 1; $s <= 3; $s++):
        $ss = $sessionTotals[$s] ?? ['total'=>0,'used_count'=>0,'unique_users'=>0];
        $conv = $ss['total'] > 0 ? round($ss['used_count'] / $ss['total'] * 100) : 0;
      ?>
      <div class="session-card">
        <h3>🎩 <?= $s ?>-СЕССİЯ</h3>
        <div class="row"><span>Всего билетов</span><span><?= number_format($ss['total']) ?></span></div>
        <div class="row"><span>Использован</span><span style="color:#5cb85c"><?= number_format($ss['used_count']) ?></span></div>
        <div class="row"><span>Ожидает</span><span style="color:#ffa500"><?= number_format($ss['total'] - $ss['used_count']) ?></span></div>
        <div class="row"><span>Уникальных</span><span><?= number_format($ss['unique_users']) ?></span></div>
        <div class="row"><span>Конверсия</span><span style="color:<?= $conv > 70 ? '#5cb85c' : ($conv > 40 ? '#ffa500' : '#dc3545') ?>"><?= $conv ?>%</span></div>
      </div>
      <?php endfor; ?>
    </div>
  </div>

  <div class="two-col">
    <div class="section">
      <div class="section-title">🕐 Активность по часам</div>
      <div class="bar-chart">
        <?php for ($hr = 0; $hr < 24; $hr++):
          $cnt = $hourlyMap[$hr] ?? 0;
          $h = $maxHourly > 0 ? round($cnt / $maxHourly * 76) : 0;
        ?>
        <div class="bar-col">
          <div class="bar-fill" style="height:<?= max(2,$h) ?>px" title="<?= $hr ?>:00 — <?= $cnt ?> билет"></div>
          <div class="bar-label"><?= $hr ?></div>
        </div>
        <?php endfor; ?>
      </div>
      <div style="margin-top:8px;font-size:0.72rem;color:#555;text-align:center">Часы (0–23) | Пик: <?= $maxHourly ?> билет</div>
    </div>
    <div class="section">
      <div class="section-title">📅 Активность по дням (30 дней)</div>
      <?php $maxDay = max(array_merge([1], array_column($dailyStats, 'attempts'))); ?>
      <div class="daily-chart">
        <?php foreach ($dailyStats as $d):
          $h = round((int)$d['attempts'] / $maxDay * 90);
        ?>
        <div class="day-col">
          <div class="day-fill" style="height:<?= max(2,$h) ?>px" title="<?= $d['day'] ?>: <?= $d['attempts'] ?> билет"></div>
          <div class="day-label"><?= date('d.m', strtotime($d['day'])) ?></div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($dailyStats)): ?><div style="color:#444;font-size:0.8rem;padding:20px">Данных нет</div><?php endif; ?>
      </div>
      <?php if (!empty($dailyStats)):
        $todayRow = end($dailyStats); reset($dailyStats);
      ?>
      <div style="margin-top:8px;display:flex;gap:20px;font-size:0.75rem;color:#666;">
        <span>Всего: <strong style="color:#d4a017"><?= array_sum(array_column($dailyStats,'attempts')) ?></strong></span>
        <span>Ср./день: <strong style="color:#d4a017"><?= count($dailyStats) > 0 ? round(array_sum(array_column($dailyStats,'attempts')) / count($dailyStats), 1) : 0 ?></strong></span>
        <?php if ($todayRow): ?><span>Сегодня: <strong style="color:#5cb85c"><?= $todayRow['attempts'] ?></strong></span><?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="section">
    <div class="section-title">🏆 Приз таралымы</div>
    <?php if (empty($prizeDist)): ?>
    <div class="empty-state"><div class="icon">📭</div>Данных нет</div>
    <?php else:
      $maxPrize = max(array_column($prizeDist, 'total_given'));
    ?>
    <div class="prize-dist-list">
      <?php foreach ($prizeDist as $pd):
        $pct = $maxPrize > 0 ? round($pd['total_given'] / $maxPrize * 100) : 0;
      ?>
      <div class="prize-dist-item">
        <?php if ($pd['img_data']): ?>
          <img class="pimg" src="<?= htmlspecialchars($pd['img_data']) ?>" alt="">
        <?php else: ?>
          <span class="pem"><?= htmlspecialchars($pd['emoji']) ?></span>
        <?php endif; ?>
        <span class="pname" title="<?= htmlspecialchars($pd['name']) ?>"><?= htmlspecialchars($pd['name']) ?></span>
        <div class="bar-wrap"><div class="bar-inner" style="width:<?= $pct ?>%"></div></div>
        <span class="pcount"><?= $pd['total_given'] ?></span>
        <span style="color:#555;font-size:0.72rem;flex:0 0 36px"><?= round($pd['total_given'] / max(1,$totalAttempts) * 100, 1) ?>%</span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="section">
    <div class="section-title">👤 Топ пользователей</div>
    <?php if (empty($topUsers)): ?>
    <div class="empty-state"><div class="icon">👤</div>Пользователей нет</div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="top-users-table">
      <thead><tr><th>#</th><th>User ID</th><th>ФИО</th><th>Телефон</th><th>Билеттер</th><th>Пайд.</th><th>Конв.</th><th>Первый</th><th>Последний</th></tr></thead>
      <tbody>
        <?php foreach ($topUsers as $i => $u):
          $rank = $i + 1;
          $rankClass = $rank===1?'gold':($rank===2?'silver':($rank===3?'bronze':''));
          $conv = $u['attempts'] > 0 ? round($u['used_count'] / $u['attempts'] * 100) : 0;
          $fullName = trim(($u['name']??'').' '.($u['surname']??''));
        ?>
        <tr>
          <td><span class="rank-badge <?= $rankClass ?>"><?= $rank ?></span></td>
          <td style="color:#d4a017;font-weight:600;font-size:0.82rem"><?= $u['userid'] ?></td>
          <td><span style="color:#ccc;font-size:0.82rem"><?= $fullName ?: '—' ?></span></td>
          <td style="color:#666;font-size:0.78rem"><?= $u['phone'] ? htmlspecialchars($u['phone']) : '—' ?></td>
          <td style="color:#d4a017;font-weight:700"><?= $u['attempts'] ?></td>
          <td style="color:#5cb85c"><?= $u['used_count'] ?></td>
          <td><span style="color:<?= $conv>70?'#5cb85c':($conv>40?'#ffa500':'#888') ?>;font-size:0.78rem"><?= $conv ?>%</span></td>
          <td style="color:#555;font-size:0.75rem"><?= date('d.m.Y H:i', strtotime($u['first_at'])) ?></td>
          <td style="color:#555;font-size:0.75rem"><?= date('d.m.Y H:i', strtotime($u['last_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>

</div><!-- /tab-stats -->


<!-- ═══════════════ TAB: ATTEMPTS ═══════════════ -->
<div class="tab-content <?= $activeTab==='attempts'?'active':'' ?>" id="tab-attempts">

  <form method="GET" class="filter-bar">
    <input type="hidden" name="tab" value="attempts">
    <div><label>Поиск</label><input type="text" name="search" placeholder="User ID, транзакция, приз..." value="<?= htmlspecialchars($filterSearch) ?>" style="width:220px"></div>
    <div><label>Сессия</label><select name="session"><option value="">Всего</option><?php for ($s=1;$s<=3;$s++): ?><option value="<?= $s ?>" <?= $filterSession==$s?'selected':'' ?>><?= $s ?>-я сессия</option><?php endfor; ?></select></div>
    <div><label>Статус</label><select name="used"><option value="">Всего</option><option value="1" <?= $filterUsed==='1'?'selected':'' ?>>Использован</option><option value="0" <?= $filterUsed==='0'?'selected':'' ?>>Ожидает</option></select></div>
    <div><label>С даты</label><input type="date" name="date_from" value="<?= htmlspecialchars($filterDateFrom) ?>"></div>
    <div><label>По дату</label><input type="date" name="date_to" value="<?= htmlspecialchars($filterDateTo) ?>"></div>
    <div style="display:flex;gap:8px;align-items:flex-end">
      <button type="submit" class="btn btn-gold">🔍 Фильтр</button>
      <a href="?tab=attempts" class="btn-secondary">✕</a>
    </div>
  </form>

  <div class="section">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
      <div class="section-title" style="margin-bottom:0">📋 Всего билетовтер</div>
      <div style="display:flex;align-items:center;gap:12px;">
        <span style="color:#666;font-size:0.8rem"><?= number_format($totalRows) ?> жазба</span>
        <a href="?export=csv&tab=attempts&<?= http_build_query(array_filter(['session'=>$filterSession,'used'=>$filterUsed,'date_from'=>$filterDateFrom,'date_to'=>$filterDateTo,'search'=>$filterSearch])) ?>" class="export-btn">⬇ CSV</a>
      </div>
    </div>
    <?php if (empty($rows)): ?>
    <div class="empty-state"><div class="icon">📭</div>Жазба табылмады</div>
    <?php else: ?>
    <div class="data-table-wrap">
    <table class="data-table">
      <thead><tr><th>#</th><th>Билет №</th><th>User ID</th><th>ФИО</th><th>Телефон</th><th>Сессия</th><th>Приз</th><th>Статус</th><th>Транзакция</th><th>Создан</th><th>Время использ.</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr class="<?= $r['used'] ? 'used-row' : '' ?>">
          <td style="color:#444;font-size:0.75rem"><?= $r['id'] ?></td>
          <td style="font-family:monospace;color:#888;font-size:0.78rem"><?= $r['numeric_token'] ?></td>
          <td style="color:#d4a017;font-weight:600;font-size:0.82rem"><?= $r['userid'] ?></td>
          <td style="color:#ccc;font-size:0.78rem"><?= trim(($r['user_name']??'').' '.($r['user_surname']??'')) ?: '<span style="color:#333">—</span>' ?></td>
          <td style="color:#666;font-size:0.75rem"><?= $r['user_phone'] ? htmlspecialchars($r['user_phone']) : '<span style="color:#333">—</span>' ?></td>
          <td style="color:#888;font-size:0.8rem"><?= $r['session_num'] ?>-с</td>
          <td>
            <div class="prize-cell">
              <?php if ($r['prize_img']): ?><img src="<?= htmlspecialchars($r['prize_img']) ?>" alt=""><?php else: ?><span style="font-size:1.1rem"><?= htmlspecialchars($r['prize_emoji'] ?? '🎁') ?></span><?php endif; ?>
              <span style="font-size:0.8rem;color:#ccc"><?= htmlspecialchars($r['prize_name'] ?? '—') ?></span>
            </div>
          </td>
          <td><?= $r['used'] ? '<span class="badge badge-used">✅ Пайд.</span>' : '<span class="badge badge-unused">⏳ Ожидает</span>' ?></td>
          <td><span class="tx-code" title="<?= htmlspecialchars($r['transaction_number']) ?>"><?= htmlspecialchars($r['transaction_number']) ?></span></td>
          <td style="color:#555;font-size:0.75rem;white-space:nowrap"><?= date('d.m.Y H:i', strtotime($r['created_at'])) ?></td>
          <td style="color:#555;font-size:0.75rem;white-space:nowrap"><?= $r['used_at'] ? date('d.m.Y H:i', strtotime($r['used_at'])) : '<span style="color:#333">—</span>' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php if ($totalPages > 1):
      $href = fn($pg) => 'kese_stats.php' . qp(['tab'=>'attempts','page'=>$pg]);
    ?>
    <div class="pagination">
      <a href="<?= $href(1) ?>" class="<?= $page<=1?'disabled':'' ?>">«</a>
      <a href="<?= $href($page-1) ?>" class="<?= $page<=1?'disabled':'' ?>">‹</a>
      <?php
      $from = max(1,$page-2); $to = min($totalPages,$page+2);
      if ($from>1) echo '<span>…</span>';
      for ($i=$from;$i<=$to;$i++) echo $i==$page?"<span class='current'>$i</span>":"<a href='{$href($i)}'>$i</a>";
      if ($to<$totalPages) echo '<span>…</span>';
      ?>
      <a href="<?= $href($page+1) ?>" class="<?= $page>=$totalPages?'disabled':'' ?>">›</a>
      <a href="<?= $href($totalPages) ?>" class="<?= $page>=$totalPages?'disabled':'' ?>">»</a>
    </div>
    <div style="text-align:center;font-size:0.75rem;color:#444;margin-top:8px"><?= $page ?> / <?= $totalPages ?> стр. · <?= $totalRows ?> жазба</div>
    <?php endif; ?>
    <?php endif; ?>
  </div>

</div><!-- /tab-attempts -->


<!-- ═══════════════ TAB: ALL USERS ═══════════════ -->
<div class="tab-content <?= $activeTab==='users'?'active':'' ?>" id="tab-users">

  <form method="GET" class="filter-bar">
    <input type="hidden" name="tab" value="users">
    <div><label>Поиск</label><input type="text" name="usearch" placeholder="User ID, аты, телефон, мекен-жай..." value="<?= htmlspecialchars($uSearch) ?>" style="width:280px"></div>
    <div style="display:flex;gap:8px;align-items:flex-end">
      <button type="submit" class="btn btn-gold">🔍 Поиск</button>
      <a href="?tab=users" class="btn-secondary">✕</a>
    </div>
  </form>

  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
    <div style="color:#666;font-size:0.85rem"><?= number_format($uTotalRows) ?> пользователей найдено · всего <?= number_format($registeredUsers) ?> зарегистрир.</div>
    <a href="?export=users_csv" class="export-btn" style="background:rgba(91,155,213,0.15);border-color:rgba(91,155,213,0.35);color:#5b9bd5;">⬇ Все пользователилар CSV</a>
  </div>

  <?php if (empty($allUsers)): ?>
  <div class="empty-state"><div class="icon">👤</div>Пользователь табылмады</div>
  <?php else: ?>

  <?php foreach ($allUsers as $u):
    $uid = $u['userid'];
    $fullName = trim(($u['name']??'').' '.($u['surname']??''));
    $atts = $userAttempts[$uid] ?? [];
    $wonCount = count(array_filter($atts, fn($a) => $a['used']));
  ?>
  <div class="user-card">

    <!-- Header -->
    <div class="user-card-header">
      <span class="uid-badge">ID: <?= $uid ?></span>
      <span class="uname-big"><?= $fullName ?: '—' ?></span>
      <?php if ($u['phone']): ?><span class="uphone">📞 <?= htmlspecialchars($u['phone']) ?></span><?php endif; ?>
      <span style="margin-left:auto;display:flex;gap:8px;">
        <span style="background:rgba(212,160,23,0.1);border:1px solid rgba(212,160,23,0.25);color:#d4a017;padding:3px 10px;border-radius:12px;font-size:0.75rem;">🎟 <?= count($atts) ?> билет</span>
        <?php if ($wonCount): ?>
        <span style="background:rgba(40,167,69,0.1);border:1px solid rgba(40,167,69,0.25);color:#5cb85c;padding:3px 10px;border-radius:12px;font-size:0.75rem;">✅ <?= $wonCount ?> ойнады</span>
        <?php endif; ?>
      </span>
    </div>

    <!-- Info Grid -->
    <div class="user-info-grid">
      <div class="user-info-item">
        <div class="lbl">Telegram ID</div>
        <div class="val gold"><?= $uid ?></div>
      </div>
      <div class="user-info-item">
        <div class="lbl">Имя</div>
        <div class="val"><?= htmlspecialchars($u['name'] ?: '—') ?></div>
      </div>
      <div class="user-info-item">
        <div class="lbl">Фамилия</div>
        <div class="val"><?= htmlspecialchars($u['surname'] ?: '—') ?></div>
      </div>
      <div class="user-info-item">
        <div class="lbl">Телефон</div>
        <div class="val"><?= htmlspecialchars($u['phone'] ?: '—') ?></div>
      </div>
      <div class="user-info-item">
        <div class="lbl">Адрес</div>
        <div class="val"><?= htmlspecialchars($u['address'] ?: '—') ?></div>
      </div>
      <div class="user-info-item">
        <div class="lbl">Индекс</div>
        <div class="val"><?= htmlspecialchars($u['postcode'] ?? '' ?: '—') ?></div>
      </div>
      <div class="user-info-item">
        <div class="lbl">Город</div>
        <div class="val"><?= htmlspecialchars($u['city'] ?: '—') ?></div>
      </div>
      <div class="user-info-item">
        <div class="lbl">Зарегистр.</div>
        <div class="val"><?= $u['created_at'] ? date('d.m.Y H:i', strtotime($u['created_at'])) : '—' ?></div>
      </div>
      <div class="user-info-item">
        <div class="lbl">Всего билетов</div>
        <div class="val gold"><?= count($atts) ?></div>
      </div>
      <div class="user-info-item">
        <div class="lbl">Сыграл</div>
        <div class="val green"><?= $wonCount ?></div>
      </div>
      <?php if ($u['first_ticket_at']): ?>
      <div class="user-info-item">
        <div class="lbl">Первый билет</div>
        <div class="val"><?= date('d.m.Y H:i', strtotime($u['first_ticket_at'])) ?></div>
      </div>
      <div class="user-info-item">
        <div class="lbl">Последний билет</div>
        <div class="val"><?= date('d.m.Y H:i', strtotime($u['last_ticket_at'])) ?></div>
      </div>
      <?php endif; ?>
    </div>

    <!-- Tickets Table -->
    <div class="tickets-wrap">
      <?php if (empty($atts)): ?>
      <div class="no-tickets">Билетов нет</div>
      <?php else: ?>
      <div class="tickets-title">🎟 СПИСОК БИЛЕТОВ</div>
      <table class="tickets-table">
        <thead>
          <tr>
            <th>Билет №</th>
            <th>Сессия</th>
            <th>Приз</th>
            <th>Статус</th>
            <th>Транзакция</th>
            <th>Выдан</th>
            <th>Сыграл</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($atts as $att): ?>
          <tr>
            <td style="font-family:monospace;color:#d4a017;font-weight:700">#<?= $att['numeric_token'] ?></td>
            <td style="color:#888"><?= $att['session_num'] ?>-я сессия</td>
            <td>
              <div class="prize-cell">
                <?php if ($att['prize_img']): ?><img src="<?= htmlspecialchars($att['prize_img']) ?>" alt="" style="width:20px;height:20px;border-radius:50%;object-fit:cover;"><?php else: ?><span style="font-size:1rem"><?= htmlspecialchars($att['prize_emoji'] ?? '🎁') ?></span><?php endif; ?>
                <span style="color:<?= $att['used'] ? '#5cb85c' : '#ccc' ?>;font-size:0.8rem"><?= htmlspecialchars($att['prize_name'] ?? '—') ?></span>
              </div>
            </td>
            <td>
              <?php if ($att['used']): ?>
                <span class="badge badge-used">✅ Сыграл</span>
              <?php else: ?>
                <span class="badge badge-unused">⏳ Ожидает</span>
              <?php endif; ?>
            </td>
            <td><span style="font-family:monospace;font-size:0.72rem;color:#555;max-width:120px;display:inline-block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars($att['transaction_number']) ?>"><?= htmlspecialchars($att['transaction_number']) ?></span></td>
            <td style="color:#555;font-size:0.75rem;white-space:nowrap"><?= date('d.m.Y H:i', strtotime($att['created_at'])) ?></td>
            <td style="color:#555;font-size:0.75rem;white-space:nowrap"><?= $att['used_at'] ? date('d.m.Y H:i', strtotime($att['used_at'])) : '<span style="color:#2a2a2a">—</span>' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

  </div>
  <?php endforeach; ?>

  <!-- Users Pagination -->
  <?php if ($uTotalPages > 1):
    $uHref = fn($pg) => 'kese_stats.php' . qp(['tab'=>'users','upage'=>$pg,'usearch'=>$uSearch]);
  ?>
  <div class="pagination">
    <a href="<?= $uHref(1) ?>" class="<?= $uPage<=1?'disabled':'' ?>">«</a>
    <a href="<?= $uHref($uPage-1) ?>" class="<?= $uPage<=1?'disabled':'' ?>">‹</a>
    <?php
    $from = max(1,$uPage-2); $to = min($uTotalPages,$uPage+2);
    if ($from>1) echo '<span>…</span>';
    for ($i=$from;$i<=$to;$i++) echo $i==$uPage?"<span class='current'>$i</span>":"<a href='{$uHref($i)}'>$i</a>";
    if ($to<$uTotalPages) echo '<span>…</span>';
    ?>
    <a href="<?= $uHref($uPage+1) ?>" class="<?= $uPage>=$uTotalPages?'disabled':'' ?>">›</a>
    <a href="<?= $uHref($uTotalPages) ?>" class="<?= $uPage>=$uTotalPages?'disabled':'' ?>">»</a>
  </div>
  <div style="text-align:center;font-size:0.75rem;color:#444;margin-top:8px"><?= $uPage ?> / <?= $uTotalPages ?> стр. · <?= $uTotalRows ?> пользователей</div>
  <?php endif; ?>

  <?php endif; ?>

</div><!-- /tab-users -->

</div><!-- .container -->

</body>
</html>