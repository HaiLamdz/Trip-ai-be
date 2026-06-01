<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TripFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id'        => User::factory(),
            'destination'    => $this->faker->city(),
            'start_date'     => $this->faker->dateTimeBetween('+1 day', '+30 days')->format('Y-m-d'),
            'duration_days'  => $this->faker->numberBetween(1, 7),
            'budget'         => $this->faker->numberBetween(1000000, 10000000),
            'num_people'     => $this->faker->numberBetween(1, 5),
            'transport_mode' => $this->faker->randomElement(['car', 'motorbike', 'bus', 'plane']),
            'preferences'    => $this->faker->randomElements(['food', 'cafe', 'nature', 'culture'], 2),
            'status'         => 'completed',
            'timeline'       => ['days' => []],
        ];
    }
}
