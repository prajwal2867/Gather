<?php
declare(strict_types=1);

try {
    require_once dirname(__DIR__) . '/bootstrap.php';
} catch (Throwable $exception) {
    error_log($exception->__toString());
    json_response(['error' => 'Unable to connect to the chat database. Check the local setup instructions.'], 500);
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    $providedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($providedToken) || !hash_equals($_SESSION['csrf_token'], $providedToken)) {
        json_response(['error' => 'Your session expired. Refresh the page and try again.'], 419);
    }
}

try {
    if ($method === 'POST' && $action === 'create') {
        $payload = request_payload();
        $name = clean_text($payload['room_name'] ?? '', 80);
        $nickname = clean_text($payload['nickname'] ?? '', 32);
        if ($name === '' || $nickname === '') {
            json_response(['error' => 'Add a room name and your display name.'], 422);
        }

        do {
            $code = strtoupper(bin2hex(random_bytes(2)));
            $check = $pdo->prepare('SELECT id FROM rooms WHERE code = ?');
            $check->execute([$code]);
        } while ($check->fetch());

        $pdo->beginTransaction();
        $insert = $pdo->prepare('INSERT INTO rooms (code, name) VALUES (?, ?)');
        $insert->execute([$code, $name]);
        $roomId = (int) $pdo->lastInsertId();
        $memberKey = bin2hex(random_bytes(16));
        $member = $pdo->prepare(
            'INSERT INTO room_members (member_key, room_id, nickname, is_admin) VALUES (?, ?, ?, 1)'
        );
        $member->execute([$memberKey, $roomId, $nickname]);
        $pdo->commit();

        session_regenerate_id(true);
        $_SESSION['membership'] = [
            'room_id' => $roomId,
            'member_key' => $memberKey,
            'nickname' => $nickname,
            'is_admin' => true,
        ];
        json_response(['room' => ['code' => $code, 'name' => $name, 'nickname' => $nickname, 'is_admin' => true]]);
    }

    if ($method === 'POST' && $action === 'join') {
        $payload = request_payload();
        $code = strtoupper(clean_text($payload['code'] ?? '', 4));
        $nickname = clean_text($payload['nickname'] ?? '', 32);
        if (!preg_match('/^[A-F0-9]{4}$/', $code) || $nickname === '') {
            json_response(['error' => 'Enter a valid 4-character room code and your display name.'], 422);
        }

        $find = $pdo->prepare('SELECT id, code, name FROM rooms WHERE code = ? AND closed_at IS NULL');
        $find->execute([$code]);
        $room = $find->fetch();
        if (!$room) {
            json_response(['error' => 'We could not find an open room with that code.'], 404);
        }

        $memberKey = bin2hex(random_bytes(16));
        $add = $pdo->prepare(
            'INSERT INTO room_members (member_key, room_id, nickname) VALUES (?, ?, ?)'
        );
        $add->execute([$memberKey, $room['id'], $nickname]);
        session_regenerate_id(true);
        $_SESSION['membership'] = [
            'room_id' => (int) $room['id'],
            'member_key' => $memberKey,
            'nickname' => $nickname,
            'is_admin' => false,
        ];
        json_response([
            'room' => ['code' => $room['code'], 'name' => $room['name'], 'nickname' => $nickname, 'is_admin' => false],
        ]);
    }

    if ($method === 'GET' && $action === 'messages') {
        $room = active_room($pdo);
        $memberKey = $room['member_key'];
        $heartbeat = $pdo->prepare('UPDATE room_members SET last_seen = CURRENT_TIMESTAMP WHERE member_key = ?');
        $heartbeat->execute([$memberKey]);
        $after = max(0, (int) ($_GET['after'] ?? 0));

        if ($after === 0) {
            $statement = $pdo->prepare(
                'SELECT id, member_key, nickname, body, created_at FROM (
                    SELECT id, member_key, nickname, body, created_at
                    FROM room_messages WHERE room_id = ? ORDER BY id DESC LIMIT 100
                ) recent ORDER BY id ASC'
            );
            $statement->execute([$room['id']]);
        } else {
            $statement = $pdo->prepare(
                'SELECT id, member_key, nickname, body, created_at
                 FROM room_messages WHERE room_id = ? AND id > ? ORDER BY id ASC LIMIT 100'
            );
            $statement->execute([$room['id'], $after]);
        }
        $messages = array_map(static function (array $message) use ($memberKey): array {
            return [
                'id' => (int) $message['id'],
                'nickname' => $message['nickname'],
                'body' => $message['body'],
                'created_at' => $message['created_at'],
                'is_mine' => hash_equals($memberKey, $message['member_key']),
            ];
        }, $statement->fetchAll());

        $presence = $pdo->prepare(
            'SELECT COUNT(*) FROM room_members WHERE room_id = ? AND last_seen >= (CURRENT_TIMESTAMP - INTERVAL 45 SECOND)'
        );
        $presence->execute([$room['id']]);
        json_response([
            'room' => ['code' => $room['code'], 'name' => $room['name']],
            'messages' => $messages,
            'online_count' => (int) $presence->fetchColumn(),
        ]);
    }

    if ($method === 'POST' && $action === 'send') {
        $room = active_room($pdo);
        $payload = request_payload();
        $body = clean_text($payload['body'] ?? '', 2000);
        if ($body === '') {
            json_response(['error' => 'Write a message before sending.'], 422);
        }
        $insert = $pdo->prepare(
            'INSERT INTO room_messages (room_id, member_key, nickname, body) VALUES (?, ?, ?, ?)'
        );
        $insert->execute([$room['id'], $room['member_key'], $room['nickname'], $body]);
        json_response(['sent' => true, 'id' => (int) $pdo->lastInsertId()], 201);
    }

    if ($method === 'POST' && $action === 'close') {
        $room = active_room($pdo);
        if (!(bool) $room['is_admin']) {
            json_response(['error' => 'Only the room creator can close this room.'], 403);
        }
        $close = $pdo->prepare('UPDATE rooms SET closed_at = CURRENT_TIMESTAMP WHERE id = ?');
        $close->execute([$room['id']]);
        unset($_SESSION['membership']);
        json_response(['closed' => true]);
    }

    if ($method === 'POST' && $action === 'leave') {
        active_room($pdo);
        unset($_SESSION['membership']);
        json_response(['left' => true]);
    }

    json_response(['error' => 'Not found.'], 404);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log($exception->__toString());
    json_response(['error' => 'Something went wrong while processing your request. Please try again.'], 500);
}
