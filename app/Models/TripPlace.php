<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TripPlace extends Model
{
    use HasFactory;

    protected $fillable = [
        'trip_day_id',
        'trip_id',
        'place_name',
        'place_type',
        'time',
        'title',
        'description',
        'estimated_cost',
        'duration_minutes',
        'transport_to_next',
        'distance_to_next_km',
        'latitude',
        'longitude',
        'sort_order',
        'checked_in_at',
        'checkin_photo',
        'checkin_note',
        'actual_time',
    ];

    protected function casts(): array
    {
        return [
            'estimated_cost'      => 'decimal:2',
            'duration_minutes'    => 'integer',
            'distance_to_next_km' => 'decimal:2',
            'latitude'            => 'decimal:7',
            'longitude'           => 'decimal:7',
            'sort_order'          => 'integer',
            'checked_in_at'       => 'datetime',
        ];
    }

    // ─────────────────────────────────────────────
    // Accessors
    // ─────────────────────────────────────────────

    /**
     * URL đầy đủ của ảnh check-in (null nếu chưa check-in hoặc chưa có ảnh).
     */
    public function getCheckinPhotoUrlAttribute(): ?string
    {
        if (! $this->checkin_photo) {
            return null;
        }
        return rtrim(config('app.url'), '/') . '/storage/' . $this->checkin_photo;
    }

    // ─────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────

    public function tripDay(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(TripDay::class);
    }

    public function trip(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function expenses(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TripExpense::class);
    }
}
