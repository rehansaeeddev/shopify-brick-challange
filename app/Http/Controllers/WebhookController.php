<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Shopify's three mandatory privacy webhooks.
 *
 * Every response is 200 once the HMAC has passed: Shopify retries anything else,
 * and a 500 on a topic we have no data for would retry forever. The signature is
 * checked by VerifyShopifyWebhook before any of these run.
 *
 * What this app stores per row: shop domain, staff member ID, nickname, score,
 * and the UTC day. None of it is customer data, which is why two of the three
 * topics have nothing to erase.
 */
class WebhookController extends Controller
{
    /**
     * A shopper asked for their data. This app never stores shopper data -- only
     * staff scores -- so there is nothing to hand over.
     */
    public function customersDataRequest(Request $request)
    {
        Log::info('Shopify customers/data_request: no customer data is stored by this app.', [
            'shop_domain' => $request->input('shop_domain'),
        ]);

        return response()->json(['ok' => true, 'customer_data' => []]);
    }

    /**
     * A shopper asked to be erased. Again, no shopper data is stored, so this is
     * acknowledged without deleting anything.
     */
    public function customersRedact(Request $request)
    {
        Log::info('Shopify customers/redact: no customer data is stored by this app.', [
            'shop_domain' => $request->input('shop_domain'),
        ]);

        return response()->json(['ok' => true, 'deleted' => 0]);
    }

    /**
     * The shop was uninstalled 48 hours ago and must be erased. This one really
     * does delete: every daily score belonging to that shop goes.
     */
    public function shopRedact(Request $request)
    {
        $shopDomain = (string) $request->input('shop_domain', '');

        if ($shopDomain === '') {
            return response()->json(['ok' => true, 'deleted' => 0]);
        }

        // shop_id is stored as the shop host, which is exactly what Shopify
        // sends as shop_domain (for example "example.myshopify.com").
        $deleted = DB::table('daily_scores')->where('shop_id', $shopDomain)->delete();

        Log::info('Shopify shop/redact: deleted daily scores.', [
            'shop_domain' => $shopDomain,
            'deleted' => $deleted,
        ]);

        return response()->json(['ok' => true, 'deleted' => $deleted]);
    }
}
