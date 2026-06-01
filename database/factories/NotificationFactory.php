<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class NotificationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type'    => $this->faker->randomElement(['trip_completed', 'trip_failed', 'budget_warning', 'weather_alert']),
            'title'   => $this->faker->sentence(4),
            'body'    => $this->faker->sentence(),
            'data'    => null,
            'read_at' => null,
        ];
    }
}
