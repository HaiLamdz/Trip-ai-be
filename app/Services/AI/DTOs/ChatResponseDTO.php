<?php

namespace App\Services\AI\DTOs;

class ChatResponseDTO
{
    public function __construct(
        public readonly string $message,
        public readonly ?TimelineDTO $updatedTimeline = null,
        public readonly array $suggestions = [],
    ) {}
}
