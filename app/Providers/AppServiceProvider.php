<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /**
         * Score submissions are limited per player, not per IP.
         *
         * The default IP key is wrong here in both directions. TRUSTED_PROXIES
         * defaults to "*", so X-Forwarded-For is client-controlled and rotating
         * it would buy a fresh bucket on every request. And a whole shop behind
         * one office IP would otherwise share a single allowance, so one busy
         * player would lock out their colleagues.
         *
         * shop_id and player_id are set by VerifyShopifySession from the signed
         * session token, so they cannot be spoofed. The IP fallback only applies
         * if this limiter somehow runs before that middleware.
         */
        RateLimiter::for('score-submit', function (Request $request) {
            $shop = (string) $request->attributes->get('shop_id', '');
            $player = (string) $request->attributes->get('player_id', '');

            $key = ($shop !== '' && $player !== '')
                ? "score-submit:{$shop}|{$player}"
                : 'score-submit:ip:' . $request->ip();

            return Limit::perMinute(10)->by($key);
        });

        // Starting a game writes a row, so it needs its own ceiling. Kept well
        // above the submit limit: a player may abandon several games in a row.
        RateLimiter::for('game-start', function (Request $request) {
            $shop = (string) $request->attributes->get('shop_id', '');
            $player = (string) $request->attributes->get('player_id', '');

            $key = ($shop !== '' && $player !== '')
                ? "game-start:{$shop}|{$player}"
                : 'game-start:ip:' . $request->ip();

            return Limit::perMinute(30)->by($key);
        });
    }
}
