<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Every embedded page needs the same frame-ancestors header, so render them
// through one helper rather than repeating it per route.
$embedded = fn (string $view) => response(view($view))
    ->header('Content-Security-Policy', 'frame-ancestors https://admin.shopify.com https://*.myshopify.com;');

Route::get('/shopify/app', fn () => $embedded('admin'));
Route::get('/shopify/app/leaderboard', fn () => $embedded('leaderboard'));

// Public, and deliberately not embedded: the App Store listing links to it.
Route::get('/privacy', fn () => view('privacy'));