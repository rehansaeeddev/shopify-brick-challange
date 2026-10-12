<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\LeaderboardController;

Route::post('/leaderboard/submit', [LeaderboardController::class, 'submit']);
