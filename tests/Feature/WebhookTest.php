<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.shopify.secret' => $this->secret]);
    }

    /** Signs a body exactly the way Shopify does. */
    private function sign(string $body): string
    {
        return base64_encode(hash_hmac('sha256', $body, $this->secret, true));
    }

    private function postWebhook(string $uri, array $payload, ?string $signature = null)
    {
        $body = json_encode($payload);

        return $this->call(
            'POST',
            $uri,
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_SHOPIFY_HMAC_SHA256' => $signature ?? $this->sign($body),
            ],
            $body
        );
    }

    private function seedScore(string $shop, string $player = '1', int $score = 500): void
    {
        DB::table('daily_scores')->insert([
            'day' => now('UTC')->toDateString(),
            'shop_id' => $shop,
            'player_id' => $player,
            'nickname' => 'Player',
            'best_score' => $score,
            'updated_at' => now(),
        ]);
    }

    public function test_valid_hmac_is_accepted_on_all_three_topics(): void
    {
        $topics = [
            '/api/webhooks/customers/data-request',
            '/api/webhooks/customers/redact',
            '/api/webhooks/shop/redact',
        ];

        foreach ($topics as $uri) {
            $this->postWebhook($uri, ['shop_domain' => 'example.myshopify.com'])
                ->assertStatus(200);
        }
    }

    public function test_invalid_hmac_is_rejected_on_all_three_topics(): void
    {
        $topics = [
            '/api/webhooks/customers/data-request',
            '/api/webhooks/customers/redact',
            '/api/webhooks/shop/redact',
        ];

        foreach ($topics as $uri) {
            $this->postWebhook($uri, ['shop_domain' => 'example.myshopify.com'], 'not-a-signature')
                ->assertStatus(401);
        }
    }

    public function test_missing_hmac_header_is_rejected(): void
    {
        $this->postJson('/api/webhooks/shop/redact', ['shop_domain' => 'example.myshopify.com'])
            ->assertStatus(401);
    }

    public function test_signature_over_a_different_body_is_rejected(): void
    {
        // Signing one payload and sending another must not pass: this is what
        // catches a replayed or tampered body.
        $signature = $this->sign(json_encode(['shop_domain' => 'other.myshopify.com']));

        $this->postWebhook('/api/webhooks/shop/redact', ['shop_domain' => 'example.myshopify.com'], $signature)
            ->assertStatus(401);
    }

    public function test_shop_redact_deletes_only_that_shops_rows(): void
    {
        $this->seedScore('example.myshopify.com', '1');
        $this->seedScore('example.myshopify.com', '2');
        $this->seedScore('other.myshopify.com', '3');

        $this->assertSame(3, DB::table('daily_scores')->count());

        $this->postWebhook('/api/webhooks/shop/redact', ['shop_domain' => 'example.myshopify.com'])
            ->assertStatus(200)
            ->assertJson(['ok' => true, 'deleted' => 2]);

        $this->assertSame(0, DB::table('daily_scores')->where('shop_id', 'example.myshopify.com')->count());
        $this->assertSame(1, DB::table('daily_scores')->where('shop_id', 'other.myshopify.com')->count());
    }

    public function test_shop_redact_also_deletes_that_shops_game_sessions(): void
    {
        foreach ([['example.myshopify.com', '1'], ['example.myshopify.com', '2'], ['other.myshopify.com', '3']] as [$shop, $player]) {
            DB::table('game_sessions')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'shop_id' => $shop,
                'player_id' => $player,
                'seed' => 42,
                'created_at' => now(),
                'used_at' => null,
            ]);
        }

        $this->postWebhook('/api/webhooks/shop/redact', ['shop_domain' => 'example.myshopify.com'])
            ->assertStatus(200)
            ->assertJson(['sessions_deleted' => 2]);

        $this->assertSame(0, DB::table('game_sessions')->where('shop_id', 'example.myshopify.com')->count());
        $this->assertSame(1, DB::table('game_sessions')->where('shop_id', 'other.myshopify.com')->count());
    }

    public function test_customer_topics_delete_nothing(): void
    {
        $this->seedScore('example.myshopify.com');

        $this->postWebhook('/api/webhooks/customers/redact', ['shop_domain' => 'example.myshopify.com'])
            ->assertStatus(200);

        $this->assertSame(1, DB::table('daily_scores')->count());
    }
}
