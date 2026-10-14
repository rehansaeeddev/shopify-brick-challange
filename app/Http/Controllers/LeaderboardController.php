<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeaderboardController extends Controller
{
    public function submit(Request $request)
    {
        $data = $request->validate([
            'nickname'   => 'required|string|max:50',
            'best_score' => 'required|integer|min:0|max:100000',
        ]);

        $today = now('UTC')->toDateString();

        // Set by VerifyShopifySession after the token is checked
        $shopId = $request->attributes->get('shop_id');
        $playerId = $request->attributes->get('player_id');

        DB::statement(
            'INSERT INTO daily_scores (day, shop_id, player_id, nickname, best_score, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
               updated_at = IF(VALUES(best_score) > best_score, NOW(), updated_at),
               best_score = GREATEST(best_score, VALUES(best_score))',
            [$today, $shopId, $playerId, $data['nickname'], $data['best_score']]
        );

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
