<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/shopify/app', function () {
    return response(view('admin'))
        ->header('Content-Security-Policy', 'frame-ancestors https://admin.shopify.com https://*.myshopify.com;');
});