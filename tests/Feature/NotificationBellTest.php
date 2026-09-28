<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La campana del encabezado: los avisos que se guardan en la aplicación.
 *
 * Mientras la salida de correo del servidor siga bloqueada, es la única vía
 * por la que un cliente se entera de que tiene una reparación para autorizar.
 */
class NotificationBellTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function aviso(User $user, array $data, bool $leido = false): string
    {
        $id = (string) Str::uuid();

        $user->notifications()->create([
            'id' => $id,
            'type' => 'App\\Notifications\\Prueba',
            'data' => $data,
            'read_at' => $leido ? now() : null,
        ]);

        return $id;
    }

    private function campana(User $user): array
    {
        return $this->actingAs($user)->get('/panel')->viewData('page')['props']['notifications'];
    }

    public function test_la_campana_trae_los_no_leidos_con_titulo_aunque_el_aviso_no_lo_guarde(): void
    {
        $cliente = $this->clientUser($this->organization());
        // Así se guardaba la consulta de reparación: sin título propio.
        $this->aviso($cliente, [
            'type' => 'repair_authorization_requested',
            'asset_name' => 'Válvula 3"',
            'serial_number' => 'VX-1',
            'url' => '/taller/recepciones?stage=all&reception=abc',
        ]);
        $this->aviso($cliente, ['type' => 'account_request', 'title' => 'Ya leído', 'url' => '/panel'], leido: true);
        $this->aviso($this->clientUser($this->organization()), ['title' => 'Ajeno', 'url' => '/panel']);

        $campana = $this->campana($cliente);

        $this->assertSame(1, $campana['unread']);
        $this->assertCount(1, $campana['items']);
        $this->assertSame('Autorización de reparación requerida', $campana['items'][0]['title']);
        $this->assertSame('Válvula 3" · serie VX-1', $campana['items'][0]['body']);
    }

    public function test_abrir_un_aviso_lo_marca_leido_y_lleva_a_su_enlace(): void
    {
        $cliente = $this->clientUser($this->organization());
        $id = $this->aviso($cliente, ['title' => 'Algo', 'url' => '/taller/recepciones?stage=all&reception=abc']);

        $this->actingAs($cliente)
            ->post("/notificaciones/{$id}/abrir")
            ->assertRedirect('/taller/recepciones?stage=all&reception=abc');

        $this->assertNotNull($cliente->notifications()->first()->read_at);
    }

    public function test_no_se_abre_el_aviso_de_otro_usuario(): void
    {
        $ajeno = $this->clientUser($this->organization());
        $id = $this->aviso($ajeno, ['title' => 'Ajeno', 'url' => '/panel']);

        $this->actingAs($this->staffUser(Permissions::ROLE_WORKSHOP))
            ->post("/notificaciones/{$id}/abrir")
            ->assertNotFound();

        $this->assertNull($ajeno->notifications()->first()->read_at);
    }

    /** El enlace sale de la base: nunca se redirige fuera del sistema. */
    public function test_un_enlace_externo_no_se_sigue(): void
    {
        $cliente = $this->clientUser($this->organization());
        $id = $this->aviso($cliente, ['title' => 'Raro', 'url' => '//sitio-externo.example/x']);

        $this->actingAs($cliente)->post("/notificaciones/{$id}/abrir")->assertRedirect('/panel');
    }

    public function test_marcar_todos_como_leidos(): void
    {
        $cliente = $this->clientUser($this->organization());
        $this->aviso($cliente, ['title' => 'Uno', 'url' => '/panel']);
        $this->aviso($cliente, ['title' => 'Dos', 'url' => '/panel']);

        $this->actingAs($cliente)->post('/notificaciones/leidas')->assertRedirect();

        $this->assertSame(0, $cliente->unreadNotifications()->count());
    }
}
