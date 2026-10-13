<?php

namespace App\Http\Middleware;

use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;

class VerifyShopifySession
{
    public function handle(Request $request, Closure $next)
    {
        // 1. Read "Bearer eyJ..." and take the token after "Bearer ".
        $header = $request->header('Authorization', '');
        if (! str_starts_with($header, 'Bearer ')) {
            return response()->json(['error' => 'Missing session token'], 401);
        }
        $token = substr($header, 7);

        try {
            // 2. Verify the signature with the API secret and check expiry.
            //    The library throws if the signature is wrong or the token has expired.
            $claims = JWT::decode($token, new Key(config('services.shopify.secret'), 'HS256'));
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Invalid session token'], 401);
        }

        // 3. The token must be issued for this app (aud = API key).
        if (($claims->aud ?? null) !== config('services.shopify.key')) {
            return response()->json(['error' => 'Wrong app'], 401);
        }

        // 4. "dest" is the store, like https://your-store.myshopify.com.
        $shop = parse_url($claims->dest ?? '', PHP_URL_HOST);
        if (! $shop || ! str_ends_with($shop, '.myshopify.com')) {
            return response()->json(['error' => 'Invalid shop'], 401);
        }

        // 5. "sub" is the staff member's ID.
        $request->attributes->set('shop_id', $shop);
        $request->attributes->set('player_id', (string) ($claims->sub ?? ''));

        return $next($request);
    }
}
