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

        // Placeholders until the Shopify session check is added
        $shopId = 'test-shop';
        $playerId = 'test-player';

        DB::statement(
            'INSERT INTO daily_scores (day, shop_id, player_id, nickname, best_score, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
               best_score = GREATEST(best_score, VALUES(best_score)),
               updated_at = IF(VALUES(best_score) > best_score, NOW(), updated_at),
               best_score = GREATEST(best_score, VALUES(best_score))',
            [$today, $shopId, $playerId, $data['nickname'], $data['best_score']]
        );

        return response()->json(['saved' => true, 'day' => $today]);
    }
}
