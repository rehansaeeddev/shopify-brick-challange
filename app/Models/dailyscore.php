<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyScore extends Model
{
    protected $table = 'daily_scores';

    protected $fillable = [
        'day',
        'shop_id',
        'player_id',
        'nickname',
        'best_score',
        'updated_at',
    ];

    protected $casts = [
        'day' => 'date',
        'best_score' => 'integer',
        'updated_at' => 'datetime',
    ];

    public $timestamps = false;
}