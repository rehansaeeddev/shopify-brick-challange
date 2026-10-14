<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeaderboardController extends Controller
{
    /**
     * Ceiling for a single classic round. A perfect game is bounded by how many
     * lines can be cleared before the stack tops out; this sits well above real
     * play and well below the values a tampered client would send. The score is
     * computed in the browser, so this is a damage limit, not proof of honesty.
     */
    public const MAX_SCORE = 100000;

    public function submit(Request $request)
    {
        $data = $request->validate([
            'nickname'   => 'required|string|max:50',
            'best_score' => 'required|integer|min:0|max:' . self::MAX_SCORE,
        ]);

        $today = now('UTC')->toDateString();

        // Set by VerifyShopifySession after the token is checked
        $shopId = $request->attributes->get('shop_id');
        $playerId = $request->attributes->get('player_id');

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
