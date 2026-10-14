const tableWrap = document.getElementById('table-wrap');
const statusWrap = document.getElementById('status-wrap');
const status = document.getElementById('status');
const tbody = document.getElementById('rows');
const table = document.getElementById('table');

// Gets a fresh session token from App Bridge and calls our API with it.
async function apiFetch(path, options = {}) {
    const token = await shopify.idToken();
    const res = await fetch(path, {
        ...options,
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${token}`,
        },
    });
    return res.json();
}

function showStatus(message) {
    status.textContent = message;
    statusWrap.hidden = false;
    tableWrap.hidden = true;
}

function render(rows) {
    tbody.replaceChildren();

    rows.forEach((row, i) => {
        const tr = document.createElement('s-table-row');
        const score = Number(row.best_score);

        const values = [
            String(i + 1),
            String(row.nickname ?? ''),
            Number.isFinite(score) ? score.toLocaleString() : '—',
        ];

        for (const value of values) {
            const cell = document.createElement('s-table-cell');
            // textContent, never innerHTML: nicknames are free text typed
            // by other players, so interpolating them would be an XSS hole.
            cell.textContent = value;
            tr.appendChild(cell);
        }

        tbody.appendChild(tr);
    });

    statusWrap.hidden = true;
    tableWrap.hidden = false;
}

async function load() {
    table.loading = true;

    try {
        const rows = await apiFetch('/api/leaderboard/top');

        if (!Array.isArray(rows)) {
            showStatus('The leaderboard returned something unexpected.');
        } else if (rows.length === 0) {
            showStatus('No scores posted today yet. Play a game to be the first.');
        } else {
            render(rows);
        }
    } catch {
        showStatus('Could not reach the leaderboard. Try refreshing.');
    } finally {
        table.loading = false;
    }
}

document.getElementById('btn-refresh').addEventListener('click', load);

load();
