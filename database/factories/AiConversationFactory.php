<?php

namespace Database\Factories;

use App\Models\Trip;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AiConversationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'trip_id'           => Trip::factory(),
            'user_id'           => User::factory(),
            'user_message'      => $this->faker->sentence(),
            'ai_response'       => $this->faker->paragraph(),
            'timeline_snapshot' => null,
            'created_at'        => now(),
        ];
    }
}
