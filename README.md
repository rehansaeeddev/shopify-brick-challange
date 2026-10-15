# Shopify Brick Challenge

A free brick-breaker game for Shopify merchants, with a daily leaderboard. Merchants play during short breaks in the Shopify admin, and their best score for the day appears on a shared board.

## Why I built this

I wanted to learn how a real Shopify embedded app works end to end: a frontend inside the admin, a secure backend, a database with business rules, and the decisions that come with a public leaderboard. This project was also a step from building apps with AI assistance toward understanding and maintaining the code myself.

## What it does

- Players submit a score after a game.
- The server keeps only each player's best score per shop per day.
- The leaderboard shows the top 10 scores for the current day.
- Scores reset every 24 hours at 00:00 UTC, so every merchant competes on the same clock.
- Only nicknames and scores are shown. No customer data is stored.

## Business model

This app is free for every merchant. That is a deliberate choice, not an unfinished one.

Charging would not have been the hard part. [Shopify App Pricing](https://shopify.dev/docs/apps/launch/billing) is now the default for new public apps: plans are defined in the app submission form, and Shopify hosts plan selection, trials, proration and upgrades, so there is no billing code to write. The legacy Billing API is only needed for one-time charges, discounts or usage caps.

I left it out because I could not answer the question a paywall raises: what is the merchant paying for? A game inside the admin is used by staff, not by the business. It does not increase revenue, reduce cost or save time — it spends time. Merchants pay for apps that move a number they are measured on, and this moves none. Gating levels or board sizes behind $9/month would be charging for something with no business case.

If it did need revenue, the honest path would be to change the product rather than add a price:

- **Storefront version.** The same engine rendered in the theme, where a shopper plays for a discount code in exchange for an email. That is an established paid category precisely because it affects conversion rate and list growth. The canvas engine, scoring and leaderboard all carry over; what changes is where it renders and who plays it.
- **Part of a larger staff-engagement product.** Credible as a feature, not as a product.

So it stays free, and the leaderboard is the whole point: the thing worth building here was the Shopify integration, not a billable feature.

## Tech stack

- **Backend:** Laravel (PHP 8.4), REST API
- **Database:** MySQL
- **Frontend:** Blade views with Polaris web components (`polaris-1.js`) and App Bridge, served inside the Shopify admin iframe. No React, no build step — the game is plain JavaScript on a `<canvas>`.

## Key design decisions

- **Scores are range-checked on the server, but not derived there.** The score is computed in the browser and posted, so it is forgeable from the console; server-side validation caps it at 0–100,000 and the session token ties it to a real shop and staff member. Making it tamper-proof would mean replaying the game server-side, which is out of scope here. I would rather state this limit than claim the browser is untrusted when it isn't.
- **One row per player per day.** A unique key on (day, shop, player) enforces this in the database, not only in code.
- **Upsert logic.** `INSERT ... ON DUPLICATE KEY UPDATE` keeps the higher score in a single query, so there is no race between "check" and "save".
- **Index for the leaderboard.** A composite index on (day, best_score) lets MySQL read the top 10 directly. I verified this with `EXPLAIN`, comparing the query plan with and without the index.
- **UTC daily key.** The server sets the date, so the reset is consistent and players can't change it.

## Current status

- [x] Database schema and migrations
- [x] Score submission with validation
- [x] Best-score-per-day logic
- [x] Top 10 endpoint
- [x] Shopify session token verification (JWT verified in middleware; real shop and staff IDs)
- [x] Polaris frontend inside the admin (game page + leaderboard page, linked by `s-app-nav`)
- [x] Game: falling-block puzzle on a `<canvas>`, with row-clear, cascade and landing effects
- [x] Touch controls: the board itself is the controller (drag, tap, flick, swipe)
- [x] GDPR webhooks (`shop/redact`, `customers/redact`, `customers/data_request`) with HMAC verification
- [x] Privacy policy at `/privacy`
- [x] Test suites for the API and the game logic
- [x] Game verified by hand in the Shopify admin: touch gestures, clock, pause
- [ ] Leaderboard page and app nav verified by hand (see *Known gaps*)

## Running locally

Requirements: PHP 8.4, Composer, MySQL.

```bash
composer install
cp .env.example .env
php artisan key:generate
# Set DB_* values in .env for your local MySQL
php artisan migrate
php artisan serve
```

API endpoints:

- `POST /api/game/start` → `{gameId, seed}` (throttled, 30/min)
- `POST /api/leaderboard/submit` with `gameId`, `nickname`, `best_score`, `lines`
  (throttled, 10/min)
- `GET /api/leaderboard/top`
- `POST /api/webhooks/customers/data-request`
- `POST /api/webhooks/customers/redact`
- `POST /api/webhooks/shop/redact`

Pages: `/shopify/app` (game), `/shopify/app/leaderboard`, `/privacy`.

## Running the tests

One command runs everything:

```bash
composer test
```

That runs both suites, which can also be run on their own:

```bash
php artisan test    # 37 feature tests: API, HMAC webhooks, throttle, upsert, pages
npm test            # game logic: row-clear maths, effects, gestures, clock, submit
```

The Node tests run the real `public/js/game.js` inside a stubbed DOM, so they
exercise the same file the browser loads. That is why the game script lives in
`public/js/` rather than inline in the Blade view. No build step is involved.

## Deploying

The app is a plain Laravel app with no Node build for the embedded pages.

1. Set these in the production environment (never commit them):
   - `SHOPIFY_API_KEY`, `SHOPIFY_API_SECRET` from the Partner Dashboard
   - `APP_DEBUG=false` — a stack trace in production leaks environment values
   - `APP_ENV=production`, `APP_KEY` generated
   - `DB_CONNECTION=mysql` and the `DB_*` values
   - `TRUSTED_PROXIES` — **set this to your load balancer's IP or CIDR**, for
     example `10.0.0.0/16`. The default `*` trusts any proxy, which is fine
     behind a tunnel or a trusted-only LB, and unsafe when the app is also
     reachable directly, because `X-Forwarded-*` then becomes attacker
     controlled and can poison the scheme and host.
     Note: `php artisan serve` only forwards a fixed allowlist of variables to
     its subprocess, so this one must come from `.env` or the real process
     environment — exporting it before `artisan serve` has no effect.
   - `SESSION_DRIVER=array` — the database driver records an IP address and user
     agent per request, which the privacy policy states is not collected. Nothing
     in the app reads a session.
2. `php artisan migrate --force`
3. Point the web server's document root at `public/`.
4. Edit `shopify.app.toml` with the real `client_id` and `application_url`, then
   `shopify app deploy` to register the URLs and compliance webhooks.

MySQL is the production database. The daily-score upsert is written for both
MySQL and SQLite (the test suite runs on SQLite in memory); any other driver
throws rather than silently writing the wrong thing.

## What I learned

- Writing and testing a schema, including how indexes change query plans
- Keeping business rules (one best score per day) in the database
- Separating what the client sends from what the server trusts
- Debugging a Laravel setup: environment files, migrations, and model configuration

## Known gaps

Worth stating plainly rather than leaving to be discovered:

- **The daily leaderboard is global, not per shop.** `top()` filters on the date only, so a nickname typed in one store is visible in every store that installs the app. The composite index on `(day, best_score)` is built for exactly that global query, so it is the intended design — but the nickname field should say so, since "today's leaderboard" reads as store-local.
- **The score is client-supplied** (see *Key design decisions* above). Mitigated
  in layers, not solved:
  - A game must be opened through `POST /api/game/start`, which records when it
    began, and that game id can be redeemed exactly once by the player it was
    issued to.
  - The elapsed time is measured by the server, not reported by the browser, and
    is checked against the minimum a given number of lines could take. It is a
    lower bound: a forger who simply waits will clear it.
  - The submitted score is checked against the lowest and highest score the
    scoring rules allow for the claimed line count.
  - Submissions are throttled per player, not per IP.

  A forger who sends internally consistent numbers after a realistic delay will
  still get through. Closing that needs server-side replay: the client sends the
  resting position of each piece and the server replays them against a
  server-chosen piece sequence and computes the score itself. The seed handed out
  by `game/start` is the groundwork for that; on its own it proves nothing,
  because nothing yet forces the browser to use it.
- **Partly unverified in a real browser.** The game page has been checked by hand
  in the Shopify admin on mobile: touch gestures, the clock and pause all behave.
  The leaderboard page (`s-table` rows, loading and empty states) and the app nav
  between the two pages have only been checked headlessly.

## Roadmap

- Auto-repeat on the mobile touch controls
- Privacy policy and the three mandatory GDPR webhooks before any public release
- Decide per-shop vs global leaderboard and make the UI copy match

## License

This project is licensed under the MIT License. See the LICENSE file for details.
