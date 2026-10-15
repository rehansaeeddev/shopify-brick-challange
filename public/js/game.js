const COLS = 10;
const ROWS = 20;
const CELL = 24;          // size of one block in pixels

// One colour per piece. The grid stores these keys, not colours, so the
// palette can change in one place without touching the board data.
const COLORS = {
    I: '#4ac0d0',   // teal
    O: '#e3b23c',   // yellow
    T: '#8b5cf6',   // purple
    S: '#3f9c5b',   // green
    Z: '#d05a52',   // red
    J: '#4a7fd4',   // blue
    L: '#e08a3c',   // orange
};

const SHAPES = {
    I: [[1, 1, 1, 1]],
    O: [[1, 1], [1, 1]],
    T: [[0, 1, 0], [1, 1, 1]],
    S: [[0, 1, 1], [1, 1, 0]],
    Z: [[1, 1, 0], [0, 1, 1]],
    J: [[1, 0, 0], [1, 1, 1]],
    L: [[0, 0, 1], [1, 1, 1]],
};

const MAX_HOLDS = 3;      // swaps allowed per game
const POINTS = [0, 100, 300, 500, 800];
const CLEAR_NAMES = ['', 'single', 'double', 'triple', 'tetris'];

// Honour the OS "reduce motion" setting: no shake, no sweep, shorter fades.
const REDUCED = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const canvas = document.getElementById('board');
const ctx = canvas.getContext('2d');

canvas.width = COLS * CELL;
canvas.height = ROWS * CELL;

// ---------------------------------------------------------------- game state

// Each cell is null when empty, or a piece key like 'T' when filled.
let grid = Array.from({ length: ROWS }, () => Array(COLS).fill(null));

let piece = null;         // { key, shape, row, col } -- carries its own rotated shape
let bag = [];             // 7-bag randomiser, so every piece appears once per cycle
let queue = [];           // the three pieces shown in "Up next"
let held = null;          // the piece parked in the HOLD slot
let holdsLeft = MAX_HOLDS;// a budget of swaps for the whole game
let score = 0;
let lines = 0;
let level = 1;

// attract -> playing -> (clearing -> falling) -> playing ... -> over
let state = 'attract';

let anim = null;          // the clear/fall animation currently in flight
let particles = [];       // landing dust
let popups = [];          // floating "+300 double" text
let shake = 0;            // board shake in px, decays to 0
let dropAcc = 0;          // milliseconds banked towards the next gravity step
let submitted = false;    // one leaderboard post per game
let elapsed = 0;          // ms of play time this game, for the clock badge
let clockShown = -1;      // last whole second written to the DOM
let session = null;       // { gameId, seed } issued by the server for this game
let starting = false;     // guards against opening two games at once

// ------------------------------------------------------------------- helpers

const easeOut = (t) => 1 - Math.pow(1 - t, 3);
const clamp01 = (t) => (t < 0 ? 0 : t > 1 ? 1 : t);

/** Rotates a shape matrix 90 degrees clockwise. */
function rotate(shape) {
    const h = shape.length;
    const w = shape[0].length;
    const out = Array.from({ length: w }, () => Array(h).fill(0));

    for (let r = 0; r < h; r++) {
        for (let c = 0; c < w; c++) out[c][h - 1 - r] = shape[r][c];
    }

    return out;
}

/** True when the shape would overlap a wall, the floor, or a settled block. */
function collides(shape, row, col) {
    for (let r = 0; r < shape.length; r++) {
        for (let c = 0; c < shape[r].length; c++) {
            if (!shape[r][c]) continue;

            const gr = row + r;
            const gc = col + c;

            if (gc < 0 || gc >= COLS || gr >= ROWS) return true;
            if (gr >= 0 && grid[gr][gc]) return true;
        }
    }

    return false;
}

/** How far the current piece would fall if dropped right now. */
function landingRow() {
    let row = piece.row;
    while (!collides(piece.shape, row + 1, piece.col)) row++;
    return row;
}

/**
 * mulberry32: a tiny seeded pseudo-random generator.
 *
 * Given the same seed it produces the same sequence every time, which is what
 * lets the server hand out a piece order instead of the browser inventing one.
 * Only the piece bag uses it -- dust, shake and particles stay on Math.random,
 * since nothing needs to reproduce those.
 */
function makeRng(seed) {
    let a = seed >>> 0;

    return function () {
        a = (a + 0x6d2b79f5) >>> 0;
        let t = a;
        t = Math.imul(t ^ (t >>> 15), 1 | t);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

// Seeded locally so the attract screen can deal pieces before any game has been
// opened. newGame replaces it with the seed the server issues.
let rng = makeRng((Math.random() * 0xffffffff) >>> 0);

function refillBag() {
    const keys = Object.keys(SHAPES);

    for (let i = keys.length - 1; i > 0; i--) {
        const j = Math.floor(rng() * (i + 1));
        [keys[i], keys[j]] = [keys[j], keys[i]];
    }

    bag.push(...keys);
}

function nextKey() {
    if (bag.length === 0) refillBag();
    return bag.shift();
}

// ------------------------------------------------------------------- drawing

/**
 * Draws one block. `ghost` outlines it instead of filling, which is how the
 * landing preview is shown.
 */
function drawCell(c, x, y, size, key, ghost = false) {
    const color = COLORS[key];
    const pad = Math.max(1, size * 0.06);

    c.beginPath();
    c.roundRect(x + pad, y + pad, size - pad * 2, size - pad * 2, size * 0.18);

    if (ghost) {
        c.save();
        c.globalAlpha = 0.45;
        c.strokeStyle = color;
        c.lineWidth = 2;
        c.stroke();
        c.restore();
        return;
    }

    c.fillStyle = color;
    c.fill();
    c.strokeStyle = 'rgba(0, 0, 0, 0.12)';
    c.lineWidth = 1;
    c.stroke();

    // Clip to the block, then paint a soft highlight along its top edge.
    c.save();
    c.clip();
    c.fillStyle = 'rgba(255, 255, 255, 0.28)';
    c.fillRect(x, y, size, size * 0.34);
    c.restore();
}

function drawShape(c, shape, key, row, col, size, ghost = false) {
    for (let r = 0; r < shape.length; r++) {
        for (let cc = 0; cc < shape[r].length; cc++) {
            if (shape[r][cc]) {
                drawCell(c, (col + cc) * size, (row + r) * size, size, key, ghost);
            }
        }
    }
}

/**
 * A row that is about to vanish. A band of white sweeps across it, then the
 * whole row fades. Reduced motion skips the sweep and just fades.
 */
function drawClearingRow(row, p) {
    const sweep = REDUCED ? 1 : Math.min(1, p * 1.7);
    const fade = p < 0.55 ? 1 : 1 - (p - 0.55) / 0.45;

    for (let col = 0; col < COLS; col++) {
        const key = grid[row][col];
        if (!key) continue;

        ctx.save();
        ctx.globalAlpha = fade;
        drawCell(ctx, col * CELL, row * CELL, CELL, key);

        if (sweep > (col + 0.5) / COLS) {
            ctx.globalAlpha = fade * 0.9;
            ctx.beginPath();
            ctx.roundRect(col * CELL + 2, row * CELL + 2, CELL - 4, CELL - 4, CELL * 0.18);
            ctx.fillStyle = '#ffffff';
            ctx.fill();
        }

        ctx.restore();
    }
}

/** Vertical offset for a settled row while the stack is cascading down. */
function rowOffset(row) {
    if (!anim || anim.kind !== 'fall') return 0;

    const start = anim.offsets[row];
    if (!start) return 0;

    const delay = Math.min(ROWS - 1 - row, 7) * 8;
    const p = clamp01((anim.t - delay) / anim.dur);

    return start * (1 - easeOut(p));
}

function drawBoard() {
    ctx.save();
    ctx.clearRect(0, 0, canvas.width, canvas.height);

    if (shake > 0.1) {
        ctx.translate((Math.random() - 0.5) * shake, (Math.random() - 0.5) * shake);
    }

    // Empty cells stay as faint guides so the playfield reads as a grid.
    ctx.strokeStyle = '#ebedef';
    ctx.lineWidth = 1;
    for (let row = 0; row < ROWS; row++) {
        for (let col = 0; col < COLS; col++) {
            if (!grid[row][col]) {
                ctx.strokeRect(col * CELL + 0.5, row * CELL + 0.5, CELL - 1, CELL - 1);
            }
        }
    }

    const flashing = state === 'clearing' ? anim.rows : [];
    const flashP = state === 'clearing' ? clamp01(anim.t / anim.dur) : 0;

    for (let row = 0; row < ROWS; row++) {
        if (flashing.includes(row)) {
            drawClearingRow(row, flashP);
            continue;
        }

        const dy = rowOffset(row);
        for (let col = 0; col < COLS; col++) {
            if (grid[row][col]) {
                drawCell(ctx, col * CELL, row * CELL + dy, CELL, grid[row][col]);
            }
        }
    }

    // The falling piece is hidden while the board is resolving a clear.
    if (piece && (state === 'playing' || state === 'paused')) {
        drawShape(ctx, piece.shape, piece.key, landingRow(), piece.col, CELL, true);
        drawShape(ctx, piece.shape, piece.key, piece.row, piece.col, CELL);
    }

    drawParticles();
    drawPopups();
    ctx.restore();

    if (state === 'paused') drawOverlay('Paused', 'Press P or tap Resume');
    if (state === 'over') drawOverlay('Game over', 'Press New game to play again');
    if (state === 'attract') drawOverlay('Ready', 'Press New game to start');
}

function drawOverlay(title, subtitle) {
    ctx.save();
    ctx.fillStyle = 'rgba(255, 255, 255, 0.6)';
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    // Sized from the backing store, not in fixed px: the canvas is scaled down
    // on a phone, so fixed 18px would land at roughly 12px on screen.
    const big = Math.round(canvas.width * 0.095);
    const small = Math.round(canvas.width * 0.058);

    ctx.textAlign = 'center';
    ctx.fillStyle = '#1a1a1a';
    ctx.font = `600 ${big}px system-ui, sans-serif`;
    ctx.fillText(title, canvas.width / 2, canvas.height / 2 - small * 0.5);

    ctx.fillStyle = '#6a6a6a';
    ctx.font = `${small}px system-ui, sans-serif`;
    ctx.fillText(subtitle, canvas.width / 2, canvas.height / 2 + big);
    ctx.restore();
}

function drawParticles() {
    for (const p of particles) {
        ctx.save();
        ctx.globalAlpha = (p.life / p.max) * 0.5;
        ctx.fillStyle = '#5c5f62';
        ctx.beginPath();
        ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
        ctx.fill();
        ctx.restore();
    }
}

function drawPopups() {
    for (const p of popups) {
        const t = 1 - p.life / p.max;

        ctx.save();
        ctx.globalAlpha = 1 - t * t;
        ctx.textAlign = 'center';
        ctx.fillStyle = '#2f7a4d';
        ctx.font = '600 16px system-ui, sans-serif';
        ctx.fillText(p.text, p.x, p.y - t * 28);
        ctx.restore();
    }
}

/** Renders a single piece centred in one of the small preview canvases. */
function drawPreview(el, key) {
    if (!el) return;

    const c = el.getContext('2d');
    c.clearRect(0, 0, el.width, el.height);
    if (!key) return;

    const shape = SHAPES[key];
    const size = 14;
    const w = shape[0].length * size;
    const h = shape.length * size;

    c.save();
    c.translate((el.width - w) / 2, (el.height - h) / 2);
    drawShape(c, shape, key, 0, 0, size);
    c.restore();
}

function drawPanels() {
    drawPreview(document.getElementById('hold'), held);
    document.querySelectorAll('.preview').forEach((el, i) => drawPreview(el, queue[i]));
}

function updateStats(cleared) {
    document.getElementById('score').textContent = score.toLocaleString();
    document.getElementById('lines').textContent = lines;
    document.getElementById('level').textContent = String(level).padStart(2, '0');

    const last = document.getElementById('last-clear');
    if (last && cleared) {
        last.textContent = `+${POINTS[cleared] * level} · Last clear: ${CLEAR_NAMES[cleared]}`;
    }
}

// ------------------------------------------------------------------- effects

/** A puff of dust under each column the piece came to rest on. */
function spawnDust(shape, row, col) {
    for (let c = 0; c < shape[0].length; c++) {
        let bottom = -1;
        for (let r = 0; r < shape.length; r++) if (shape[r][c]) bottom = r;
        if (bottom < 0) continue;

        const x = (col + c) * CELL + CELL / 2;
        const y = (row + bottom + 1) * CELL;

        for (let i = 0; i < (REDUCED ? 2 : 5); i++) {
            particles.push({
                x: x + (Math.random() - 0.5) * CELL,
                y,
                vx: (Math.random() - 0.5) * 0.09,
                vy: -Math.random() * 0.05,
                r: 1.5 + Math.random() * 2.5,
                life: 300,
                max: 300,
            });
        }
    }
}

function updateParticles(dt) {
    for (const p of particles) {
        p.x += p.vx * dt;
        p.y += p.vy * dt;
        p.vy += 0.00022 * dt;   // a touch of gravity so the dust settles
        p.life -= dt;
    }

    particles = particles.filter((p) => p.life > 0);
}

function updatePopups(dt) {
    for (const p of popups) p.life -= dt;
    popups = popups.filter((p) => p.life > 0);
}

// ------------------------------------------------------------- board changes

/**
 * Removes the cleared rows and returns, per row of the NEW grid, how far above
 * its new home that row starts. The cascade animates those offsets back to 0.
 */
function applyClear(rows) {
    const gone = new Set(rows);
    const kept = [];

    for (let r = 0; r < ROWS; r++) {
        if (!gone.has(r)) kept.push({ from: r, cells: grid[r] });
    }

    const next = Array.from({ length: ROWS }, () => Array(COLS).fill(null));
    const offsets = new Array(ROWS).fill(0);

    let write = ROWS - 1;
    for (let i = kept.length - 1; i >= 0; i--) {
        next[write] = kept[i].cells;
        offsets[write] = (kept[i].from - write) * CELL;   // negative: starts higher up
        write--;
    }

    grid = next;
    return offsets;
}

function lock(hard) {
    const { shape, row, col, key } = piece;

    for (let r = 0; r < shape.length; r++) {
        for (let c = 0; c < shape[r].length; c++) {
            if (shape[r][c] && row + r >= 0) grid[row + r][col + c] = key;
        }
    }

    spawnDust(shape, row, col);
    if (hard && !REDUCED) shake = 7;
    piece = null;

    const full = [];
    for (let r = 0; r < ROWS; r++) if (grid[r].every(Boolean)) full.push(r);

    if (full.length === 0) {
        spawn();
        return;
    }

    const cleared = full.length;
    lines += cleared;
    level = Math.floor(lines / 10) + 1;
    score += POINTS[cleared] * level;
    updateStats(cleared);

    popups.push({
        text: `+${POINTS[cleared] * level} ${CLEAR_NAMES[cleared]}`,
        x: canvas.width / 2,
        y: full[0] * CELL,
        life: 800,
        max: 800,
    });

    state = 'clearing';
    anim = { kind: 'clear', rows: full, t: 0, dur: REDUCED ? 110 : 150 };
}

function spawn() {
    const key = queue.shift();
    queue.push(nextKey());
    drawPanels();

    const shape = SHAPES[key].map((r) => r.slice());
    const col = Math.floor((COLS - shape[0].length) / 2);

    if (collides(shape, 0, col)) {
        piece = null;
        state = 'over';
        submitScore();
        return;
    }

    piece = { key, shape, row: 0, col };
    dropAcc = 0;      // don't carry the last piece's leftover gravity into this one
    state = 'playing';
}

/** Shows how many swaps are left and disables the Hold button at zero. */
function updateHoldUi() {
    const label = document.getElementById('hold-count');
    if (label) {
        label.textContent = holdsLeft === 0
            ? 'No holds left'
            : `${holdsLeft} of ${MAX_HOLDS} holds left`;
    }

    // The HOLD side panel is hidden below 720px, so the phone layout carries the
    // count in its gesture hint instead -- there is no Hold button to put it on.
    const hint = document.getElementById('touch-hint');
    if (hint) {
        hint.textContent = holdsLeft === 0
            ? 'Drag to move · Tap to rotate · Flick down to drop · No holds left'
            : `Drag to move · Tap to rotate · Flick down to drop · Swipe up to hold (${holdsLeft})`;
    }
}

/**
 * Swaps the falling piece with the one in HOLD. Limited to MAX_HOLDS per game,
 * which also removes the need for a per-piece guard: swapping back and forth
 * just spends the budget faster.
 */
function swapHold() {
    if (state !== 'playing' || !piece || holdsLeft <= 0) return;

    const outgoing = piece.key;

    if (held === null) {
        held = outgoing;
        spawn();
    } else {
        const shape = SHAPES[held].map((r) => r.slice());
        const col = Math.floor((COLS - shape[0].length) / 2);
        if (collides(shape, 0, col)) return;   // no room to bring it back in

        piece = { key: held, shape, row: 0, col };
        held = outgoing;
    }

    holdsLeft--;
    updateHoldUi();
    drawPanels();
}

function step() {
    if (!collides(piece.shape, piece.row + 1, piece.col)) {
        piece.row++;
    } else {
        lock(false);
    }
}

// --------------------------------------------------------------- the rAF loop

/**
 * Writes mm:ss into the clock badge, but only when the whole second changes --
 * the rAF loop runs ~60 times a second and the DOM does not need that.
 */
function updateClock() {
    const total = Math.floor(elapsed / 1000);
    if (total === clockShown) return;
    clockShown = total;

    const el = document.getElementById('clock');
    if (!el) return;

    const mm = String(Math.floor(total / 60)).padStart(2, '0');
    const ss = String(total % 60).padStart(2, '0');
    el.textContent = `${mm}:${ss}`;
}

function update(dt) {
    if (shake > 0) shake = Math.max(0, shake - dt * 0.03);

    // The clock runs while the game is live. 'clearing' and 'falling' count --
    // they are mid-game animations, not a pause. It stops on paused, over and
    // attract, which is what the player expects.
    if (state === 'playing' || state === 'clearing' || state === 'falling') {
        elapsed += dt;
    }

    updateClock();
    updateParticles(dt);
    updatePopups(dt);

    if (state === 'playing') {
        dropAcc += dt;
        const interval = Math.max(90, 650 - (level - 1) * 55);
        while (state === 'playing' && dropAcc >= interval) {
            dropAcc -= interval;
            step();
        }
    } else if (state === 'clearing') {
        anim.t += dt;
        if (anim.t >= anim.dur) {
            anim = { kind: 'fall', offsets: applyClear(anim.rows), t: 0, dur: 140 };
            state = 'falling';
        }
    } else if (state === 'falling') {
        anim.t += dt;
        if (anim.t >= anim.dur + 60) {
            anim = null;
            spawn();
        }
    }
}

let last = performance.now();

function frame(now) {
    const dt = Math.min(now - last, 50);   // clamped so a backgrounded tab can't jump the game
    last = now;

    update(dt);
    drawBoard();
    requestAnimationFrame(frame);
}

// ---------------------------------------------------------------- game setup

/** A few settled rows so the board isn't an empty box before the first game. */
function seedDemoStack() {
    const rows = [
        [null, null, 'J', 'J', null, null, null, null, null, null],
        [null, 'I', 'J', 'J', null, null, null, 'S', 'S', null],
        [null, 'I', 'L', 'L', 'O', 'O', null, 'S', 'S', null],
        ['O', 'O', 'L', 'Z', 'Z', 'T', null, 'J', 'J', 'J'],
        ['O', 'O', 'I', 'Z', 'Z', 'T', 'T', null, 'I', 'I'],
    ];

    rows.forEach((cells, i) => { grid[ROWS - rows.length + i] = cells; });
}

/**
 * Starts a game.
 *
 * The session is opened here and awaited, not fetched in advance: the server
 * timestamps that row and uses it to measure how long the game really took, so
 * opening it early would count idle time as play time. If the call fails the
 * game still runs, but it cannot be posted to the leaderboard.
 */
async function newGame() {
    if (starting) return;          // a double tap must not open two games
    starting = true;

    try {
        session = await apiFetch('/api/game/start', { method: 'POST' });
        rng = makeRng(session.seed);
    } catch {
        session = null;
        rng = makeRng((Math.random() * 0xffffffff) >>> 0);
    } finally {
        starting = false;
    }

    grid = Array.from({ length: ROWS }, () => Array(COLS).fill(null));
    bag = [];
    queue = [nextKey(), nextKey(), nextKey()];
    held = null;
    holdsLeft = MAX_HOLDS;
    particles = [];
    popups = [];
    anim = null;
    shake = 0;
    dropAcc = 0;
    score = 0;
    lines = 0;
    level = 1;
    submitted = false;
    elapsed = 0;
    clockShown = -1;

    const last = document.getElementById('last-clear');
    if (last) last.textContent = 'No clears yet';

    updateStats(0);
    updateHoldUi();
    updateClock();
    spawn();
    canvas.focus({ preventScroll: true });
}

function setPaused(paused) {
    state = paused ? 'paused' : 'playing';
    document.getElementById('btn-pause').textContent = paused ? 'Resume' : 'Pause';
    if (!paused) canvas.focus({ preventScroll: true });
}

// ------------------------------------------------------------------- controls

function move(dx) {
    if (state !== 'playing' || !piece) return;
    if (!collides(piece.shape, piece.row, piece.col + dx)) piece.col += dx;
}

function softDrop() {
    if (state !== 'playing' || !piece) return;
    dropAcc = 0;
    step();
}

function hardDrop() {
    if (state !== 'playing' || !piece) return;
    piece.row = landingRow();
    lock(true);
}

function turn() {
    if (state !== 'playing' || !piece) return;

    const shape = rotate(piece.shape);

    // Wall kick: if the rotation clips a wall, nudge it a column either way.
    for (const dx of [0, -1, 1, -2, 2]) {
        if (!collides(shape, piece.row, piece.col + dx)) {
            piece.shape = shape;
            piece.col += dx;
            return;
        }
    }
}

// ------------------------------------------------------------ touch gestures
//
// On a phone the board IS the controller -- there are no on-screen buttons:
//
//   drag left / right   move one cell per cell-width of travel
//   drag down           soft drop, one row per cell-height
//   flick down          hard drop
//   tap                 rotate (or start a game from the Ready / Game over screen)
//   swipe up            hold
//
// Travel is measured against the DISPLAYED cell size from getBoundingClientRect,
// not the CELL constant, because the canvas is scaled down to fit the screen.
// Using CELL would make the piece lag the finger on every phone.

const TAP_MS = 250;        // a press shorter than this can be a tap
const TAP_SLOP = 12;       // px of travel still forgiven as a tap
const FLICK_SPEED = 0.6;   // px per ms downward that counts as a hard drop
const SWIPE_UP = 48;       // px of upward travel that triggers hold

let gesture = null;

function cellSize() {
    const rect = canvas.getBoundingClientRect();
    return { x: rect.width / COLS, y: rect.height / ROWS };
}

canvas.addEventListener('pointerdown', (e) => {
    canvas.focus({ preventScroll: true });
    canvas.setPointerCapture?.(e.pointerId);

    gesture = {
        x: e.clientX, y: e.clientY,         // the last position we acted on
        startX: e.clientX, startY: e.clientY,
        t: performance.now(),
        travel: 0,
    };

    e.preventDefault();
});

canvas.addEventListener('pointermove', (e) => {
    if (!gesture) return;
    e.preventDefault();

    gesture.travel += Math.abs(e.clientX - gesture.x) + Math.abs(e.clientY - gesture.y);

    if (state !== 'playing') {
        gesture.x = e.clientX;
        gesture.y = e.clientY;
        return;
    }

    const cell = cellSize();

    // Horizontal: the piece tracks the finger, one cell at a time.
    while (e.clientX - gesture.x >= cell.x) { move(1); gesture.x += cell.x; }
    while (gesture.x - e.clientX >= cell.x) { move(-1); gesture.x -= cell.x; }

    // Downward drag soft-drops. An upward drag must never raise the piece, so
    // the anchor follows the finger up without stepping anything.
    while (e.clientY - gesture.y >= cell.y) { softDrop(); gesture.y += cell.y; }
    if (e.clientY < gesture.y) gesture.y = e.clientY;
});

function endGesture(e) {
    if (!gesture) return;

    const g = gesture;
    gesture = null;

    const dt = Math.max(1, performance.now() - g.t);
    const dx = e.clientX - g.startX;
    const dy = e.clientY - g.startY;

    // A tap barely moves. On the Ready / Game over screen it starts a game.
    if (g.travel < TAP_SLOP && dt < TAP_MS) {
        if (state === 'attract' || state === 'over') newGame();
        else if (state === 'playing') turn();
        return;
    }

    if (state !== 'playing') return;

    if (-dy > SWIPE_UP && Math.abs(dy) > Math.abs(dx)) {
        swapHold();
    } else if (dy > 0 && dy / dt > FLICK_SPEED) {
        hardDrop();
    }
}

canvas.addEventListener('pointerup', endGesture);
canvas.addEventListener('pointercancel', () => { gesture = null; });

document.getElementById('btn-new').addEventListener('click', () => {
    document.getElementById('btn-pause').textContent = 'Pause';
    newGame();
});

document.getElementById('btn-pause').addEventListener('click', () => {
    if (state === 'playing') setPaused(true);
    else if (state === 'paused') setPaused(false);
});

// Keys are bound to the canvas, not the window. The board only reacts when it
// has focus, so a focused s-button keeps Space and Enter for itself and the
// admin iframe never steals or scrolls on the arrow keys.
const KEYS = {
    ArrowLeft: () => move(-1),
    ArrowRight: () => move(1),
    ArrowUp: turn,
    ArrowDown: softDrop,
    ' ': hardDrop,
    c: swapHold,
    C: swapHold,
    p: () => { if (state === 'playing' || state === 'paused') setPaused(state === 'playing'); },
    P: () => { if (state === 'playing' || state === 'paused') setPaused(state === 'playing'); },
};

canvas.addEventListener('keydown', (e) => {
    const fn = KEYS[e.key];
    if (!fn) return;

    e.preventDefault();
    fn();
});


// Gets a fresh session token from App Bridge and calls our API with it.
async function apiFetch(path, options = {}) {
    const token = await shopify.idToken();
    const res = await fetch(path, {
        ...options,
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${token}`,
            ...(options.headers ?? {}),
        },
    });
    return res.json();
}

// Fill the "Best" badge from the leaderboard instead of hard-coding a number.
async function loadBest() {
    try {
        const result = await apiFetch('/api/leaderboard/top');
        const top = result?.[0]?.best_score;   // top() returns a bare array
        if (top != null) {
            document.getElementById('best').textContent = `Best ${top.toLocaleString()}`;
        }
    } catch {
        // Leave the badge as it is; a missing leaderboard must not stop the game.
    }
}

const NICK_KEY = 'brick-breaker-nickname';

// localStorage can be partitioned or blocked outright inside the admin iframe,
// so every access is guarded and the game works fine without it.
function restoreNickname() {
    try {
        const saved = localStorage.getItem(NICK_KEY);
        const field = document.getElementById('nickname');
        if (saved && field) field.value = saved;
    } catch { /* storage unavailable */ }
}

function rememberNickname(name) {
    try {
        localStorage.setItem(NICK_KEY, name);
    } catch { /* storage unavailable */ }
}

/**
 * Posts the finished game to the leaderboard. Deliberately async with a blanket
 * catch: it is called from spawn(), which runs inside the rAF loop, and a throw
 * there would stop the loop rescheduling and freeze the board.
 */
async function submitScore() {
    if (submitted) return;
    submitted = true;

    const note = document.getElementById('last-clear');

    try {
        if (score <= 0) return;

        if (!session) {
            if (note) note.textContent = 'This game was not verified, so it cannot be posted.';
            return;
        }

        const field = document.getElementById('nickname');
        const nickname = (field?.value ?? '').trim();

        if (!nickname) {
            showNicknameError('Add a name to post your score.');
            return;
        }

        if (nicknameIsBlocked(nickname)) {
            showNicknameError(NAME_BLOCKED_MSG);
            return;
        }

        const best = Math.min(100000, Math.max(0, Math.round(score)));

        const result = await apiFetch('/api/leaderboard/submit', {
            method: 'POST',
            body: JSON.stringify({
                gameId: session.gameId,
                nickname,
                best_score: best,
                lines,
            }),
        });

        if (result?.saved === true) {
            rememberNickname(nickname);
            showNicknameError('');
            if (note) note.textContent = `Score posted as ${nickname}.`;
            await loadBest();
        } else {
            const serverMsg = result?.errors?.nickname?.[0];
            if (serverMsg) {
                showNicknameError(serverMsg);
            } else if (note) {
                note.textContent = result?.message ?? 'That score could not be posted.';
            }
        }
    } catch {
        if (note) note.textContent = 'Could not reach the leaderboard.';
    }
}

// Same word list as AllowedNickname.php. Keep the two lists identical.
const BLOCKED_SUBSTRINGS = [
    'fuck', 'shit', 'bitch', 'bastard', 'cunt', 'whore', 'slut',
    'nigger', 'nigga', 'faggot', 'retard', 'rape', 'nazi', 'porn',
    'idiot', 'moron', 'dumbass', 'asshole', 'loser', 'wanker', 'twat', 'prick', 'scumbag',
    'bewakoof', 'bewaqoof', 'bevaqoof', 'jahil', 'kamina', 'kameena', 'harami',
    'zaleel', 'badtameez', 'ghatiya', 'kutta', 'kutte', 'kutti', 'chutiya',
    'madarchod', 'bhenchod', 'behenchod',
    'dhakkan', 'nikamma', 'gadha', 'gadhe', 'besharam', 'ulludapattha', 'ulludapatha',
];
const BLOCKED_EXACT = [
    'ass', 'arse', 'dick', 'cock', 'pussy', 'tit', 'tits', 'piss', 'fag',
];

function nicknameIsBlocked(name) {
    const n = name.toLowerCase()
        .replace(/0/g, 'o').replace(/1/g, 'i').replace(/3/g, 'e')
        .replace(/[4@]/g, 'a').replace(/[5$]/g, 's')
        .replace(/[^a-z]/g, '');

    if (BLOCKED_SUBSTRINGS.some((w) => n.includes(w))) return true;
    return BLOCKED_EXACT.includes(n);
}

let nicknameErrorEl = null;

// Shows a message directly under the name field. An empty string clears it.
function showNicknameError(msg) {
    const field = document.getElementById('nickname');
    if (!field) return;

    if (!nicknameErrorEl) {
        nicknameErrorEl = document.createElement('div');
        nicknameErrorEl.id = 'nickname-error';
        nicknameErrorEl.setAttribute('role', 'alert');
        nicknameErrorEl.style.cssText = 'color:#d72c0d;font-size:13px;margin-top:4px;';
        field.insertAdjacentElement('afterend', nicknameErrorEl);
    }

    nicknameErrorEl.textContent = msg;
    field.setAttribute('aria-invalid', msg ? 'true' : 'false');
}

const NAME_BLOCKED_MSG = 'This name is not allowed. Please choose another name.';
const nicknameField = document.getElementById('nickname');
if (nicknameField) {
    nicknameField.addEventListener('input', () => {
        showNicknameError(nicknameIsBlocked(nicknameField.value) ? NAME_BLOCKED_MSG : '');
    });
}

seedDemoStack();
queue = [nextKey(), nextKey(), nextKey()];
drawPanels();
updateHoldUi();
restoreNickname();
loadBest();
requestAnimationFrame(frame);
