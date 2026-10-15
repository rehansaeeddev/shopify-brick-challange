<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per game the server has issued.
 *
 * The server hands out the row before play starts, so it knows when the game
 * really began. That makes the elapsed time a server-measured value rather than
 * something the browser reports, and it lets a score be accepted exactly once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('shop_id');
            $table->string('player_id');
            $table->unsignedInteger('seed');
            $table->timestamp('created_at');
            $table->timestamp('used_at')->nullable();

            // shop_id for shop/redact; created_at for the retention sweep.
            $table->index(['shop_id', 'player_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_sessions');
    }
};
