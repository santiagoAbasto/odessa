<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Copia oculta interna de todos los correos del sistema.
 *
 * Cuando ODESSA_MAIL_COPY_TO está configurada, cada correo que sale lleva esa
 * casilla en copia oculta —para que el negocio tenga control—, sin exponerla al
 * destinatario. El Mailer no tiene un «always bcc» propio: la copia se agrega
 * sobre el evento de envío (ver AppServiceProvider).
 */
class GlobalMailBccTest extends TestCase
{
    /** @return list<string> */
    private function bccDelUltimoCorreo(): array
    {
        $ultimo = collect(Mail::getSymfonyTransport()->messages())->last();
        $this->assertNotNull($ultimo, 'No se envió ningún correo.');

        return array_map(
            fn ($address) => $address->getAddress(),
            $ultimo->getOriginalMessage()->getBcc()
        );
    }

    public function test_todo_correo_lleva_la_copia_oculta_interna(): void
    {
        config(['odessa.mail_copy_to' => 'sistemas@odessaoil.test']);

        Mail::raw('cuerpo de prueba', function ($message) {
            $message->to('cliente@empresa.test')->subject('Prueba');
        });

        $this->assertContains('sistemas@odessaoil.test', $this->bccDelUltimoCorreo());
    }

    public function test_sin_casilla_configurada_no_se_agrega_copia(): void
    {
        config(['odessa.mail_copy_to' => null]);

        Mail::raw('cuerpo de prueba', function ($message) {
            $message->to('cliente@empresa.test')->subject('Prueba');
        });

        $this->assertSame([], $this->bccDelUltimoCorreo());
    }
}
