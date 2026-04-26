<?php
/**
 * Бизнес-действия Kese — адаптировано из старого kese_api.php.
 * Все action-ы принимаются через JSON body { "action": "...", ... }.
 * Публичные: check_token, get_session_prizes, reveal (всё остальное — только с X-API-Token).
 */

$pdo = db();

// ─── HELPERS ────────────────────────────────────────────────────────────────
function getSessionSettings(PDO $pdo, int $session): array {
    $stmt = $pdo->prepare("SELECT * FROM kese_session_settings WHERE session_num = ?");
    $stmt->execute([$session]);
    $row = $stmt->fetch();
    if (!$row) return ['mode' => 'probability', 'prize_order' => [], 'queue_mode' => 'personal'];
    $order = !empty($row['prize_order']) ? (json_decode($row['prize_order'], true) ?: []) : [];
    return ['mode' => $row['mode'], 'prize_order' => $order, 'queue_mode' => $row['queue_mode'] ?? 'personal'];
}

function getNextGlobalAttemptNumber(PDO $pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM kese_attempts")->fetchColumn() + 1;
}

function getPresetPrize(PDO $pdo, int $attemptNumber) {
    $stmt = $pdo->prepare("
        SELECT pp.*, p.name AS prize_name, p.emoji AS prize_emoji, p.img_data AS prize_img,
               p.is_active, p.session_num AS prize_session
        FROM kese_preset_prizes pp
        JOIN kese_prizes p ON p.id = pp.prize_id
        WHERE pp.attempt_number = ? AND p.is_active = 1");
    $stmt->execute([$attemptNumber]);
    return $stmt->fetch() ?: null;
}

function getUserPrize(PDO $pdo, int $userid) {
    $stmt = $pdo->prepare("
        SELECT up.*, p.name AS prize_name, p.emoji AS prize_emoji, p.img_data AS prize_img,
               p.is_active
        FROM kese_user_prizes up
        JOIN kese_prizes p ON p.id = up.prize_id
        WHERE up.userid = ? AND up.used = 0 AND p.is_active = 1
        ORDER BY up.id ASC
        LIMIT 1");
    $stmt->execute([$userid]);
    return $stmt->fetch() ?: null;
}

function notifyBotPrize(int $userId, string $prizeName, string $prizeEmoji, ?string $prizeImg, ?string $guideVideoUrl = null): void {
    $botToken = config('TELEGRAM_BOT_TOKEN');
    if (!$botToken) return;

    $text = $prizeEmoji . ' <b>Құттықтаймын!</b>' . "\n\n"
          . '🏆 Сіздің жүлдеңіз: <b>' . htmlspecialchars($prizeName) . '</b>';

    if ($guideVideoUrl) {
        $text .= "\n\n🎓 <b>Гайд:</b> " . htmlspecialchars($guideVideoUrl);
    }

    if ($prizeImg) {
        $imgData = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $prizeImg));
        $tmp = tempnam(sys_get_temp_dir(), 'prize_') . '.jpg';
        file_put_contents($tmp, $imgData);
        $ch = curl_init("https://api.telegram.org/bot{$botToken}/sendPhoto");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                'chat_id'    => $userId,
                'photo'      => new CURLFile($tmp, 'image/jpeg', 'prize.jpg'),
                'caption'    => $text,
                'parse_mode' => 'HTML',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        curl_exec($ch); curl_close($ch); @unlink($tmp);
    } else {
        $ch = curl_init("https://api.telegram.org/bot{$botToken}/sendMessage");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'chat_id' => $userId, 'text' => $text, 'parse_mode' => 'HTML',
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        curl_exec($ch); curl_close($ch);
    }
}

// ─── DISPATCH ───────────────────────────────────────────────────────────────
// $input & $action уже определены в public/index.php
logMsg("ACTION: $action");

switch ($action) {
    // ─── PUBLIC (kese.php frontend) ──────────────────────────────────────────
    case 'check_token': {
        $token = trim($input['token'] ?? '');
        if (!$token) { echo json_encode(['success'=>false,'message'=>'Token жоқ']); return; }
        $s = $pdo->prepare("SELECT * FROM kese_attempts WHERE token = ?");
        $s->execute([$token]);
        $a = $s->fetch();
        if (!$a) { echo json_encode(['success'=>false,'message'=>'Token жарамсыз']); return; }
        if ((int)$a['used'] === 1) { echo json_encode(['success'=>false,'message'=>'Бұл мүмкіндік пайдаланылған']); return; }
        echo json_encode([
            'success'=>true,'userid'=>$a['userid'],'session_num'=>$a['session_num'],
            'prize_id'=>$a['prize_id'],'attempt_id'=>$a['id']
        ]);
        return;
    }

    case 'get_session_prizes': {
        $session = (int)($input['session'] ?? 1);
        if ($session < 1 || $session > 3) { echo json_encode(['success'=>false,'message'=>'Сессия 1-3']); return; }
        $s = $pdo->prepare("SELECT * FROM kese_prizes WHERE session_num=? AND is_active=1 ORDER BY slot_num");
        $s->execute([$session]);
        echo json_encode(['success'=>true,'prizes'=>$s->fetchAll()]);
        return;
    }

    case 'reveal': {
        $token = trim($input['token'] ?? '');
        if (!$token) { echo json_encode(['success'=>false,'message'=>'Token жоқ']); return; }
        $s = $pdo->prepare("SELECT * FROM kese_attempts WHERE token = ?");
        $s->execute([$token]);
        $a = $s->fetch();
        if (!$a) { echo json_encode(['success'=>false,'message'=>'Token жарамсыз']); return; }
        if ((int)$a['used'] === 1) { echo json_encode(['success'=>false,'message'=>'Бұл мүмкіндік пайдаланылған']); return; }
        $s2 = $pdo->prepare("SELECT * FROM kese_prizes WHERE id = ?");
        $s2->execute([$a['prize_id']]);
        $p = $s2->fetch();
        if (!$p) { echo json_encode(['success'=>false,'message'=>'Жүлде табылмады']); return; }

        // ─── Guide video logic ────────────────────────────────────────────
        $guideVideoUrl = null;
        if (!empty($p['is_guide'])) {
            // Count guide wins BEFORE this one — globally across ALL sessions
            $gc = $pdo->prepare("
                SELECT COUNT(*) FROM kese_attempts a
                JOIN kese_prizes p2 ON p2.id = a.prize_id
                WHERE a.userid = ? AND a.used = 1 AND p2.is_guide = 1
            ");
            $gc->execute([$a['userid']]);
            $guideWinCount = (int)$gc->fetchColumn();

            // Global video list (session_num=0)
            $gvs = $pdo->prepare("SELECT video_urls FROM kese_session_guide_videos WHERE session_num = 0");
            $gvs->execute();
            $gvRow = $gvs->fetch();
            if ($gvRow && !empty(trim($gvRow['video_urls']))) {
                $videos = array_values(array_filter(array_map('trim', explode("\n", $gvRow['video_urls']))));
                if (!empty($videos)) {
                    $idx = $guideWinCount % count($videos);
                    $guideVideoUrl = $videos[$idx];
                }
            }
        }

        $pdo->prepare("UPDATE kese_attempts SET used=1, used_at=NOW() WHERE id=?")->execute([$a['id']]);
        notifyBotPrize((int)$a['userid'], $p['name'], $p['emoji'], $p['img_data'], $guideVideoUrl);
        echo json_encode([
            'success'=>true,'prize_id'=>$p['id'],'prize_name'=>$p['name'],
            'prize_img'=>$p['img_data'],'prize_emoji'=>$p['emoji']
        ]);
        return;
    }

    // ─── BOT-only ────────────────────────────────────────────────────────────
    case 'check_user': {
        $userid = (int)($input['userid'] ?? 0);
        if (!$userid) { echo json_encode(['success'=>false,'message'=>'userid жоқ']); return; }
        $s = $pdo->prepare("SELECT id FROM kese_users WHERE userid = ?");
        $s->execute([$userid]);
        echo json_encode(['success'=>true,'exists'=>(bool)$s->fetch()]);
        return;
    }

    case 'register': {
        $userid  = (int)($input['userid']  ?? 0);
        $phone   = trim($input['phone']   ?? '');
        $name    = trim($input['name']    ?? '');
        $surname = trim($input['surname'] ?? '');
        $address = trim($input['address'] ?? '');
        $postcode= trim($input['postcode']?? '');
        if (!$userid || !$phone || !$name) { echo json_encode(['success'=>false,'message'=>'Деректер жеткіліксіз']); return; }
        $s = $pdo->prepare("SELECT id FROM kese_users WHERE userid = ?");
        $s->execute([$userid]);
        if ($s->fetch()) { echo json_encode(['success'=>true,'already_exists'=>true]); return; }
        $pdo->prepare("INSERT INTO kese_users (userid,phone,name,surname,address,postcode) VALUES (?,?,?,?,?,?)")
            ->execute([$userid,$phone,$name,$surname,$address,$postcode]);
        echo json_encode(['success'=>true,'user_id'=>(int)$pdo->lastInsertId()]);
        return;
    }

    case 'create_attempt': {
        $userid   = (int)($input['userid'] ?? 0);
        $txNumber = trim($input['transaction_number'] ?? '');
        if (!$userid || !$txNumber) { echo json_encode(['success'=>false,'message'=>'userid/tx жоқ']); return; }

        $ct = $pdo->prepare("SELECT COUNT(*) FROM kese_attempts WHERE userid = ?");
        $ct->execute([$userid]);
        $session = ((int)$ct->fetchColumn() % 3) + 1;

        $chk = $pdo->prepare("SELECT id FROM kese_attempts WHERE transaction_number = ?");
        $chk->execute([$txNumber]);
        if ($chk->fetch()) { echo json_encode(['success'=>false,'message'=>'Бұл транзакция бұрын қолданылған']); return; }

        // Будущий номер билета (numeric_token). Используется и для выдачи, и для поиска preset.
        $row          = $pdo->query("SELECT COALESCE(MAX(numeric_token),10000)+1 AS nt FROM kese_attempts")->fetch();
        $numericToken = (int)$row['nt'];

        $selected = null; $userPrizeId = null; $preset = null;

        // 1) user-specific
        $up = getUserPrize($pdo, $userid);
        if ($up) {
            $s = $pdo->prepare("SELECT * FROM kese_prizes WHERE id = ? AND is_active = 1");
            $s->execute([$up['prize_id']]);
            $selected = $s->fetch() ?: null;
            if ($selected) $userPrizeId = $up['id'];
        }
        // 2) preset по номеру билета (numeric_token), который увидит пользователь в админке
        if (!$selected) {
            $preset = getPresetPrize($pdo, $numericToken);
            if ($preset) {
                $s = $pdo->prepare("SELECT * FROM kese_prizes WHERE id = ? AND is_active = 1");
                $s->execute([$preset['prize_id']]);
                $selected = $s->fetch() ?: null;
            }
        }
        // 3) normal
        if (!$selected) {
            $ss = getSessionSettings($pdo, $session);
            if ($ss['mode'] === 'sequential' && !empty($ss['prize_order'])) {
                if (($ss['queue_mode'] ?? 'personal') === 'global') {
                    // Общая очередь — счётчик по всем попыткам в этой сессии
                    $pc = $pdo->prepare("SELECT COUNT(*) FROM kese_attempts WHERE session_num = ?");
                    $pc->execute([$session]);
                } else {
                    // Персональная очередь — счётчик только для этого пользователя
                    $pc = $pdo->prepare("SELECT COUNT(*) FROM kese_attempts WHERE userid = ? AND session_num = ?");
                    $pc->execute([$userid, $session]);
                }
                $idx = ((int)$pc->fetchColumn()) % count($ss['prize_order']);
                $s = $pdo->prepare("SELECT * FROM kese_prizes WHERE id = ? AND is_active = 1");
                $s->execute([$ss['prize_order'][$idx]]);
                $selected = $s->fetch() ?: null;
            }
            if (!$selected) {
                $s = $pdo->prepare("SELECT * FROM kese_prizes WHERE session_num=? AND is_active=1 AND probability>0 ORDER BY slot_num");
                $s->execute([$session]);
                $prizes = $s->fetchAll();
                if (empty($prizes)) { echo json_encode(['success'=>false,'message'=>'Жүлде табылмады']); return; }
                $total = array_sum(array_column($prizes, 'probability'));
                $rand  = (mt_rand() / mt_getrandmax()) * $total;
                $cur = 0;
                foreach ($prizes as $p) {
                    $cur += (float)$p['probability'];
                    if ($rand <= $cur) { $selected = $p; break; }
                }
                if (!$selected) $selected = $prizes[0];
            }
        }

        $token        = bin2hex(random_bytes(16));
        $siteUrl      = config('SITE_PUBLIC_URL');
        $gameUrl      = $siteUrl ? ($siteUrl . "/kese.php?token=$token") : "/kese.php?token=$token";

        $pdo->prepare("INSERT INTO kese_attempts (userid,token,numeric_token,transaction_number,session_num,prize_id) VALUES (?,?,?,?,?,?)")
            ->execute([$userid, $token, $numericToken, $txNumber, $session, $selected['id']]);
        $attemptId = (int)$pdo->lastInsertId();

        if ($userPrizeId) {
            $pdo->prepare("UPDATE kese_user_prizes SET used=1, used_at=NOW() WHERE id=?")->execute([$userPrizeId]);
        }

        echo json_encode([
            'success'=>true,'wheel_url'=>$gameUrl,'numeric_token'=>$numericToken,
            'attempt_id'=>$attemptId,
            'is_user_prize'=>!empty($userPrizeId),
            'is_preset'=>!empty($preset),
        ]);
        return;
    }

    case 'stats': {
        $total = (int)$pdo->query("SELECT COUNT(*) FROM kese_attempts")->fetchColumn();
        $used  = (int)$pdo->query("SELECT COUNT(*) FROM kese_attempts WHERE used=1")->fetchColumn();
        $bySession = $pdo->query("SELECT session_num, COUNT(*) AS cnt, SUM(used) AS used_cnt FROM kese_attempts GROUP BY session_num ORDER BY session_num")->fetchAll();
        echo json_encode(['success'=>true,'total'=>$total,'used'=>$used,'by_session'=>$bySession]);
        return;
    }

    default:
        logMsg("UNKNOWN ACTION: $action");
        echo json_encode(['success'=>false,'message'=>'Белгісіз action: ' . htmlspecialchars($action)]);
}

