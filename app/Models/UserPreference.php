<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class UserPreference extends Model
{
    use HasFactory;

    // Only updated_at, no created_at
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'preferences',
        'travel_history',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'preferences'    => 'array',
            'travel_history' => 'array',
            'updated_at'     => 'datetime',
        ];
    }

    // ─────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
