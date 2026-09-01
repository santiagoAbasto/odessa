<?php

namespace Database\Factories;

use App\Models\CatalogItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CatalogItem> */
class CatalogItemFactory extends Factory
{
    protected $model = CatalogItem::class;

    public function definition(): array
    {
        return [
            'kind' => 'part',
            'description' => fake()->unique()->words(3, true),
            'part_number' => Str::upper(fake()->unique()->bothify('###-####-###')),
        ];
    }
}
