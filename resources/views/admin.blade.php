<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="shopify-api-key" content="{{ config('services.shopify.key') }}">
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
    <script src="https://cdn.shopify.com/shopifycloud/polaris-1.js"></script>
    <title>Brick Breaker</title>
    <style>
        body { margin: 0; padding: 0; }

        /* The canvas is ours, not Polaris's, so it is styled with plain CSS.
           The backing store stays 240x480, so all the game maths keeps working in
           whole cells; only the displayed size changes. A canvas is a replaced
           element, so giving it both max-width and max-height scales it down while
           preserving its aspect ratio -- which is what keeps the board, the d-pad
           and the New game button on screen together on a phone. */
        #board {
            display: block;
            background: #ffffff;
            border: 1px solid #e1e3e5;
            border-radius: 8px;
            width: 100%;          /* fill the card on a phone; 'auto' pinned it at 240px */
            height: auto;
            max-width: 100%;
            max-height: 56dvh;    /* the ratio is kept, so this caps it on short screens */
            margin: 0 auto;
            touch-action: none;   /* stops the browser scrolling when you drag on the board */
        }

        /* dvh is not universal; vh is the fallback and is close enough here. */
        @supports not (height: 1dvh) {
            #board { max-height: 56vh; }
        }

        /* The board takes keyboard focus, so it needs a visible focus ring. */
        #board:focus-visible {
            outline: 2px solid #005bd3;
            outline-offset: 2px;
        }

        /* Side columns are decoration; they go away when the board needs the width. */
        .side-panel { display: none; }

        /* Mobile-only gesture hint. There are no control buttons on a phone --
           the board is the controller -- so this is the only thing teaching them. */
        .touch-controls { display: block; }

        #touch-hint {
            margin: 12px 0 0;
            text-align: center;
            font-size: 13px;
            line-height: 1.5;
            color: #616161;
        }

        /* One centred column on mobile, three on desktop. Plain CSS rather than
           s-grid so the hidden hold/hint tracks collapse completely -- a fixed
           "auto auto auto" would keep their gutters and shove the board off-centre. */
        .board-row {
            display: grid;
            grid-template-columns: auto;
            justify-content: center;
            align-items: start;
            gap: 20px;
        }

        @media (min-width: 720px) {
            .side-panel { display: block; }
            .touch-controls { display: none; }
            .board-row { grid-template-columns: auto auto auto; }
            /* Back to its natural 240px beside the hold and hint columns --
               width:100% would stretch it inside an auto-sized grid track. */
            #board { width: auto; max-height: none; }
        }
    </style>
</head>
<body>
    {{-- App nav: rel="home" marks the landing page and is not rendered as a link. --}}
    <s-app-nav>
        <s-link href="/shopify/app" rel="home">Game</s-link>
        <s-link href="/shopify/app/leaderboard">Leaderboard</s-link>
    </s-app-nav>

    <s-page heading="Brick Breaker" inline-size="base">

        {{-- ---------- Main column: the board ---------- --}}
        <s-section heading="Classic mode" accessibilityLabel="Game board">
            <s-text slot="accessory" color="subdued">10 × 20 board</s-text>
            <s-badge slot="supplemental" icon="clock" id="clock">00:00</s-badge>

            <s-stack gap="base">

                {{-- hold | board | hint --}}
                <s-stack background="subdued" padding="base" borderRadius="base">
                    <div class="board-row">
                        <div class="side-panel">
                            <s-stack gap="small-200" alignItems="center" minInlineSize="120px">
                                <s-text color="subdued" fontSize="small">HOLD</s-text>
                                <canvas id="hold" width="96" height="48"></canvas>
                                <s-text id="hold-count" color="subdued" fontSize="small">3 of 3 holds left</s-text>
                            </s-stack>
                        </div>

                        {{-- tabindex makes the board focusable so it can own the game keys --}}
                        <canvas id="board" tabindex="0" aria-label="Game board"></canvas>

                        <div class="side-panel">
                            <s-stack gap="small-200" maxInlineSize="160px">
                                <s-text fontSize="small">Landing preview</s-text>
                                <s-text color="subdued" fontSize="small">
                                    Complete rows to clear space and earn points.
                                </s-text>
                            </s-stack>
                        </div>
                    </div>
                </s-stack>

                {{-- Mobile-only d-pad. Handlers come with the press-and-hold step. --}}
                {{-- Mobile has no control buttons: the board itself is the
                     controller. Gestures aren't discoverable, so they're spelled
                     out here, and the hold count rides along since the HOLD
                     panel is hidden at this width. --}}
                <div class="touch-controls">
                    <p id="touch-hint">
                        Drag to move &middot; Tap to rotate &middot; Flick down to drop
                        &middot; Swipe up to hold (3)
                    </p>
                </div>

                {{-- Card footer: caption on the left, game actions on the right. --}}
                <s-stack direction="inline" justifyContent="space-between" alignItems="center" gap="base">
                    <s-text color="subdued" fontSize="small">Your store stays right where you left it.</s-text>
                    <s-stack direction="inline" gap="small">
                        <s-button id="btn-new">New game</s-button>
                        <s-button id="btn-pause" variant="primary">Pause</s-button>
                    </s-stack>
                </s-stack>

            </s-stack>
        </s-section>

        {{-- ---------- Right rail ---------- --}}
        <s-section slot="aside" heading="This game">
            <s-badge slot="supplemental" tone="neutral" id="best">Best &mdash;</s-badge>

            <s-stack gap="base">
                <s-stack gap="small-300">
                    <s-text color="subdued" fontSize="small">Score</s-text>
                    <s-text id="score" fontSize="large-100" fontWeight="bold" tone="success">0</s-text>
                    <s-text id="last-clear" color="subdued" fontSize="small">No clears yet</s-text>
                </s-stack>

                <s-divider></s-divider>

                <s-grid gridTemplateColumns="1fr 1fr" gap="base">
                    <s-stack gap="small-300">
                        <s-text color="subdued" fontSize="small">Level</s-text>
                        <s-text id="level" fontSize="large" fontWeight="bold">01</s-text>
                    </s-stack>
                    <s-stack gap="small-300">
                        <s-text color="subdued" fontSize="small">Lines cleared</s-text>
                        <s-text id="lines" fontSize="large" fontWeight="bold">0</s-text>
                    </s-stack>
                </s-grid>

                <s-divider></s-divider>

                {{-- The API requires a nickname. maxLength matches the server's 50. --}}
                <s-text-field
                    id="nickname"
                    label="Leaderboard name"
                    maxLength="50"
                    placeholder="Add a name to post your score"
                    details="Shown on today's leaderboard when a game ends."
                ></s-text-field>
            </s-stack>
        </s-section>

        <s-section slot="aside" heading="Up next">
            <s-grid gridTemplateColumns="1fr 1fr 1fr" gap="small">
                <canvas class="preview" width="72" height="48"></canvas>
                <canvas class="preview" width="72" height="48"></canvas>
                <canvas class="preview" width="72" height="48"></canvas>
            </s-grid>
        </s-section>

        <s-section slot="aside" heading="How to play">
            <s-stack gap="small">
                <s-stack direction="inline" justifyContent="space-between">
                    <s-text color="subdued" fontSize="small">Move</s-text>
                    <s-text fontSize="small">&larr; &rarr;</s-text>
                </s-stack>
                <s-stack direction="inline" justifyContent="space-between">
                    <s-text color="subdued" fontSize="small">Rotate</s-text>
                    <s-text fontSize="small">&uarr;</s-text>
                </s-stack>
                <s-stack direction="inline" justifyContent="space-between">
                    <s-text color="subdued" fontSize="small">Soft drop</s-text>
                    <s-text fontSize="small">&darr;</s-text>
                </s-stack>
                <s-stack direction="inline" justifyContent="space-between">
                    <s-text color="subdued" fontSize="small">Hard drop</s-text>
                    <s-text fontSize="small">Space</s-text>
                </s-stack>
                <s-stack direction="inline" justifyContent="space-between">
                    <s-text color="subdued" fontSize="small">Hold piece (3 per game)</s-text>
                    <s-text fontSize="small">C</s-text>
                </s-stack>
                <s-stack direction="inline" justifyContent="space-between">
                    <s-text color="subdued" fontSize="small">Pause / resume</s-text>
                    <s-text fontSize="small">P</s-text>
                </s-stack>
            </s-stack>
        </s-section>

    </s-page>

    <script src="{{ asset('js/game.js') }}"></script>
</body>
</html>
