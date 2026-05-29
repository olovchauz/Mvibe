/* global ROOM, TOKEN, ME_ID */
(() => {
    const $  = (q, c = document) => c.querySelector(q);
    const $$ = (q, c = document) => [...c.querySelectorAll(q)];

    const ICONS = ["🏃", "🏃‍♀️", "🐎", "🐇", "🦊", "🐆", "🦁", "🐸"];

    const ui = {
        track:     $("#track"),
        trackWrap: $("#track-wrap"),
        status:    $("#status"),
        overlay:   $("#overlay"),
        overlayC:  $("#overlay-content"),
        btnReady:  $("#btn-ready"),
        btnLeft:   $("#btn-left"),
        btnRight:  $("#btn-right"),
        results:   $("#results"),
        meName:    $("#me-name"),
        copyLink:  $("#copy-link"),
    };

    /** @type {object|null} */
    let state = null;
    let lastKey = "";                 // last key pressed locally (L/R)
    let pendingMoves = 0;             // queued moves not yet flushed
    let inFlight = false;             // a move/state call in progress
    let lastPositions = new Map();    // player id -> previous pos (for bob animation)
    let pollErrors = 0;
    let restartedFlag = false;        // suppress duplicate winner banner

    /* ---------- Networking ---------- */
    async function call(action, extra = {}) {
        const body = new URLSearchParams({ action, token: TOKEN, ...extra });
        const r = await fetch("api.php", { method: "POST", body });
        const j = await r.json();
        if (!j.ok) throw new Error(j.error || "Xato");
        return j;
    }

    async function fetchState() {
        try {
            const r = await call("state");
            applyState(r.state);
            pollErrors = 0;
        } catch (e) {
            pollErrors++;
            console.warn("state error", e);
            if (pollErrors > 5) {
                ui.status.textContent = "Aloqa yo'q";
                ui.status.className   = "status status-waiting";
            }
            if (String(e.message || "").includes("Sessiya")) {
                localStorage.removeItem("poyga_token");
                localStorage.removeItem("poyga_room");
                location.href = "index.php?room=" + encodeURIComponent(ROOM);
            }
        }
    }

    async function flushMoves() {
        if (inFlight || pendingMoves === 0) return;
        if (!state || state.status !== "running") {
            pendingMoves = 0;
            return;
        }
        inFlight = true;
        try {
            // We send one move per network round-trip, using current lastKey.
            // (The server only accepts alternating keys anyway.)
            const key = lastKey;
            pendingMoves = 0;
            const r = await call("move", { key });
            applyState(r.state);
        } catch (e) {
            console.warn("move error", e);
        } finally {
            inFlight = false;
            // If user pressed more during the round trip → keep flushing
            if (pendingMoves > 0) flushMoves();
        }
    }

    /* ---------- Render ---------- */
    function applyState(s) {
        state = s;
        renderStatus();
        renderTrack();
        renderControls();
        renderOverlay();
        renderResults();
    }

    function renderStatus() {
        const el = ui.status;
        el.className = "status status-" + state.status;
        if (state.status === "waiting") {
            const n = state.players.length;
            const ready = state.players.filter(p => p.ready).length;
            el.textContent = `Kutmoqda · ${ready}/${n} tayyor`;
        } else if (state.status === "countdown") {
            el.textContent = "Boshlanmoqda...";
        } else if (state.status === "running") {
            el.textContent = "POYGA!";
        } else if (state.status === "finished") {
            el.textContent = "Tugadi";
        }

        const me = myPlayer();
        if (me) ui.meName.textContent = me.name;
    }

    function myPlayer() {
        return state ? state.players.find(p => p.id === ME_ID) : null;
    }

    function renderTrack() {
        const tl = state.track_length;
        const wrap = ui.trackWrap;
        // Lane usable width: subtract finish-line width + paddings
        const padding = 14;
        const finishW = 14;
        const totalW = wrap.clientWidth - padding * 2 - finishW - 18;

        // Build / update lanes
        const ids = state.players.map(p => String(p.id));
        $$(".lane", ui.track).forEach(l => {
            if (!ids.includes(l.dataset.id)) l.remove();
        });

        state.players.forEach((p, i) => {
            let lane = $('.lane[data-id="' + p.id + '"]', ui.track);
            if (!lane) {
                lane = document.createElement("div");
                lane.className = "lane";
                lane.dataset.id = p.id;
                lane.innerHTML = `
                    <div class="lane-name">
                        <span class="dot"></span>
                        <span class="nm"></span>
                        <span class="place" hidden></span>
                        <span class="offline" hidden>offline</span>
                    </div>
                    <div class="runner"></div>
                `;
                ui.track.appendChild(lane);
            }
            const isMe = p.id === ME_ID;
            lane.classList.toggle("me", isMe);
            lane.classList.toggle("finished", !!p.finished_at);

            const dot = $(".dot", lane);
            dot.style.background = p.color;
            $(".nm", lane).textContent = (isMe ? "Siz: " : "") + p.name;

            const place = $(".place", lane);
            if (p.place) { place.hidden = false; place.textContent = "#" + p.place; }
            else { place.hidden = true; }

            $(".offline", lane).hidden = !!p.online || !!p.finished_at;

            const runner = $(".runner", lane);
            runner.textContent = ICONS[i % ICONS.length];
            runner.style.color = p.color;
            const pct = Math.min(1, p.position / tl);
            const left = 12 + pct * totalW;
            runner.style.left = left + "px";

            const prev = lastPositions.get(p.id) ?? p.position;
            if (p.position > prev) {
                runner.classList.remove("step");
                void runner.offsetWidth;
                runner.classList.add("step");
            }
            lastPositions.set(p.id, p.position);
        });
    }

    function renderControls() {
        const me = myPlayer();
        if (!me) return;

        const waiting = state.status === "waiting";
        ui.btnReady.style.display = (waiting || state.status === "finished") ? "" : "none";
        ui.btnLeft.style.display  = (state.status === "running") ? "" : "none";
        ui.btnRight.style.display = (state.status === "running") ? "" : "none";

        if (waiting) {
            const ready = !!me.ready;
            ui.btnReady.textContent = ready ? "Tayyor (kutilmoqda)" : "Tayyorman ✋";
            ui.btnReady.classList.toggle("unready", ready);
            ui.btnReady.disabled = ready;
        } else if (state.status === "finished") {
            ui.btnReady.textContent = "Qaytadan o'ynash 🔄";
            ui.btnReady.classList.remove("unready");
            ui.btnReady.disabled = false;
        }
    }

    let overlayTimer = null;
    function renderOverlay() {
        const me = myPlayer();
        if (state.status === "countdown") {
            const remain = state.start_at - state.server_time;
            const n = Math.max(0, Math.ceil(remain));
            showOverlay(n > 0
                ? `<div class="count">${n}</div>`
                : `<div class="go">GO!</div>`);
            if (overlayTimer) clearTimeout(overlayTimer);
            if (n > 0) overlayTimer = setTimeout(renderOverlay, 250);
            else overlayTimer = setTimeout(hideOverlay, 700);
        } else if (state.status === "finished" && !restartedFlag) {
            const sorted = [...state.players]
                .filter(p => p.finished_at)
                .sort((a, b) => a.place - b.place);
            const winner = sorted[0];
            const wonHtml = winner
                ? `<h2>🏆 ${escapeHTML(winner.name)} g'olib!</h2>`
                : `<h2>Poyga tugadi</h2>`;
            const myPlace = me && me.place ? `<p>Sizning o'rningiz: <b>#${me.place}</b></p>` : "";
            showOverlay(`
                ${wonHtml}
                ${myPlace}
                <button id="overlay-close" class="btn primary">Yopish</button>
            `);
            $("#overlay-close")?.addEventListener("click", hideOverlay);
        } else if (state.status === "running" || state.status === "waiting") {
            hideOverlay();
        }
    }

    function showOverlay(html) {
        ui.overlay.hidden = false;
        ui.overlayC.innerHTML = html;
    }
    function hideOverlay() {
        ui.overlay.hidden = true;
    }

    function renderResults() {
        if (state.status !== "finished") {
            ui.results.hidden = true;
            return;
        }
        const sorted = [...state.players].sort((a, b) => {
            if (a.finished_at && b.finished_at) return a.place - b.place;
            if (a.finished_at) return -1;
            if (b.finished_at) return 1;
            return b.position - a.position;
        });
        const lis = sorted.map(p => {
            const place = p.place ? `#${p.place}` : "—";
            const me = p.id === ME_ID ? " me" : "";
            return `<li class="${me.trim()}">${place} · <span style="color:${p.color}">●</span> ${escapeHTML(p.name)}</li>`;
        }).join("");
        ui.results.innerHTML = `
            <h3>Natijalar</h3>
            <ol>${lis}</ol>
            <div class="actions">
                <button id="res-restart" class="btn primary">Qaytadan o'ynash 🔄</button>
                <button id="res-leave"   class="btn ghost">Chiqish</button>
            </div>`;
        ui.results.hidden = false;
        $("#res-restart").addEventListener("click", doRestart);
        $("#res-leave").addEventListener("click", doLeave);
    }

    function escapeHTML(s) {
        return String(s).replace(/[&<>"']/g, c => ({
            "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;",
        }[c]));
    }

    /* ---------- Actions ---------- */
    async function doReady() {
        if (state.status === "finished") return doRestart();
        try { applyState((await call("ready")).state); }
        catch (e) { alert(e.message); }
    }
    async function doRestart() {
        try {
            restartedFlag = true;
            applyState((await call("restart")).state);
            setTimeout(() => restartedFlag = false, 1000);
        } catch (e) { alert(e.message); }
    }
    async function doLeave() {
        try { await call("leave"); } catch {}
        localStorage.removeItem("poyga_token");
        localStorage.removeItem("poyga_player_id");
        localStorage.removeItem("poyga_room");
        location.href = "index.php";
    }

    function press(key) {
        if (!state || state.status !== "running") return;
        const me = myPlayer();
        if (!me || me.finished_at) return;
        if (key === lastKey) {
            // Same as last — server will reject; still flash for feedback.
            flashBtn(key);
            return;
        }
        lastKey = key;
        pendingMoves++;
        flashBtn(key);
        // Optimistic local position bump (re-synced from server)
        me.position = Math.min(state.track_length, me.position + 1);
        renderTrack();
        flushMoves();
    }
    function flashBtn(key) {
        const b = key === "L" ? ui.btnLeft : ui.btnRight;
        if (!b) return;
        b.classList.add("flash");
        setTimeout(() => b.classList.remove("flash"), 120);
    }

    /* ---------- Input ---------- */
    document.addEventListener("keydown", e => {
        if (e.repeat) return;
        if (e.key === "ArrowLeft"  || e.key === "a" || e.key === "A") { e.preventDefault(); press("L"); }
        if (e.key === "ArrowRight" || e.key === "d" || e.key === "D") { e.preventDefault(); press("R"); }
        if (e.key === " "  && state?.status === "waiting") { e.preventDefault(); doReady(); }
        if (e.key === "Enter" && state?.status === "finished") { e.preventDefault(); doRestart(); }
    });
    ui.btnLeft.addEventListener("pointerdown",  e => { e.preventDefault(); press("L"); });
    ui.btnRight.addEventListener("pointerdown", e => { e.preventDefault(); press("R"); });
    ui.btnReady.addEventListener("click", doReady);

    ui.copyLink.addEventListener("click", async () => {
        const url = location.origin + location.pathname.replace(/game\.php.*/, "")
                  + "index.php?room=" + encodeURIComponent(ROOM);
        try {
            await navigator.clipboard.writeText(url);
            ui.copyLink.textContent = "✓ Nusxalandi";
            setTimeout(() => ui.copyLink.textContent = "🔗 Taklif", 1500);
        } catch {
            prompt("Taklif havolasi:", url);
        }
    });

    window.addEventListener("beforeunload", () => {
        // Best-effort leave notification — keep token though, so user can come back
        navigator.sendBeacon?.(
            "api.php",
            new URLSearchParams({ action: "state", token: TOKEN }),
        );
    });

    window.addEventListener("resize", () => { if (state) renderTrack(); });

    /* ---------- Poll loop ---------- */
    fetchState();
    setInterval(fetchState, 700);
})();
