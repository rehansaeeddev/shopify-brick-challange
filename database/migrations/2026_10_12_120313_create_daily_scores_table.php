<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('daily_scores', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->string('shop_id');
            $table->string('player_id');
            $table->string('nickname', 50);
            $table->unsignedInteger('best_score');
            $table->timestamp('updated_at')->useCurrent();

            $table->unique(['day', 'shop_id', 'player_id'], 'unique_daily_score');
            $table->index(['day', 'best_score']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_scores');
    }
};
