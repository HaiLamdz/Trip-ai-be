<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AiConversation extends Model
{
    use HasFactory;

    // Only created_at, no updated_at
    public $timestamps = false;

    protected $fillable = [
        'trip_id',
        'user_id',
        'user_message',
        'ai_response',
        'timeline_snapshot',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'timeline_snapshot' => 'array',
            'created_at'        => 'datetime',
        ];
    }

    // ─────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────

    public function trip(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
