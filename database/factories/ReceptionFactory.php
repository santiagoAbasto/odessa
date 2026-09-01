<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\Organization;
use App\Models\Reception;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Reception> */
class ReceptionFactory extends Factory
{
    protected $model = Reception::class;

    public function definition(): array
    {
        $received = fake()->dateTimeBetween('-90 days', 'now');

        return [
            'organization_id' => Organization::factory(),
            'asset_id' => Asset::factory(),
            'received_at' => $received,
            'promised_at' => (clone $received)->modify('+7 days'),
            'is_urgent' => fake()->boolean(15),
        ];
    }
}
