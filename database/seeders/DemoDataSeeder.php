<?php

namespace Database\Seeders;

use App\Actions\RecalculateAssetStatus;
use App\Enums\AssetStatus;
use App\Enums\InspectionResult;
use App\Enums\ReportStatus;
use App\Models\Asset;
use App\Models\AssetSet;
use App\Models\CatalogItem;
use App\Models\Connection;
use App\Models\InspectionReport;
use App\Models\InspectionReportAsset;
use App\Models\Manufacturer;
use App\Models\Organization;
use App\Models\PressureRating;
use App\Models\Reception;
use App\Models\Service;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Datos de demostración.
 *
 * Todo es ficticio y generado localmente. NO se copia ninguna información del
 * sistema auditado: ni empresas, ni números de serie, ni números de FMR, ni
 * descripciones de piezas reales.
 *
 * La contraseña de los usuarios demo se toma de DEMO_PASSWORD; si no está
 * definida se genera una aleatoria y se muestra por consola una única vez,
 * de modo que nunca queda un secreto escrito en el repositorio.
 */
class DemoDataSeeder extends Seeder
{
    private string $demoPassword;

    /** Correlativo de numeración de FMR dentro de la organización en curso. */
    private int $reportNumber = 0;

    public function run(): void
    {
        $this->demoPassword = (string) env('DEMO_PASSWORD', '');

        if ($this->demoPassword === '') {
            $this->demoPassword = Str::password(16);
            $this->command?->warn('Contraseña generada para los usuarios demo: '.$this->demoPassword);
            $this->command?->warn('Anótela ahora: no se vuelve a mostrar ni queda guardada en el repositorio.');
        }

        $this->createStaff();

        foreach ([['Perforaciones del Golfo', 'PDG'], ['Servicios Energéticos Delta', 'SED']] as [$name, $code]) {
            $organization = Organization::firstOrCreate(
                ['short_code' => $code],
                ['name' => $name, 'contact_email' => 'contacto@'.Str::slug($name).'.example', 'is_active' => true]
            );

            $this->createClientUsers($organization);
            $this->createInventory($organization);
        }
    }

    private function createStaff(): void
    {
        $staff = [
            ['Ana Superadmin', 'super@odessaoil.test', Permissions::ROLE_SUPER_ADMIN, 'Dirección'],
            ['Carla Taller', 'taller@odessaoil.test', Permissions::ROLE_WORKSHOP, 'Taller'],
        ];

        foreach ($staff as [$name, $email, $role, $jobTitle]) {
            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => Hash::make($this->demoPassword),
                    'job_title' => $jobTitle,
                    'organization_id' => null,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]
            );

            $user->syncRoles([$role]);
        }
    }

    private function createClientUsers(Organization $organization): void
    {
        $slug = Str::lower($organization->short_code);

        $users = [
            ["Operador {$organization->short_code}", "operador.{$slug}@cliente.test", Permissions::ROLE_CLIENT_OPERATOR],
            ["Consulta {$organization->short_code}", "consulta.{$slug}@cliente.test", Permissions::ROLE_CLIENT_VIEWER],
        ];

        foreach ($users as [$name, $email, $role]) {
            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => Hash::make($this->demoPassword),
                    'organization_id' => $organization->getKey(),
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]
            );

            $user->syncRoles([$role]);
        }
    }

    private function createInventory(Organization $organization): void
    {
        $manufacturers = Manufacturer::pluck('id')->all();
        $pressures = PressureRating::pluck('id')->all();
        $connections = Connection::pluck('id')->all();
        $certifying = Service::where('determines_fitness', true)->get();
        $nonCertifying = Service::where('determines_fitness', false)->get();

        $catalog = collect([
            ['Válvula de compuerta 5 1/8"', '506-0475-038'],
            ['Lubricador 10 pies', '527-0788-280'],
            ['Adaptador de bombeo 5 1/8"', 'L-9701014556'],
            ['Brida inferior 7 1/16"', 'M102591386-E'],
            ['Cabezal de prueba E32', '524-0788-200'],
            ['Sección de acople rápido', '547-0000-350'],
        ])->map(fn (array $row) => CatalogItem::firstOrCreate(
            ['kind' => 'part', 'description' => $row[0], 'part_number' => $row[1]],
            ['manufacturer_id' => fake()->randomElement($manufacturers)]
        ));

        $genericCatalog = collect(['Manómetro digital', 'Sensor de presión', 'Kit de sellos'])
            ->map(fn (string $d) => CatalogItem::firstOrCreate(
                ['kind' => 'generic', 'description' => $d, 'part_number' => null]
            ));

        // --- Piezas -------------------------------------------------------
        $assets = collect();

        for ($i = 0; $i < 40; $i++) {
            $item = $catalog->random();

            $assets->push(Asset::create([
                'organization_id' => $organization->getKey(),
                'catalog_item_id' => $item->getKey(),
                'kind' => Asset::KIND_PART,
                'serial_number' => Str::upper(fake()->bothify('??######-#')),
                'code' => Str::upper(Str::random(8)),
                'client_reference' => (string) fake()->numberBetween(10_000_000, 99_999_999),
                'manufacturer_reference' => Str::upper(fake()->bothify('###-####-###')),
                'description' => $item->description,
                'workshop_description' => $item->description.' | '.Str::upper(Str::random(6)),
                'manufacturer_id' => fake()->randomElement($manufacturers),
                'test_pressure_id' => fake()->randomElement($pressures),
                'working_pressure_id' => fake()->randomElement($pressures),
                'service_id' => $certifying->random()->getKey(),
                'connection_top_id' => fake()->randomElement($connections),
                'connection_bottom_id' => fake()->randomElement($connections),
            ]));
        }

        for ($i = 0; $i < 15; $i++) {
            $item = $genericCatalog->random();

            $assets->push(Asset::create([
                'organization_id' => $organization->getKey(),
                'catalog_item_id' => $item->getKey(),
                'kind' => Asset::KIND_GENERIC,
                'serial_number' => Str::upper(fake()->bothify('G-#####')),
                'code' => Str::upper(Str::random(8)),
                'client_reference' => (string) fake()->numberBetween(10_000_000, 99_999_999),
                'description' => $item->description,
                'brand' => fake()->randomElement(['Fabricante Norte', 'Industrias Sur', 'Equipos Centro']),
                'model' => Str::upper(fake()->bothify('MD-###')),
                'identifier' => Str::upper(fake()->bothify('ID####')),
            ]));
        }

        // --- FMR ----------------------------------------------------------
        // Los informes se generan como JORNADAS de inspección: una fecha, un
        // servicio, y varias piezas dictaminadas a la vez. Es como funciona el
        // negocio real y es lo que hace visible la regla «un informe, muchos
        // dictámenes»: el mismo FMR aparece con resultados distintos por pieza.
        $recalculate = app(RecalculateAssetStatus::class);
        $this->reportNumber = 4000 + ($organization->getKey() * 500);

        $parts = $assets->where('kind', Asset::KIND_PART)->values();

        /**
         * Jornadas de certificación.
         *
         * Las piezas se reparten entre las jornadas de forma que TODAS reciban
         * al menos un dictamen. Los meses elegidos hacen que, con vigencia de
         * un año, la demo muestre piezas aptas, próximas a vencer y vencidas:
         * a 11 meses el vencimiento cae dentro de la ventana preventiva.
         */
        $certifyingMonths = [26, 20, 14, 11, 9, 5, 2];
        $shuffled = $parts->shuffle()->values();
        $perBatch = (int) ceil($shuffled->count() / count($certifyingMonths));

        foreach ($certifyingMonths as $index => $monthsAgo) {
            $batch = $shuffled->slice($index * $perBatch, $perBatch);

            if ($batch->isEmpty()) {
                continue;
            }

            $service = $certifying->random();
            $inspectedAt = Carbon::today()->subMonths($monthsAgo)->subDays(fake()->numberBetween(0, 12));

            $report = $this->createReport($organization, $inspectedAt, $service);

            foreach ($batch as $asset) {
                // Dentro de una misma jornada conviven aptos y rechazos: es lo
                // que hace visible que el dictamen es por pieza, no por informe.
                $result = fake()->boolean(22) ? InspectionResult::NoApto : InspectionResult::Apto;
                $this->createLine($report, $asset, $service, $result, $inspectedAt);
            }
        }

        // Jornadas operativas: servicio de campo sobre lotes de piezas.
        // Son POSTERIORES a las certificaciones, para demostrar que no renuevan.
        foreach ([7, 3, 1] as $monthsAgo) {
            $batch = $parts->random(min(fake()->numberBetween(3, 6), $parts->count()));
            $service = $nonCertifying->random();
            $inspectedAt = Carbon::today()->subMonths($monthsAgo)->subDays(fake()->numberBetween(0, 15));

            $report = $this->createReport($organization, $inspectedAt, $service);

            foreach ($batch as $asset) {
                $this->createLine($report, $asset, $service, InspectionResult::NoAplica, $inspectedAt);
            }
        }

        // Genéricos: historial propio, con precinto en cada intervención.
        foreach ($assets->where('kind', Asset::KIND_GENERIC) as $generic) {
            $monthsAgo = fake()->numberBetween(1, 13);
            $service = $certifying->random();
            $inspectedAt = Carbon::today()->subMonths($monthsAgo)->subDays(fake()->numberBetween(0, 20));

            $report = $this->createReport($organization, $inspectedAt, $service);
            $result = fake()->boolean(20) ? InspectionResult::NoApto : InspectionResult::Apto;
            $this->createLine($report, $generic, $service, $result, $inspectedAt);
        }

        // --- Pieza de demostración ----------------------------------------
        // Reproduce exactamente el caso que más se malinterpreta: certificada
        // hace ocho meses, con dos servicios operativos POSTERIORES que quedan
        // en la bitácora pero no renuevan la vigencia.
        $showcase = $parts->first();

        $certification = $certifying->firstWhere('determines_fitness', true) ?? $certifying->first();
        $certifiedAt = Carbon::today()->subMonths(8);
        $certReport = $this->createReport($organization, $certifiedAt, $certification);
        $this->createLine($certReport, $showcase, $certification, InspectionResult::Apto, $certifiedAt);

        foreach ([4, 1] as $monthsAgo) {
            $service = $nonCertifying->random();
            $at = Carbon::today()->subMonths($monthsAgo);
            $opReport = $this->createReport($organization, $at, $service);
            $this->createLine($opReport, $showcase, $service, InspectionResult::NoAplica, $at);
        }

        foreach ($assets as $asset) {
            $recalculate($asset);
        }

        /**
         * Sets.
         *
         * Se arman DESPUÉS del recálculo para poder componer deliberadamente un
         * set completamente vigente y otro bloqueado: es el contraste que hace
         * entender la regla de disponibilidad de un vistazo.
         */
        $refreshed = Asset::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->where('kind', Asset::KIND_PART)
            ->get();

        $fit = $refreshed->where('status', AssetStatus::Fit)->values();
        $blocked = $refreshed->whereIn('status', [AssetStatus::Unfit, AssetStatus::Unknown])->values();

        $compositions = [
            ['Equipo de intervención 1', $fit->take(6), 'Conjunto con toda su certificación vigente.'],
            ['Equipo de intervención 2', $blocked->take(2)->concat($fit->slice(6, 5)), 'Conjunto con piezas pendientes de recertificar.'],
            ['Set de accesorios', $refreshed->shuffle()->take(8), 'Agrupación de accesorios varios.'],
        ];

        foreach ($compositions as [$setName, $members, $notes]) {
            if ($members->isEmpty()) {
                continue;
            }

            $set = AssetSet::create([
                'organization_id' => $organization->getKey(),
                'name' => $setName,
                'notes' => $notes,
            ]);

            $set->assets()->attach(
                $members->values()->mapWithKeys(
                    fn (Asset $a, int $pos) => [$a->getKey() => ['position' => $pos + 1]]
                )->all()
            );
        }

        // --- Recepciones en taller ----------------------------------------
        foreach ($assets->random(18) as $index => $asset) {
            $receivedAt = Carbon::today()->subDays(fake()->numberBetween(1, 60));

            $reception = Reception::create([
                'organization_id' => $organization->getKey(),
                'asset_id' => $asset->getKey(),
                'inspection_report_id' => $asset->current_inspection_report_id,
                'received_at' => $receivedAt,
                'promised_at' => $receivedAt->copy()->addDays(7),
                'is_urgent' => fake()->boolean(20),
                'notes' => 'Ingreso de demostración.',
            ]);

            // Distribución del pipeline: la mayoría despachadas, algunas en curso.
            $stage = fake()->randomElement(['pending', 'pending', 'released', 'dispatched', 'dispatched']);

            if ($stage !== 'pending') {
                $reception->forceFill([
                    'started_at' => $receivedAt->copy()->addDays(2),
                    'released_at' => $receivedAt->copy()->addDays(4),
                    'outcome' => Reception::OUTCOME_APPROVED,
                ])->save();
            }

            if ($stage === 'dispatched') {
                $reception->forceFill([
                    'dispatched_at' => $receivedAt->copy()->addDays(6),
                    'dispatch_note' => 'REM-'.fake()->numerify('#####'),
                ])->save();
            }
        }

        $this->createCompositeAsset($organization, $fit, $blocked);
        $this->createShipments($organization, $refreshed);
    }

    /**
     * Un equipo compuesto con una pieza bloqueándolo.
     *
     * Es el contraste que hace entender la regla de un vistazo: el conjunto
     * tiene su propia certificación al día, pero uno de sus componentes no
     * está apto y eso lo deja fuera de servicio. Sin un caso así en los datos,
     * la funcionalidad existe pero nadie la ve.
     */
    private function certifyingService(): Service
    {
        return Service::where('determines_fitness', true)->firstOrFail();
    }

    private function createCompositeAsset(Organization $organization, $fit, $blocked): void
    {
        if ($fit->count() < 3 || $blocked->isEmpty()) {
            return;
        }

        $conjunto = Asset::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->getKey(),
            'kind' => Asset::KIND_PART,
            'description' => 'Preventor de reventones — conjunto',
            'workshop_description' => 'BOP completo',
            'serial_number' => Str::upper(fake()->bothify('BOP-####')),
            'code' => Str::upper(fake()->bothify('C-#####')),
        ]);

        // Dos piezas sanas y una que lo bloquea.
        $componentes = $fit->take(2)->concat($blocked->take(1));

        foreach ($componentes as $componente) {
            $componente->forceFill(['parent_id' => $conjunto->getKey()])->save();
        }

        // El conjunto certifica al día: lo que lo bloquea es su componente.
        $report = $this->createReport($organization, Carbon::today()->subDays(40), $this->certifyingService());

        InspectionReportAsset::create([
            'inspection_report_id' => $report->getKey(),
            'asset_id' => $conjunto->getKey(),
            'service_id' => $this->certifyingService()->getKey(),
            'result' => InspectionResult::Apto,
            'expires_at' => Carbon::today()->addMonths(10),
        ]);

        app(RecalculateAssetStatus::class)($conjunto->refresh());

        foreach ($componentes as $componente) {
            app(RecalculateAssetStatus::class)($componente->refresh());
        }
    }

    /**
     * Envíos pre-cargados por el cliente, uno en cada estado del flujo, para
     * que la demostración muestre el ciclo completo sin tener que armarlo.
     */
    private function createShipments(Organization $organization, $parts): void
    {
        $operator = User::where('organization_id', $organization->getKey())
            ->whereHas('roles', fn ($q) => $q->where('name', Permissions::ROLE_CLIENT_OPERATOR))
            ->first();

        // Transportes y orígenes verosímiles: el listado del taller se lee mejor
        // cuando cada envío dice de dónde viene y quién lo trae.
        $logistica = [
            ['Transporte Austral S.R.L.', 'Base Las Heras'],
            ['Flota propia — camioneta 4x4', 'Yacimiento Cerro Dragón'],
            ['Expreso Patagónico', 'Base Comodoro Rivadavia'],
        ];

        $estados = [
            [Shipment::STATUS_SUBMITTED, 'Esperando que el taller lo autorice.'],
            [Shipment::STATUS_DRAFT, 'El cliente todavía lo está armando.'],
            [Shipment::STATUS_AUTHORIZED, 'Autorizado; sus piezas ya están en el taller.'],
        ];

        foreach ($estados as $index => [$status, $notes]) {
            [$carrier, $origin] = $logistica[$index];

            $shipment = Shipment::create([
                'organization_id' => $organization->getKey(),
                'reference' => sprintf('ENV-%s-%04d', now()->format('Y'), $index + 1),
                // El remito propio del cliente: es el número con el que él lo busca.
                'client_reference' => sprintf('R-%s-%03d', now()->format('y'), 140 + $index),
                'expected_at' => Carbon::today()->addDays(fake()->numberBetween(2, 12)),
                'contact_name' => $operator?->name,
                'contact_phone' => $operator?->phone,
                'carrier' => $carrier,
                'origin' => $origin,
                'notes' => $notes,
                'created_by' => $operator?->getKey(),
            ]);

            foreach ($parts->random(min(3, $parts->count())) as $asset) {
                $shipment->items()->create([
                    'asset_id' => $asset->getKey(),
                    'is_urgent' => fake()->boolean(20),
                ]);
            }

            // Una línea declarada sin registrar, para mostrar ese caso al taller.
            $shipment->items()->create([
                'declared_description' => 'Brida de repuesto sin registrar',
                'declared_serial' => Str::upper(fake()->bothify('BR-####')),
            ]);

            if ($status !== Shipment::STATUS_DRAFT) {
                $shipment->forceFill([
                    'status' => $status,
                    'submitted_at' => now()->subDays(fake()->numberBetween(1, 5)),
                ])->save();
            }
        }
    }

    /** Cabecera de un FMR ya finalizado. */
    private function createReport(Organization $organization, Carbon $inspectedAt, Service $service): InspectionReport
    {
        return InspectionReport::create([
            'organization_id' => $organization->getKey(),
            'number' => (string) (++$this->reportNumber),
            'inspected_at' => $inspectedAt,
            'status' => ReportStatus::Final->value,
            'service_id' => $service->getKey(),
            'finalized_at' => $inspectedAt,
        ]);
    }

    /** Línea de dictamen: el resultado de UNA pieza dentro de UN informe. */
    private function createLine(
        InspectionReport $report,
        Asset $asset,
        Service $service,
        InspectionResult $result,
        Carbon $inspectedAt,
    ): void {
        // Una pieza no puede aparecer dos veces en el mismo informe.
        if (InspectionReportAsset::where('inspection_report_id', $report->getKey())
            ->where('asset_id', $asset->getKey())->exists()) {
            return;
        }

        InspectionReportAsset::create([
            'inspection_report_id' => $report->getKey(),
            'asset_id' => $asset->getKey(),
            'service_id' => $service->getKey(),
            'result' => $result->value,
            'expires_at' => $result->determinesFitness()
                ? $inspectedAt->copy()->addDays($service->validity_days ?? 365)
                : null,
            'seal' => $asset->kind === Asset::KIND_GENERIC ? Str::upper(Str::random(6)) : null,
            'findings' => $result === InspectionResult::NoApto
                ? 'Se detectó desgaste fuera de tolerancia en el asiento de sello.'
                : null,
        ]);
    }
}
