<?php
// kese_admin.php — УСТАРЕЛО. Единая админка теперь на /admin/ (index.php)
$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: index.php' . ($qs ? '?' . $qs : ''), true, 301);
exit;
