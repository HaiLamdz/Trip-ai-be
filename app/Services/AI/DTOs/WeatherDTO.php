<?php

namespace App\Services\AI\DTOs;

class WeatherDTO
{
    public function __construct(
        public readonly string $summary = '',
        public readonly string $icon = '01d',
        public readonly float $temperatureHigh = 30.0,
        public readonly float $temperatureLow = 22.0,
        public readonly float $rainProbability = 0.0,
    ) {}

    public static function default(): self
    {
        return new self(
            summary: 'Không có dữ liệu thời tiết',
            icon: '01d',
            temperatureHigh: 30.0,
            temperatureLow: 22.0,
            rainProbability: 0.0,
        );
    }

    public function toArray(): array
    {
        return [
            'summary'          => $this->summary,
            'icon'             => $this->icon,
            'temperature_high' => $this->temperatureHigh,
            'temperature_low'  => $this->temperatureLow,
            'rain_probability' => $this->rainProbability,
        ];
    }
}
