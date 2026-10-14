<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="shopify-api-key" content="{{ config('services.shopify.key') }}">
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
    <script src="https://cdn.shopify.com/shopifycloud/polaris-1.js"></script>
    <title>Leaderboard</title>
    <style>
        body { margin: 0; padding: 0; }
    </style>
</head>
<body>
    {{-- App nav: rel="home" marks the landing page and is not rendered as a link. --}}
    <s-app-nav>
        <s-link href="/shopify/app" rel="home">Game</s-link>
        <s-link href="/shopify/app/leaderboard">Leaderboard</s-link>
    </s-app-nav>

    <s-page heading="Leaderboard" inline-size="base">
        <s-button slot="secondary-actions" id="btn-refresh">Refresh</s-button>

        <s-section heading="Today's top scores" padding="none">
            <s-text slot="accessory" color="subdued">Resets daily (UTC)</s-text>

            <div id="table-wrap" hidden>
                <s-table id="table">
                    <s-table-header-row>
                        <s-table-header listSlot="kicker" format="numeric">#</s-table-header>
                        <s-table-header listSlot="primary">Player</s-table-header>
                        <s-table-header listSlot="labeled" format="numeric">Score</s-table-header>
                    </s-table-header-row>
                    <s-table-body id="rows"></s-table-body>
                </s-table>
            </div>

            <div id="status-wrap">
                <s-stack padding="base" alignItems="center">
                    <s-text id="status" color="subdued">Loading scores…</s-text>
                </s-stack>
            </div>
        </s-section>

        <s-section slot="aside" heading="How scoring works">
            <s-stack gap="small">
                <s-text color="subdued" fontSize="small">
                    Clearing 1, 2, 3 or 4 lines at once scores 100, 300, 500 or 800 points,
                    multiplied by your level.
                </s-text>
                <s-text color="subdued" fontSize="small">
                    Only your best game of the day is kept. Play again to beat it.
                </s-text>
            </s-stack>
        </s-section>
    </s-page>

    <script src="{{ asset('js/leaderboard.js') }}"></script>
</body>
</html>
