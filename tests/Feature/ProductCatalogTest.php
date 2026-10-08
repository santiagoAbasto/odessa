<?php

namespace Tests\Feature;

use App\Models\CatalogItem;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Maestro de productos.
 *
 * El catálogo existe para poder filtrar: una sola descripción por cosa, de la
 * que cuelgan las series de todos los clientes. Por eso el alta queda sólo en
 * manos del superadministrador —textual del negocio— y por eso se defiende el
 * duplicado: dos formas de escribir lo mismo lo vuelven inútil.
 */
class ProductCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function product(string $description, ?string $partNumber = null): CatalogItem
    {
        return CatalogItem::create([
            'kind' => 'part',
            'description' => $description,
            'part_number' => $partNumber,
        ]);
    }

    public function test_el_superadministrador_ve_el_maestro(): void
    {
        $this->product('TOOL TRAP', 'X81853-1');

        $this->actingAs($this->staffUser(Permissions::ROLE_SUPER_ADMIN))
            ->get('/administracion/productos')
            ->assertOk()
            ->assertSee('TOOL TRAP');
    }

    /** El taller clasifica equipos, así que necesita consultarlo. */
    public function test_el_taller_lo_consulta(): void
    {
        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->get('/administracion/productos')
            ->assertOk();
    }

    /** «Sólo el súper la da de alta, así controlamos el filtro». */
    public function test_el_taller_no_puede_dar_de_alta(): void
    {
        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post('/administracion/productos', [
                'description' => 'LUBRICADOR',
                'kind' => 'part',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('catalog_items', ['description' => 'LUBRICADOR']);
    }

    public function test_un_cliente_no_llega_al_maestro(): void
    {
        $organization = $this->organization();

        $this->actingAs($this->clientUser($organization))
            ->get('/administracion/productos')
            ->assertForbidden();
    }

    public function test_el_superadministrador_crea_un_producto(): void
    {
        $this->actingAs($this->staffUser(Permissions::ROLE_SUPER_ADMIN))
            ->post('/administracion/productos', [
                'description' => 'STRIPPER DOBLE',
                'part_number' => 'X81854-1',
                'kind' => 'part',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('catalog_items', [
            'description' => 'STRIPPER DOBLE',
            'part_number' => 'X81854-1',
            'kind' => 'part',
        ]);
    }

    public function test_no_se_puede_repetir_descripcion_y_numero_de_parte(): void
    {
        $this->product('TOOL TRAP', 'X81853-1');

        $this->actingAs($this->staffUser(Permissions::ROLE_SUPER_ADMIN))
            ->post('/administracion/productos', [
                'description' => 'TOOL TRAP',
                'part_number' => 'X81853-1',
                'kind' => 'part',
            ])
            ->assertSessionHasErrors('description');

        $this->assertSame(1, CatalogItem::where('description', 'TOOL TRAP')->count());
    }

    /** Sin número de parte el choque es sólo por descripción. */
    public function test_tampoco_se_repite_una_descripcion_sin_numero_de_parte(): void
    {
        $this->product('LUBRICADOR');

        $this->actingAs($this->staffUser(Permissions::ROLE_SUPER_ADMIN))
            ->post('/administracion/productos', [
                'description' => 'LUBRICADOR',
                'kind' => 'part',
            ])
            ->assertSessionHasErrors('description');
    }

    /** La misma descripción con otro número de parte sí es otro producto. */
    public function test_la_misma_descripcion_con_otro_numero_si_entra(): void
    {
        $this->product('BRIDA', 'X81853-1');

        $this->actingAs($this->staffUser(Permissions::ROLE_SUPER_ADMIN))
            ->post('/administracion/productos', [
                'description' => 'BRIDA',
                'part_number' => 'X81853-2',
                'kind' => 'part',
            ])
            ->assertRedirect();

        $this->assertSame(2, CatalogItem::where('description', 'BRIDA')->count());
    }

    /** Editar sin cambiar nada no puede chocar contra sí mismo. */
    public function test_editar_sin_cambiar_la_descripcion_no_falla(): void
    {
        $product = $this->product('TOOL TRAP', 'X81853-1');

        $this->actingAs($this->staffUser(Permissions::ROLE_SUPER_ADMIN))
            ->put("/administracion/productos/{$product->getKey()}", [
                'description' => 'TOOL TRAP',
                'part_number' => 'X81853-1',
                'kind' => 'part',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    public function test_se_borra_un_producto_que_no_clasifica_ningun_equipo(): void
    {
        $product = $this->product('CARGADO DE MAS');

        $this->actingAs($this->staffUser(Permissions::ROLE_SUPER_ADMIN))
            ->delete("/administracion/productos/{$product->getKey()}")
            ->assertRedirect();

        $this->assertSoftDeleted('catalog_items', ['id' => $product->getKey()]);
    }

    /** Con equipos colgando, borrarlo les dejaría el historial sin sentido. */
    public function test_no_se_borra_un_producto_con_equipos(): void
    {
        $product = $this->product('TOOL TRAP');
        $organization = $this->organization();
        $this->asset($organization, ['catalog_item_id' => $product->getKey()]);

        $this->actingAs($this->staffUser(Permissions::ROLE_SUPER_ADMIN))
            ->delete("/administracion/productos/{$product->getKey()}")
            ->assertRedirect();

        $this->assertDatabaseHas('catalog_items', [
            'id' => $product->getKey(),
            'deleted_at' => null,
        ]);
    }

    /** Buscan por número de parte, y por descripción cuando no lo recuerdan. */
    public function test_la_busqueda_encuentra_por_numero_de_parte_y_por_descripcion(): void
    {
        $this->product('TOOL TRAP', 'X81853-1');
        $this->product('LUBRICADOR', 'X99999-9');

        $admin = $this->staffUser(Permissions::ROLE_SUPER_ADMIN);

        $this->actingAs($admin)
            ->get('/administracion/productos?search=X81853')
            ->assertOk()
            ->assertSee('TOOL TRAP')
            ->assertDontSee('LUBRICADOR');

        $this->actingAs($admin)
            ->get('/administracion/productos?search=lubric')
            ->assertOk()
            ->assertSee('LUBRICADOR')
            ->assertDontSee('TOOL TRAP');
    }

    /**
     * El conteo de equipos es un enlace a Activos filtrado por producto: es la
     * pregunta para la que existe el catálogo.
     */
    public function test_activos_se_filtra_por_producto(): void
    {
        $organization = $this->organization();
        $trap = $this->product('TOOL TRAP');
        $otro = $this->product('LUBRICADOR');

        $this->asset($organization, [
            'catalog_item_id' => $trap->getKey(),
            'serial_number' => 'SERIE-TRAP',
        ]);
        $this->asset($organization, [
            'catalog_item_id' => $otro->getKey(),
            'serial_number' => 'SERIE-LUBRI',
        ]);

        $this->actingAs($this->staffUser(Permissions::ROLE_SUPER_ADMIN))
            ->get("/activos?kind=part&product={$trap->getKey()}")
            ->assertOk()
            ->assertSee('SERIE-TRAP')
            ->assertDontSee('SERIE-LUBRI');
    }
}
