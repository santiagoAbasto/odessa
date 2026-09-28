<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use App\Support\Permissions;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'email' => ['required', 'email:filter', 'max:180', Rule::unique('users', 'email')->withoutTrashed()],
            'phone' => ['nullable', 'string', 'max:40'],
            'job_title' => ['nullable', 'string', 'max:120'],
            // La pantalla identifica la organización por su id público, como en
            // el resto del sistema: el número interno no sale del servidor.
            'organization_id' => ['nullable', 'uuid', Rule::exists('organizations', 'public_id')],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
            // Sólo de la lista pensada para clientes: dar un permiso no puede ser una
            // vía para escalar a funciones del taller o de administración.
            'extra_permissions' => ['sometimes', 'array'],
            'extra_permissions.*' => ['string', Rule::in(array_keys(Permissions::clientGrantable()))],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'Ya existe un usuario con ese correo.',
            'roles.required' => 'Asigne al menos un rol.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
    }
}
