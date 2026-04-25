<?php
// kese_admin.php — Admin panel for Kese (Cup Game)
require_once __DIR__ . '/../../src/auth.php';

$pdo = site_db();

// ─── TABLES ─────────────────────────────────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS kese_prizes (
    id INT AUTO_INCREMENT PRIMARY KEY, session_num TINYINT NOT NULL DEFAULT 1,
    slot_num TINYINT NOT NULL, name VARCHAR(255) NOT NULL,
    img_data MEDIUMTEXT NULL, emoji VARCHAR(16) NOT NULL DEFAULT '🎁',
    probability FLOAT NOT NULL DEFAULT 1, is_active TINYINT NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_session_slot (session_num, slot_num)
) CHARACTER SET utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS kese_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY, userid BIGINT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE, numeric_token INT NOT NULL,
    transaction_number VARCHAR(100) NOT NULL UNIQUE, session_num TINYINT NOT NULL DEFAULT 1,
    prize_id INT NULL, used TINYINT NOT NULL DEFAULT 0, used_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS kese_session_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_num TINYINT NOT NULL,
    mode ENUM('probability','sequential') NOT NULL DEFAULT 'probability',
    prize_order TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_session (session_num)
) CHARACTER SET utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS kese_preset_prizes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    attempt_number INT NOT NULL COMMENT 'Global attempt number',
    prize_id INT NOT NULL,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_attempt_number (attempt_number)
) CHARACTER SET utf8mb4");

// ─── NEW: User-specific prizes table ─────────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS kese_user_prizes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userid BIGINT NOT NULL COMMENT 'Telegram user ID',
    prize_id INT NOT NULL,
    note VARCHAR(255) NULL,
    used TINYINT NOT NULL DEFAULT 0,
    used_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_userid (userid),
    KEY idx_prize_id (prize_id)
) CHARACTER SET utf8mb4");

for ($s = 1; $s <= 3; $s++) {
    $pdo->prepare("INSERT IGNORE INTO kese_session_settings (session_num, mode) VALUES (?, 'probability')")->execute([$s]);
}

try { $pdo->exec("ALTER TABLE kese_prizes ADD COLUMN is_guide TINYINT NOT NULL DEFAULT 0"); } catch(PDOException $e){}

$pdo->exec("CREATE TABLE IF NOT EXISTS kese_session_guide_videos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_num TINYINT NOT NULL,
    video_urls TEXT NULL,
    UNIQUE KEY uq_session (session_num)
) CHARACTER SET utf8mb4");
for ($s = 1; $s <= 3; $s++) {
    $pdo->prepare("INSERT IGNORE INTO kese_session_guide_videos (session_num, video_urls) VALUES (?, '')")->execute([$s]);
}

// Seed prizes
$cnt = $pdo->query("SELECT COUNT(*) FROM kese_prizes")->fetchColumn();
if ($cnt == 0) {
    $emojis = ['🥇','💎','🎁','🏆','⭐','🎯','🌟','🎊','🔮','🥈','🎖','🎀','🏅','💫','🎪','🌈','🎭','🎨','🥉','💝','🎗','🌺','✨','🎡','🎠','🎢','🎆'];
    $ins = $pdo->prepare("INSERT INTO kese_prizes (session_num, slot_num, name, emoji) VALUES (?,?,?,?)");
    $idx = 0;
    for ($s = 1; $s <= 3; $s++) {
        for ($sl = 1; $sl <= 9; $sl++) {
            $ins->execute([$s, $sl, ($idx+1).'-приз', $emojis[$idx % count($emojis)]]); $idx++;
        }
    }
}

$msg = ''; $msgType = 'success';

// ─── AJAX: notify_game (до общего POST-блока, иначе 302 редирект) ────────────
if (isset($_POST['ajax_notify_game'])) {
    header('Content-Type: application/json');
    $botUrl  = rtrim(getenv('BOT_WEBHOOK_URL') ?: 'http://bot:3007', '/') . '/notify_game';
    $siteUrl = rtrim(getenv('SITE_PUBLIC_URL') ?: '', '/');
    $uid     = (int)($_POST['userid'] ?? 0);
    if (!$uid) { echo json_encode(['success'=>false,'error'=>'No userid']); exit; }
    $stm = $pdo->prepare("SELECT token FROM kese_attempts WHERE userid=? AND used=0 ORDER BY id ASC");
    $stm->execute([$uid]);
    $tokens = $stm->fetchAll(PDO::FETCH_COLUMN);
    if (empty($tokens)) { echo json_encode(['success'=>false,'error'=>'No unused attempts']); exit; }
    $attempts = array_map(fn($t) => ['wheel_url' => $siteUrl . '/kese.php?token=' . $t], $tokens);
    $payload  = json_encode(['chat_id' => $uid, 'attempts' => $attempts]);
    $ch = curl_init($botUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) { echo json_encode(['success'=>false,'error'=>$err]); exit; }
    $data = json_decode($resp, true);
    echo json_encode($data ?: ['success'=>false,'error'=>'Bad response: '.$resp]);
    exit;
}

// ─── POST ACTIONS ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    // Update prize
    if ($act === 'update_prize') {
        $id = (int)$_POST['prize_id']; $name = trim($_POST['name'] ?? '');
        $emoji = trim($_POST['emoji'] ?? '🎁'); $prob = floatval($_POST['probability'] ?? 1);
        $isGuide = isset($_POST['is_guide']) ? 1 : 0;
        if (!$id || !$name) { $msg = 'Нет ID или названия!'; $msgType = 'error'; }
        else {
            if (!empty($_FILES['prize_img']['tmp_name'])) {
                $fi = $_FILES['prize_img'];
                $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
                if (!in_array($fi['type'], $allowed)) { $msg = 'Только JPG/PNG/GIF/WEBP!'; $msgType = 'error'; }
                elseif ($fi['size'] > 2*1024*1024) { $msg = 'Изображение должно быть ≤ 2MB!'; $msgType = 'error'; }
                else {
                    $imgData = 'data:'.$fi['type'].';base64,'.base64_encode(file_get_contents($fi['tmp_name']));
                    $pdo->prepare("UPDATE kese_prizes SET name=?,emoji=?,probability=?,img_data=?,is_guide=? WHERE id=?")->execute([$name,$emoji,$prob,$imgData,$isGuide,$id]);
                    $msg = 'Приз обновлён!';
                }
            } else {
                $pdo->prepare("UPDATE kese_prizes SET name=?,emoji=?,probability=?,is_guide=? WHERE id=?")->execute([$name,$emoji,$prob,$isGuide,$id]);
                $msg = 'Приз обновлён!';
            }
        }
    }

    if ($act === 'clear_img') {
        $id = (int)$_POST['prize_id'];
        $pdo->prepare("UPDATE kese_prizes SET img_data=NULL WHERE id=?")->execute([$id]);
        $msg = 'Изображение удалено!';
    }

    if ($act === 'toggle_active') {
        $id = (int)$_POST['prize_id'];
        $pdo->prepare("UPDATE kese_prizes SET is_active = NOT is_active WHERE id=?")->execute([$id]);
        $msg = 'Активность изменена!';
    }

    if ($act === 'save_session_mode') {
        $session = (int)($_POST['session_num'] ?? 1);
        $mode    = in_array($_POST['mode'] ?? '', ['probability','sequential']) ? $_POST['mode'] : 'probability';
        $rawOrder   = trim($_POST['prize_order_json'] ?? '[]');
        $prizeOrder = json_decode($rawOrder, true);
        if (!is_array($prizeOrder)) $prizeOrder = [];
        $prizeOrder = array_values(array_map('intval', $prizeOrder));
        $pdo->prepare("INSERT INTO kese_session_settings (session_num,mode,prize_order) VALUES (?,?,?) ON DUPLICATE KEY UPDATE mode=VALUES(mode), prize_order=VALUES(prize_order)")
            ->execute([$session, $mode, json_encode($prizeOrder)]);
        $msg = ($mode === 'sequential')
            ? 'Последовательный режим сохранён! ' . count($prizeOrder) . ' призов задан порядок.'
            : 'Режим вероятностей сохранён!';
    }

    if ($act === 'add_preset') {
        $attemptNum = (int)($_POST['attempt_number'] ?? 0);
        $prizeId    = (int)($_POST['prize_id'] ?? 0);
        $note       = trim($_POST['note'] ?? '');
        if ($attemptNum < 1) { $msg = 'Номер билета должен быть ≥ 1!'; $msgType = 'error'; }
        elseif (!$prizeId)   { $msg = 'Выберите приз!'; $msgType = 'error'; }
        else {
            $stmt = $pdo->prepare("SELECT name FROM kese_prizes WHERE id = ?");
            $stmt->execute([$prizeId]);
            $p = $stmt->fetch();
            if (!$p) { $msg = 'Приз табылмады!'; $msgType = 'error'; }
            else {
                $pdo->prepare("INSERT INTO kese_preset_prizes (attempt_number,prize_id,note) VALUES (?,?,?)
                               ON DUPLICATE KEY UPDATE prize_id=VALUES(prize_id),note=VALUES(note)")
                    ->execute([$attemptNum, $prizeId, $note]);
                $msg = "$attemptNum-му билету «{$p['name']}» назначен!";
            }
        }
        $tab = 6;
        header('Location: index.php?tab='.$tab.'&msg='.urlencode($msg).'&mt='.$msgType);
        exit;
    }

    if ($act === 'delete_preset') {
        $id = (int)($_POST['preset_id'] ?? 0);
        if ($id) {
            $pdo->prepare("DELETE FROM kese_preset_prizes WHERE id=?")->execute([$id]);
            $msg = 'Назначенный приз удалён!';
        }
        $tab = 6;
        header('Location: index.php?tab='.$tab.'&msg='.urlencode($msg).'&mt='.$msgType);
        exit;
    }

    // ── ADD USER PRIZE ──────────────────────────────────────────────────────
    if ($act === 'add_user_prize') {
        $userid  = trim($_POST['userid'] ?? '');
        $prizeId = (int)($_POST['prize_id'] ?? 0);
        $note    = trim($_POST['note'] ?? '');
        if (!$userid || !is_numeric($userid)) { $msg = 'Введите корректный Telegram ID!'; $msgType = 'error'; }
        elseif (!$prizeId) { $msg = 'Выберите приз!'; $msgType = 'error'; }
        else {
            $userid = (int)$userid;
            $stmt = $pdo->prepare("SELECT name FROM kese_prizes WHERE id = ?");
            $stmt->execute([$prizeId]);
            $p = $stmt->fetch();
            if (!$p) { $msg = 'Приз табылмады!'; $msgType = 'error'; }
            else {
                $pdo->prepare("INSERT INTO kese_user_prizes (userid, prize_id, note) VALUES (?,?,?)")
                    ->execute([$userid, $prizeId, $note]);
                $msg = "ID $userid пользователю «{$p['name']}» назначен!";
            }
        }
        header('Location: index.php?tab=7&msg='.urlencode($msg).'&mt='.$msgType);
        exit;
    }

    // ── DELETE USER PRIZE ───────────────────────────────────────────────────
    if ($act === 'delete_user_prize') {
        $id = (int)($_POST['up_id'] ?? 0);
        if ($id) {
            $pdo->prepare("DELETE FROM kese_user_prizes WHERE id=?")->execute([$id]);
            $msg = 'Удалено!';
        }
        header('Location: index.php?tab=7&msg='.urlencode($msg).'&mt='.$msgType);
        exit;
    }

    if ($act === 'reset_attempts') {
        if (isset($_POST['confirm_reset']) && $_POST['confirm_reset'] === 'YES') {
            $pdo->exec("DELETE FROM kese_attempts");
            $msg = 'Все попытки удалены!';
        } else { $msg = 'Неверный текст подтверждения!'; $msgType = 'error'; }
    }

    if ($act === 'save_guide_videos') {
        $session = (int)($_POST['session_num'] ?? 1);
        $urls = trim($_POST['video_urls'] ?? '');
        $pdo->prepare("INSERT INTO kese_session_guide_videos (session_num, video_urls) VALUES (?,?) ON DUPLICATE KEY UPDATE video_urls=VALUES(video_urls)")
            ->execute([$session, $urls]);
        $msg = 'Видео ссылки сохранены!';
        $tab = $session;
        header('Location: index.php?tab='.$tab.'&msg='.urlencode($msg).'&mt=success');
        exit;
    }

    $tab = (int)($_POST['tab'] ?? 0);
    header('Location: index.php?tab='.$tab.'&msg='.urlencode($msg).'&mt='.$msgType);
    exit;
}

// ─── FETCH DATA ──────────────────────────────────────────────────────────────
$_tabRaw   = (int)($_GET['tab'] ?? 0);
$activeTab = in_array($_tabRaw, [0,1,2,3,4,5,6,7,8]) ? $_tabRaw : 0;
$inMsg     = $_GET['msg'] ?? '';
$inMsgType = $_GET['mt']  ?? 'success';

$prizes = [1=>[], 2=>[], 3=>[]];
$rows   = $pdo->query("SELECT * FROM kese_prizes ORDER BY session_num, slot_num")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) $prizes[$r['session_num']][] = $r;

$allPrizes = $pdo->query("SELECT * FROM kese_prizes ORDER BY session_num, slot_num")->fetchAll(PDO::FETCH_ASSOC);

$presets = $pdo->query("
    SELECT pp.*, p.name AS prize_name, p.emoji AS prize_emoji, p.img_data AS prize_img, p.session_num AS prize_session
    FROM kese_preset_prizes pp
    JOIN kese_prizes p ON p.id = pp.prize_id
    ORDER BY pp.attempt_number ASC
")->fetchAll(PDO::FETCH_ASSOC);

// User prizes
$userPrizes = $pdo->query("
    SELECT up.*, p.name AS prize_name, p.emoji AS prize_emoji, p.img_data AS prize_img,
           u.name AS user_name, u.phone AS user_phone
    FROM kese_user_prizes up
    JOIN kese_prizes p ON p.id = up.prize_id
    LEFT JOIN kese_users u ON u.userid = up.userid
    ORDER BY up.used ASC, up.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$sessionSettings = [];
$ssRows = $pdo->query("SELECT * FROM kese_session_settings ORDER BY session_num")->fetchAll(PDO::FETCH_ASSOC);
foreach ($ssRows as $ss) {
    $order = !empty($ss['prize_order']) ? (json_decode($ss['prize_order'], true) ?: []) : [];
    $sessionSettings[$ss['session_num']] = ['mode' => $ss['mode'], 'prize_order' => $order];
}
for ($s = 1; $s <= 3; $s++) {
    if (!isset($sessionSettings[$s])) $sessionSettings[$s] = ['mode' => 'probability', 'prize_order' => []];
}

$guideVideos = [];
$gvRows = $pdo->query("SELECT session_num, video_urls FROM kese_session_guide_videos")->fetchAll(PDO::FETCH_ASSOC);
foreach ($gvRows as $gv) $guideVideos[$gv['session_num']] = $gv['video_urls'] ?? '';
for ($s = 1; $s <= 3; $s++) {
    if (!isset($guideVideos[$s])) $guideVideos[$s] = '';
}

$totalAttempts  = $pdo->query("SELECT COUNT(*) FROM kese_attempts")->fetchColumn();
$usedAttempts   = $pdo->query("SELECT COUNT(*) FROM kese_attempts WHERE used=1")->fetchColumn();
$recentAttempts = $pdo->query("
    SELECT a.*, p.name AS prize_name, p.emoji AS prize_emoji, p.img_data AS prize_img
    FROM kese_attempts a LEFT JOIN kese_prizes p ON a.prize_id=p.id
    ORDER BY a.id DESC LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

$nextAttemptNum = (int)($pdo->query("SELECT COALESCE(MAX(numeric_token),10000)+1 FROM kese_attempts")->fetchColumn());

// Count unused user prizes
$pendingUserPrizes = count(array_filter($userPrizes, fn($u) => !$u['used']));

// ─── TAB-8: Загрузившие чек, но не сыгравшие ─────────────────────────────
// Автодобавление колонок selected_box / box_selected_at если ещё нет
try { $pdo->exec("ALTER TABLE kese_attempts ADD COLUMN selected_box TINYINT NULL DEFAULT NULL"); } catch(PDOException $e){}
try { $pdo->exec("ALTER TABLE kese_attempts ADD COLUMN box_selected_at DATETIME NULL DEFAULT NULL"); } catch(PDOException $e){}

$sitePublicUrl = rtrim(getenv('SITE_PUBLIC_URL') ?: '', '/');
$unusedRows = $pdo->query("
    SELECT a.userid, a.token, a.numeric_token, a.id AS attempt_id,
           a.session_num, a.created_at,
           u.name AS uname, u.surname AS usurname, u.phone AS uphone
    FROM kese_attempts a
    LEFT JOIN kese_users u ON u.userid = a.userid
    WHERE a.used = 0
    ORDER BY a.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Группируем по userid: userid -> { info, attempts[] }
$unusedByUser = [];
foreach ($unusedRows as $r) {
    $uid = $r['userid'];
    if (!isset($unusedByUser[$uid])) {
        $unusedByUser[$uid] = [
            'userid'  => $uid,
            'uname'   => $r['uname'],
            'usurname'=> $r['usurname'],
            'uphone'  => $r['uphone'],
            'attempts'=> [],
        ];
    }
    $unusedByUser[$uid]['attempts'][] = [
        'attempt_id'   => $r['attempt_id'],
        'numeric_token'=> $r['numeric_token'],
        'token'        => $r['token'],
        'wheel_url'    => $sitePublicUrl . '/kese.php?token=' . $r['token'],
        'created_at'   => $r['created_at'],
        'session_num'  => $r['session_num'],
    ];
}
$unusedByUser = array_values($unusedByUser);
$unusedUsersCount = count($unusedByUser);


?>
<!DOCTYPE html>
<html lang="kk">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Алтын Кесе — Админка</title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Segoe UI',sans-serif; background:#0f0f0f; color:#e0e0e0; min-height:100vh; }

.topbar {
  background:linear-gradient(135deg,#1a0e03,#2a1a05);
  border-bottom:2px solid #d4a017;
  padding:16px 30px; display:flex; align-items:center; justify-content:space-between;
}
.topbar h1 { font-size:1.3rem; letter-spacing:4px; color:#d4a017; text-transform:uppercase; }
.topbar .badge { font-size:0.75rem; color:rgba(212,160,23,0.6); border:1px solid rgba(212,160,23,0.3); padding:4px 12px; border-radius:20px; }

.layout { display:flex; min-height:calc(100vh - 62px); }

.sidebar { width:230px; flex-shrink:0; background:#131313; border-right:1px solid #2a2a2a; padding:20px 0; }
.sidebar .nav-item { display:block; padding:12px 20px; color:#888; font-size:0.9rem; text-decoration:none; border-left:3px solid transparent; transition:all 0.2s; cursor:pointer; background:none; border-top:none; border-right:none; border-bottom:none; width:100%; text-align:left; }
.sidebar .nav-item:hover { color:#d4a017; background:rgba(212,160,23,0.05); }
.sidebar .nav-item.active { color:#d4a017; border-left-color:#d4a017; background:rgba(212,160,23,0.08); }
.sidebar .nav-section { padding:8px 20px; font-size:0.7rem; color:#555; letter-spacing:2px; text-transform:uppercase; margin-top:10px; }
.sidebar .mode-pill { display:inline-block; margin-left:6px; padding:1px 6px; border-radius:8px; font-size:0.65rem; }
.sidebar .mode-pill.seq  { background:rgba(255,165,0,0.2); color:#ffa500; }
.sidebar .mode-pill.prob { background:rgba(40,167,69,0.2);  color:#5cb85c; }
.sidebar .mode-pill.tg   { background:rgba(41,182,246,0.2); color:#29b6f6; }

.main { flex:1; padding:30px; overflow-y:auto; }

.stats-row { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:16px; margin-bottom:30px; }
.stat-card { background:linear-gradient(135deg,#1a0e03,#2a1a05); border:1px solid rgba(212,160,23,0.25); border-radius:8px; padding:20px; text-align:center; }
.stat-card .num { font-size:2.2rem; color:#d4a017; font-weight:700; }
.stat-card .lbl { font-size:0.78rem; color:#888; margin-top:4px; }
.stat-card.tg-card { background:linear-gradient(135deg,#0a1a2a,#0d2a3a); border-color:rgba(41,182,246,0.25); }
.stat-card.tg-card .num { color:#29b6f6; }

.flash { padding:12px 20px; border-radius:6px; margin-bottom:20px; font-size:0.9rem; font-weight:500; }
.flash.success { background:rgba(40,167,69,0.15); border:1px solid rgba(40,167,69,0.4); color:#5cb85c; }
.flash.error   { background:rgba(220,53,69,0.15);  border:1px solid rgba(220,53,69,0.4);  color:#dc3545; }

.tab-panel { display:none; }
.tab-panel.active { display:block; }

.section { background:#181818; border:1px solid #2a2a2a; border-radius:8px; padding:24px; margin-bottom:24px; }
.section h2 { color:#d4a017; font-size:1rem; letter-spacing:2px; margin-bottom:20px; }
.section h2.tg { color:#29b6f6; }

/* ── USER PRIZE section ───────────────────────────────────────────────────── */
.tg-form-grid {
  display:grid; grid-template-columns:200px 1fr 1fr auto; gap:12px; align-items:end;
  margin-bottom:20px;
}
.tg-form-grid label { display:block; font-size:0.75rem; color:#777; margin-bottom:4px; }
.tg-form-grid input[type="text"],
.tg-form-grid input[type="number"],
.tg-form-grid select {
  width:100%; padding:8px 10px; background:#252525; border:1px solid #3a3a3a;
  border-radius:4px; color:#e0e0e0; font-size:0.9rem; outline:none;
}
.tg-form-grid input:focus, .tg-form-grid select:focus { border-color:#29b6f6; }

.tg-hint {
  background:rgba(41,182,246,0.05); border:1px solid rgba(41,182,246,0.2);
  border-radius:6px; padding:14px 18px; margin-bottom:20px; font-size:0.84rem; color:#80d8f8; line-height:1.7;
}
.tg-hint strong { color:#29b6f6; }

.tg-id-badge {
  display:inline-flex; align-items:center; gap:6px;
  background:rgba(41,182,246,0.12); border:1px solid rgba(41,182,246,0.3);
  color:#29b6f6; font-weight:700; font-size:0.88rem; padding:4px 12px; border-radius:20px;
  font-family:monospace;
}
.tg-id-badge .tg-icon { font-size:0.9rem; }

.user-info-mini { font-size:0.78rem; color:#666; margin-top:2px; }

.used-badge   { padding:3px 8px; border-radius:10px; font-size:0.72rem; background:rgba(100,100,100,0.2); color:#666; }
.unused-badge { padding:3px 8px; border-radius:10px; font-size:0.72rem; background:rgba(41,182,246,0.15); color:#29b6f6; font-weight:600; }
.used-row td  { opacity:0.5; }

.priority-banner {
  background:linear-gradient(135deg, rgba(41,182,246,0.1), rgba(212,160,23,0.05));
  border:1px solid rgba(41,182,246,0.25); border-radius:8px;
  padding:16px 20px; margin-bottom:20px;
  display:flex; align-items:flex-start; gap:14px;
}
.priority-banner .icon { font-size:2rem; flex-shrink:0; }
.priority-banner h3 { color:#29b6f6; font-size:0.9rem; margin-bottom:6px; }
.priority-banner p  { color:#888; font-size:0.82rem; line-height:1.6; }
.priority-step { display:inline-block; background:rgba(41,182,246,0.15); color:#29b6f6; font-size:0.72rem; font-weight:700; padding:2px 8px; border-radius:10px; margin-right:4px; }
.priority-step.two  { background:rgba(255,165,0,0.15); color:#ffa500; }
.priority-step.three { background:rgba(100,100,100,0.2); color:#888; }

/* ── PRESET section ──────────────────────────────────────────────────────── */
.preset-form-grid {
  display:grid; grid-template-columns:140px 1fr 1fr 200px auto; gap:12px; align-items:end;
  margin-bottom:20px;
}
.preset-form-grid label { display:block; font-size:0.75rem; color:#777; margin-bottom:4px; }
.preset-form-grid input[type="number"],
.preset-form-grid input[type="text"],
.preset-form-grid select {
  width:100%; padding:8px 10px; background:#252525; border:1px solid #3a3a3a;
  border-radius:4px; color:#e0e0e0; font-size:0.9rem; outline:none;
}
.preset-form-grid input:focus, .preset-form-grid select:focus { border-color:#d4a017; }

.preset-hint {
  background:rgba(255,165,0,0.06); border:1px solid rgba(255,165,0,0.2);
  border-radius:6px; padding:12px 16px; margin-bottom:20px; font-size:0.83rem; color:#ffa500; line-height:1.6;
}

.presets-table { width:100%; border-collapse:collapse; font-size:0.85rem; }
.presets-table th { background:#1a1a1a; color:#888; padding:10px 12px; text-align:left; font-weight:500; border-bottom:1px solid #2a2a2a; }
.presets-table td { padding:10px 12px; border-bottom:1px solid #1e1e1e; vertical-align:middle; }
.presets-table tr:hover td { background:rgba(255,165,0,0.03); }
.ticket-badge {
  display:inline-block; background:rgba(255,165,0,0.15); border:1px solid rgba(255,165,0,0.35);
  color:#ffa500; font-weight:700; font-size:0.9rem; padding:4px 12px; border-radius:20px;
  font-family:monospace;
}
.ticket-badge.past { background:rgba(100,100,100,0.15); border-color:#444; color:#666; }
.ticket-badge.next { background:rgba(212,160,23,0.2); border-color:rgba(212,160,23,0.5); color:#d4a017; animation:pulse-gold 1.5s ease-in-out infinite; }
@keyframes pulse-gold { 0%,100%{box-shadow:0 0 0 0 rgba(212,160,23,0.4)} 50%{box-shadow:0 0 0 6px rgba(212,160,23,0)} }

.prize-mini { display:flex; align-items:center; gap:8px; }
.prize-mini img { width:32px; height:32px; border-radius:50%; object-fit:cover; border:1px solid #3a3a3a; }
.note-text { color:#777; font-size:0.8rem; font-style:italic; }
.status-badge { padding:3px 8px; border-radius:10px; font-size:0.72rem; }
.status-upcoming { background:rgba(40,167,69,0.2); color:#5cb85c; }
.status-passed   { background:rgba(100,100,100,0.15); color:#555; }
.status-current  { background:rgba(212,160,23,0.2); color:#d4a017; font-weight:700; }

/* Mode selector */
.mode-selector {
  display:flex; gap:0; border-radius:8px; overflow:hidden;
  border:1px solid #3a3a3a; width:fit-content; margin-bottom:24px;
}
.mode-btn {
  padding:12px 28px; border:none; background:#1e1e1e; color:#888;
  cursor:pointer; font-size:0.9rem; font-weight:500; transition:all 0.2s;
  display:flex; align-items:center; gap:8px;
}
.mode-btn:hover { background:#252525; color:#ccc; }
.mode-btn.active-prob { background:linear-gradient(135deg,rgba(40,167,69,0.25),rgba(40,167,69,0.15)); color:#5cb85c; }
.mode-btn.active-seq  { background:linear-gradient(135deg,rgba(255,165,0,0.25),rgba(255,165,0,0.15));  color:#ffa500; }

.mode-info { padding:14px 18px; border-radius:6px; margin-bottom:20px; font-size:0.88rem; line-height:1.6; }
.mode-info.prob { background:rgba(40,167,69,0.08); border:1px solid rgba(40,167,69,0.25); color:#7dd87d; }
.mode-info.seq  { background:rgba(255,165,0,0.08);  border:1px solid rgba(255,165,0,0.25);  color:#ffc966; }

.seq-builder { display:none; }
.seq-builder.visible { display:block; }
.seq-builder h3 { color:#ffa500; font-size:0.9rem; letter-spacing:2px; margin-bottom:16px; }
.order-layout { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
.prize-pool-panel h4, .order-list-panel h4 { font-size:0.8rem; color:#888; letter-spacing:1px; text-transform:uppercase; margin-bottom:12px; padding-bottom:8px; border-bottom:1px solid #2a2a2a; }
.prize-pool { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
.prize-chip { display:flex; align-items:center; gap:8px; background:#1e1e1e; border:1px solid #333; border-radius:6px; padding:8px 10px; cursor:pointer; transition:all 0.2s; font-size:0.82rem; }
.prize-chip:hover { border-color:#ffa500; background:rgba(255,165,0,0.08); }
.prize-chip .pem { font-size:1.2rem; flex-shrink:0; }
.prize-chip .pname { color:#ccc; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.order-list { min-height:200px; background:#141414; border:2px dashed #2a2a2a; border-radius:8px; padding:10px; }
.order-item { display:flex; align-items:center; gap:8px; background:#1e1e1e; border:1px solid #2d2d2d; border-radius:6px; padding:8px 10px; margin-bottom:6px; font-size:0.82rem; cursor:grab; user-select:none; }
.order-item .pos-num { width:24px; height:24px; border-radius:50%; background:rgba(255,165,0,0.15); color:#ffa500; display:flex; align-items:center; justify-content:center; font-size:0.7rem; font-weight:700; flex-shrink:0; }
.order-item .oem  { font-size:1.1rem; }
.order-item .oname { flex:1; color:#ccc; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.order-item .remove-btn { width:20px; height:20px; border-radius:50%; background:rgba(220,53,69,0.15); border:1px solid rgba(220,53,69,0.3); color:#dc3545; display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:0.75rem; flex-shrink:0; transition:all 0.2s; }
.order-item .remove-btn:hover { background:rgba(220,53,69,0.35); }
.order-empty-hint { color:#444; font-size:0.82rem; text-align:center; padding:30px 10px; display:flex; flex-direction:column; align-items:center; gap:6px; }
.order-actions { display:flex; gap:8px; margin-top:10px; flex-wrap:wrap; }
.order-item.dragging { opacity:0.4; cursor:grabbing; }
.order-item.drag-over { border-color:#ffa500; background:rgba(255,165,0,0.08); }

.prizes-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:18px; }
.prize-card { background:#1e1e1e; border:1px solid #333; border-radius:8px; padding:16px; position:relative; }
.prize-card .slot-label { position:absolute; top:10px; right:12px; font-size:0.7rem; color:#555; letter-spacing:1px; }
.prize-preview-area { display:flex; align-items:center; gap:14px; margin-bottom:14px; }
.prize-thumb { width:70px; height:70px; border-radius:50%; border:2px solid #333; background:#252525; display:flex; align-items:center; justify-content:center; font-size:2.2rem; flex-shrink:0; overflow:hidden; position:relative; }
.prize-thumb img { width:100%; height:100%; object-fit:cover; }
.prize-form label { display:block; font-size:0.75rem; color:#777; margin-bottom:4px; margin-top:10px; }
.prize-form input[type="text"], .prize-form input[type="number"], .prize-form input[type="file"] { width:100%; padding:7px 10px; background:#252525; border:1px solid #3a3a3a; border-radius:4px; color:#e0e0e0; font-size:0.9rem; outline:none; transition:border-color 0.2s; }
.prize-form input[type="text"]:focus, .prize-form input[type="number"]:focus { border-color:#d4a017; }
.prize-form .guide-check-row { display:flex; align-items:center; gap:8px; margin-top:10px; padding:8px 10px; background:rgba(41,182,246,0.07); border:1px solid rgba(41,182,246,0.2); border-radius:6px; cursor:pointer; }
.prize-form .guide-check-row input[type="checkbox"] { width:16px; height:16px; accent-color:#29b6f6; flex-shrink:0; cursor:pointer; }
.prize-form .guide-check-row label { color:#29b6f6; font-size:0.8rem; font-weight:600; cursor:pointer; margin:0; }
.guide-videos-block { margin-top:24px; padding:16px; background:rgba(41,182,246,0.05); border:1px solid rgba(41,182,246,0.18); border-radius:8px; }
.guide-videos-block h3 { color:#29b6f6; font-size:0.9rem; margin-bottom:6px; }
.guide-videos-block p  { color:#666; font-size:0.78rem; margin-bottom:10px; line-height:1.5; }
.guide-videos-block textarea { width:100%; min-height:110px; padding:9px 12px; background:#1a1a1a; border:1px solid #333; border-radius:6px; color:#e0e0e0; font-size:0.85rem; font-family:monospace; resize:vertical; outline:none; }
.guide-videos-block textarea:focus { border-color:#29b6f6; }
.guide-badge { display:inline-block; background:rgba(41,182,246,0.15); border:1px solid rgba(41,182,246,0.35); color:#29b6f6; font-size:0.65rem; padding:1px 6px; border-radius:8px; vertical-align:middle; margin-left:4px; }
.btn { padding:8px 18px; border:none; border-radius:4px; font-size:0.85rem; cursor:pointer; transition:all 0.2s; font-weight:500; }
.btn-gold    { background:linear-gradient(135deg,#d4a017,#f0c040); color:#0a0604; }
.btn-gold:hover { transform:translateY(-1px); box-shadow:0 4px 12px rgba(212,160,23,0.4); }
.btn-orange  { background:linear-gradient(135deg,#e07b00,#ffa500); color:#0a0604; }
.btn-orange:hover { transform:translateY(-1px); box-shadow:0 4px 12px rgba(255,165,0,0.4); }
.btn-tg      { background:linear-gradient(135deg,#0288d1,#29b6f6); color:#fff; }
.btn-tg:hover { transform:translateY(-1px); box-shadow:0 4px 12px rgba(41,182,246,0.4); }
.btn-danger  { background:rgba(220,53,69,0.15); border:1px solid rgba(220,53,69,0.4); color:#dc3545; }
.btn-danger:hover { background:rgba(220,53,69,0.3); }
.btn-secondary { background:rgba(212,160,23,0.1); border:1px solid rgba(212,160,23,0.25); color:#d4a017; }
.btn-secondary:hover { background:rgba(212,160,23,0.2); }
.btn-sm { padding:5px 12px; font-size:0.78rem; }
.form-actions { display:flex; gap:8px; margin-top:12px; flex-wrap:wrap; }
.active-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:0.72rem; }
.active-badge.on  { background:rgba(40,167,69,0.2);  color:#5cb85c; }
.active-badge.off { background:rgba(100,100,100,0.2); color:#888; }

.data-table { width:100%; border-collapse:collapse; font-size:0.85rem; }
.data-table th { background:#1a1a1a; color:#888; padding:10px 12px; text-align:left; font-weight:500; border-bottom:1px solid #2a2a2a; }
.data-table td { padding:10px 12px; border-bottom:1px solid #222; }
.data-table tr:hover td { background:rgba(212,160,23,0.03); }
.badge-used   { background:rgba(40,167,69,0.2);  color:#5cb85c; padding:3px 8px; border-radius:10px; font-size:0.75rem; }
.badge-unused { background:rgba(100,100,100,0.2); color:#888;    padding:3px 8px; border-radius:10px; font-size:0.75rem; }

.danger-zone { border:1px solid rgba(220,53,69,0.3); border-radius:8px; padding:20px; }
.danger-zone h3 { color:#dc3545; margin-bottom:12px; }

.session-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:20px; }
.current-mode-display { display:flex; align-items:center; gap:8px; padding:8px 16px; border-radius:20px; font-size:0.82rem; font-weight:600; }
.current-mode-display.prob { background:rgba(40,167,69,0.12); border:1px solid rgba(40,167,69,0.3); color:#5cb85c; }
.current-mode-display.seq  { background:rgba(255,165,0,0.12);  border:1px solid rgba(255,165,0,0.3);  color:#ffa500; }

/* search box */
.search-box { position:relative; margin-bottom:16px; }
.search-box input { width:100%; padding:10px 14px 10px 38px; background:#1e1e1e; border:1px solid #333; border-radius:6px; color:#e0e0e0; font-size:0.88rem; outline:none; }
.search-box input:focus { border-color:#29b6f6; }
.search-box .icon { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#555; font-size:0.9rem; }

@media (max-width:900px) {
  .preset-form-grid { grid-template-columns:1fr 1fr; }
  .tg-form-grid { grid-template-columns:1fr 1fr; }
  .order-layout { grid-template-columns:1fr; }
  .prize-pool { grid-template-columns:1fr; }
}
</style>
</head>
<body>

<div class="topbar">
  <h1>🎩 СИҚЫРЛЫ БОКС — Админка</h1>
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
    <div class="badge">3 сессии × 9 призов · <?= count($presets) ?> назначенных билетов · <?= $pendingUserPrizes ?> TG призов</div>
    <a href="index.php" style="padding:6px 16px;background:rgba(212,160,23,0.18);border:1px solid rgba(212,160,23,0.4);border-radius:8px;color:#d4a017;font-size:0.8rem;text-decoration:none;letter-spacing:1px;white-space:nowrap;">⚙️ Админка</a>
    <a href="users.php" style="padding:6px 16px;background:rgba(212,160,23,0.08);border:1px solid rgba(212,160,23,0.25);border-radius:8px;color:#d4a017;font-size:0.8rem;text-decoration:none;letter-spacing:1px;white-space:nowrap;">👥 Пользователи</a>
    <a href="kese_stats.php" style="padding:6px 16px;background:rgba(91,155,213,0.12);border:1px solid rgba(91,155,213,0.35);border-radius:8px;color:#5b9bd5;font-size:0.8rem;text-decoration:none;letter-spacing:1px;white-space:nowrap;">📊 Статистика</a>
    <a href="?logout=1" style="padding:6px 16px;background:rgba(220,50,50,0.18);border:1px solid rgba(220,80,80,0.4);border-radius:8px;color:#ff8080;font-size:0.8rem;text-decoration:none;letter-spacing:1px;white-space:nowrap;" onclick="return confirm('Выйти?')">🔒 Выход</a>
  </div>
</div>

<div class="layout">
  <!-- Sidebar -->
  <nav class="sidebar">
    <div class="nav-section">ОСНОВНОЕ</div>
    <button class="nav-item" onclick="switchTab(0)">📊 Статистика</button>

    <div class="nav-section">ПРИЗЫ И РЕЖИМ</div>
    <?php for ($s = 1; $s <= 3; $s++):
      $mode = $sessionSettings[$s]['mode'];
      $pill = $mode === 'sequential' ? '<span class="mode-pill seq">ОЧЕРЕДЬ</span>' : '<span class="mode-pill prob">ВЕРОЯТН.</span>';
    ?>
    <button class="nav-item" onclick="switchTab(<?= $s ?>)">
      🎩 <?= $s ?>-я сессия <?= $pill ?>
    </button>
    <?php endfor; ?>

    <div class="nav-section">АРНАЙЫ</div>
    <button class="nav-item" onclick="switchTab(7)">
      📱 По Telegram ID
      <?php if ($pendingUserPrizes > 0): ?>
        <span class="mode-pill tg"><?= $pendingUserPrizes ?></span>
      <?php endif; ?>
    </button>
    <button class="nav-item" onclick="switchTab(6)">
      🎯 Назначенный билеттер
      <?php if (count($presets) > 0): ?>
        <span class="mode-pill seq"><?= count($presets) ?></span>
      <?php endif; ?>
    </button>

    <div class="nav-section">ТАРИХ</div>
    <button class="nav-item" onclick="switchTab(8)">
      ⏳ Не сыграли
      <?php if ($unusedUsersCount > 0): ?>
        <span class="mode-pill" style="background:rgba(255,80,80,0.2);color:#ff6060;"><?= $unusedUsersCount ?></span>
      <?php endif; ?>
    </button>
    <button class="nav-item" onclick="switchTab(4)">📋 Попытки</button>

    <div class="nav-section">БАПТАУЛАР</div>
    <button class="nav-item" onclick="switchTab(5)">⚠️ Опасная зона</button>
  </nav>

  <!-- Main -->
  <div class="main">

    <?php if ($inMsg): ?>
    <div class="flash <?= htmlspecialchars($inMsgType) ?>"><?= htmlspecialchars($inMsg) ?></div>
    <?php endif; ?>

    <!-- Stats Tab -->
    <div class="tab-panel" id="tab-0">
      <div class="stats-row">
        <div class="stat-card"><div class="num"><?= $totalAttempts ?></div><div class="lbl">Все попыток</div></div>
        <div class="stat-card"><div class="num"><?= $usedAttempts ?></div><div class="lbl">Использован</div></div>
        <div class="stat-card"><div class="num"><?= $totalAttempts - $usedAttempts ?></div><div class="lbl">Ожидает</div></div>
        <div class="stat-card"><div class="num"><?= count($presets) ?></div><div class="lbl">Назначенный билет</div></div>
        <div class="stat-card tg-card"><div class="num"><?= $pendingUserPrizes ?></div><div class="lbl">TG ID призов (ожидает)</div></div>
      </div>
      <div class="section">
        <h2>ПО СЕССИЯМ</h2>
        <?php for ($s = 1; $s <= 3; $s++):
          $mode = $sessionSettings[$s]['mode'];
          $orderCount = count($sessionSettings[$s]['prize_order']);
        ?>
        <div style="margin-bottom:10px;padding:14px;background:#1e1e1e;border-radius:6px;display:flex;justify-content:space-between;align-items:center;gap:16px;">
          <span style="color:#d4a017;font-weight:600;min-width:80px"><?= $s ?>-я сессия</span>
          <?php if ($mode === 'sequential'): ?>
            <span style="color:#ffa500;font-size:0.82rem">🔢 Последовательный режим — <?= $orderCount ?> призов назначено</span>
          <?php else: ?>
            <span style="color:#5cb85c;font-size:0.82rem">🎲 Режим вероятности</span>
          <?php endif; ?>
          <?php
          $sc = $pdo->prepare("SELECT COUNT(*) as t, SUM(used) as u FROM kese_attempts WHERE session_num=?");
          $sc->execute([$s]); $sr = $sc->fetch(PDO::FETCH_ASSOC);
          ?>
          <span style="color:#666;font-size:0.82rem">Попыток: <?= $sr['t']?:0 ?> | Исп.: <?= $sr['u']?:0 ?></span>
        </div>
        <?php endfor; ?>
      </div>
    </div>

    <!-- Session 1,2,3 Tabs -->
    <?php for ($s = 1; $s <= 3; $s++):
      $sMode   = $sessionSettings[$s]['mode'];
      $sOrder  = $sessionSettings[$s]['prize_order'];
      $sPrizes = $prizes[$s];
      $prizeMap = [];
      foreach ($sPrizes as $p) $prizeMap[$p['id']] = $p;
    ?>
    <div class="tab-panel" id="tab-<?= $s ?>">
      <div class="section">
        <div class="session-header">
          <h2><?= $s ?>-я: РЕЖИМ СЕССИИ</h2>
          <div class="current-mode-display <?= $sMode === 'sequential' ? 'seq' : 'prob' ?>">
            <?= $sMode === 'sequential' ? '🔢 РЕЖИМ ОЧЕРЕДИ' : '🎲 РЕЖИМ ВЕРОЯТНОСТИ' ?>
          </div>
        </div>

        <form method="POST" id="mode-form-<?= $s ?>">
          <input type="hidden" name="action" value="save_session_mode">
          <input type="hidden" name="session_num" value="<?= $s ?>">
          <input type="hidden" name="tab" value="<?= $s ?>">
          <input type="hidden" name="mode" id="mode-hidden-<?= $s ?>" value="<?= $sMode ?>">
          <input type="hidden" name="prize_order_json" id="order-json-<?= $s ?>" value="<?= htmlspecialchars(json_encode($sOrder)) ?>">

          <div class="mode-selector">
            <button type="button" class="mode-btn <?= $sMode === 'probability' ? 'active-prob' : '' ?>" id="btn-prob-<?= $s ?>" onclick="setMode(<?= $s ?>, 'probability')">🎲 По вероятности</button>
            <button type="button" class="mode-btn <?= $sMode === 'sequential' ? 'active-seq' : '' ?>"  id="btn-seq-<?= $s ?>"  onclick="setMode(<?= $s ?>, 'sequential')">🔢 По очереди</button>
          </div>

          <div class="mode-info prob" id="info-prob-<?= $s ?>" style="<?= $sMode === 'sequential' ? 'display:none' : '' ?>">
            <strong>🎲 Режим вероятности:</strong> Приз выбирается случайно согласно probability.
          </div>
          <div class="mode-info seq" id="info-seq-<?= $s ?>" style="<?= $sMode !== 'sequential' ? 'display:none' : '' ?>">
            <strong>🔢 Последовательный режим:</strong> Призы выдаются в заданном вами порядке. Когда список заканчивается, начинается сначала.
          </div>

          <div class="seq-builder <?= $sMode === 'sequential' ? 'visible' : '' ?>" id="seq-builder-<?= $s ?>">
            <h3>🔢 ЗАДАТЬ ОЧЕРЕДЬ ПРИЗОВ</h3>
            <p style="color:#888;font-size:0.82rem;margin-bottom:16px">Выберите приз слева и добавьте в список справа.</p>
            <div class="order-layout">
              <div class="prize-pool-panel">
                <h4>Доступные призы</h4>
                <div class="prize-pool" id="pool-<?= $s ?>">
                  <?php foreach ($sPrizes as $p): ?>
                  <div class="prize-chip" onclick="addToOrder(<?= $s ?>, <?= $p['id'] ?>, '<?= addslashes($p['name']) ?>', '<?= addslashes($p['emoji']) ?>')" data-prize-id="<?= $p['id'] ?>">
                    <span class="pem"><?php if ($p['img_data']): ?><img src="<?= htmlspecialchars($p['img_data']) ?>" style="width:28px;height:28px;border-radius:50%;object-fit:cover;"><?php else: ?><?= htmlspecialchars($p['emoji']) ?><?php endif; ?></span>
                    <span class="pname"><?= htmlspecialchars($p['name']) ?></span>
                  </div>
                  <?php endforeach; ?>
                </div>
              </div>
              <div class="order-list-panel">
                <h4>Очередь призов</h4>
                <div class="order-list" id="order-list-<?= $s ?>">
                  <?php if (empty($sOrder)): ?>
                  <div class="order-empty-hint" id="empty-hint-<?= $s ?>"><span class="icon">👈</span><span>Выберите приз</span></div>
                  <?php else: ?>
                    <?php foreach ($sOrder as $pos => $pid):
                      $pp = $prizeMap[$pid] ?? null;
                      if (!$pp) continue;
                    ?>
                    <div class="order-item" data-prize-id="<?= $pid ?>" draggable="true" ondragstart="dragStart(event)" ondragover="dragOver(event)" ondrop="dragDrop(event,<?= $s ?>)" ondragleave="dragLeave(event)">
                      <span class="pos-num"><?= $pos + 1 ?></span>
                      <span class="oem"><?php if ($pp['img_data']): ?><img src="<?= htmlspecialchars($pp['img_data']) ?>" style="width:24px;height:24px;border-radius:50%;object-fit:cover;"><?php else: ?><?= htmlspecialchars($pp['emoji']) ?><?php endif; ?></span>
                      <span class="oname"><?= htmlspecialchars($pp['name']) ?></span>
                      <span class="remove-btn" onclick="removeFromOrder(this, <?= $s ?>)">✕</span>
                    </div>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>
                <div class="order-actions">
                  <button type="button" class="btn btn-secondary btn-sm" onclick="clearOrder(<?= $s ?>)">🗑 Очистить список</button>
                </div>
              </div>
            </div>
            <div style="margin-top:16px;padding:12px 16px;background:rgba(255,165,0,0.06);border-radius:6px;border:1px solid rgba(255,165,0,0.15);">
              <span style="color:#ffa500;font-size:0.82rem">ℹ️ Призов в списке: <strong id="count-display-<?= $s ?>"><?= count($sOrder) ?></strong> дана.</span>
            </div>
          </div>

          <div style="margin-top:20px">
            <button type="submit" class="btn btn-orange" onclick="prepareSubmit(<?= $s ?>)">💾 Сохранить режим</button>
          </div>
        </form>
      </div>

      <div class="section">
        <h2><?= $s ?>-я: ПРИЗЫ СЕССИИ</h2>
        <div class="prizes-grid">
          <?php foreach ($sPrizes as $p): ?>
          <div class="prize-card">
            <div class="slot-label">#<?= $p['slot_num'] ?> · <?= $p['is_active'] ? '<span class="active-badge on">Активен</span>' : '<span class="active-badge off">Выключен</span>' ?><?= !empty($p['is_guide']) ? ' <span class="guide-badge">🎓 ГАЙД</span>' : '' ?></div>
            <div class="prize-preview-area">
              <div class="prize-thumb"><?php if ($p['img_data']): ?><img src="<?= htmlspecialchars($p['img_data']) ?>"><?php else: ?><?= htmlspecialchars($p['emoji']) ?><?php endif; ?></div>
              <div style="flex:1">
                <div style="color:#d4a017;font-weight:600;margin-bottom:2px"><?= htmlspecialchars($p['name']) ?></div>
                <div style="color:#666;font-size:0.8rem">Вероятность: <?= $p['probability'] ?></div>
              </div>
            </div>
            <form method="POST" enctype="multipart/form-data" class="prize-form">
              <input type="hidden" name="action" value="update_prize">
              <input type="hidden" name="prize_id" value="<?= $p['id'] ?>">
              <input type="hidden" name="tab" value="<?= $s ?>">
              <label>Приз атауы</label>
              <input type="text" name="name" value="<?= htmlspecialchars($p['name']) ?>" required>
              <label>Emoji</label>
              <input type="text" name="emoji" value="<?= htmlspecialchars($p['emoji']) ?>" maxlength="8">
              <label>Вероятность</label>
              <input type="number" name="probability" value="<?= $p['probability'] ?>" min="0" step="0.1">
              <div class="guide-check-row" onclick="this.querySelector('input').click(); return false;">
                <input type="checkbox" name="is_guide" id="guide_<?= $p['id'] ?>" <?= !empty($p['is_guide']) ? 'checked' : '' ?> onclick="event.stopPropagation()">
                <label for="guide_<?= $p['id'] ?>">🎓 Является гайдом (при выигрыше отправит видео-ссылку)</label>
              </div>
              <label>Загрузить изображение</label>
              <input type="file" name="prize_img" accept="image/*">
              <div class="form-actions">
                <button type="submit" class="btn btn-gold">💾 Сохранить</button>
              </div>
            </form>
            <div class="form-actions" style="margin-top:8px">
              <?php if ($p['img_data']): ?>
              <form method="POST">
                <input type="hidden" name="action" value="clear_img">
                <input type="hidden" name="prize_id" value="<?= $p['id'] ?>">
                <input type="hidden" name="tab" value="<?= $s ?>">
                <button type="submit" class="btn btn-danger">🗑 Удалить изображение</button>
              </form>
              <?php endif; ?>
              <form method="POST">
                <input type="hidden" name="action" value="toggle_active">
                <input type="hidden" name="prize_id" value="<?= $p['id'] ?>">
                <input type="hidden" name="tab" value="<?= $s ?>">
                <button type="submit" class="btn btn-secondary"><?= $p['is_active'] ? '🔕 Выключить' : '🔔 Включить' ?></button>
              </form>
            </div>
          </div>
          <?php endforeach; ?>
      </div><!-- /prizes section -->

      <!-- ─── ГАЙД ВИДЕО ─── -->
      <div class="section">
        <div class="guide-videos-block">
          <h3>🎓 Видео-ссылки для гайд-призов (<?= $s ?>-я сессия)</h3>
          <p>
            Вставьте ссылки на видео, каждая с новой строки.<br>
            При 1-м выигрыше гайд-приза → видео 1, при 2-м → видео 2, и т.д.<br>
            Если видео <?= $s === 1 ? '3' : 'N' ?>, а человек выигрывает в <?= $s === 1 ? '4' : 'N+1' ?>-й раз — отправляет по кругу с начала.
          </p>
          <form method="POST">
            <input type="hidden" name="action" value="save_guide_videos">
            <input type="hidden" name="session_num" value="<?= $s ?>">
            <textarea name="video_urls" placeholder="https://t.me/c/...&#10;https://t.me/c/...&#10;https://t.me/c/..."><?= htmlspecialchars($guideVideos[$s] ?? '') ?></textarea>
            <?php
              $videoList = array_values(array_filter(array_map('trim', explode("\n", $guideVideos[$s] ?? ''))));
              $guideCount = count($videoList);
            ?>
            <?php if ($guideCount > 0): ?>
            <div style="margin:8px 0;color:#29b6f6;font-size:0.8rem">✅ <?= $guideCount ?> видео сохранено</div>
            <?php endif; ?>
            <div style="margin-top:10px">
              <button type="submit" class="btn btn-tg">💾 Сохранить видео</button>
            </div>
          </form>
        </div>
      </div>

    </div><!-- /tab-panel -->
    <?php endfor; ?>

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- TELEGRAM ID PRIZE TAB (tab-7)                                       -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <div class="tab-panel" id="tab-7">

      <div class="section">
        <h2 class="tg">📱 НАЗНАЧЕНИЕ ПРИЗА ПО TELEGRAM ID</h2>

        <!-- Priority explanation -->
        <div class="priority-banner">
          <div class="icon">⚡</div>
          <div>
            <h3>Приоритет выдачи приза</h3>
            <p>
              <span class="priority-step">1</span> <strong style="color:#29b6f6">Telegram ID</strong> — эта страница. Назначение конкретного приза конкретному пользователю имеет наивысший приоритет.<br>
              <span class="priority-step two">2</span> <strong style="color:#ffa500">Назначенный билет</strong> — Специальный приз для N-й попытки.<br>
              <span class="priority-step three">3</span> <strong style="color:#888">Режим сессии</strong> — обычный выбор по вероятности или очереди.
            </p>
          </div>
        </div>

        <div class="tg-hint">
          <strong>📱 Для чего это?</strong> Пользователь с указанным Telegram ID при следующей игре <em>именно этот приз</em> получит.<br>
          <strong>🔁 Несколько призов:</strong> Одному пользователю можно добавить несколько призов — они выдаются по очереди (от старого к новому).<br>
          <strong>🆔 Где взять Telegram ID?</strong> Отправьте боту /id или узнайте через @userinfobot.
        </div>

        <!-- Add form -->
        <form method="POST" style="margin-bottom:28px;">
          <input type="hidden" name="action" value="add_user_prize">
          <div class="tg-form-grid">
            <div>
              <label>📱 Telegram ID</label>
              <input type="text" name="userid" placeholder="123456789" required pattern="[0-9]+" title="Тек сандар">
            </div>
            <div>
              <label>🏆 Выберите приз</label>
              <select name="prize_id" required>
                <option value="">— Выбрать —</option>
                <?php
                $prevSession = 0;
                foreach ($allPrizes as $p):
                  if ($p['session_num'] != $prevSession):
                    if ($prevSession > 0) echo '</optgroup>';
                    echo '<optgroup label="'.$p['session_num'].'-я сессия">';
                    $prevSession = $p['session_num'];
                  endif;
                ?>
                  <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['emoji'].' '.$p['name']) ?><?= !$p['is_active'] ? ' (выключен)' : '' ?></option>
                <?php endforeach; ?>
                <?php if ($prevSession > 0) echo '</optgroup>'; ?>
              </select>
            </div>
            <div>
              <label>📝 Заметка (необязательно)</label>
              <input type="text" name="note" placeholder="Например: приз VIP-клиента" maxlength="200">
            </div>
            <div style="display:flex;align-items:flex-end;">
              <button type="submit" class="btn btn-tg" style="width:100%">➕ Назначить</button>
            </div>
          </div>
        </form>

        <!-- Search -->
        <?php if (!empty($userPrizes)): ?>
        <div class="search-box">
          <span class="icon">🔍</span>
          <input type="text" id="up-search" placeholder="Поиск по Telegram ID или имени..." oninput="filterUserPrizes(this.value)">
        </div>
        <?php endif; ?>

        <!-- Table -->
        <?php if (empty($userPrizes)): ?>
        <div style="text-align:center;padding:50px;color:#444;">
          <div style="font-size:3.5rem;margin-bottom:12px">📱</div>
          <div style="font-size:0.95rem;color:#555;">Назначенных призов нет.</div>
          <div style="font-size:0.82rem;color:#444;margin-top:6px">Добавьте через форму выше.</div>
        </div>
        <?php else: ?>
        <table class="data-table" id="up-table">
          <thead>
            <tr>
              <th>Telegram ID</th>
              <th>ФИО</th>
              <th>Приз</th>
              <th>Статус</th>
              <th>Заметка</th>
              <th>Добавлен</th>
              <th>Дата использ.</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($userPrizes as $up): ?>
            <tr class="<?= $up['used'] ? 'used-row' : '' ?>" data-search="<?= htmlspecialchars(strtolower($up['userid'].' '.($up['user_name']??'').' '.($up['user_phone']??''))) ?>">
              <td>
                <span class="tg-id-badge">
                  <span class="tg-icon">📱</span>
                  <?= htmlspecialchars($up['userid']) ?>
                </span>
              </td>
              <td>
                <?php if ($up['user_name']): ?>
                  <div style="color:#ccc"><?= htmlspecialchars($up['user_name']) ?></div>
                  <div class="user-info-mini"><?= htmlspecialchars($up['user_phone'] ?? '') ?></div>
                <?php else: ?>
                  <span style="color:#444;font-size:0.78rem">Не зарегистр.</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="prize-mini">
                  <?php if ($up['prize_img']): ?>
                    <img src="<?= htmlspecialchars($up['prize_img']) ?>" alt="">
                  <?php else: ?>
                    <span style="font-size:1.5rem"><?= htmlspecialchars($up['prize_emoji']) ?></span>
                  <?php endif; ?>
                  <span><?= htmlspecialchars($up['prize_name']) ?></span>
                </div>
              </td>
              <td>
                <?php if ($up['used']): ?>
                  <span class="used-badge">✅ Использован</span>
                <?php else: ?>
                  <span class="unused-badge">⏳ Ожидает</span>
                <?php endif; ?>
              </td>
              <td class="note-text"><?= $up['note'] ? htmlspecialchars($up['note']) : '—' ?></td>
              <td style="color:#555;font-size:0.78rem"><?= date('d.m.Y H:i', strtotime($up['created_at'])) ?></td>
              <td style="color:#555;font-size:0.78rem"><?= $up['used_at'] ? date('d.m.Y H:i', strtotime($up['used_at'])) : '—' ?></td>
              <td>
                <?php if (!$up['used']): ?>
                <form method="POST" onsubmit="return confirm('Вы уверены, что хотите удалить?')">
                  <input type="hidden" name="action" value="delete_user_prize">
                  <input type="hidden" name="up_id" value="<?= $up['id'] ?>">
                  <button type="submit" class="btn btn-danger btn-sm">🗑</button>
                </form>
                <?php else: ?>
                  <span style="color:#2a2a2a">—</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div style="color:#555;font-size:0.78rem;margin-top:10px;text-align:right">
          Всего: <?= count($userPrizes) ?> жазба · Ожидает: <?= $pendingUserPrizes ?> · Использован: <?= count($userPrizes) - $pendingUserPrizes ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- Quick user lookup -->
      <div class="section">
        <h2 class="tg">🔎 ЗАРЕГИСТРИРОВАННЫЕ ПОЛЬЗОВАТЕЛИ</h2>
        <p style="color:#888;font-size:0.85rem;margin-bottom:16px">Найдите Telegram ID пользователя, чтобы назначить приз:</p>
        <?php
        $regUsers = $pdo->query("SELECT u.*, (SELECT COUNT(*) FROM kese_attempts a WHERE a.userid=u.userid) AS attempt_cnt FROM kese_users u ORDER BY u.id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <?php if (empty($regUsers)): ?>
          <div style="color:#444;font-size:0.85rem;padding:20px 0">Зарегистрированных пользователей нет.</div>
        <?php else: ?>
        <div class="search-box">
          <span class="icon">🔍</span>
          <input type="text" id="user-search" placeholder="Поиск по имени, телефону или ID..." oninput="filterUsers(this.value)">
        </div>
        <table class="data-table" id="user-table">
          <thead>
            <tr><th>Telegram ID</th><th>ФИО</th><th>Телефон</th><th>Попытка</th><th></th></tr>
          </thead>
          <tbody>
            <?php foreach ($regUsers as $u): ?>
            <tr data-search="<?= htmlspecialchars(strtolower($u['userid'].' '.$u['name'].' '.$u['surname'].' '.$u['phone'])) ?>">
              <td>
                <span class="tg-id-badge"><span class="tg-icon">📱</span><?= htmlspecialchars($u['userid']) ?></span>
              </td>
              <td><?= htmlspecialchars($u['name'].' '.$u['surname']) ?></td>
              <td style="color:#888"><?= htmlspecialchars($u['phone']) ?></td>
              <td style="color:#666"><?= $u['attempt_cnt'] ?></td>
              <td>
                <button class="btn btn-tg btn-sm" onclick="prefillUserId('<?= $u['userid'] ?>')">
                  🎁 Назначить приз
                </button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- PRESET PRIZES TAB (tab-6)                                          -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <div class="tab-panel" id="tab-6">

      <div class="section">
        <h2>🎯 НАЗНАЧЕННЫЕ БИЛЕТЫ</h2>

        <div class="preset-hint">
        </div>

        <form method="POST" style="margin-bottom:28px;">
          <input type="hidden" name="action" value="add_preset">
          <div class="preset-form-grid">
            <div>
              <label>Номер билета №</label>
              <input type="number" name="attempt_number" min="1" placeholder="100" required>
            </div>
            <div>
              <label>Выберите приз</label>
              <select name="prize_id" required>
                <option value="">— Выбрать —</option>
                <?php
                $prevSession = 0;
                foreach ($allPrizes as $p):
                  if ($p['session_num'] != $prevSession):
                    if ($prevSession > 0) echo '</optgroup>';
                    echo '<optgroup label="'.$p['session_num'].'-я сессия">';
                    $prevSession = $p['session_num'];
                  endif;
                ?>
                  <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['emoji'].' '.$p['name']) ?><?= !$p['is_active'] ? ' (выключен)' : '' ?></option>
                <?php endforeach; ?>
                <?php if ($prevSession > 0) echo '</optgroup>'; ?>
              </select>
            </div>
            <div>
              <label>Заметка (необязательно)</label>
              <input type="text" name="note" placeholder="Например: спец. приз для 100-го билета" maxlength="200">
            </div>
            <div style="display:flex;align-items:flex-end;">
              <button type="submit" class="btn btn-orange" style="width:100%">➕ Добавить / Обновить</button>
            </div>
          </div>
        </form>

        <?php if (empty($presets)): ?>
        <div style="text-align:center;padding:40px;color:#444;">
          <div style="font-size:3rem;margin-bottom:12px">🎯</div>
          <div style="font-size:0.9rem">Назначенных билетов нет. Добавьте сверху.</div>
        </div>
        <?php else: ?>
        <table class="presets-table">
          <thead>
            <tr>
              <th>Билет №</th><th>Статус</th><th>Приз</th><th>Сессия</th><th>Заметка</th><th>Добавлен</th><th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($presets as $preset):
              $isPast    = $preset['attempt_number'] < $nextAttemptNum;
              $isCurrent = $preset['attempt_number'] == $nextAttemptNum;
            ?>
            <tr>
              <td>
                <span class="ticket-badge <?= $isPast ? 'past' : ($isCurrent ? 'next' : '') ?>">
                  #<?= $preset['attempt_number'] ?>
                </span>
              </td>
              <td>
                <?php if ($isPast): ?>
                  <span class="status-badge status-passed">✓ пройдено</span>
                <?php elseif ($isCurrent): ?>
                  <span class="status-badge status-current">⚡ СЛЕДУЮЩИЙ</span>
                <?php else: ?>
                  <span class="status-badge status-upcoming">⏳ ожидает</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="prize-mini">
                  <?php if ($preset['prize_img']): ?>
                    <img src="<?= htmlspecialchars($preset['prize_img']) ?>" alt="">
                  <?php else: ?>
                    <span style="font-size:1.5rem"><?= htmlspecialchars($preset['prize_emoji']) ?></span>
                  <?php endif; ?>
                  <span><?= htmlspecialchars($preset['prize_name']) ?></span>
                </div>
              </td>
              <td style="color:#888"><?= $preset['prize_session'] ?>-я сессия</td>
              <td class="note-text"><?= $preset['note'] ? htmlspecialchars($preset['note']) : '—' ?></td>
              <td style="color:#555;font-size:0.78rem"><?= date('d.m.Y', strtotime($preset['created_at'])) ?></td>
              <td>
                <?php if (!$isPast): ?>
                <form method="POST" onsubmit="return confirm('Вы уверены, что хотите удалить?')">
                  <input type="hidden" name="action" value="delete_preset">
                  <input type="hidden" name="preset_id" value="<?= $preset['id'] ?>">
                  <button type="submit" class="btn btn-danger btn-sm">🗑 Удалить</button>
                </form>
                <?php else: ?>
                  <span style="color:#333;font-size:0.78rem">—</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>

      <div class="section">
        <h2>⚡ БЫСТРОЕ ДОБАВЛЕНИЕ</h2>
        <p style="color:#888;font-size:0.85rem;margin-bottom:16px">Быстрое назначение для часто используемых номеров:</p>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
          <?php
          $quickNums = [10050, 10100, 10200, 10500, 11000];
          foreach ($quickNums as $n):
            $isSet  = false;
            foreach ($presets as $pr) { if ($pr['attempt_number'] == $n) { $isSet = true; break; } }
            $isPast = $n < $nextAttemptNum;
          ?>
          <button
            class="btn btn-secondary"
            style="<?= $isPast ? 'opacity:0.4;' : '' ?><?= $isSet ? 'border-color:#ffa500;color:#ffa500;' : '' ?>"
            onclick="quickFill(<?= $n ?>)"
            <?= $isPast ? 'disabled title="Пройденная попытка"' : '' ?>>
            <?= $isSet ? '✓ ' : '' ?>#<?= $n ?>
          </button>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- TAB-8: Не сыграли (чек загружен, попытка не использована)          -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <div class="tab-panel" id="tab-8">
      <div class="section">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
          <h2 style="margin-bottom:0">⏳ ЧЕК ЖҮКТЕГЕН, БІРАқ ОЙНАМАҒАН</h2>
          <div style="display:flex;align-items:center;gap:12px;">
            <span style="color:#ff6060;font-size:0.85rem;">
              <?= $unusedUsersCount ?> пайдаланушы · <?= count($unusedRows) ?> попытка
            </span>
            <?php if ($unusedUsersCount > 0): ?>
            <button class="btn btn-orange" onclick="notifyAll()">
              📣 Барлығына жіберу
            </button>
            <?php endif; ?>
          </div>
        </div>

        <?php if (empty($unusedByUser)): ?>
          <div style="text-align:center;padding:40px;color:#555;">
            ✅ Барлығы ойнады — қалдық жоқ!
          </div>
        <?php else: ?>
        <table class="data-table">
          <thead>
            <tr>
              <th>User ID</th>
              <th>Аты-жөні</th>
              <th>Телефон</th>
              <th>Попыток</th>
              <th>Билет №</th>
              <th>Жасалған</th>
              <th>Жіберу</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($unusedByUser as $u): ?>
            <tr id="urow-<?= $u['userid'] ?>">
              <td>
                <span class="tg-id-badge"><?= htmlspecialchars($u['userid']) ?></span>
              </td>
              <td>
                <?php if ($u['uname']): ?>
                  <?= htmlspecialchars($u['uname'] . ' ' . $u['usurname']) ?>
                <?php else: ?>
                  <span style="color:#555">—</span>
                <?php endif; ?>
              </td>
              <td><?= $u['uphone'] ? htmlspecialchars($u['uphone']) : '<span style="color:#555">—</span>' ?></td>
              <td>
                <span style="background:rgba(255,96,96,0.15);color:#ff6060;padding:3px 10px;border-radius:10px;font-size:0.8rem;font-weight:600;">
                  <?= count($u['attempts']) ?>
                </span>
              </td>
              <td style="font-size:0.8rem;color:#888;">
                <?php foreach ($u['attempts'] as $a): ?>
                  <span style="margin-right:4px">#<?= $a['numeric_token'] ?></span>
                <?php endforeach; ?>
              </td>
              <td style="font-size:0.78rem;color:#666;">
                <?= date('d.m H:i', strtotime($u['attempts'][0]['created_at'])) ?>
              </td>
              <td>
                <button
                  class="btn btn-tg btn-sm notify-btn"
                  data-userid="<?= $u['userid'] ?>"
                  onclick="notifyUser('<?= $u['userid'] ?>', this)"
                  title="Ойын сілтемесін жіберу">
                  🎮 Жіберу
                </button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>

    <!-- Attempts Tab -->
    <div class="tab-panel" id="tab-4">
      <div class="section">
        <h2>ПОСЛЕДНИЕ 50 ПОПЫТОК</h2>
        <table class="data-table">
          <thead><tr><th>#</th><th>Билет №</th><th>User ID</th><th>Сессия</th><th>Приз</th><th>Статус</th><th>Дата</th><th>Использован</th></tr></thead>
          <tbody>
            <?php if (empty($recentAttempts)): ?><tr><td colspan="8" style="text-align:center;color:#555;padding:30px">Попыток нет</td></tr><?php endif; ?>
            <?php foreach ($recentAttempts as $a): ?>
            <tr>
              <td style="color:#555"><?= $a['id'] ?></td>
              <td><span class="ticket-badge">#<?= (int)$a['numeric_token'] ?></span></td>
              <td style="color:#d4a017"><?= $a['userid'] ?></td>
              <td><?= $a['session_num'] ?>-я сессия</td>
              <td>
                <div class="prize-mini">
                  <?php if ($a['prize_img']): ?><img src="<?= htmlspecialchars($a['prize_img']) ?>"><?php else: ?><span style="font-size:1.4rem"><?= htmlspecialchars($a['prize_emoji'] ?? '🎁') ?></span><?php endif; ?>
                  <span><?= htmlspecialchars($a['prize_name'] ?? '—') ?></span>
                </div>
              </td>
              <td><?= $a['used'] ? '<span class="badge-used">✅ Использован</span>' : '<span class="badge-unused">⏳ Ожидает</span>' ?></td>
              <td style="color:#666;font-size:0.8rem"><?= date('d.m.Y H:i', strtotime($a['created_at'])) ?></td>
              <td style="color:#666;font-size:0.8rem"><?= $a['used_at'] ? date('d.m.Y H:i', strtotime($a['used_at'])) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Danger Zone -->
    <div class="tab-panel" id="tab-5">
      <div class="section">
        <div class="danger-zone">
          <h3>⚠️ УДАЛИТЬ ВСЕ ПОПЫТКИ</h3>
          <p style="color:#888;margin-bottom:16px;font-size:0.9rem">Это удалит всю историю игр. Призы и режимы сохранятся.</p>
          <form method="POST">
            <input type="hidden" name="action" value="reset_attempts">
            <input type="hidden" name="tab" value="5">
            <label style="display:block;margin-bottom:8px;font-size:0.85rem;color:#888">Напишите "YES" для подтверждения:</label>
            <input type="text" name="confirm_reset" placeholder="YES" style="width:200px;padding:8px 12px;background:#1e1e1e;border:1px solid rgba(220,53,69,0.4);border-radius:4px;color:#dc3545;margin-bottom:12px">
            <br>
            <button type="submit" class="btn btn-danger">🗑 Удалить все попытки</button>
          </form>
        </div>
      </div>
    </div>

  </div><!-- .main -->
</div><!-- .layout -->

<script>
// ─── TAB SYSTEM ───────────────────────────────────────────────────────────────
const TAB_NAV_MAP = {0:0, 1:1, 2:2, 3:3, 7:4, 6:5, 8:6, 4:7, 5:8};

function switchTab(n) {
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(b => b.classList.remove('active'));
  const panel = document.getElementById('tab-'+n);
  if (panel) panel.classList.add('active');
  const idx = TAB_NAV_MAP[n];
  const items = document.querySelectorAll('.nav-item');
  if (items[idx]) items[idx].classList.add('active');
  history.replaceState(null, '', '?tab='+n);
}

// Activate correct tab on load
(function() {
  const active = <?= $activeTab ?>;
  switchTab(active);
})();

// ─── TG ID PREFILL ────────────────────────────────────────────────────────────
function prefillUserId(id) {
  switchTab(7);
  setTimeout(() => {
    const inp = document.querySelector('#tab-7 input[name="userid"]');
    if (inp) { inp.value = id; inp.focus(); inp.scrollIntoView({behavior:'smooth', block:'center'}); }
  }, 80);
}

// ─── USER PRIZE SEARCH ───────────────────────────────────────────────────────
function filterUserPrizes(q) {
  q = q.toLowerCase().trim();
  document.querySelectorAll('#up-table tbody tr').forEach(tr => {
    tr.style.display = (!q || tr.dataset.search.includes(q)) ? '' : 'none';
  });
}

function filterUsers(q) {
  q = q.toLowerCase().trim();
  document.querySelectorAll('#user-table tbody tr').forEach(tr => {
    tr.style.display = (!q || tr.dataset.search.includes(q)) ? '' : 'none';
  });
}

// ─── PRESET QUICK FILL ───────────────────────────────────────────────────────
function quickFill(num) {
  switchTab(6);
  setTimeout(() => {
    const inp = document.querySelector('#tab-6 input[name="attempt_number"]');
    if (inp) { inp.value = num; inp.focus(); inp.select(); }
  }, 50);
}

// ─── MODE TOGGLE ──────────────────────────────────────────────────────────────
function setMode(session, mode) {
  document.getElementById('mode-hidden-'+session).value = mode;
  const btnProb = document.getElementById('btn-prob-'+session);
  const btnSeq  = document.getElementById('btn-seq-'+session);
  btnProb.className = 'mode-btn' + (mode === 'probability' ? ' active-prob' : '');
  btnSeq.className  = 'mode-btn' + (mode === 'sequential'  ? ' active-seq'  : '');
  document.getElementById('info-prob-'+session).style.display = mode === 'probability' ? '' : 'none';
  document.getElementById('info-seq-'+session).style.display  = mode === 'sequential'  ? '' : 'none';
  document.getElementById('seq-builder-'+session).classList.toggle('visible', mode === 'sequential');
}

// ─── ORDER BUILDER ────────────────────────────────────────────────────────────
function addToOrder(session, prizeId, name, emoji) {
  const list = document.getElementById('order-list-'+session);
  const hint = document.getElementById('empty-hint-'+session);
  if (hint) hint.remove();
  const pos = list.querySelectorAll('.order-item').length + 1;
  const item = document.createElement('div');
  item.className = 'order-item';
  item.dataset.prizeId = prizeId;
  item.draggable = true;
  item.innerHTML = `<span class="pos-num">${pos}</span><span class="oem">${emoji}</span><span class="oname">${name}</span><span class="remove-btn" onclick="removeFromOrder(this, ${session})">✕</span>`;
  item.addEventListener('dragstart', dragStart);
  item.addEventListener('dragover',  dragOver);
  item.addEventListener('drop', (e) => dragDrop(e, session));
  item.addEventListener('dragleave', dragLeave);
  list.appendChild(item);
  updateOrderJson(session);
}

function removeFromOrder(btn, session) {
  btn.closest('.order-item').remove();
  updatePositions(session);
  updateOrderJson(session);
  const list = document.getElementById('order-list-'+session);
  if (list.querySelectorAll('.order-item').length === 0) {
    const hint = document.createElement('div');
    hint.className = 'order-empty-hint'; hint.id = 'empty-hint-'+session;
    hint.innerHTML = '<span class="icon">👈</span><span>Выберите приз</span>';
    list.appendChild(hint);
  }
}

function clearOrder(session) {
  document.getElementById('order-list-'+session).innerHTML = `<div class="order-empty-hint" id="empty-hint-${session}"><span class="icon">👈</span><span>Выберите приз</span></div>`;
  updateOrderJson(session);
}

function updatePositions(session) {
  document.querySelectorAll('#order-list-'+session+' .order-item').forEach((item, i) => {
    item.querySelector('.pos-num').textContent = i + 1;
  });
}

function updateOrderJson(session) {
  const items = document.querySelectorAll('#order-list-'+session+' .order-item');
  const ids = Array.from(items).map(item => parseInt(item.dataset.prizeId));
  document.getElementById('order-json-'+session).value = JSON.stringify(ids);
  const el = document.getElementById('count-display-'+session);
  if (el) el.textContent = ids.length;
}

function prepareSubmit(session) { updateOrderJson(session); }

// ─── DRAG & DROP ──────────────────────────────────────────────────────────────
let dragEl = null;
function dragStart(e) { dragEl = e.currentTarget; dragEl.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; }
function dragOver(e)  { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; const t = e.currentTarget; if (t !== dragEl && t.classList.contains('order-item')) t.classList.add('drag-over'); }
function dragLeave(e) { e.currentTarget.classList.remove('drag-over'); }
function dragDrop(e, session) {
  e.preventDefault();
  const target = e.currentTarget;
  target.classList.remove('drag-over');
  if (dragEl && target !== dragEl && target.classList.contains('order-item')) {
    const list = document.getElementById('order-list-'+session);
    const items = Array.from(list.querySelectorAll('.order-item'));
    const fromIdx = items.indexOf(dragEl), toIdx = items.indexOf(target);
    if (fromIdx < toIdx) list.insertBefore(dragEl, target.nextSibling);
    else                 list.insertBefore(dragEl, target);
    updatePositions(session);
    updateOrderJson(session);
  }
  if (dragEl) { dragEl.classList.remove('dragging'); dragEl = null; }
}
document.addEventListener('dragend', () => {
  if (dragEl) { dragEl.classList.remove('dragging'); dragEl = null; }
  document.querySelectorAll('.drag-over').forEach(el => el.classList.remove('drag-over'));
});

// ─── NOTIFY GAME (tab-8) ──────────────────────────────────────────────────────
function notifyUser(userId, btn) {
  btn.disabled = true;
  btn.textContent = '⏳';
  fetch('', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: 'ajax_notify_game=1&userid=' + encodeURIComponent(userId)
  })
  .then(r => r.json())
  .then(d => {
    if (d.success) {
      btn.textContent = '✅';
      btn.style.color = '#5cb85c';
      const row = document.getElementById('urow-' + userId);
      if (row) row.style.opacity = '0.45';
    } else {
      btn.textContent = '❌';
      btn.style.color = '#dc3545';
      btn.title = d.error || 'Ошибка';
      btn.disabled = false;
    }
  })
  .catch(() => { btn.textContent = '❌'; btn.disabled = false; });
}

function notifyAll() {
  const btns = document.querySelectorAll('.notify-btn:not([disabled])');
  if (!btns.length) return;
  if (!confirm('Барлық ' + btns.length + ' пайдаланушыға хабарлама жіберілсін бе?')) return;
  let i = 0;
  function next() {
    if (i >= btns.length) return;
    const b = btns[i++];
    const uid = b.dataset.userid;
    notifyUser(uid, b);
    setTimeout(next, 800); // 800ms задержка между отправками
  }
  next();
}
</script>
</body>
</html>

