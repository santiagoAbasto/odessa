<?php

namespace App\Actions;

use App\Enums\InspectionResult;
use App\Enums\ReportStatus;
use App\Models\Asset;
use App\Models\CatalogItem;
use App\Models\Connection;
use App\Models\InspectionReport;
use App\Models\InspectionReportAsset;
use App\Models\Manufacturer;
use App\Models\Organization;
use App\Models\PressureRating;
use App\Models\Service;
use Illuminate\Support\Carbon;

/**
 * Carga los equipos de un cliente desde las filas ya parseadas de la hoja
 * "Tabla", y arma el maestro de productos a partir de ellos.
 *
 * El maestro se deriva de los propios equipos (no de la hoja "Tabla Padre a
 * Subir", que en el Excel son fórmulas ilegibles): la "Descripción Odessa" se
 * compone con los mismos campos que usa la planilla —fabricante, tipo,
 * diámetro, conexiones y presiones— y cada descripción distinta es un producto.
 * Así cada equipo engancha con su modelo por construcción, sin depender de que
 * alguien mantenga la tabla padre a mano.
 *
 * El vencimiento y el estado NO se escriben a mano: la certificación anual
 * (COS) se registra como un FMR —informe final + línea Apto que vence a los 365
 * días— y RecalculateAssetStatus deriva el estado. Un equipo sin COS queda sin
 * FMR, es decir "Sin Certificar" (unknown). El COC (quinquenal) todavía no se
 * guarda: el equipo tiene un solo vencimiento. Queda como mejora pendiente.
 */
class ImportarInventario
{
    /** Días de taller y vigencia de todos los productos (constantes del negocio). */
    private const DIAS_TALLER = 30;

    private const DIAS_VIGENCIA = 365;

    /** @var array<string, int> */
    private array $fabricantes = [];

    /** @var array<string, int> */
    private array $presiones = [];

    /** @var array<string, int> */
    private array $conexiones = [];

    /** @var array<string, CatalogItem> */
    private array $modelos = [];

    private int $nroFmr = 0;

    public function __construct(private RecalculateAssetStatus $recalcular) {}

    /**
     * @param  list<array<int, mixed>>  $equipos  filas de la hoja "Tabla" (sin encabezado)
     * @param  list<string>             $excluirSeries  números de serie a dejar afuera
     * @return array{modelos:int, equipos:int, excluidos:int, sin_certificar:int}
     */
    public function __invoke(Organization $org, array $equipos, array $excluirSeries = []): array
    {
        $cos = Service::firstOrCreate(
            ['code' => 'COS'],
            ['name' => 'Certificación de Servicio', 'determines_fitness' => true, 'validity_days' => self::DIAS_VIGENCIA, 'is_active' => true]
        );

        $excluir = array_flip($excluirSeries);
        $cargados = 0;
        $excluidos = 0;
        $sinCertificar = 0;

        foreach ($equipos as $fila) {
            $serie = $this->txt($fila[2] ?? '');
            if ($serie === '' || isset($excluir[$serie])) {
                $excluidos++;

                continue;
            }

            $descripcion = $this->descripcion($fila);
            $modelo = $this->modelo($descripcion, $this->fabricante($fila[4] ?? ''));

            $asset = Asset::create([
                'organization_id' => $org->getKey(),
                'catalog_item_id' => $modelo->getKey(),
                'kind' => Asset::KIND_PART,
                'serial_number' => $serie,
                'client_reference' => $this->txt($fila[1] ?? '') ?: null,
                'manufacturer_reference' => $this->txt($fila[3] ?? '') ?: null,
                'manufacturer_id' => $this->fabricante($fila[4] ?? ''),
                'description' => $descripcion ?: null,
                'workshop_description' => $this->txt($fila[5] ?? '') ?: null,
                'working_pressure_id' => $this->presion($fila[13] ?? ''),
                'test_pressure_id' => $this->presion($fila[14] ?? ''),
                'connection_top_id' => $this->conexion($fila[10] ?? ''),
                'connection_bottom_id' => $this->conexion($fila[11] ?? ''),
                'connection_side_1_id' => $this->conexion($fila[12] ?? ''),
            ]);

            $cosFecha = $this->fecha($fila[15] ?? null);

            if ($cosFecha !== null) {
                $this->registrarCertificacion($org, $asset, $cos, $cosFecha);
            } else {
                $sinCertificar++;
            }

            ($this->recalcular)($asset);
            $cargados++;
        }

        return [
            'modelos' => count($this->modelos),
            'equipos' => $cargados,
            'excluidos' => $excluidos,
            'sin_certificar' => $sinCertificar,
        ];
    }

    /**
     * Descripción Odessa compuesta con los componentes del equipo, en el orden
     * de la planilla: fabricante, tipo, diámetro, conexiones y presiones.
     * Lo mismo que hace la fórmula TEXTJOIN del Excel, pero legible.
     *
     * @param  array<int, mixed>  $fila
     */
    private function descripcion(array $fila): string
    {
        $partes = array_map(
            fn (int $i) => $this->txt($fila[$i] ?? ''),
            [4, 6, 9, 10, 11, 12, 13, 14],
        );

        return implode(', ', array_filter($partes, fn (string $p) => $p !== ''));
    }

    private function modelo(string $descripcion, ?int $fabricanteId): CatalogItem
    {
        return $this->modelos[$descripcion] ??= CatalogItem::firstOrCreate(
            ['kind' => Asset::KIND_PART, 'description' => $descripcion, 'part_number' => null],
            [
                'manufacturer_id' => $fabricanteId,
                'turnaround_days' => self::DIAS_TALLER,
                'validity_days' => self::DIAS_VIGENCIA,
                'determines_fitness' => true,
            ]
        );
    }

    private function registrarCertificacion(Organization $org, Asset $asset, Service $cos, Carbon $fecha): void
    {
        $informe = InspectionReport::create([
            'organization_id' => $org->getKey(),
            'number' => 'COS-'.(++$this->nroFmr),
            'equipment_label' => $asset->serial_number,
            'inspected_at' => $fecha,
            'status' => ReportStatus::Final->value,
            'service_id' => $cos->getKey(),
        ]);
        $informe->forceFill(['finalized_at' => $fecha])->save();

        InspectionReportAsset::create([
            'inspection_report_id' => $informe->getKey(),
            'asset_id' => $asset->getKey(),
            'service_id' => $cos->getKey(),
            'result' => InspectionResult::Apto->value,
            'expires_at' => $fecha->copy()->addDays(self::DIAS_VIGENCIA),
        ]);
    }

    private function fabricante(mixed $nombre): ?int
    {
        $nombre = $this->txt($nombre);
        if ($nombre === '') {
            return null;
        }

        return $this->fabricantes[$nombre] ??= Manufacturer::firstOrCreate(['name' => $nombre])->getKey();
    }

    private function presion(mixed $label): ?int
    {
        $label = $this->txt($label);
        if ($label === '') {
            return null;
        }

        return $this->presiones[$label] ??= PressureRating::firstOrCreate(
            ['label' => $label],
            ['psi' => $this->psi($label)]
        )->getKey();
    }

    private function conexion(mixed $label): ?int
    {
        $label = $this->txt($label);
        if ($label === '') {
            return null;
        }

        return $this->conexiones[$label] ??= Connection::firstOrCreate(['label' => $label])->getKey();
    }

    /** "10M" -> 10000, "22.5M" -> 22500, "1.5M" -> 1500. Sin número reconocible -> null. */
    private function psi(string $label): ?int
    {
        if (preg_match('/([\d.]+)\s*M/i', $label, $m)) {
            return (int) round(((float) $m[1]) * 1000);
        }

        return null;
    }

    /** Texto normalizado; "0" y vacío se tratan como vacío. Una fecha no es texto. */
    private function txt(mixed $v): string
    {
        if ($v instanceof \DateTimeInterface) {
            return '';
        }

        $v = trim((string) $v);

        return $v === '0' ? '' : $v;
    }

    private function fecha(mixed $v): ?Carbon
    {
        if ($v instanceof \DateTimeInterface) {
            return Carbon::instance($v);
        }

        $v = trim((string) $v);
        if ($v === '' || $v === '0') {
            return null;
        }

        try {
            return Carbon::parse($v);
        } catch (\Throwable) {
            return null;
        }
    }
}
