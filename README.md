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

## Tech stack

- **Backend:** Laravel (PHP 8.4), REST API
- **Database:** MySQL
- **Frontend (in progress):** React Router and Shopify Polaris, inside the Shopify admin

## Key design decisions

- **Scores are validated on the server.** The browser is never trusted for the final score, and input is checked against limits.
- **One row per player per day.** A unique key on (day, shop, player) enforces this in the database, not only in code.
- **Upsert logic.** `INSERT ... ON DUPLICATE KEY UPDATE` keeps the higher score in a single query, so there is no race between "check" and "save".
- **Index for the leaderboard.** A composite index on (day, best_score) lets MySQL read the top 10 directly. I verified this with `EXPLAIN`, comparing the query plan with and without the index.
- **UTC daily key.** The server sets the date, so the reset is consistent and players can't change it.

## Current status

- [x] Database schema and migrations
- [x] Score submission with validation
- [x] Best-score-per-day logic
- [x] Top 10 endpoint
- [ ] Shopify session token verification (currently uses placeholder shop and player IDs)
- [ ] React Router and Polaris frontend
- [ ] Game (brick breaker in the admin modal)
- [ ] GDPR webhooks (`shop/redact`, `customers/redact`, `customers/data_request`)

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

- `POST /api/leaderboard/submit` with `nickname` and `best_score`
- `GET /api/leaderboard/top`

## What I learned

- Writing and testing a schema, including how indexes change query plans
- Keeping business rules (one best score per day) in the database
- Separating what the client sends from what the server trusts
- Debugging a Laravel setup: environment files, migrations, and model configuration

## Roadmap

- Verify Shopify session tokens so each score is tied to the real shop and staff member
- Build the admin game and leaderboard UI
- Add privacy policy and data deletion handling before any public release

## License

This project is licensed under the MIT License. See the LICENSE file for details.
