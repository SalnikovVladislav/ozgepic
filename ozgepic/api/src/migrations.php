<?php
/**
 * Создание таблиц + миграции. Идемпотентно.
 */
function runMigrations(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo->exec("CREATE TABLE IF NOT EXISTS kese_prizes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_num TINYINT NOT NULL DEFAULT 1,
        slot_num TINYINT NOT NULL,
        name VARCHAR(255) NOT NULL DEFAULT '',
        img_data MEDIUMTEXT NULL,
        emoji VARCHAR(16) NOT NULL DEFAULT '',
        probability FLOAT NOT NULL DEFAULT 1,
        is_active TINYINT NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_session_slot (session_num, slot_num)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS kese_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        userid BIGINT NOT NULL,
        token VARCHAR(64) NOT NULL,
        numeric_token INT NOT NULL DEFAULT 0,
        transaction_number VARCHAR(100) NOT NULL,
        session_num TINYINT NOT NULL DEFAULT 1,
        prize_id INT NULL,
        used TINYINT NOT NULL DEFAULT 0,
        used_at DATETIME NULL,
        selected_box TINYINT NULL,
        box_selected_at DATETIME NULL,
        gift_sent TINYINT NOT NULL DEFAULT 0,
        gift_sent_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_token (token),
        UNIQUE KEY uq_tx (transaction_number),
        KEY idx_userid (userid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS kese_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        userid BIGINT NOT NULL,
        phone VARCHAR(200) NOT NULL DEFAULT '',
        name VARCHAR(255) NOT NULL DEFAULT '',
        surname VARCHAR(255) NOT NULL DEFAULT '',
        address TEXT NULL,
        postcode VARCHAR(16) NULL,
        city VARCHAR(100) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_userid (userid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Миграция: добавить postcode, если старая БД
    try { $pdo->exec("ALTER TABLE kese_users ADD COLUMN postcode VARCHAR(16) NULL AFTER address"); }
    catch (\PDOException $e) { /* уже есть */ }

    // Миграция: расширить phone/name/surname (могли быть слишком короткими)
    try { $pdo->exec("ALTER TABLE kese_users MODIFY COLUMN phone VARCHAR(200) NOT NULL DEFAULT ''"); }
    catch (\PDOException $e) { /* ignore */ }
    try { $pdo->exec("ALTER TABLE kese_users MODIFY COLUMN name VARCHAR(255) NOT NULL DEFAULT ''"); }
    catch (\PDOException $e) { /* ignore */ }
    try { $pdo->exec("ALTER TABLE kese_users MODIFY COLUMN surname VARCHAR(255) NOT NULL DEFAULT ''"); }
    catch (\PDOException $e) { /* ignore */ }

    $pdo->exec("CREATE TABLE IF NOT EXISTS kese_session_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_num TINYINT NOT NULL,
        mode ENUM('probability','sequential') NOT NULL DEFAULT 'probability',
        prize_order TEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_session (session_num)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS kese_preset_prizes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        attempt_number INT NOT NULL,
        prize_id INT NOT NULL,
        note VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_attempt_number (attempt_number),
        KEY idx_prize_id (prize_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS kese_user_prizes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        userid BIGINT NOT NULL,
        prize_id INT NOT NULL,
        note VARCHAR(255) NULL,
        used TINYINT NOT NULL DEFAULT 0,
        used_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_userid (userid),
        KEY idx_prize_id (prize_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    for ($s = 1; $s <= 3; $s++) {
        $pdo->prepare("INSERT IGNORE INTO kese_session_settings (session_num, mode) VALUES (?, 'probability')")
            ->execute([$s]);
    }

    $count = (int)$pdo->query("SELECT COUNT(*) FROM kese_prizes")->fetchColumn();
    if ($count === 0) {
        $emojis = ['🥇','💎','🎁','🏆','⭐','🎯','🌟','🎊','🔮','🥈','🎖','🎀','🏅','💫','🎪','🌈','🎭','🎨','🥉','💝','🎗','🌺','✨','🎡','🎠','🎢','🎆'];
        $ins = $pdo->prepare("INSERT IGNORE INTO kese_prizes (session_num, slot_num, name, emoji) VALUES (?,?,?,?)");
        $idx = 0;
        for ($s = 1; $s <= 3; $s++) {
            for ($sl = 1; $sl <= 9; $sl++) {
                $ins->execute([$s, $sl, ($idx + 1) . '-жүлде', $emojis[$idx]]); $idx++;
            }
        }
    }
}

