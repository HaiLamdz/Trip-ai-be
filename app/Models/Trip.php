<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Trip extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'destination',
        'origin',
        'destination_lat',
        'destination_lng',
        'start_date',
        'duration_days',
        'budget',
        'num_people',
        'travel_type',
        'transport_mode',
        'accommodation_type',
        'accommodation_area',
        'arrival_time',
        'accommodation_lat',
        'accommodation_lng',
        'accommodation_name',
        'preferences',
        'notes',
        'status',
        'timeline',
        'share_token',
        'is_public',
        'user_notes',
        'cover_image_url',
        'is_published',
        'published_at',
        'publish_description',
        'clone_count',
        'view_count',
        'cloned_from_id',
        'invite_link_token',
    ];

    protected function casts(): array
    {
        return [
            'start_date'        => 'date',
            'duration_days'     => 'integer',
            'budget'            => 'decimal:2',
            'num_people'        => 'integer',
            'destination_lat'   => 'decimal:7',
            'destination_lng'   => 'decimal:7',
            'accommodation_lat' => 'decimal:7',
            'accommodation_lng' => 'decimal:7',
            'preferences'       => 'array',
            'timeline'          => 'array',
            'is_public'         => 'boolean',
            'is_published'      => 'boolean',
            'published_at'      => 'datetime',
            'clone_count'       => 'integer',
            'view_count'        => 'integer',
        ];
    }

    // ─────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function days(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TripDay::class)->orderBy('day_number');
    }

    public function places(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TripPlace::class);
    }

    public function budget(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(TripBudget::class);
    }

    public function conversations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AiConversation::class)->orderBy('created_at');
    }

    public function expenses(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TripExpense::class);
    }

    public function favoritedBy(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'favorites');
    }

    public function members(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TripMember::class);
    }

    public function clonedFrom(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Trip::class, 'cloned_from_id');
    }

    public function clones(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Trip::class, 'cloned_from_id');
    }
}
