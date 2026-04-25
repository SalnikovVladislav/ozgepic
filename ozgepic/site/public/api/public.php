<?php
/**
 * Публичный прокси к приватному API.
 * Пропускает ТОЛЬКО белый список action-ов — остальное блокируется.
 * JS из kese.php стучится именно сюда.
 */
require_once __DIR__ . '/../../src/config.php';

header('Content-Type: application/json; charset=utf-8');

$ALLOWED = ['check_token', 'get_session_prizes', 'reveal'];

$raw    = file_get_contents('php://input');
$input  = json_decode($raw, true) ?: [];
$action = $input['action'] ?? '';

if (!in_array($action, $ALLOWED, true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden action']);
    exit;
}

$ch = curl_init(site_config('API_INTERNAL_URL'));
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $raw,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'X-API-Token: ' . site_config('API_INTERNAL_TOKEN'),
    ],
]);
$response = curl_exec($ch);
$code     = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 502;
$err      = curl_error($ch);
curl_close($ch);

if ($response === false) {
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => 'API unreachable: ' . $err]);
    exit;
}
http_response_code($code);
echo $response;

