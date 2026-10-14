// Runs the real game.js in a stubbed DOM so the clear / cascade / dust / popup
// / overlay / submit code paths actually execute. node --check only proves it
// parses; a bad name inside drawClearingRow would throw on the first line clear
// and, because frame() reschedules rAF on its last line, freeze the game for good.
const fs = require('fs');
const { join } = require('path');
const vm = require('vm');
const assert = require('assert');

const src = fs.readFileSync(join(__dirname, '..', '..', 'public', 'js', 'game.js'), 'utf8');

// Any ctx.* call is a no-op that returns a no-op.
const noopCtx = new Proxy({}, { get: () => () => {} });

// Listeners are recorded per element id so the test can fire pointer events at
// the board the same way a browser would.
const listeners = {};

function stubEl(id) {
    const bag = (listeners[id] = {});

    return {
        width: 240,
        height: 480,
        value: '',
        textContent: '',
        getContext: () => noopCtx,
        addEventListener: (type, fn) => { (bag[type] ||= []).push(fn); },
        focus: () => {},
        // 240x480 displayed 1:1, so one cell is 24px on screen.
        getBoundingClientRect: () => ({ width: 240, height: 480, left: 0, top: 0 }),
    };
}

const els = {};

// Shared with the sandbox so the test body can read posts and flip failFetch.
const ctl = { posts: [], failFetch: false, now: 0 };

const sandbox = {
    console,
    Math,
    Object,
    Array,
    Set,
    Number,
    String,
    JSON,
    Promise,
    setTimeout,
    window: { matchMedia: () => ({ matches: false }) },
    document: {
        getElementById: (id) => (els[id] ||= stubEl(id)),
        querySelectorAll: () => [],
    },
    // Controllable clock: a stuck clock makes every drag look like a flick.
    performance: { now: () => ctl.now },
    requestAnimationFrame: () => {},
    shopify: { idToken: async () => 'stub' },
    localStorage: {
        store: {},
        getItem(k) { return this.store[k] ?? null; },
        setItem(k, v) { this.store[k] = v; },
    },
    fetch: async (path, options) => {
        if (ctl.failFetch) throw new Error('network down');
        if (options?.method === 'POST') {
            ctl.posts.push(JSON.parse(options.body));
            return { json: async () => ({ saved: true, day: '2026-10-14' }) };
        }
        return { json: async () => [] };
    },
};

const TEST = `
const settle = () => new Promise((r) => setTimeout(r, 0));

;(async function () {
    // ---- 1. a real line clear, start to finish -------------------------
    newGame();
    assert.strictEqual(state, 'playing', 'newGame should start play');

    // Leave columns 0-3 open, then hard-drop a flat I piece into them.
    for (let c = 4; c < COLS; c++) grid[19][c] = 'A';
    piece = { key: 'I', shape: [[1, 1, 1, 1]], row: 0, col: 0 };

    hardDrop();
    assert.strictEqual(state, 'clearing', 'a full row must enter the clearing state');
    assert.ok(particles.length > 0, 'landing dust should have spawned');
    assert.ok(popups.length > 0, 'a score popup should have spawned');
    assert.ok(shake > 0, 'a hard drop should shake the board');

    // Drive flash -> cascade -> respawn, drawing every frame so drawClearingRow
    // / rowOffset / drawPopups / drawParticles all actually execute.
    let sawFalling = false;
    for (let i = 0; i < 40; i++) {
        update(16);
        drawBoard();
        if (state === 'falling') sawFalling = true;
    }

    assert.ok(sawFalling, 'the cascade state should have been entered');
    assert.strictEqual(state, 'playing', 'play should resume after the cascade');
    assert.strictEqual(lines, 1, 'one line cleared');
    assert.strictEqual(score, 100, 'a single at level 1 scores 100');
    assert.strictEqual(grid[19].filter(Boolean).length, 0, 'the cleared row is gone');
    console.log('  1. line clear + cascade          OK');

    // ---- 2. rotation, hold, pause overlay ------------------------------
    turn();
    assert.ok(Array.isArray(piece.shape), 'rotate produced a shape');

    // Hold is a budget of MAX_HOLDS for the whole game.
    assert.strictEqual(holdsLeft, MAX_HOLDS, 'a new game starts with a full budget');

    const wasKey = piece.key;
    swapHold();
    assert.strictEqual(held, wasKey, 'hold parks the falling piece');
    assert.strictEqual(holdsLeft, MAX_HOLDS - 1, 'a swap spends one hold');

    swapHold();
    swapHold();
    assert.strictEqual(holdsLeft, 0, 'the budget runs out after MAX_HOLDS swaps');
    assert.match(document.getElementById('touch-hint').textContent, /no holds left/i,
        'the touch hint reports an exhausted budget');

    const parked = held;
    const falling = piece.key;
    swapHold();
    assert.strictEqual(held, parked, 'a swap past the budget changes nothing');
    assert.strictEqual(piece.key, falling, 'the falling piece is untouched too');

    newGame();
    assert.strictEqual(holdsLeft, MAX_HOLDS, 'New game refills the budget');
    assert.match(document.getElementById('touch-hint').textContent, /swipe up to hold \\(3\\)/i,
        'New game restores the hint to a full budget');

    // ---- 2b. touch gestures --------------------------------------------
    // The stub reports a 240x480 box, so one displayed cell is 24px.
    const PX = 24;
    const pt = (x, y) => ({ clientX: x, clientY: y, pointerId: 1, preventDefault() {} });
    const fire = (type, ev) => (listeners.board[type] || []).forEach((fn) => fn(ev));

    // Drag right three cells, slowly enough not to read as a flick. The piece is
    // pinned to a 2-wide O at column 2 so the drag can't be stopped by the wall
    // (a random 4-wide I at column 4 would legitimately block the third step).
    newGame();
    piece = { key: 'O', shape: [[1, 1], [1, 1]], row: 0, col: 2 };
    const beforeCol = piece.col;
    fire('pointerdown', pt(100, 100));
    fire('pointermove', pt(100 + 3 * PX, 100));
    ctl.now += 300;
    fire('pointerup', pt(100 + 3 * PX, 100));
    assert.strictEqual(piece.col, beforeCol + 3, 'dragging right moves three cells');

    // Drag down two cells.
    const beforeRow = piece.row;
    fire('pointerdown', pt(100, 100));
    fire('pointermove', pt(100, 100 + 2 * PX));
    ctl.now += 300;
    fire('pointerup', pt(100, 100 + 2 * PX));
    assert.ok(piece.row >= beforeRow + 2, 'dragging down soft-drops');

    // A short upward drag must never raise the piece. It stays under SWIPE_UP
    // so it doesn't trigger hold, and over TAP_SLOP so it isn't read as a tap.
    const beforeUp = piece.row;
    const beforeKey = piece.key;
    fire('pointerdown', pt(100, 300));
    fire('pointermove', pt(100, 270));
    ctl.now += 300;
    fire('pointerup', pt(100, 270));
    assert.strictEqual(piece.row, beforeUp, 'an upward drag never moves the piece up');
    assert.strictEqual(piece.key, beforeKey, 'and does not swap it either');

    // A fast downward flick hard-drops: the piece locks and a new one spawns.
    const flickKey = piece.key;
    fire('pointerdown', pt(100, 100));
    fire('pointermove', pt(100, 100 + PX));
    ctl.now += 10;                       // 100px / 10ms is well past FLICK_SPEED
    fire('pointerup', pt(100, 400));
    assert.ok(piece.row <= 1, 'after a flick the board is on a freshly spawned piece');

    // A tap rotates and must not throw.
    assert.doesNotThrow(() => {
        fire('pointerdown', pt(100, 100));
        fire('pointerup', pt(100, 100));
    }, 'a tap must not throw');

    // A long swipe up spends a hold. The intermediate move matters: travel is
    // only accumulated on pointermove, and without it this reads as a tap.
    const beforeHolds = holdsLeft;
    fire('pointerdown', pt(100, 300));
    fire('pointermove', pt(100, 210));
    ctl.now += 150;
    fire('pointerup', pt(100, 200));     // 100px up, mostly vertical
    assert.strictEqual(holdsLeft, beforeHolds - 1, 'swiping up spends one hold');
    console.log('  2b. touch gestures               OK');

    // ---- 2c. the clock --------------------------------------------------
    newGame();
    assert.strictEqual(document.getElementById('clock').textContent, '00:00',
        'New game resets the clock');

    for (let i = 0; i < 65; i++) update(1000);   // 65 seconds of play
    assert.strictEqual(document.getElementById('clock').textContent, '01:05',
        'the clock shows mm:ss');

    // Paused and over must freeze it.
    setPaused(true);
    const atPause = document.getElementById('clock').textContent;
    for (let i = 0; i < 5; i++) update(1000);
    assert.strictEqual(document.getElementById('clock').textContent, atPause,
        'the clock stops while paused');

    setPaused(false);
    update(1000);
    assert.strictEqual(document.getElementById('clock').textContent, '01:06',
        'and resumes afterwards');
    console.log('  2c. clock mm:ss                  OK');

    setPaused(true);
    assert.strictEqual(state, 'paused');
    drawBoard();                 // exercises drawOverlay
    setPaused(false);
    console.log('  2. rotate / hold / pause         OK');

    // ---- 3. game over posts the score ----------------------------------
    document.getElementById('nickname').value = '  Ali  ';
    score = 123456;              // deliberately over the server's 100000 cap

    for (let r = 0; r < ROWS; r++) for (let c = 0; c < COLS; c++) grid[r][c] = 'A';
    assert.doesNotThrow(() => spawn(), 'game over must not throw into the rAF loop');
    assert.strictEqual(state, 'over', 'a blocked spawn ends the game');
    assert.doesNotThrow(() => drawBoard(), 'the game-over overlay renders');

    await settle();
    assert.strictEqual(ctl.posts.length, 1, 'exactly one score posted');
    assert.strictEqual(ctl.posts[0].nickname, 'Ali', 'nickname is trimmed');
    assert.strictEqual(ctl.posts[0].best_score, 100000, 'score clamped to the server cap');
    assert.ok(Number.isInteger(ctl.posts[0].best_score), 'best_score is an integer');

    submitScore();               // a repeat must not post again
    await settle();
    assert.strictEqual(ctl.posts.length, 1, 'one post per game');
    console.log('  3. submit, trimmed + clamped     OK');

    // ---- 4. no nickname: skip, don't post ------------------------------
    newGame();
    document.getElementById('nickname').value = '   ';
    score = 400;
    for (let r = 0; r < ROWS; r++) for (let c = 0; c < COLS; c++) grid[r][c] = 'A';
    spawn();
    await settle();
    assert.strictEqual(ctl.posts.length, 1, 'an empty nickname must not post');
    assert.match(document.getElementById('last-clear').textContent, /leaderboard name/i,
        'the player is told why nothing was posted');
    console.log('  4. empty nickname skipped        OK');

    // ---- 5. the network is down ----------------------------------------
    ctl.failFetch = true;
    newGame();
    document.getElementById('nickname').value = 'Ali';
    score = 500;
    for (let r = 0; r < ROWS; r++) for (let c = 0; c < COLS; c++) grid[r][c] = 'A';

    assert.doesNotThrow(() => spawn(), 'a failing submit must not throw');
    assert.strictEqual(state, 'over');
    assert.doesNotThrow(() => drawBoard(), 'the board still renders');

    await settle();
    assert.strictEqual(ctl.posts.length, 1, 'the failed post was not recorded');
    assert.match(document.getElementById('last-clear').textContent, /could not reach/i,
        'the player sees the failure');
    console.log('  5. network failure handled       OK');

    console.log('\\nsmoke test: all paths executed cleanly');
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
`;

sandbox.assert = assert;
sandbox.ctl = ctl;
sandbox.listeners = listeners;
sandbox.process = process;
vm.createContext(sandbox);
vm.runInContext(src + TEST, sandbox, { filename: 'game.js' });
