<?php

namespace App\Services\AI\DTOs;

class TripGenerationRequest
{
    public function __construct(
        public readonly string  $destination,
        public readonly string  $startDate,
        public readonly int     $durationDays,
        public readonly float   $budget,
        public readonly int     $numPeople,
        public readonly ?string $origin              = null,
        public readonly ?string $travelType          = null,  // solo|couple|family|group
        public readonly ?string $transportMode       = null,
        public readonly ?string $accommodationType   = null,  // hotel|homestay|hostel|resort|airbnb|villa|other
        public readonly ?string $accommodationArea   = null,  // khu vực / tên chỗ ở người dùng nhập
        public readonly ?string $arrivalTime         = null,  // HH:MM — giờ đến ngày đầu
        public readonly array   $preferences         = [],
        public readonly ?string $notes               = null,
        public readonly array   $weatherData         = [],
        public readonly array   $userPreferences     = [],
    ) {}
}
