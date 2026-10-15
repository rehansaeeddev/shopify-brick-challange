<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Privacy policy &mdash; Brick Breaker</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            margin: 0 auto;
            padding: 40px 20px 80px;
            max-width: 42rem;
            font: 16px/1.65 system-ui, -apple-system, "Segoe UI", sans-serif;
            color: #1a1a1a;
            background: #ffffff;
        }
        h1 { font-size: 1.7rem; margin: 0 0 0.25em; }
        h2 { font-size: 1.1rem; margin: 2em 0 0.5em; }
        .updated { color: #6a6a6a; margin: 0 0 2em; font-size: 0.9rem; }
        table { border-collapse: collapse; width: 100%; margin: 1em 0; }
        th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #e1e3e5; vertical-align: top; }
        th { font-weight: 600; }
        code { background: #f1f2f3; padding: 1px 5px; border-radius: 4px; font-size: 0.9em; }
        @media (prefers-color-scheme: dark) {
            body { color: #e3e3e3; background: #1a1a1a; }
            th, td { border-bottom-color: #3a3a3a; }
            code { background: #2b2b2b; }
            .updated { color: #9a9a9a; }
        }
    </style>
</head>
<body>
    <h1>Privacy policy</h1>
    <p class="updated">Brick Breaker for Shopify &middot; last updated {{ now('UTC')->toFormattedDateString() }}</p>

    <p>
        Brick Breaker is a game that staff play inside the Shopify admin. It keeps a
        daily leaderboard. This page lists everything the app stores, and nothing else
        is collected.
    </p>

    <h2>What is stored</h2>

    <p>One row per staff member per store per day, in a table called <code>daily_scores</code>:</p>

    <table>
        <tr><th>Field</th><th>Example</th><th>Why it is stored</th></tr>
        <tr>
            <td>Shop domain</td>
            <td><code>example.myshopify.com</code></td>
            <td>Identifies which store a score belongs to, so a store's data can be deleted on request.</td>
        </tr>
        <tr>
            <td>Staff member ID</td>
            <td><code>82619</code></td>
            <td>Keeps one row per player, so a player's best score replaces their previous one instead of adding a new row. It is the identifier Shopify puts in the session token; no name or email is stored with it.</td>
        </tr>
        <tr>
            <td>Nickname</td>
            <td><code>Ali</code></td>
            <td>The display name shown on the leaderboard. It is typed by the player and can be anything.</td>
        </tr>
        <tr>
            <td>Score</td>
            <td><code>12480</code></td>
            <td>The player's best score for that day.</td>
        </tr>
        <tr>
            <td>Day</td>
            <td><code>2026-10-14</code></td>
            <td>The UTC date the score was set. Boards are per day.</td>
        </tr>
    </table>

    <h2>Game records</h2>

    <p>
        When a game starts, a short-lived row is written in a table called
        <code>game_sessions</code> holding a random game ID, the shop domain, the
        staff member ID, a random number used to pick the falling pieces, and the
        time the game began. This is how the app checks that a submitted score
        belongs to a game that was really started, and that it is counted only
        once. These rows are deleted automatically six hours after the game
        starts, and immediately if the store is uninstalled.
    </p>

    <h2>Stored in your browser, not on the server</h2>

    <p>
        The leaderboard name you type is also saved in your own browser's local
        storage, so you do not have to type it again. It never leaves your device
        except as part of a score submission, and clearing your browser data removes
        it.
    </p>

    <h2>What is not stored</h2>

    <p>
        No customer data of any kind. No orders, products, or store contents. No email
        addresses, names, or IP addresses. The app requests no Shopify API access
        scopes and never reads from the Shopify Admin API.
    </p>

    <h2>Who can see it</h2>

    <p>
        Nicknames and scores appear on the in-app leaderboard. The daily board is
        shared across all stores that have the app installed, so a nickname entered in
        one store is visible to staff of other stores using it. Choose a nickname
        accordingly. Nothing is shown to shoppers and nothing appears on the
        storefront. Data is not sold, and is not shared with third parties.
    </p>

    <h2>How long it is kept</h2>

    <p>
        Scores are grouped by day and are retained until the store is uninstalled.
        When a store is uninstalled, Shopify sends a <code>shop/redact</code> request
        and every row belonging to that store is deleted.
    </p>

    <h2>Deletion requests</h2>

    <p>
        The app responds to Shopify's three mandatory privacy webhooks:
        <code>customers/data_request</code> and <code>customers/redact</code>, which
        return nothing because no customer data exists, and
        <code>shop/redact</code>, which deletes that store's scores.
    </p>

    <h2>Contact</h2>

    <p>
        For any question about this policy or to request deletion, email
        <a href="mailto:hs2438450@gmail.com">hs2438450@gmail.com</a>.
    </p>
</body>
</html>
