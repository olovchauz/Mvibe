<?php
$room = isset($_GET['room']) ? strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)$_GET['room'])) : '';
?>
<!DOCTYPE html>
<html lang="uz">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title>Poyga · <?= htmlspecialchars($room) ?></title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="game-page">
<div class="bg-grid"></div>

<header class="topbar">
    <a href="index.php" class="back">← Bosh sahifa</a>
    <div class="room-info">
        Xona: <b id="room-code"><?= htmlspecialchars($room) ?></b>
        <button id="copy-link" class="btn ghost small" title="Taklif havolasini nusxalash">🔗 Taklif</button>
    </div>
    <div id="status" class="status status-waiting">Kutmoqda</div>
</header>

<main id="game" class="game">
    <div id="track-wrap" class="track-wrap">
        <div class="finish-line" aria-hidden="true">FINISH</div>
        <div id="track" class="track"></div>
    </div>

    <div id="overlay" class="overlay" hidden>
        <div id="overlay-content" class="overlay-content"></div>
    </div>

    <div id="controls" class="controls">
        <button id="btn-left" class="pad-btn left" type="button" aria-label="Chap">◀</button>
        <button id="btn-ready" class="btn primary big" type="button">Tayyorman ✋</button>
        <button id="btn-right" class="pad-btn right" type="button" aria-label="O'ng">▶</button>
    </div>

    <p class="help">
        ⌨️ <b>← →</b> yoki <b>A D</b> — navbatma-navbat tez bosing.
        Mobil — ekrandagi tugmalardan foydalaning.
    </p>

    <div id="results" class="results" hidden></div>
</main>

<footer class="footer">quvnoq.biz · poyga · siz: <b id="me-name">—</b></footer>

<script>
const ROOM  = <?= json_encode($room) ?>;
let TOKEN = localStorage.getItem('poyga_token');
let ME_ID = parseInt(localStorage.getItem('poyga_player_id') || '0', 10);

// Allow URL-based session bootstrap: ?t=TOKEN&pid=ID
const _qp = new URLSearchParams(location.search);
if (_qp.get('t') && _qp.get('pid')) {
    localStorage.setItem('poyga_token', _qp.get('t'));
    localStorage.setItem('poyga_player_id', _qp.get('pid'));
    localStorage.setItem('poyga_room', ROOM);
    history.replaceState({}, '', location.pathname + '?room=' + encodeURIComponent(ROOM));
}
const TOKEN2 = localStorage.getItem('poyga_token');
const PID2   = parseInt(localStorage.getItem('poyga_player_id') || '0', 10);
if (!TOKEN2 || localStorage.getItem('poyga_room') !== ROOM) {
    location.href = 'index.php?room=' + encodeURIComponent(ROOM);
}
</script>
<script src="assets/game.js"></script>
</body>
</html>
