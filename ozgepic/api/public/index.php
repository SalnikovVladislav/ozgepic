<?php
/**
 * Единый роутер приватного API.
 *   GET  /health           — пинг
 *   *    /kese              → src/endpoints/kese.php   (бизнес-логика)
 *   GET  /export           → src/endpoints/export.php  (дамп для Google Sheets)
 *
 * Все действия, кроме белого списка ($PUBLIC_ACTIONS), требуют заголовок
 *   X-API-Token: <API_INTERNAL_TOKEN>
 */

require_once __DIR__ . '/../src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$uri  = strtok($_SERVER['REQUEST_URI'], '?');
$path = rtrim($uri, '/') ?: '/';

// Health-check (без токена)
if ($path === '/health' || $path === '/') {
    echo json_encode(['ok' => true, 'service' => 'kese-api', 'time' => date('c')]);
    exit;
}

// Действия, разрешённые публично (через site/public/api/public.php прокси) —
// только они попадают сюда без X-API-Token
$PUBLIC_ACTIONS = ['check_token', 'get_session_prizes', 'reveal'];

$input  = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? ($_GET['action'] ?? '');

$providedToken = $_SERVER['HTTP_X_API_TOKEN'] ?? '';
$expectedToken = config('API_INTERNAL_TOKEN');

$isPublicAction = in_array($action, $PUBLIC_ACTIONS, true);
$tokenOk        = $expectedToken !== '' && hash_equals($expectedToken, $providedToken);

if (!$isPublicAction && !$tokenOk) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized (X-API-Token missing or invalid)']);
    exit;
}

if ($path === '/kese') {
    require __DIR__ . '/../src/endpoints/kese.php';
    exit;
}
if ($path === '/export') {
    require __DIR__ . '/../src/endpoints/export.php';
    exit;
}

http_response_code(404);
echo json_encode(['success' => false, 'message' => 'Not found: ' . $path]);

