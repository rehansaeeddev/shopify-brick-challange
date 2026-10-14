// Pulls applyClear out of the real game.js source so the test runs the shipped
// code rather than a re-typed copy of it.
const fs = require('fs');
const { join } = require('path');
const assert = require('assert');

const src = fs.readFileSync(join(__dirname, '..', '..', 'public', 'js', 'game.js'), 'utf8');
const m = src.match(/function applyClear\(rows\) \{[\s\S]*?\n\}/);
assert(m, 'could not find applyClear in game.js');

const COLS = 10;
const ROWS = 20;
const CELL = 24;
let grid;

// Evaluate the extracted function in a scope that supplies the `grid` binding
// it closes over, so it behaves exactly as it does in the page.
const run = (() => {
    const fn = eval(`(function (ROWS, COLS, CELL) {
        let grid;
        ${m[0]}
        return { applyClear, set: (g) => { grid = g; }, get: () => grid };
    })`)(ROWS, COLS, CELL);
    return (startGrid, rows) => {
        fn.set(startGrid);
        const offsets = fn.applyClear(rows);
        return { grid: fn.get(), offsets };
    };
})();

function emptyGrid() {
    return Array.from({ length: ROWS }, () => Array(COLS).fill(null));
}

// ---- case 1: two NON-ADJACENT full rows -----------------------------------
{
    const g = emptyGrid();
    g[19] = Array(COLS).fill('A');   // full
    g[18] = Array(COLS).fill(null); g[18][0] = 'b';
    g[17] = Array(COLS).fill('C');   // full
    g[16] = Array(COLS).fill(null); g[16][0] = 'd';

    const { grid: out, offsets } = run(g, [17, 19]);

    // 'b' (was row 18) should end at the bottom; 'd' (was 16) just above it.
    assert.strictEqual(out[19][0], 'b', 'row 18 should land at row 19');
    assert.strictEqual(out[18][0], 'd', 'row 16 should land at row 18');
    assert.strictEqual(out[17][0], null, 'row 17 should now be empty');

    // Offsets are how far ABOVE its new home each row starts (negative px).
    assert.strictEqual(offsets[19], (18 - 19) * CELL, 'row 19 starts 1 cell up');
    assert.strictEqual(offsets[18], (16 - 18) * CELL, 'row 18 starts 2 cells up');

    // Every row above the lowest clear shifts; none shifts by more than the
    // number of rows cleared beneath it, and none shifts upward.
    for (let r = 0; r < ROWS; r++) {
        assert.ok(offsets[r] <= 0, `row ${r} must not start below its home`);
        assert.ok(offsets[r] >= -2 * CELL, `row ${r} must not shift more than 2 cells`);
        assert.strictEqual(Math.abs(offsets[r]) % CELL, 0, `row ${r} offset is a whole cell`);
    }
    console.log('case 1 (non-adjacent clears) OK');
}

// ---- case 1b: rows BELOW a clear must not move ----------------------------
{
    const g = emptyGrid();
    g[17] = Array(COLS).fill('C');   // the only full row
    g[18][0] = 'x';
    g[19][0] = 'y';

    const { grid: out, offsets } = run(g, [17]);

    assert.strictEqual(out[19][0], 'y', 'row 19 stays put');
    assert.strictEqual(out[18][0], 'x', 'row 18 stays put');
    assert.strictEqual(offsets[19], 0, 'row below the clear does not animate');
    assert.strictEqual(offsets[18], 0, 'row below the clear does not animate');
    assert.strictEqual(offsets[17], -CELL, 'rows above drop exactly one cell');
    console.log('case 1b (rows below a clear) OK');
}

// ---- case 2: a tetris, four adjacent rows ---------------------------------
{
    const g = emptyGrid();
    for (let r = 16; r <= 19; r++) g[r] = Array(COLS).fill('A');
    g[15] = Array(COLS).fill(null); g[15][3] = 'z';

    const { grid: out, offsets } = run(g, [16, 17, 18, 19]);

    assert.strictEqual(out[19][3], 'z', 'the surviving row falls to the floor');
    assert.strictEqual(offsets[19], (15 - 19) * CELL, 'it starts 4 cells up');
    for (let r = 0; r < 19; r++) assert.strictEqual(out[r][3], null, `row ${r} empty`);
    console.log('case 2 (tetris) OK');
}

// ---- case 3: nothing is lost ----------------------------------------------
{
    const g = emptyGrid();
    for (let r = 0; r < ROWS; r++) g[r][5] = 'x';
    g[10] = Array(COLS).fill('F');

    const { grid: out } = run(g, [10]);
    const count = out.flat().filter(Boolean).length;
    assert.strictEqual(count, ROWS - 1, 'exactly one row of cells removed');
    console.log('case 3 (cell count) OK');
}

console.log('\nall row-clear assertions passed');
