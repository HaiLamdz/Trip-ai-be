<?php

namespace App\Services\AI\DTOs;

class TripDayDTO
{
    /**
     * @param ActivityDTO[] $activities
     */
    public function __construct(
        public readonly string $date,
        public readonly ?WeatherDTO $weather,
        public readonly array $activities,
    ) {}

    public static function fromArray(array $data): self
    {
        $activities = array_map(
            fn (array $a) => ActivityDTO::fromArray($a),
            $data['activities'] ?? []
        );

        $weather = null;
        if (isset($data['weather']) && is_array($data['weather'])) {
            $w = $data['weather'];
            $weather = new WeatherDTO(
                summary:          $w['summary'] ?? '',
                icon:             $w['icon'] ?? '01d',
                temperatureHigh:  (float) ($w['temperature_high'] ?? 30),
                temperatureLow:   (float) ($w['temperature_low'] ?? 22),
                rainProbability:  (float) ($w['rain_probability'] ?? 0),
            );
        }

        return new self(
            date:       $data['date'] ?? '',
            weather:    $weather,
            activities: $activities,
        );
    }

    public function toArray(): array
    {
        return [
            'date'       => $this->date,
            'weather'    => $this->weather?->toArray(),
            'activities' => array_map(fn (ActivityDTO $a) => $a->toArray(), $this->activities),
        ];
    }
}
