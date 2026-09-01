<?php

namespace Tests;

use App\Enums\InspectionResult;
use App\Enums\ReportStatus;
use App\Models\Asset;
use App\Models\Document;
use App\Models\InspectionReport;
use App\Models\InspectionReportAsset;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use App\Support\Permissions;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    /** Roles y permisos: los necesita prácticamente cada prueba. */
    protected function seedRoles(): void
    {
        $this->seed(RolePermissionSeeder::class);
    }

    /** Organización de prueba. Sin código explícito genera uno único. */
    protected function organization(?string $code = null): Organization
    {
        $code ??= Str::upper(Str::random(6));

        return Organization::factory()->create([
            'name' => "Organización {$code}",
            'short_code' => $code,
        ]);
    }

    /** Usuario de cliente atado a una organización. */
    protected function clientUser(Organization $organization, string $role = Permissions::ROLE_CLIENT_OPERATOR): User
    {
        $user = User::factory()->create([
            'organization_id' => $organization->getKey(),
            'password' => Hash::make('N0-Es-Un-Secreto-Real'),
        ]);

        $user->assignRole($role);

        return $user;
    }

    /** Usuario interno de Odessa Oil, sin organización. */
    protected function staffUser(string $role = Permissions::ROLE_WORKSHOP): User
    {
        $user = User::factory()->create(['organization_id' => null]);
        $user->assignRole($role);

        return $user;
    }

    /** Documento adjunto a un FMR o a una pieza, con su binario en disco falso. */
    protected function document(Model $documentable, array $attributes = []): Document
    {
        $path = 'org-'.$documentable->organization_id.'/'.Str::uuid7().'.pdf';
        Storage::disk(config('odessa.documents_disk'))
            ->put($path, '%PDF-1.4 contenido de prueba');

        $document = new Document([
            'organization_id' => $documentable->organization_id,
            'category' => 'fmr_report',
            'original_name' => 'informe-de-prueba.pdf',
            'path' => $path,
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            ...$attributes,
        ]);

        $document->documentable()->associate($documentable);
        $document->save();

        return $document;
    }

    protected function service(bool $determinesFitness = true, int $validityDays = 365): Service
    {
        return Service::create([
            'code' => 'S'.Str::upper(Str::random(4)),
            'name' => 'Servicio de prueba',
            'determines_fitness' => $determinesFitness,
            'validity_days' => $validityDays,
        ]);
    }

    protected function asset(Organization $organization, array $attributes = []): Asset
    {
        return Asset::factory()->create([
            'organization_id' => $organization->getKey(),
            'catalog_item_id' => null,
            ...$attributes,
        ]);
    }

    /**
     * FMR final con una línea de dictamen sobre la pieza indicada.
     * Devuelve el informe ya persistido, sin recalcular el estado.
     */
    protected function reportFor(
        Asset $asset,
        InspectionResult $result = InspectionResult::Apto,
        ?string $inspectedAt = null,
        ?string $expiresAt = null,
        ReportStatus $status = ReportStatus::Final,
    ): InspectionReport {
        $report = InspectionReport::withoutGlobalScope('organization')->forceCreate([
            'public_id' => (string) Str::uuid7(),
            'organization_id' => $asset->organization_id,
            'number' => (string) fake()->unique()->numberBetween(1000, 999999),
            'inspected_at' => $inspectedAt ?? now()->subMonth()->toDateString(),
            'status' => $status->value,
        ]);

        InspectionReportAsset::create([
            'inspection_report_id' => $report->getKey(),
            'asset_id' => $asset->getKey(),
            'result' => $result->value,
            'expires_at' => $expiresAt,
        ]);

        return $report;
    }
}
