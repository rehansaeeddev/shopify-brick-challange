<?php

use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\WebhookController;
use App\Http\Middleware\VerifyShopifySession;
use App\Http\Middleware\VerifyShopifyWebhook;
use Illuminate\Support\Facades\Route;

// Player-facing endpoints. Identity comes from the verified session token.
// submit is throttled: the score is computed in the browser, so the cheapest
// abuse is flooding the board with forged values rather than one big one.
// The 'score-submit' limiter is keyed on the verified shop and staff member
// (see AppServiceProvider) because an IP key is both spoofable and unfair.
Route::middleware(VerifyShopifySession::class)->group(function () {
    Route::post('/leaderboard/submit', [LeaderboardController::class, 'submit'])
        ->middleware('throttle:score-submit');

    Route::get('/leaderboard/top', [LeaderboardController::class, 'top']);
});

// Shopify's mandatory privacy webhooks. These carry no session token, so the
// HMAC signature on the raw body is the only proof of origin.
Route::middleware(VerifyShopifyWebhook::class)->prefix('webhooks')->group(function () {
    Route::post('/customers/data-request', [WebhookController::class, 'customersDataRequest']);
    Route::post('/customers/redact', [WebhookController::class, 'customersRedact']);
    Route::post('/shop/redact', [WebhookController::class, 'shopRedact']);
});
