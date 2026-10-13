<?php

use App\Http\Controllers\LeaderboardController;
use App\Http\Middleware\VerifyShopifySession;
use Illuminate\Support\Facades\Route;

Route::middleware(VerifyShopifySession::class)->group(function () {
    Route::post('/leaderboard/submit', [LeaderboardController::class, 'submit']);
    Route::get('/leaderboard/top', [LeaderboardController::class, 'top']);
});