<?php
declare(strict_types=1);

require __DIR__ . '/lib/db.php';

// Polyfills for hosts without ext-mbstring (some shared PHP hosts).
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $s): int { return strlen($s); }
}
if (!function_exists('mb_substr')) {
    function mb_substr(string $s, int $start, ?int $len = null): string {
        return $len === null ? substr($s, $start) : substr($s, $start, $len);
    }
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $msg, int $code = 400): void {
    out(['ok' => false, 'error' => $msg], $code);
}

function param(string $key, ?string $default = null): ?string {
    if (isset($_POST[$key])) return (string)$_POST[$key];
    if (isset($_GET[$key]))  return (string)$_GET[$key];
    return $default;
}

/* ---------- Game logic helpers ---------- */

const TRACK_LENGTH        = 100;   // total steps from start to finish
const COUNTDOWN_SECONDS   = 3;     // GO! after 3s
const MIN_PLAYERS_TO_START = 1;    // allow solo for testing
const PALETTE = [
    '#ef4444', '#3b82f6', '#10b981', '#f59e0b',
    '#a855f7', '#ec4899', '#06b6d4', '#84cc16',
];

function pick_color(int $roomId): string {
    $pdo = db();
    $used = $pdo->prepare('SELECT color FROM players WHERE room_id = :r');
    $used->execute([':r' => $roomId]);
    $taken = array_column($used->fetchAll(), 'color');
    foreach (PALETTE as $c) {
        if (!in_array($c, $taken, true)) return $c;
    }
    return PALETTE[array_rand(PALETTE)];
}

function get_room_by_code(string $code): ?array {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM rooms WHERE code = :c LIMIT 1');
    $stmt->execute([':c' => strtoupper($code)]);
    $r = $stmt->fetch();
    return $r ?: null;
}

function get_player_by_token(string $token): ?array {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM players WHERE token = :t LIMIT 1');
    $stmt->execute([':t' => $token]);
    $p = $stmt->fetch();
    return $p ?: null;
}

function snapshot_room(int $roomId): array {
    $pdo = db();
    $room = $pdo->prepare('SELECT * FROM rooms WHERE id = :i');
    $room->execute([':i' => $roomId]);
    $room = $room->fetch();
    if (!$room) return [];

    $players = $pdo->prepare(
        'SELECT id, name, color, position, ready, finished_at, place, seen_at
         FROM players WHERE room_id = :r ORDER BY joined_at ASC'
    );
    $players->execute([':r' => $roomId]);
    $players = $players->fetchAll();

    $now = now_ts();
    foreach ($players as &$p) {
        $p['online'] = ($now - (float)$p['seen_at']) < 8.0;
    }
    unset($p);

    // Promote to "running" once countdown is over
    if ($room['status'] === 'countdown' && (float)$room['start_at'] <= $now) {
        $pdo->prepare('UPDATE rooms SET status = "running", updated_at = :u WHERE id = :i')
            ->execute([':u' => $now, ':i' => $roomId]);
        $room['status'] = 'running';
    }

    return [
        'code'         => $room['code'],
        'status'       => $room['status'],
        'track_length' => (int)$room['track_length'],
        'server_time'  => $now,
        'start_at'     => $room['start_at'] !== null ? (float)$room['start_at'] : null,
        'finished_at'  => $room['finished_at'] !== null ? (float)$room['finished_at'] : null,
        'winner_id'    => $room['winner_id'] !== null ? (int)$room['winner_id'] : null,
        'players'      => $players,
    ];
}

function touch_player(int $playerId): void {
    db()->prepare('UPDATE players SET seen_at = :s WHERE id = :i')
        ->execute([':s' => now_ts(), ':i' => $playerId]);
}

/* ---------- Routes ---------- */

$action = param('action', '');

try {
    gc_old();

    switch ($action) {
        case 'create': {
            $name = trim((string)param('name', ''));
            if ($name === '') fail('Ism kiriting');
            if (mb_strlen($name) > 20) $name = mb_substr($name, 0, 20);

            $pdo = db();
            $pdo->beginTransaction();

            // Unique room code
            do {
                $code = random_code(4);
                $check = $pdo->prepare('SELECT 1 FROM rooms WHERE code = :c');
                $check->execute([':c' => $code]);
            } while ($check->fetchColumn());

            $now = now_ts();
            $pdo->prepare(
                'INSERT INTO rooms (code, status, track_length, created_at, updated_at)
                 VALUES (:c, "waiting", :t, :n, :n)'
            )->execute([':c' => $code, ':t' => TRACK_LENGTH, ':n' => $now]);
            $roomId = (int)$pdo->lastInsertId();

            $token = random_token();
            $pdo->prepare(
                'INSERT INTO players (room_id, token, name, color, seen_at, joined_at)
                 VALUES (:r, :t, :n, :c, :s, :s)'
            )->execute([
                ':r' => $roomId, ':t' => $token, ':n' => $name,
                ':c' => pick_color($roomId), ':s' => $now,
            ]);
            $playerId = (int)$pdo->lastInsertId();

            $pdo->commit();
            out([
                'ok' => true, 'room' => $code, 'token' => $token,
                'player_id' => $playerId, 'state' => snapshot_room($roomId),
            ]);
        }

        case 'join': {
            $name = trim((string)param('name', ''));
            $code = strtoupper(trim((string)param('room', '')));
            if ($name === '') fail('Ism kiriting');
            if ($code === '') fail('Xona kodi kiriting');
            if (mb_strlen($name) > 20) $name = mb_substr($name, 0, 20);

            $room = get_room_by_code($code);
            if (!$room) fail('Bunday xona topilmadi', 404);

            $pdo = db();
            $count = $pdo->prepare('SELECT COUNT(*) FROM players WHERE room_id = :r');
            $count->execute([':r' => $room['id']]);
            if ((int)$count->fetchColumn() >= count(PALETTE)) {
                fail('Xona to\'lgan (maks ' . count(PALETTE) . ' o\'yinchi)');
            }
            if ($room['status'] === 'running') {
                fail('Poyga allaqachon boshlangan. Tugashini kuting yoki yangi xona oching.');
            }

            $token = random_token();
            $now = now_ts();
            $pdo->prepare(
                'INSERT INTO players (room_id, token, name, color, seen_at, joined_at)
                 VALUES (:r, :t, :n, :c, :s, :s)'
            )->execute([
                ':r' => $room['id'], ':t' => $token, ':n' => $name,
                ':c' => pick_color((int)$room['id']), ':s' => $now,
            ]);
            $playerId = (int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE rooms SET updated_at = :u WHERE id = :i')
                ->execute([':u' => $now, ':i' => $room['id']]);

            out([
                'ok' => true, 'room' => $room['code'], 'token' => $token,
                'player_id' => $playerId, 'state' => snapshot_room((int)$room['id']),
            ]);
        }

        case 'state': {
            $token = (string)param('token', '');
            $player = get_player_by_token($token);
            if (!$player) fail('Sessiya topilmadi. Qaytadan kiring.', 401);
            touch_player((int)$player['id']);
            out(['ok' => true, 'state' => snapshot_room((int)$player['room_id'])]);
        }

        case 'ready': {
            $token = (string)param('token', '');
            $player = get_player_by_token($token);
            if (!$player) fail('Sessiya topilmadi', 401);

            $pdo = db();
            $room = $pdo->prepare('SELECT * FROM rooms WHERE id = :i');
            $room->execute([':i' => $player['room_id']]);
            $room = $room->fetch();
            if (!$room || $room['status'] !== 'waiting') {
                fail('Poyga allaqachon boshlangan');
            }

            $pdo->prepare('UPDATE players SET ready = 1, seen_at = :s WHERE id = :i')
                ->execute([':s' => now_ts(), ':i' => $player['id']]);

            // If everyone is ready (and at least min players) → start countdown.
            $cnt = $pdo->prepare(
                'SELECT COUNT(*) all_cnt,
                        SUM(CASE WHEN ready = 1 THEN 1 ELSE 0 END) ready_cnt
                 FROM players WHERE room_id = :r'
            );
            $cnt->execute([':r' => $player['room_id']]);
            $c = $cnt->fetch();
            if ((int)$c['all_cnt'] >= MIN_PLAYERS_TO_START
                && (int)$c['all_cnt'] === (int)$c['ready_cnt']) {
                $start = now_ts() + COUNTDOWN_SECONDS;
                $pdo->prepare(
                    'UPDATE rooms SET status = "countdown", start_at = :s,
                                       finished_at = NULL, winner_id = NULL,
                                       updated_at = :u WHERE id = :i'
                )->execute([':s' => $start, ':u' => now_ts(), ':i' => $player['room_id']]);
                $pdo->prepare(
                    'UPDATE players SET position = 0, last_key = "",
                                         finished_at = NULL, place = NULL
                     WHERE room_id = :r'
                )->execute([':r' => $player['room_id']]);
            }

            out(['ok' => true, 'state' => snapshot_room((int)$player['room_id'])]);
        }

        case 'move': {
            $token = (string)param('token', '');
            $key   = strtoupper((string)param('key', ''));
            if (!in_array($key, ['L', 'R'], true)) fail('Noto\'g\'ri tugma');

            $player = get_player_by_token($token);
            if (!$player) fail('Sessiya topilmadi', 401);

            $pdo = db();
            $room = $pdo->prepare('SELECT * FROM rooms WHERE id = :i');
            $room->execute([':i' => $player['room_id']]);
            $room = $room->fetch();
            if (!$room) fail('Xona topilmadi', 404);

            touch_player((int)$player['id']);

            if ($room['status'] !== 'running') {
                out(['ok' => true, 'state' => snapshot_room((int)$player['room_id'])]);
            }
            if ($player['finished_at'] !== null) {
                out(['ok' => true, 'state' => snapshot_room((int)$player['room_id'])]);
            }

            // Only count alternating keys (left → right → left ...).
            $advance = 0;
            if ($player['last_key'] !== $key) {
                $advance = 1;
            }
            $newPos = min(TRACK_LENGTH, (int)$player['position'] + $advance);

            // Update player position + last_key
            $pdo->prepare(
                'UPDATE players SET position = :p, last_key = :k, seen_at = :s
                 WHERE id = :i'
            )->execute([
                ':p' => $newPos, ':k' => $key, ':s' => now_ts(), ':i' => $player['id'],
            ]);

            // Crossed finish line?
            if ($newPos >= TRACK_LENGTH) {
                $pdo->beginTransaction();
                $finCnt = $pdo->prepare(
                    'SELECT COUNT(*) FROM players
                     WHERE room_id = :r AND finished_at IS NOT NULL'
                );
                $finCnt->execute([':r' => $player['room_id']]);
                $place = (int)$finCnt->fetchColumn() + 1;
                $now = now_ts();
                $pdo->prepare(
                    'UPDATE players SET finished_at = :f, place = :pl
                     WHERE id = :i AND finished_at IS NULL'
                )->execute([':f' => $now, ':pl' => $place, ':i' => $player['id']]);

                if ($place === 1) {
                    $pdo->prepare(
                        'UPDATE rooms SET winner_id = :w WHERE id = :i'
                    )->execute([':w' => $player['id'], ':i' => $player['room_id']]);
                }

                // Are all players done?
                $remain = $pdo->prepare(
                    'SELECT COUNT(*) FROM players
                     WHERE room_id = :r AND finished_at IS NULL'
                );
                $remain->execute([':r' => $player['room_id']]);
                if ((int)$remain->fetchColumn() === 0) {
                    $pdo->prepare(
                        'UPDATE rooms SET status = "finished", finished_at = :f, updated_at = :f
                         WHERE id = :i'
                    )->execute([':f' => $now, ':i' => $player['room_id']]);
                }
                $pdo->commit();
            }

            out(['ok' => true, 'state' => snapshot_room((int)$player['room_id'])]);
        }

        case 'restart': {
            $token = (string)param('token', '');
            $player = get_player_by_token($token);
            if (!$player) fail('Sessiya topilmadi', 401);
            $pdo = db();
            $pdo->prepare(
                'UPDATE players SET position = 0, last_key = "", ready = 0,
                                     finished_at = NULL, place = NULL, seen_at = :s
                 WHERE room_id = :r'
            )->execute([':s' => now_ts(), ':r' => $player['room_id']]);
            $pdo->prepare(
                'UPDATE rooms SET status = "waiting", start_at = NULL,
                                   finished_at = NULL, winner_id = NULL, updated_at = :u
                 WHERE id = :i'
            )->execute([':u' => now_ts(), ':i' => $player['room_id']]);
            out(['ok' => true, 'state' => snapshot_room((int)$player['room_id'])]);
        }

        case 'leave': {
            $token = (string)param('token', '');
            $player = get_player_by_token($token);
            if (!$player) out(['ok' => true]);
            db()->prepare('DELETE FROM players WHERE id = :i')
                ->execute([':i' => $player['id']]);
            out(['ok' => true]);
        }

        default:
            fail('Noma\'lum amal: ' . htmlspecialchars($action));
    }
} catch (Throwable $e) {
    fail('Server xatosi: ' . $e->getMessage(), 500);
}
