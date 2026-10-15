<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GameController extends Controller
{
    /** How long an issued game stays redeemable, and how long the row is kept. */
    public const SESSION_TTL_HOURS = 6;

    /**
     * Opens a game.
     *
     * Returns an id the score must be submitted against, and a seed for the
     * piece bag. The seed is groundwork: without server-side replay nothing
     * forces the browser to use it. The id is what does the work here -- it
     * records when the game began and can be redeemed exactly once.
     */
    public function start(Request $request)
    {
        $shopId = (string) $request->attributes->get('shop_id');
        $playerId = (string) $request->attributes->get('player_id');

        // Retention, done on the way in so no scheduled job is needed.
        DB::table('game_sessions')
            ->where('created_at', '<', now()->subHours(self::SESSION_TTL_HOURS))
            ->delete();

        $id = (string) Str::uuid();
        $seed = random_int(1, 0xFFFFFFFF);

        DB::table('game_sessions')->insert([
            'id' => $id,
            'shop_id' => $shopId,
            'player_id' => $playerId,
            'seed' => $seed,
            'created_at' => now(),
            'used_at' => null,
        ]);

        return response()->json(['gameId' => $id, 'seed' => $seed]);
    }
}
