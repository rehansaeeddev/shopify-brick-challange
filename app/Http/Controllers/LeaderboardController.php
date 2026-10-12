<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class LeaderboardController extends Controller
{
    public function submit(Request $request)
    {
        $data = $request->validate([
            'nickname'   => 'required|string|max:50',
            'best_score' => 'required|integer|min:0|max:100000',
        ]);

        // Shop and player will come from the verified session token later.
        return response()->json(['received' => $data]);
    }
}