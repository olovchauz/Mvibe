<?php
$prefill = isset($_GET['room']) ? strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)$_GET['room'])) : '';
?>
<!DOCTYPE html>
<html lang="uz">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title>Poyga — Quvnoq.biz</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="lobby-page">
<div class="bg-grid"></div>

<main class="lobby">
    <h1 class="logo">🏁 <span>POYGA</span></h1>
    <p class="tagline">Do'stlaringizni chaqiring, kim tezroq chopadi?</p>

    <div class="panel">
        <div class="tabs">
            <button class="tab active" data-tab="create">Yangi xona</button>
            <button class="tab" data-tab="join">Xonaga qo'shilish</button>
        </div>

        <form id="form-create" class="tab-panel active">
            <label>Ismingiz
                <input type="text" name="name" maxlength="20" required placeholder="Masalan: Ali" autocomplete="off">
            </label>
            <button type="submit" class="btn primary">Xona ochish 🚀</button>
        </form>

        <form id="form-join" class="tab-panel">
            <label>Ismingiz
                <input type="text" name="name" maxlength="20" required placeholder="Masalan: Vali" autocomplete="off">
            </label>
            <label>Xona kodi
                <input type="text" name="room" maxlength="4" required placeholder="ABCD"
                       value="<?= htmlspecialchars($prefill) ?>"
                       style="text-transform: uppercase; letter-spacing: .4em; text-align:center; font-weight:700;">
            </label>
            <button type="submit" class="btn primary">Qo'shilish 🏃</button>
        </form>

        <div id="error" class="error" hidden></div>
    </div>

    <details class="howto">
        <summary>Qanday o'ynaladi?</summary>
        <ul>
            <li>Bir o'yinchi <b>Yangi xona</b> ochib, do'stlariga <b>4 harfli kod</b> beradi.</li>
            <li>Hamma <b>Tayyorman</b> tugmasini bosgach, 3..2..1 sanaladi va poyga boshlanadi.</li>
            <li>O'yin paytida <b>← va →</b> (yoki <b>A va D</b>) tugmalarini <b>navbatma-navbat</b> tez bosing — yuguruvchingiz harakatlanadi.</li>
            <li>Bitta tugmani ikki marta bosish hisobga olinmaydi — chinakam yugurish kabi!</li>
            <li>Mobil telefonda: ekrandagi <b>chap/o'ng</b> tugmalarni navbatma-navbat bosing.</li>
        </ul>
    </details>
</main>

<footer class="footer">quvnoq.biz · poyga · PHP + SQLite</footer>

<script>
const $ = (q, c=document) => c.querySelector(q);
const $$ = (q, c=document) => [...c.querySelectorAll(q)];

$$('.tab').forEach(b => b.addEventListener('click', () => {
    $$('.tab').forEach(t => t.classList.toggle('active', t === b));
    $$('.tab-panel').forEach(p => p.classList.toggle('active', p.id === 'form-' + b.dataset.tab));
    $('#error').hidden = true;
}));

async function api(action, data) {
    const body = new URLSearchParams({action, ...data});
    const r = await fetch('api.php', {method: 'POST', body});
    return r.json();
}

function showError(msg) {
    const e = $('#error');
    e.textContent = msg;
    e.hidden = false;
}

function go(res) {
    if (!res.ok) { showError(res.error || 'Xato'); return; }
    localStorage.setItem('poyga_token', res.token);
    localStorage.setItem('poyga_player_id', res.player_id);
    localStorage.setItem('poyga_room', res.room);
    location.href = 'game.php?room=' + encodeURIComponent(res.room);
}

$('#form-create').addEventListener('submit', async e => {
    e.preventDefault();
    const name = e.target.name.value.trim();
    if (!name) return;
    const btn = e.target.querySelector('button');
    btn.disabled = true; btn.textContent = 'Ochilmoqda...';
    go(await api('create', {name}));
    btn.disabled = false; btn.textContent = "Xona ochish 🚀";
});

$('#form-join').addEventListener('submit', async e => {
    e.preventDefault();
    const name = e.target.name.value.trim();
    const room = e.target.room.value.trim().toUpperCase();
    if (!name || !room) return;
    const btn = e.target.querySelector('button');
    btn.disabled = true; btn.textContent = 'Kirilmoqda...';
    go(await api('join', {name, room}));
    btn.disabled = false; btn.textContent = "Qo'shilish 🏃";
});

// If a previous session exists and room is in URL, auto-redirect to lobby's game.
const lastRoom = localStorage.getItem('poyga_room');
const lastToken = localStorage.getItem('poyga_token');
if (lastRoom && lastToken && !location.search) {
    // Subtle UX hint — don't auto redirect, but mention.
    const note = document.createElement('div');
    note.className = 'note';
    note.innerHTML = `Avvalgi xona: <b>${lastRoom}</b> &nbsp; <a href="game.php?room=${lastRoom}">Davom etish →</a>`;
    $('.panel').after(note);
}
</script>
</body>
</html>
