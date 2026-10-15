<?php

namespace Tests\Feature;

use App\Http\Controllers\LeaderboardController;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** Opens a game and returns its id. */
    private function startGame(?string $token = null): string
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . ($token ?? $this->token()))
            ->postJson('/api/game/start')
            ->assertStatus(200);

        return $response->json('gameId');
    }

    /**
     * Plays a plausible game: opens it, lets enough time pass for the claimed
     * lines, and submits numbers the scoring rules actually allow.
     */
    private function playAndSubmit(int $score = 100, int $lines = 1, ?string $token = null, string $nickname = 'Ali')
    {
        $gameId = $this->startGame($token);

        // MIN_MS_PER_PIECE is 120 and a line needs ceil(2.5) pieces, so a
        // minute of travel clears the floor for any small line count.
        $this->travel(60)->seconds();

        return $this->submit([
            'gameId' => $gameId,
            'nickname' => $nickname,
            'best_score' => $score,
            'lines' => $lines,
        ], $token);
    }

    /**
     * Submits a finished-instantly game: zero lines scores zero, which needs no
     * elapsed time. Used by the throttle tests, which must not call travel()
     * because moving the clock would also roll the rate limiter's window.
     */
    private function quickSubmit(string $token)
    {
        $gameId = $this->startGame($token);

        return $this->submit([
            'gameId' => $gameId,
            'nickname' => 'Ali',
            'best_score' => 0,
            'lines' => 0,
        ], $token);
    }

    public function test_a_valid_score_is_saved(): void
    {
        $this->playAndSubmit(score: 800, lines: 4)
            ->assertStatus(200)
            ->assertJson(['saved' => true]);

        $this->assertDatabaseHas('daily_scores', [
            'shop_id' => 'example.myshopify.com',
            'player_id' => '42',
            'nickname' => 'Ali',
            'best_score' => 800,
        ]);
    }

    public function test_a_score_above_the_maximum_is_rejected(): void
    {
        $this->playAndSubmit(score: LeaderboardController::MAX_SCORE + 1, lines: 4)
            ->assertStatus(422)
            ->assertJsonValidationErrors('best_score');

        $this->assertDatabaseCount('daily_scores', 0);
    }

    public function test_a_negative_score_is_rejected(): void
    {
        $this->playAndSubmit(score: -1, lines: 1)
            ->assertStatus(422)
            ->assertJsonValidationErrors('best_score');
    }

    public function test_requests_without_a_valid_token_are_rejected(): void
    {
        $this->postJson('/api/leaderboard/submit', ['nickname' => 'Ali', 'best_score' => 10, 'lines' => 1])
            ->assertStatus(401);

        $this->submit(['nickname' => 'Ali', 'best_score' => 10, 'lines' => 1], 'garbage.token.here')
            ->assertStatus(401);
    }

    #[DataProvider('blockedNicknames')]
    public function test_insulting_nicknames_are_rejected(string $nickname): void
    {
        $this->playAndSubmit(nickname: $nickname)
            ->assertStatus(422)
            ->assertJsonPath('errors.nickname.0', 'Please use another name. This nickname is not allowed.');

        $this->assertDatabaseCount('daily_scores', 0);
    }

    public static function blockedNicknames(): array
    {
        return [
            'English insult' => ['idiot'],
            'Roman Urdu spelling' => ['bewaqoof'],
            'Roman Urdu profanity' => ['madarchod'],
            'Punjabi insult' => ['dhakkan'],
            'Punjabi phrase' => ['ullu da pattha'],
        ];
    }

    /** The upsert must keep the higher score and never add a second row per day. */
    public function test_only_the_best_score_per_player_per_day_is_kept(): void
    {
        // 5 lines: min 500 (five singles), max 1300. 4 lines: min 400, max 800.
        $this->playAndSubmit(score: 500, lines: 5)->assertStatus(200);
        $this->playAndSubmit(score: 400, lines: 4)->assertStatus(200);

        $this->assertDatabaseCount('daily_scores', 1);
        $this->assertSame(500, (int) DB::table('daily_scores')->value('best_score'));

        $this->playAndSubmit(score: 800, lines: 4)->assertStatus(200);

        $this->assertDatabaseCount('daily_scores', 1);
        $this->assertSame(800, (int) DB::table('daily_scores')->value('best_score'));
    }

    public function test_different_players_get_their_own_rows(): void
    {
        $this->playAndSubmit(score: 500, lines: 5, token: $this->token(sub: '42'));
        $this->playAndSubmit(score: 400, lines: 4, token: $this->token(sub: '43'), nickname: 'Sam');

        $this->assertDatabaseCount('daily_scores', 2);
    }

    public function test_submit_is_throttled(): void
    {
        $token = $this->token();

        // The route allows 10 per minute.
        for ($i = 0; $i < 10; $i++) {
            $this->quickSubmit($token)->assertStatus(200);
        }

        $this->quickSubmit($token)->assertStatus(429);
    }

    /**
     * TRUSTED_PROXIES defaults to "*", so X-Forwarded-For is client-controlled.
     * An IP-keyed limiter would hand out a fresh bucket for every spoofed value.
     */
    public function test_the_throttle_cannot_be_bypassed_by_rotating_x_forwarded_for(): void
    {
        $token = $this->token();

        for ($i = 0; $i < 10; $i++) {
            $this->withHeader('X-Forwarded-For', "203.0.113.{$i}");
            $this->quickSubmit($token)->assertStatus(200);
        }

        $this->withHeader('X-Forwarded-For', '203.0.113.99');
        $this->quickSubmit($token)->assertStatus(429);
    }

    /** Two players behind one office IP must not share a bucket. */
    public function test_players_sharing_an_ip_have_separate_limits(): void
    {
        $a = $this->token(sub: '42');
        $b = $this->token(sub: '43');

        for ($i = 0; $i < 10; $i++) {
            $this->quickSubmit($a)->assertStatus(200);
        }

        $this->quickSubmit($a)->assertStatus(429);
        $this->quickSubmit($b)->assertStatus(200);
    }

    public function test_top_returns_the_highest_scores_first(): void
    {
        $this->playAndSubmit(score: 500, lines: 5, token: $this->token(sub: '1'));
        $this->playAndSubmit(score: 800, lines: 4, token: $this->token(sub: '2'), nickname: 'Sam');
        $this->playAndSubmit(score: 700, lines: 7, token: $this->token(sub: '3'), nickname: 'Mo');

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token())
            ->getJson('/api/leaderboard/top')
            ->assertStatus(200);

        $scores = array_column($response->json(), 'best_score');
        $this->assertSame([800, 700, 500], array_map('intval', $scores));
    }
}
