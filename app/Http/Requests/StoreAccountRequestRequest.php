<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Solicitud pública de cuenta.
 *
 * Réplica mejorada del formulario del sistema auditado, que no marcaba ningún
 * campo como obligatorio ni tenía etiquetas. Aquí todo se valida en servidor.
 */
class StoreAccountRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'email' => ['required', 'string', 'email:filter', 'max:180'],
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\s\-]+$/'],
            'company' => ['required', 'string', 'min:2', 'max:150'],
            'message' => ['nullable', 'string', 'max:1000'],
            // Honeypot: si viene relleno, es un bot.
            'website' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Indique su nombre y apellido.',
            'name.min' => 'El nombre debe tener al menos 3 caracteres.',
            'email.required' => 'Indique un correo de contacto.',
            'email.email' => 'El correo no tiene un formato válido.',
            'phone.regex' => 'El teléfono sólo admite números, espacios y los signos + ( ) -',
            'company.required' => 'Indique la empresa a la que pertenece.',
            'message.max' => 'El mensaje no puede superar los 1000 caracteres.',
            'website.prohibited' => 'No se pudo procesar la solicitud.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => strtolower(trim((string) $this->input('email'))),
            'name' => trim((string) $this->input('name')),
            'company' => trim((string) $this->input('company')),
        ]);
    }
}
