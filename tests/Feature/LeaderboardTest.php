<?php

namespace Tests\Feature;

use App\Http\Controllers\LeaderboardController;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LeaderboardTest extends TestCase
{
    use RefreshDatabase;

    private string $key = 'test-api-key';

    // HS256 requires at least 32 bytes, and a real Shopify API secret is a
    // 32-character hex string, so the fixture matches that shape.
    private string $secret = '0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.shopify.key' => $this->key,
            'services.shopify.secret' => $this->secret,
            'cache.default' => 'array',   // keeps the throttle counter per test
        ]);

        Cache::clear();
    }

    /** Builds a session token the way Shopify's App Bridge would. */
    private function token(string $shop = 'example.myshopify.com', string $sub = '42'): string
    {
        return JWT::encode([
            'aud' => $this->key,
            'dest' => "https://{$shop}",
            'sub' => $sub,
            'exp' => time() + 300,
        ], $this->secret, 'HS256');
    }

    private function submit(array $payload, ?string $token = null)
    {
        return $this->withHeader('Authorization', 'Bearer ' . ($token ?? $this->token()))
            ->postJson('/api/leaderboard/submit', $payload);
    }

    public function test_a_valid_score_is_saved(): void
    {
        $this->submit(['nickname' => 'Ali', 'best_score' => 900])
            ->assertStatus(200)
            ->assertJson(['saved' => true]);

        $this->assertDatabaseHas('daily_scores', [
            'shop_id' => 'example.myshopify.com',
            'player_id' => '42',
            'nickname' => 'Ali',
            'best_score' => 900,
        ]);
    }

    public function test_a_score_above_the_maximum_is_rejected(): void
    {
        $this->submit(['nickname' => 'Ali', 'best_score' => LeaderboardController::MAX_SCORE + 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('best_score');

        $this->assertDatabaseCount('daily_scores', 0);
    }

    public function test_a_negative_score_is_rejected(): void
    {
        $this->submit(['nickname' => 'Ali', 'best_score' => -1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('best_score');
    }

    public function test_requests_without_a_valid_token_are_rejected(): void
    {
        $this->postJson('/api/leaderboard/submit', ['nickname' => 'Ali', 'best_score' => 10])
            ->assertStatus(401);

        $this->submit(['nickname' => 'Ali', 'best_score' => 10], 'garbage.token.here')
            ->assertStatus(401);
    }

    /** The upsert must keep the higher score and never add a second row per day. */
    public function test_only_the_best_score_per_player_per_day_is_kept(): void
    {
        $this->submit(['nickname' => 'Ali', 'best_score' => 500])->assertStatus(200);
        $this->submit(['nickname' => 'Ali', 'best_score' => 300])->assertStatus(200);

        $this->assertDatabaseCount('daily_scores', 1);
        $this->assertSame(500, (int) DB::table('daily_scores')->value('best_score'));

        $this->submit(['nickname' => 'Ali', 'best_score' => 900])->assertStatus(200);

        $this->assertDatabaseCount('daily_scores', 1);
        $this->assertSame(900, (int) DB::table('daily_scores')->value('best_score'));
    }

    public function test_different_players_get_their_own_rows(): void
    {
        $this->submit(['nickname' => 'Ali', 'best_score' => 500], $this->token(sub: '42'));
        $this->submit(['nickname' => 'Sam', 'best_score' => 700], $this->token(sub: '43'));

        $this->assertDatabaseCount('daily_scores', 2);
    }

    public function test_submit_is_throttled(): void
    {
        $token = $this->token();

        // The route allows 10 per minute.
        for ($i = 0; $i < 10; $i++) {
            $this->submit(['nickname' => 'Ali', 'best_score' => 100 + $i], $token)
                ->assertStatus(200);
        }

        $this->submit(['nickname' => 'Ali', 'best_score' => 999], $token)
            ->assertStatus(429);
    }

    /**
     * TRUSTED_PROXIES defaults to "*", so X-Forwarded-For is client-controlled.
     * An IP-keyed limiter would hand out a fresh bucket for every spoofed value.
     */
    public function test_the_throttle_cannot_be_bypassed_by_rotating_x_forwarded_for(): void
    {
        $token = $this->token();

        for ($i = 0; $i < 10; $i++) {
            $this->withHeader('X-Forwarded-For', "203.0.113.{$i}")
                ->submit(['nickname' => 'Ali', 'best_score' => 100 + $i], $token)
                ->assertStatus(200);
        }

        $this->withHeader('X-Forwarded-For', '203.0.113.99')
            ->submit(['nickname' => 'Ali', 'best_score' => 999], $token)
            ->assertStatus(429);
    }

    /** Two players behind one office IP must not share a bucket. */
    public function test_players_sharing_an_ip_have_separate_limits(): void
    {
        $a = $this->token(sub: '42');
        $b = $this->token(sub: '43');

        for ($i = 0; $i < 10; $i++) {
            $this->submit(['nickname' => 'Ali', 'best_score' => 100 + $i], $a)->assertStatus(200);
        }

        $this->submit(['nickname' => 'Ali', 'best_score' => 999], $a)->assertStatus(429);
        $this->submit(['nickname' => 'Sam', 'best_score' => 500], $b)->assertStatus(200);
    }

    public function test_top_returns_the_highest_scores_first(): void
    {
        $this->submit(['nickname' => 'Ali', 'best_score' => 500], $this->token(sub: '1'));
        $this->submit(['nickname' => 'Sam', 'best_score' => 900], $this->token(sub: '2'));
        $this->submit(['nickname' => 'Mo', 'best_score' => 700], $this->token(sub: '3'));

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token())
            ->getJson('/api/leaderboard/top')
            ->assertStatus(200);

        $scores = array_column($response->json(), 'best_score');
        $this->assertSame([900, 700, 500], array_map('intval', $scores));
    }
}
