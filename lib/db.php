<?php
declare(strict_types=1);

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $dataDir = __DIR__ . '/../data';
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0775, true);
    }
    $dbFile = $dataDir . '/poyga.sqlite';
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode = WAL;');
    $pdo->exec('PRAGMA busy_timeout = 5000;');
    $pdo->exec('PRAGMA foreign_keys = ON;');

    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS rooms (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            code        TEXT NOT NULL UNIQUE,
            status      TEXT NOT NULL DEFAULT 'waiting', -- waiting | countdown | running | finished
            track_length INTEGER NOT NULL DEFAULT 100,
            start_at    REAL,
            finished_at REAL,
            winner_id   INTEGER,
            created_at  REAL NOT NULL,
            updated_at  REAL NOT NULL
        );
        CREATE TABLE IF NOT EXISTS players (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            room_id     INTEGER NOT NULL,
            token       TEXT NOT NULL UNIQUE,
            name        TEXT NOT NULL,
            color       TEXT NOT NULL,
            position    INTEGER NOT NULL DEFAULT 0,
            last_key    TEXT NOT NULL DEFAULT '',
            ready       INTEGER NOT NULL DEFAULT 0,
            finished_at REAL,
            place       INTEGER,
            seen_at     REAL NOT NULL,
            joined_at   REAL NOT NULL,
            FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
        );
        CREATE INDEX IF NOT EXISTS idx_players_room ON players(room_id);
    SQL);

    return $pdo;
}

function now_ts(): float {
    return microtime(true);
}

function random_code(int $len = 4): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

function random_token(): string {
    return bin2hex(random_bytes(16));
}

/** Garbage-collect ancient rooms and disconnected players. */
function gc_old(): void {
    $now = now_ts();
    $pdo = db();
    // Players not seen for 60s are dropped (unless room finished within 5 min)
    $pdo->prepare('DELETE FROM players WHERE seen_at < :cut')
        ->execute([':cut' => $now - 60]);
    // Empty / very old rooms cleared after 1 hour
    $pdo->prepare('DELETE FROM rooms
        WHERE id NOT IN (SELECT DISTINCT room_id FROM players)
          AND updated_at < :cut')
        ->execute([':cut' => $now - 3600]);
}
