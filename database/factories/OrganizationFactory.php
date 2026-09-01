<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Organization> */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'short_code' => Str::upper(Str::substr(preg_replace('/[^A-Za-z]/', '', $name), 0, 3)).fake()->unique()->numberBetween(10, 99),
            'tax_id' => fake()->numerify('##-########-#'),
            'contact_email' => fake()->companyEmail(),
            'contact_phone' => fake()->numerify('+54 11 ####-####'),
            'is_active' => true,
        ];
    }
}
