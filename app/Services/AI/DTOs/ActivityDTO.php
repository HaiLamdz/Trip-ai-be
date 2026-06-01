<?php

namespace App\Services\AI\DTOs;

class ActivityDTO
{
    public function __construct(
        public readonly string $time,
        public readonly string $title,
        public readonly string $description,
        public readonly string $placeName,
        public readonly string $placeType,
        public readonly float $estimatedCost,
        public readonly int $durationMinutes,
        public readonly ?string $transportToNext,
        public readonly float $distanceToNextKm,
        public readonly ?float $latitude,
        public readonly ?float $longitude,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            time:              $data['time'] ?? '08:00',
            title:             $data['title'] ?? '',
            description:       $data['description'] ?? '',
            placeName:         $data['place_name'] ?? '',
            placeType:         $data['place_type'] ?? 'other',
            estimatedCost:     (float) ($data['estimated_cost'] ?? 0),
            durationMinutes:   (int) ($data['duration_minutes'] ?? 0),
            transportToNext:   $data['transport_to_next'] ?? null,
            distanceToNextKm:  (float) ($data['distance_to_next_km'] ?? 0),
            latitude:          isset($data['latitude']) ? (float) $data['latitude'] : null,
            longitude:         isset($data['longitude']) ? (float) $data['longitude'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'time'                 => $this->time,
            'title'                => $this->title,
            'description'          => $this->description,
            'place_name'           => $this->placeName,
            'place_type'           => $this->placeType,
            'estimated_cost'       => $this->estimatedCost,
            'duration_minutes'     => $this->durationMinutes,
            'transport_to_next'    => $this->transportToNext,
            'distance_to_next_km'  => $this->distanceToNextKm,
            'latitude'             => $this->latitude,
            'longitude'            => $this->longitude,
        ];
    }
}
