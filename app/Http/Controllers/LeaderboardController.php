<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Rules\AllowedNickname;

class LeaderboardController extends Controller
{
    /**
     * Ceiling for a single classic round. A perfect game is bounded by how many
     * lines can be cleared before the stack tops out; this sits well above real
     * play and well below the values a tampered client would send. The score is
     * computed in the browser, so this is a damage limit, not proof of honesty.
     */
    public const MAX_SCORE = 100000;

    /** Bounds the bounds DP, so a request can't ask for a billion iterations. */
    public const MAX_LINES = 2000;

    /** Points per simultaneous clear, indexed by the number of lines. */
    private const POINTS = [0, 100, 300, 500, 800];

    /**
     * The floor on how long a game could honestly have taken.
     *
     * A line is 10 cells and a piece is 4, so L lines need at least ceil(2.5L)
     * pieces, and no one places a piece faster than this. It is a lower bound
     * only: a forger who simply waits will clear it.
     */
    private const MIN_MS_PER_PIECE = 120;

    public function submit(Request $request)
    {
        $data = $request->validate([
            'gameId'     => 'required|uuid',
            'nickname'   => ['required', 'string', 'max:50', new AllowedNickname()],
            'best_score' => 'required|integer|min:0|max:' . self::MAX_SCORE,
            'lines'      => 'required|integer|min:0|max:' . self::MAX_LINES,
        ]);

        $today = now('UTC')->toDateString();

        // Set by VerifyShopifySession after the token is checked
        $shopId = $request->attributes->get('shop_id');
        $playerId = $request->attributes->get('player_id');

        $session = $this->consumeSession($data['gameId'], $shopId, $playerId);

        if ($session === null) {
            return response()->json([
                'message' => 'That game is unknown, already submitted, or expired.',
            ], 422);
        }

        $problem = $this->implausible($data['best_score'], $data['lines'], $session);

        if ($problem !== null) {
            return response()->json(['message' => $problem], 422);
        }

        $bindings = [$today, $shopId, $playerId, $data['nickname'], $data['best_score']];

        // "Insert, or keep whichever score is higher" is one atomic statement on
        // both drivers, so there is no check-then-save race. The two dialects
        // spell it differently, hence the branch rather than MySQL-only SQL.
        $driver = DB::connection()->getDriverName();

        $sql = match ($driver) {
            'mysql', 'mariadb' =>
                'INSERT INTO daily_scores (day, shop_id, player_id, nickname, best_score, updated_at)
                 VALUES (?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                   updated_at = IF(VALUES(best_score) > best_score, NOW(), updated_at),
                   best_score = GREATEST(best_score, VALUES(best_score))',

            'sqlite' =>
                'INSERT INTO daily_scores (day, shop_id, player_id, nickname, best_score, updated_at)
                 VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                 ON CONFLICT (day, shop_id, player_id) DO UPDATE SET
                   updated_at = CASE WHEN excluded.best_score > daily_scores.best_score
                                     THEN CURRENT_TIMESTAMP ELSE daily_scores.updated_at END,
                   best_score = MAX(daily_scores.best_score, excluded.best_score)',

            // Fail loudly rather than silently writing the wrong thing. Postgres
            // would need the ON CONFLICT form with GREATEST instead of MAX.
            default => throw new \RuntimeException(
                "No daily-score upsert defined for database driver [{$driver}]."
            ),
        };

        DB::statement($sql, $bindings);

        return response()->json(['saved' => true, 'day' => $today]);
    }

    /**
     * Claims the game in one statement.
     *
     * The WHERE clause carries every condition -- right owner, never used, not
     * expired -- so two submissions racing each other cannot both succeed: the
     * second one updates zero rows. A select-then-update would let both through.
     *
     * Returns the row as it was before being claimed, or null if the claim failed.
     */
    private function consumeSession(string $gameId, string $shopId, string $playerId): ?object
    {
        $cutoff = now()->subHours(GameController::SESSION_TTL_HOURS);

        $claimed = DB::table('game_sessions')
            ->where('id', $gameId)
            ->where('shop_id', $shopId)
            ->where('player_id', $playerId)
            ->whereNull('used_at')
            ->where('created_at', '>', $cutoff)
            ->update(['used_at' => now()]);

        if ($claimed !== 1) {
            return null;
        }

        return DB::table('game_sessions')->where('id', $gameId)->first();
    }

    /**
     * Checks the claimed score against what the rules allow.
     *
     * Returns a message describing the first problem, or null when the numbers
     * are self-consistent. Nothing here proves the game was really played; it
     * rules out numbers that could not have come from these rules.
     */
    private function implausible(int $score, int $lines, object $session): ?string
    {
        // Server-measured: the row was written before play began, so this is
        // real wall-clock time, not something the browser reported.
        //
        // Order matters. Carbon 3 returns a SIGNED difference, so calling this
        // the other way round gives a negative number and every game looks too
        // short. Earlier date first, later date as the argument.
        $elapsedMs = Carbon::parse($session->created_at)->diffInMilliseconds(now());

        $piecesNeeded = (int) ceil($lines * 10 / 4);

        if ($elapsedMs < $piecesNeeded * self::MIN_MS_PER_PIECE) {
            return 'That game was too short for the number of lines cleared.';
        }

        [$low, $high] = $this->scoreBounds($lines);

        // The client clamps its score at MAX_SCORE, so an honest long game can
        // legitimately report less than the true minimum. Clamp the bounds the
        // same way or those players get rejected.
        $low = min($low, self::MAX_SCORE);
        $high = min($high, self::MAX_SCORE);

        if ($score < $low || $score > $high) {
            return 'That score does not match the number of lines cleared.';
        }

        return null;
    }

    /**
     * Lowest and highest score reachable for a given number of lines.
     *
     * Worked out with dynamic programming rather than a greedy rule, because
     * "all tetrises" is not provably optimal once the level multiplier moves
     * mid-game. best[l] considers arriving at l lines via a clear of 1..4,
     * scoring at the level that applies after those lines are added -- exactly
     * what lock() does in the browser.
     *
     * @return array{0:int,1:int}
     */
    private function scoreBounds(int $lines): array
    {
        $min = [0 => 0];
        $max = [0 => 0];

        for ($l = 1; $l <= $lines; $l++) {
            $level = intdiv($l, 10) + 1;
            $lo = null;
            $hi = null;

            for ($n = 1; $n <= min(4, $l); $n++) {
                $value = self::POINTS[$n] * $level;
                $lo = $lo === null ? $min[$l - $n] + $value : min($lo, $min[$l - $n] + $value);
                $hi = $hi === null ? $max[$l - $n] + $value : max($hi, $max[$l - $n] + $value);
            }

            $min[$l] = $lo;
            $max[$l] = $hi;
        }

        return [$min[$lines], $max[$lines]];
    }

    public function top()
    {
        $today = now('UTC')->toDateString();

        $topScores = DB::table('daily_scores')
            ->where('day', $today)
            ->orderByDesc('best_score')
            ->limit(10)
            ->get(['nickname', 'best_score']);

        return response()->json($topScores);
    }
}
