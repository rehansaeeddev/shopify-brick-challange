<?php

namespace Tests\Feature;

use App\Http\Controllers\LeaderboardController;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The checks that stop a browser posting a score it did not earn.
 *
 * None of this proves a game was played. It proves the numbers are consistent
 * with the rules, that the game was opened through the API, and that it is
 * redeemed once.
 */
class ScoreIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private string $key = 'test-api-key';
    private string $secret = '0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.shopify.key' => $this->key,
            'services.shopify.secret' => $this->secret,
            'cache.default' => 'array',
        ]);

        Cache::clear();
    }

    private function token(string $shop = 'example.myshopify.com', string $sub = '42'): string
    {
        return JWT::encode([
            'aud' => $this->key,
            'dest' => "https://{$shop}",
            'sub' => $sub,
            'exp' => time() + 300,
        ], $this->secret, 'HS256');
    }

    private function startGame(?string $token = null): string
    {
        return $this->withHeader('Authorization', 'Bearer ' . ($token ?? $this->token()))
            ->postJson('/api/game/start')
            ->assertStatus(200)
            ->json('gameId');
    }

    private function submit(array $payload, ?string $token = null)
    {
        return $this->withHeader('Authorization', 'Bearer ' . ($token ?? $this->token()))
            ->postJson('/api/leaderboard/submit', $payload);
    }

    private function body(string $gameId, int $score, int $lines): array
    {
        return ['gameId' => $gameId, 'nickname' => 'Ali', 'best_score' => $score, 'lines' => $lines];
    }

    public function test_starting_a_game_returns_an_id_and_a_seed(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token())
            ->postJson('/api/game/start')
            ->assertStatus(200)
            ->assertJsonStructure(['gameId', 'seed']);

        $this->assertIsInt($response->json('seed'));
        $this->assertDatabaseCount('game_sessions', 1);
    }

    public function test_a_submission_without_a_game_id_is_rejected(): void
    {
        $this->submit(['nickname' => 'Ali', 'best_score' => 100, 'lines' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('gameId');
    }

    public function test_an_unknown_game_id_is_rejected(): void
    {
        $this->submit($this->body((string) Str::uuid(), 100, 1))->assertStatus(422);
        $this->assertDatabaseCount('daily_scores', 0);
    }

    public function test_another_players_game_cannot_be_used(): void
    {
        $gameId = $this->startGame($this->token(sub: '42'));
        $this->travel(60)->seconds();

        $this->submit($this->body($gameId, 100, 1), $this->token(sub: '99'))
            ->assertStatus(422);

        $this->assertDatabaseCount('daily_scores', 0);
    }

    public function test_a_game_cannot_be_submitted_twice(): void
    {
        $gameId = $this->startGame();
        $this->travel(60)->seconds();

        $this->submit($this->body($gameId, 100, 1))->assertStatus(200);
        $this->submit($this->body($gameId, 100, 1))->assertStatus(422);
    }

    public function test_an_expired_game_is_rejected(): void
    {
        $gameId = $this->startGame();

        $this->travel(7)->hours();   // TTL is 6

        $this->submit($this->body($gameId, 100, 1))->assertStatus(422);
    }

    public function test_a_game_finished_impossibly_fast_is_rejected(): void
    {
        $gameId = $this->startGame();

        // 40 lines needs 100 pieces, so at least 12 seconds. No travel at all
        // here, so barely any time has passed.
        $this->submit($this->body($gameId, 10000, 40))
            ->assertStatus(422)
            ->assertJson(fn ($json) => $json->where('message', fn ($m) => str_contains($m, 'too short')));
    }

    public function test_a_score_too_high_for_the_lines_cleared_is_rejected(): void
    {
        $gameId = $this->startGame();
        $this->travel(5)->minutes();

        // One line can only ever be a single: 100 points at level 1.
        $this->submit($this->body($gameId, 50000, 1))->assertStatus(422);
    }

    public function test_a_score_too_low_for_the_lines_cleared_is_rejected(): void
    {
        $gameId = $this->startGame();
        $this->travel(5)->minutes();

        // 10 lines cannot score 1 point.
        $this->submit($this->body($gameId, 1, 10))->assertStatus(422);
    }

    public function test_the_exact_maximum_for_the_lines_cleared_is_accepted(): void
    {
        $gameId = $this->startGame();
        $this->travel(5)->minutes();

        // 4 lines at once at level 1 is a tetris: 800.
        $this->submit($this->body($gameId, 800, 4))->assertStatus(200);
    }

    public function test_the_exact_minimum_for_the_lines_cleared_is_accepted(): void
    {
        $gameId = $this->startGame();
        $this->travel(5)->minutes();

        // 4 singles at level 1 is 400.
        $this->submit($this->body($gameId, 400, 4))->assertStatus(200);
    }

    /**
     * The browser clamps at MAX_SCORE, so a long honest game reports less than
     * its true minimum. The bounds are clamped the same way or it is rejected.
     */
    public function test_a_long_honest_game_clamped_to_the_maximum_is_accepted(): void
    {
        $gameId = $this->startGame();
        $this->travel(2)->hours();

        $this->submit($this->body($gameId, LeaderboardController::MAX_SCORE, 300))
            ->assertStatus(200);
    }

    public function test_an_absurd_line_count_is_rejected_before_the_bounds_run(): void
    {
        $gameId = $this->startGame();

        $this->submit($this->body($gameId, 100, 1_000_000_000))
            ->assertStatus(422)
            ->assertJsonValidationErrors('lines');
    }

    public function test_expired_sessions_are_swept_when_a_game_starts(): void
    {
        DB::table('game_sessions')->insert([
            'id' => (string) Str::uuid(),
            'shop_id' => 'old.myshopify.com',
            'player_id' => '1',
            'seed' => 123,
            'created_at' => now()->subHours(9),
            'used_at' => null,
        ]);

        $this->assertDatabaseCount('game_sessions', 1);

        $this->startGame();

        // The stale row is gone; only the one just opened remains.
        $this->assertDatabaseCount('game_sessions', 1);
        $this->assertDatabaseMissing('game_sessions', ['shop_id' => 'old.myshopify.com']);
    }
}
