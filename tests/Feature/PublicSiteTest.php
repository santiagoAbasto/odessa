<?php

namespace Tests\Feature;

use App\Models\AccountRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** Sitio abierto y solicitud de acceso. */
class PublicSiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_las_paginas_publicas_responden_sin_sesion(): void
    {
        foreach (['/', '/servicios', '/solicitar-acceso'] as $ruta) {
            $this->get($ruta)->assertOk();
        }
    }

    public function test_las_paginas_publicas_llevan_cabeceras_de_seguridad(): void
    {
        $response = $this->get('/');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $this->assertStringContainsString("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
    }

    public function test_los_formularios_de_autenticacion_no_se_guardan_en_cache(): void
    {
        $this->get('/ingresar')->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/recuperar-clave')->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_se_puede_enviar_una_solicitud_de_acceso_valida(): void
    {
        $this->post('/solicitar-acceso', [
            'name' => 'Persona Solicitante',
            'email' => 'Solicitante@Empresa.TEST',
            'company' => 'Empresa de prueba',
            'phone' => '+54 11 5555-5555',
            'message' => 'Necesito consultar el estado de nuestros equipos.',
        ])->assertRedirect('/solicitar-acceso');

        $this->assertDatabaseHas('account_requests', [
            'email' => 'solicitante@empresa.test', // normalizado a minúsculas
            'company' => 'Empresa de prueba',
            'status' => AccountRequest::STATUS_PENDING,
        ]);
    }

    public function test_la_solicitud_exige_los_campos_obligatorios(): void
    {
        $this->post('/solicitar-acceso', [])
            ->assertSessionHasErrors(['name', 'email', 'company']);

        $this->assertDatabaseCount('account_requests', 0);
    }

    public function test_el_honeypot_bloquea_los_envios_automatizados(): void
    {
        $this->post('/solicitar-acceso', [
            'name' => 'Bot Automatizado',
            'email' => 'bot@ejemplo.test',
            'company' => 'Bots SA',
            'website' => 'http://spam.example',
        ])->assertSessionHasErrors('website');

        $this->assertDatabaseCount('account_requests', 0);
    }

    public function test_se_rechaza_un_telefono_con_caracteres_invalidos(): void
    {
        $this->post('/solicitar-acceso', [
            'name' => 'Persona Solicitante',
            'email' => 'persona@empresa.test',
            'company' => 'Empresa',
            'phone' => '<script>alert(1)</script>',
        ])->assertSessionHasErrors('phone');
    }

    public function test_la_solicitud_queda_registrada_en_la_auditoria(): void
    {
        $this->post('/solicitar-acceso', [
            'name' => 'Persona Solicitante',
            'email' => 'persona@empresa.test',
            'company' => 'Empresa',
        ]);

        $this->assertDatabaseHas('activity_logs', ['action' => 'account_request.created']);
    }

    public function test_el_formulario_de_solicitud_esta_limitado_por_ip(): void
    {
        RateLimiter::clear('');

        for ($intento = 0; $intento < 5; $intento++) {
            $this->post('/solicitar-acceso', [
                'name' => "Solicitante {$intento}",
                'email' => "solicitante{$intento}@empresa.test",
                'company' => 'Empresa',
            ]);
        }

        $this->post('/solicitar-acceso', [
            'name' => 'Uno de más',
            'email' => 'extra@empresa.test',
            'company' => 'Empresa',
        ])->assertStatus(429);
    }
}
