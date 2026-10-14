<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Verifies that a webhook really came from Shopify.
 *
 * Shopify signs the request body with the app's API secret and sends the result
 * base64-encoded in X-Shopify-Hmac-Sha256. Unlike session tokens these requests
 * carry no JWT, so this signature is the only proof of origin.
 */
class VerifyShopifyWebhook
{
    public function handle(Request $request, Closure $next)
    {
        $secret = (string) config('services.shopify.secret');
        $signature = (string) $request->header('X-Shopify-Hmac-Sha256', '');

        // No secret configured means we cannot verify anything, so refuse rather
        // than wave the request through.
        if ($secret === '' || $signature === '') {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // The RAW body must be hashed. Re-encoding the parsed JSON would change
        // whitespace and key order, and the signature would never match.
        $expected = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));

        // hash_equals is constant-time, so a wrong signature cannot be guessed
        // byte by byte from response timings.
        if (! hash_equals($expected, $signature)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
