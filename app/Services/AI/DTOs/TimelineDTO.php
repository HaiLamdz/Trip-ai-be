<?php

namespace App\Services\AI\DTOs;

class TimelineDTO
{
    /**
     * @param TripDayDTO[] $days
     */
    public function __construct(
        public readonly array $days,
    ) {}

    public static function fromArray(array $data): self
    {
        $days = array_map(
            fn (array $d) => TripDayDTO::fromArray($d),
            $data['days'] ?? []
        );

        return new self(days: $days);
    }

    public function toArray(): array
    {
        return [
            'days' => array_map(fn (TripDayDTO $d) => $d->toArray(), $this->days),
        ];
    }
}
