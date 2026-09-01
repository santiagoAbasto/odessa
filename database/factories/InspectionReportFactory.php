<?php

namespace Database\Factories;

use App\Enums\ReportStatus;
use App\Models\InspectionReport;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InspectionReport> */
class InspectionReportFactory extends Factory
{
    protected $model = InspectionReport::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'number' => (string) fake()->unique()->numberBetween(1000, 9999),
            'inspected_at' => fake()->dateTimeBetween('-2 years', 'now'),
            'status' => ReportStatus::Final->value,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => ReportStatus::Draft->value]);
    }
}
