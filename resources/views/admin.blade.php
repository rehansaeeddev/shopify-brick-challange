<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="shopify-api-key" content="{{ config('services.shopify.key') }}">
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
    <title>Brick Challenge</title>
</head>
<body>
    <h1>Brick Challenge</h1>
    <button id="send-score">Send test score (900)</button>
    <button id="load-top">Load top 10</button>
    <pre id="output"></pre>

    <script>
        const output = document.getElementById('output');

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

        document.getElementById('send-score').addEventListener('click', async () => {
            const result = await apiFetch('/api/leaderboard/submit', {
                method: 'POST',
                body: JSON.stringify({ nickname: 'Ali', best_score: 900 }),
            });
            output.textContent = JSON.stringify(result, null, 2);
        });

        document.getElementById('load-top').addEventListener('click', async () => {
            const result = await apiFetch('/api/leaderboard/top');
            output.textContent = JSON.stringify(result, null, 2);
        });
    </script>
</body>
</html>