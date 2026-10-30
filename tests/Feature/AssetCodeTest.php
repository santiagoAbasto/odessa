<?php

namespace Tests\Feature;

use App\Actions\CreateAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El código del equipo es un correlativo por empresa con su prefijo
 * (HASA-0001, HASA-0002…), no una cadena al azar. Dos empresas llevan
 * contadores separados.
 */
class AssetCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_codigo_es_correlativo_por_empresa(): void
    {
        $crear = app(CreateAsset::class);
        $hasa = $this->organization('HASA');

        $primero = $crear($hasa, ['description' => 'Puente de prueba uno']);
        $segundo = $crear($hasa, ['description' => 'Puente de prueba dos']);

        $this->assertSame('HASA-0001', $primero->code);
        $this->assertSame('HASA-0002', $segundo->code);
    }

    public function test_cada_empresa_lleva_su_propio_contador(): void
    {
        $crear = app(CreateAsset::class);
        $hasa = $this->organization('HASA');
        $acme = $this->organization('ACME');

        $crear($hasa, ['description' => 'Equipo HASA']);
        $deAcme = $crear($acme, ['description' => 'Equipo ACME']);

        // El primero de ACME arranca en 0001 aunque HASA ya tenga uno.
        $this->assertSame('ACME-0001', $deAcme->code);
    }
}
