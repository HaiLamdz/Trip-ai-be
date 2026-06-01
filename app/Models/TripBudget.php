<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TripBudget extends Model
{
    use HasFactory;

    protected $fillable = [
        'trip_id',
        'food',
        'transport',
        'attraction',
        'accommodation',
        'other',
        'total_estimated',
        'food_actual',
        'transport_actual',
        'attraction_actual',
        'accommodation_actual',
        'other_actual',
        'total_actual',
    ];

    protected function casts(): array
    {
        return [
            'food'                 => 'decimal:2',
            'transport'            => 'decimal:2',
            'attraction'           => 'decimal:2',
            'accommodation'        => 'decimal:2',
            'other'                => 'decimal:2',
            'total_estimated'      => 'decimal:2',
            'food_actual'          => 'decimal:2',
            'transport_actual'     => 'decimal:2',
            'attraction_actual'    => 'decimal:2',
            'accommodation_actual' => 'decimal:2',
            'other_actual'         => 'decimal:2',
            'total_actual'         => 'decimal:2',
        ];
    }

    // ─────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────

    public function trip(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }
}
