<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\CatalogItem;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Asset> */
class AssetFactory extends Factory
{
    protected $model = Asset::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'catalog_item_id' => CatalogItem::factory(),
            'kind' => Asset::KIND_PART,
            'serial_number' => Str::upper(fake()->bothify('??####-##')),
            'code' => Str::upper(Str::random(8)),
            'client_reference' => (string) fake()->numberBetween(10_000_000, 99_999_999),
            'manufacturer_reference' => Str::upper(fake()->bothify('###-####-###')),
            'description' => fake()->randomElement([
                'Válvula de compuerta', 'Brida de conexión', 'Adaptador de presión',
                'Sección inferior de acople', 'Lubricador', 'Cabezal de prueba',
            ]).' '.fake()->randomElement(['4 1/16"', '5 1/8"', '7 1/16"']),
            'status' => 'unknown',
        ];
    }

    public function generic(): static
    {
        return $this->state(fn () => [
            'kind' => Asset::KIND_GENERIC,
            'brand' => fake()->randomElement(['Fabricante Norte', 'Industrias Sur', 'Equipos Centro']),
            'model' => Str::upper(fake()->bothify('MD-###')),
            'identifier' => Str::upper(fake()->bothify('ID####')),
        ]);
    }
}
