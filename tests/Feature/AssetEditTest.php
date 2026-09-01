<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\CatalogItem;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ficha técnica editable.
 *
 * El negocio la pidió para superadministrador y taller, con un motivo
 * concreto: los datos de la ficha condicionan el mantenimiento posterior.
 * Cambiar el tipo de equipo cambia el tiempo de taller y, con él, la fecha
 * límite de envío, así que el guardado tiene que recalcular.
 */
class AssetEditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();

        config([
            'odessa.recertification.turnaround_days' => 30,
            'odessa.recertification.safety_margin_days' => 15,
        ]);
    }

    /** @return array<string, mixed> */
    private function fichaValida(Asset $asset, array $overrides = []): array
    {
        return array_merge([
            'description' => $asset->description ?? 'Descripción',
            'serial_number' => $asset->serial_number,
        ], $overrides);
    }

    public function test_el_taller_puede_editar_la_ficha(): void
    {
        $pieza = $this->asset($this->organization(), ['description' => 'Nombre viejo']);

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->put("/activos/{$pieza->public_id}", $this->fichaValida($pieza, [
                'description' => 'Nombre corregido',
            ]))
            ->assertRedirect();

        $this->assertSame('Nombre corregido', $pieza->fresh()->description);
    }

    public function test_el_superadministrador_puede_editar_la_ficha(): void
    {
        $pieza = $this->asset($this->organization());

        $this->actingAs($this->staffUser(Permissions::ROLE_SUPER_ADMIN))
            ->put("/activos/{$pieza->public_id}", $this->fichaValida($pieza, [
                'description' => 'Editado por dirección',
            ]))
            ->assertRedirect();

        $this->assertSame('Editado por dirección', $pieza->fresh()->description);
    }

    public function test_un_cliente_no_puede_editar_la_ficha(): void
    {
        $organizacion = $this->organization();
        $pieza = $this->asset($organizacion);

        $this->actingAs($this->clientUser($organizacion))
            ->put("/activos/{$pieza->public_id}", $this->fichaValida($pieza, [
                'description' => 'Intento del cliente',
            ]))
            ->assertForbidden();
    }

    public function test_la_descripcion_no_puede_quedar_vacia(): void
    {
        $pieza = $this->asset($this->organization());

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->put("/activos/{$pieza->public_id}", ['description' => ''])
            ->assertSessionHasErrors('description');
    }

    /**
     * Lo que el negocio remarcó: cambiar la ficha condiciona el mantenimiento
     * posterior. Un tipo de equipo con más días de taller adelanta la fecha
     * límite de envío, y eso tiene que pasar al guardar, no la próxima vez
     * que alguien inspeccione la pieza.
     */
    public function test_cambiar_el_tipo_de_equipo_recalcula_la_fecha_limite(): void
    {
        $organizacion = $this->organization();
        $pieza = $this->asset($organizacion);

        $this->reportFor($pieza, \App\Enums\InspectionResult::Apto, '2026-01-10', '2026-10-30');
        app(\App\Actions\RecalculateAssetStatus::class)($pieza);

        // Con el valor por defecto (30 + 15) el límite es el 15/09.
        $this->assertSame('2026-09-15', $pieza->fresh()->send_by_at->toDateString());

        $preventor = CatalogItem::create([
            'kind' => $pieza->kind,
            'description' => 'Preventor',
            'turnaround_days' => 60,
        ]);

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->put("/activos/{$pieza->public_id}", $this->fichaValida($pieza, [
                'catalog_item_id' => $preventor->getKey(),
            ]))
            ->assertRedirect();

        // Con 60 + 15 el límite se adelanta al 16/08.
        $this->assertSame('2026-08-16', $pieza->fresh()->send_by_at->toDateString());
    }

    public function test_el_cambio_queda_en_la_auditoria_con_el_antes_y_el_despues(): void
    {
        $pieza = $this->asset($this->organization(), ['description' => 'Antes']);
        $usuario = $this->staffUser(Permissions::ROLE_WORKSHOP);

        $this->actingAs($usuario)
            ->put("/activos/{$pieza->public_id}", $this->fichaValida($pieza, [
                'description' => 'Después',
            ]));

        $registro = \App\Models\ActivityLog::where('action', 'asset.updated')->latest('id')->first();

        $this->assertNotNull($registro);
        $this->assertSame($usuario->getKey(), $registro->user_id);
        $this->assertSame('Antes', $registro->changes['old']['description']);
        $this->assertSame('Después', $registro->changes['new']['description']);
    }

    public function test_se_puede_asignar_la_pieza_a_un_conjunto(): void
    {
        $organizacion = $this->organization();
        $conjunto = $this->asset($organizacion, ['description' => 'Conjunto']);
        $pieza = $this->asset($organizacion, ['description' => 'Componente']);

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->put("/activos/{$pieza->public_id}", $this->fichaValida($pieza, [
                'parent_public_id' => $conjunto->public_id,
            ]))
            ->assertRedirect();

        $this->assertSame($conjunto->getKey(), $pieza->fresh()->parent_id);
    }

    /** Una pieza no puede ser su propio conjunto: el recálculo giraría solo. */
    public function test_una_pieza_no_puede_ser_su_propio_conjunto(): void
    {
        $pieza = $this->asset($this->organization());

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->put("/activos/{$pieza->public_id}", $this->fichaValida($pieza, [
                'parent_public_id' => $pieza->public_id,
            ]))
            ->assertSessionHasErrors('parent_public_id');

        $this->assertNull($pieza->fresh()->parent_id);
    }

    /** No se puede colgar una pieza de un conjunto de otra empresa. */
    public function test_no_se_puede_usar_como_conjunto_una_pieza_de_otra_empresa(): void
    {
        $propia = $this->organization('PROPIA');
        $ajena = $this->organization('AJENA');

        $pieza = $this->asset($propia);
        $conjuntoAjeno = $this->asset($ajena);

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->put("/activos/{$pieza->public_id}", $this->fichaValida($pieza, [
                'parent_public_id' => $conjuntoAjeno->public_id,
            ]))
            ->assertSessionHasErrors('parent_public_id');

        $this->assertNull($pieza->fresh()->parent_id);
    }
}
