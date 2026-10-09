<?php
declare(strict_types=1);

$settingsFile = __DIR__ . '/config.local.php';
if (!is_file($settingsFile)) {
    fwrite(STDERR, "Missing config.local.php. Configure the local database settings first.\n");
    exit(1);
}

$settings = require $settingsFile;
$dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $settings['host'], $settings['port']);
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
];
$databaseName = str_replace('`', '``', $settings['database']);

try {
    $server = new PDO($dsn, $settings['username'], $settings['password'], $options);
    $server->exec("CREATE DATABASE IF NOT EXISTS `{$databaseName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo = new PDO(
        $dsn . ';dbname=' . rawurlencode($settings['database']),
        $settings['username'],
        $settings['password'],
        $options
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS rooms (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            code CHAR(4) NOT NULL UNIQUE,
            name VARCHAR(80) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            closed_at TIMESTAMP NULL DEFAULT NULL,
            INDEX idx_rooms_closed (closed_at)
        ) ENGINE=InnoDB"
    );
    $column = $pdo->query(
        "SELECT CHARACTER_MAXIMUM_LENGTH
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rooms' AND COLUMN_NAME = 'code'"
    )->fetchColumn();
    if ((int) $column > 4) {
        $rooms = $pdo->query('SELECT id, code FROM rooms ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $reservedCodes = [];
        foreach ($rooms as $room) {
            $currentCode = strtoupper(trim($room['code']));
            if (preg_match('/^[A-F0-9]{4}$/', $currentCode)) {
                $reservedCodes[$currentCode] = true;
            }
        }

        $update = $pdo->prepare('UPDATE rooms SET code = ? WHERE id = ?');
        foreach ($rooms as $room) {
            $currentCode = strtoupper(trim($room['code']));
            if (preg_match('/^[A-F0-9]{4}$/', $currentCode)) {
                if ($currentCode !== $room['code']) {
                    $update->execute([$currentCode, $room['id']]);
                }
                continue;
            }

            do {
                $newCode = strtoupper(bin2hex(random_bytes(2)));
            } while (isset($reservedCodes[$newCode]));
            $reservedCodes[$newCode] = true;
            $update->execute([$newCode, $room['id']]);
        }
        $pdo->exec('ALTER TABLE rooms MODIFY code CHAR(4) NOT NULL');
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS room_members (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            member_key CHAR(32) NOT NULL UNIQUE,
            room_id BIGINT UNSIGNED NOT NULL,
            nickname VARCHAR(32) NOT NULL,
            is_admin TINYINT(1) NOT NULL DEFAULT 0,
            last_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_members_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
            INDEX idx_members_presence (room_id, last_seen)
        ) ENGINE=InnoDB"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS room_messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            room_id BIGINT UNSIGNED NOT NULL,
            member_key CHAR(32) NOT NULL,
            nickname VARCHAR(32) NOT NULL,
            body VARCHAR(2000) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_messages_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
            INDEX idx_messages_room_id (room_id, id)
        ) ENGINE=InnoDB"
    );
    fwrite(STDOUT, "Gather database is ready.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'Database setup failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
