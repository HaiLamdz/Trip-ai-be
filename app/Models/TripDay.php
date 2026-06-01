<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TripDay extends Model
{
    use HasFactory;

    protected $fillable = [
        'trip_id',
        'day_number',
        'date',
        'weather',
    ];

    protected function casts(): array
    {
        return [
            'date'       => 'date',
            'day_number' => 'integer',
            'weather'    => 'array',
        ];
    }

    // ─────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────

    public function trip(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function places(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TripPlace::class)->orderBy('sort_order');
    }
}
