<?php
declare(strict_types=1);

$settingsFile = __DIR__ . '/config.local.php';
if (!is_file($settingsFile)) {
    throw new RuntimeException('Missing config.local.php. Copy the database settings file and configure MySQL.');
}

$settings = require $settingsFile;
$dsnBase = sprintf(
    'mysql:host=%s;port=%d;charset=utf8mb4',
    $settings['host'],
    $settings['port']
);

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

$pdo = new PDO(
    $dsnBase . ';dbname=' . rawurlencode($settings['database']),
    $settings['username'],
    $settings['password'],
    $options
);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function request_payload(): array
{
    $decoded = json_decode(file_get_contents('php://input'), true);
    return is_array($decoded) ? $decoded : [];
}

function clean_text(mixed $value, int $maxLength): string
{
    if (!is_string($value)) {
        return '';
    }
    $text = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '');
    return mb_substr($text, 0, $maxLength);
}

function active_room(PDO $pdo): array
{
    $membership = $_SESSION['membership'] ?? null;
    if (!is_array($membership) || empty($membership['room_id']) || empty($membership['member_key'])) {
        json_response(['error' => 'Join a room to continue.'], 401);
    }

    $statement = $pdo->prepare(
        'SELECT r.id, r.code, r.name, r.closed_at, m.member_key, m.nickname, m.is_admin
         FROM rooms r JOIN room_members m ON m.room_id = r.id
         WHERE r.id = ? AND m.member_key = ? LIMIT 1'
    );
    $statement->execute([$membership['room_id'], $membership['member_key']]);
    $room = $statement->fetch();
    if (!$room || $room['closed_at'] !== null) {
        unset($_SESSION['membership']);
        json_response(['error' => 'This room is no longer available.', 'code' => 'room_closed'], 410);
    }
    return $room;
}
