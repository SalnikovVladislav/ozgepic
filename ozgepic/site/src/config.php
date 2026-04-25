<?php
/**
 * Глобальная конфигурация сайта. Читает env.
 */
function site_config(string $k, $default = null) {
    static $c = null;
    if ($c === null) {
        $c = [
            'DB_HOST'            => getenv('DB_HOST') ?: 'db',
            'DB_PORT'            => getenv('DB_PORT') ?: '3306',
            'DB_NAME'            => getenv('DB_NAME') ?: 'kese',
            'DB_USER'            => getenv('DB_USER') ?: 'kese',
            'DB_PASSWORD'        => getenv('DB_PASSWORD') ?: '',
            'API_INTERNAL_URL'   => rtrim(getenv('API_INTERNAL_URL') ?: 'http://api/kese', '/'),
            'API_INTERNAL_TOKEN' => getenv('API_INTERNAL_TOKEN') ?: '',
            'BOT_WEBHOOK_URL'    => rtrim(getenv('BOT_WEBHOOK_URL') ?: 'http://bot:3007', '/'),
            'SITE_PUBLIC_URL'    => rtrim(getenv('SITE_PUBLIC_URL') ?: '', '/'),
            'ADMIN_PASSWORD'     => getenv('ADMIN_PASSWORD') ?: 'change_me',
            'ADMIN_ALLOWED_IPS'  => array_filter(array_map('trim',
                                        explode(',', getenv('ADMIN_ALLOWED_IPS') ?: ''))),
            'ADMIN_IP_ONLY'      => getenv('ADMIN_IP_ONLY') === '1',
            'ASSETS_IMG_PATH'    => '/assets/images',
        ];
    }
    return $c[$k] ?? $default;
}

/** PDO для админки (только внутри docker-сети к mysql) */
function site_db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        site_config('DB_HOST'), site_config('DB_PORT'), site_config('DB_NAME'));
    $pdo = new PDO($dsn, site_config('DB_USER'), site_config('DB_PASSWORD'), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("SET NAMES utf8mb4");
    return $pdo;
}

