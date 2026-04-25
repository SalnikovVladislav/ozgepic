<?php
/**
 * auth.php — Единая авторизация для всех admin-страниц.
 * Пароль и список IP — из env.
 */
require_once __DIR__ . '/config.php';

define('KESE_SESSION_NAME', 'kese_adm');
session_name(KESE_SESSION_NAME);
session_start();

function kese_get_real_ip(): string {
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_REAL_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = trim(explode(',', $_SERVER[$h])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

function kese_ip_in_cidr(string $ip, string $cidr): bool {
    if (!str_contains($cidr, '/')) return $ip === $cidr;
    [$subnet, $bits] = explode('/', $cidr, 2);
    $bits = (int)$bits;
    if (str_contains($ip, ':')) {
        $ipBin = inet_pton($ip); $sBin = inet_pton($subnet);
        if ($ipBin === false || $sBin === false) return false;
        $byteCount = (int)ceil($bits / 8);
        $lastByte = $bits % 8;
        for ($i = 0; $i < $byteCount - ($lastByte ? 1 : 0); $i++)
            if ($ipBin[$i] !== $sBin[$i]) return false;
        if ($lastByte) {
            $mask = 0xFF & (0xFF << (8 - $lastByte));
            if ((ord($ipBin[$byteCount-1]) & $mask) !== (ord($sBin[$byteCount-1]) & $mask)) return false;
        }
        return true;
    }
    $ipLong = ip2long($ip); $sLong = ip2long($subnet);
    if ($ipLong === false || $sLong === false) return false;
    $mask = $bits === 0 ? 0 : (~0 << (32 - $bits));
    return ($ipLong & $mask) === ($sLong & $mask);
}

function kese_ip_is_allowed(string $ip): bool {
    $list = site_config('ADMIN_ALLOWED_IPS');
    if (empty($list)) return false;
    foreach ($list as $entry) if (kese_ip_in_cidr($ip, $entry)) return true;
    return false;
}

if (isset($_GET['logout'])) {
    $_SESSION = []; session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

$clientIp  = kese_get_real_ip();
$ipAllowed = kese_ip_is_allowed($clientIp);
$sessionOk = !empty($_SESSION['kese_admin_ok']);

if ($sessionOk || $ipAllowed) {
    $_SESSION['kese_admin_ip'] = $clientIp;
    return;
}

if (site_config('ADMIN_IP_ONLY')) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body style="background:#0a0604;color:#d4a017;font-family:sans-serif;text-align:center;padding:80px">'
       . '<h1>🔒 403 — ДОСТУП ЗАПРЕЩЁН</h1><p>IP: <code>' . htmlspecialchars($clientIp) . '</code></p></body></html>';
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kese_pass'])) {
    if (hash_equals((string)site_config('ADMIN_PASSWORD'), (string)$_POST['kese_pass'])) {
        session_regenerate_id(true);
        $_SESSION['kese_admin_ok'] = true;
        $_SESSION['kese_admin_ip'] = $clientIp;
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
    $error = 'Неверный пароль / Неверный пароль';
}
?>
<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>СИҚЫРЛЫ БОКС — Вход</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0a0604;font-family:Arial,sans-serif}
.card{width:380px;max-width:95%;padding:40px 36px;background:linear-gradient(160deg,#1a1000,#120c02);border:1px solid rgba(212,160,23,0.35);border-radius:20px;text-align:center;color:#f5e8c0}
h1{color:#d4a017;font-size:1.35rem;letter-spacing:2px;margin-bottom:6px}
.sub{font-size:.75rem;color:rgba(212,160,23,.45);letter-spacing:1.5px;margin-bottom:12px}
.ip{font-size:.7rem;color:rgba(212,160,23,.3);margin-bottom:20px;font-family:monospace}
input{width:100%;padding:13px 16px;margin-bottom:20px;background:rgba(255,255,255,.04);border:1px solid rgba(212,160,23,.25);border-radius:10px;color:#f5e8c0;font-size:1rem;outline:none}
input:focus{border-color:rgba(212,160,23,.65)}
button{width:100%;padding:14px;background:linear-gradient(135deg,#c8920a,#f0c040);border:none;border-radius:10px;color:#1a0c00;font-weight:700;letter-spacing:2.5px;cursor:pointer;text-transform:uppercase}
.err{margin-top:16px;padding:10px;background:rgba(220,50,50,.12);border:1px solid rgba(220,80,80,.3);border-radius:8px;color:#ff8080;font-size:.85rem}
</style></head><body><div class="card">
<div style="font-size:2.8rem">🏆</div>
<h1>СИҚЫРЛЫ БОКС</h1><div class="sub">ADMIN PANEL</div>
<div class="ip">IP: <?= htmlspecialchars($clientIp) ?></div>
<form method="POST" autocomplete="off">
<input type="password" name="kese_pass" placeholder="••••••••••" autofocus required>
<button type="submit">Войти</button>
</form>
<?php if ($error): ?><div class="err">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>
</div></body></html>
<?php exit;

