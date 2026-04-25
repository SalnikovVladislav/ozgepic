<?php
/**
 * Единая точка инициализации: env → config → PDO → логгер.
 */

function config(string $key, $default = null) {
    static $cache = null;
    if ($cache === null) {
        $cache = [
            'DB_HOST'            => getenv('DB_HOST') ?: 'db',
            'DB_PORT'            => getenv('DB_PORT') ?: '3306',
            'DB_NAME'            => getenv('DB_NAME') ?: 'kese',
            'DB_USER'            => getenv('DB_USER') ?: 'kese',
            'DB_PASSWORD'        => getenv('DB_PASSWORD') ?: '',
            'API_INTERNAL_TOKEN' => getenv('API_INTERNAL_TOKEN') ?: '',
            'TELEGRAM_BOT_TOKEN' => getenv('TELEGRAM_BOT_TOKEN') ?: '',
            'SITE_PUBLIC_URL'    => rtrim(getenv('SITE_PUBLIC_URL') ?: '', '/'),
            'LOG_FILE'           => '/var/log/kese/kese.log',
        ];
    }
    return $cache[$key] ?? $default;
}

function logMsg(string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    @file_put_contents(config('LOG_FILE'), $line, FILE_APPEND);
}

set_exception_handler(function ($e) {
    logMsg('UNCAUGHT: ' . $e->getMessage());
    if (!headers_sent()) http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    exit;
});
set_error_handler(function ($no, $str, $file, $line) {
    logMsg("PHP ERROR [$no]: $str in $file:$line");
    return false;
});

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        config('DB_HOST'), config('DB_PORT'), config('DB_NAME')
    );
    $pdo = new PDO($dsn, config('DB_USER'), config('DB_PASSWORD'), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("SET NAMES utf8mb4");
    require_once __DIR__ . '/migrations.php';
    runMigrations($pdo);
    return $pdo;
}

