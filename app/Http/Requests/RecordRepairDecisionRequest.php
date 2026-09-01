<?php

namespace App\Http\Requests;

use App\Models\Reception;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Respuesta del cliente sobre una reparación.
 *
 * La evidencia adjunta es opcional en general pero obligatoria cuando la
 * respuesta llegó por fuera del panel: en ese caso la carga alguien de Odessa
 * transcribiendo lo que le dijeron, y como la reparación después se cobra, esa
 * transcripción tiene que poder respaldarse. Cuando contesta el propio cliente
 * autenticado no hace falta: la evidencia es el registro mismo.
 */
class RecordRepairDecisionRequest extends FormRequest
{
    /**
     * La autorización va acá y no sólo en el controlador porque Laravel valida
     * DESPUÉS de instanciar el Request: si la dejáramos únicamente en el
     * controlador, a quien no tiene permiso le contestaríamos primero con los
     * errores del formulario —contándole de paso qué campos existen— y recién
     * después con el 403. El controlador la repite igual, como segunda barrera.
     */
    public function authorize(): bool
    {
        $reception = $this->route('reception');

        return $reception instanceof Reception
            && $this->user()?->can('authorizeRepair', $reception);
    }

    /**
     * El canal no se elige: se deduce de quién está contestando.
     *
     * Si lo dejáramos elegir, cualquiera del taller podría marcar "desde el
     * panel del cliente" y saltearse la evidencia, que es justamente lo que le
     * da valor al registro. Cuando contesta un usuario del cliente el canal es
     * `panel` por definición; cuando contesta Odessa, tiene que decir por
     * dónde le llegó.
     */
    protected function prepareForValidation(): void
    {
        if ($this->user()?->isClientUser()) {
            $this->merge(['channel' => 'panel']);
        }
    }

    public function rules(): array
    {
        $maxKb = (int) config('odessa.uploads.max_size_kb');

        $channels = array_keys(Reception::DECISION_CHANNELS);

        // Odessa no puede declarar que la respuesta vino del panel: si hubiera
        // venido del panel, la habría cargado el cliente.
        if (! $this->user()?->isClientUser()) {
            $channels = array_values(array_diff($channels, ['panel']));
        }

        return [
            'decision' => [
                'required',
                Rule::in([Reception::REPAIR_APPROVED, Reception::REPAIR_DECLINED]),
            ],
            'channel' => ['required', Rule::in($channels)],
            'notes' => [
                // No autorizar deja al equipo sin certificar: el motivo no es
                // opcional. La acción lo vuelve a verificar.
                Rule::requiredIf(fn () => $this->input('decision') === Reception::REPAIR_DECLINED),
                'nullable',
                'string',
                'max:2000',
            ],
            'evidence' => [
                Rule::requiredIf(fn () => $this->input('channel') !== 'panel'),
                'nullable',
                'file',
                "max:{$maxKb}",
                'mimetypes:'.implode(',', config('odessa.uploads.allowed_mimes')),
                'mimes:'.implode(',', config('odessa.uploads.allowed_extensions')),
            ],
        ];
    }

    public function messages(): array
    {
        $maxMb = round(((int) config('odessa.uploads.max_size_kb')) / 1024);

        return [
            'decision.required' => 'Indique si el cliente autoriza la reparación.',
            'channel.required' => 'Indique por qué vía llegó la respuesta.',
            'channel.in' => 'Indique por qué vía llegó la respuesta del cliente.',
            'notes.required' => 'Indique por qué no se autoriza la reparación.',
            'evidence.required' => 'Adjunte el respaldo de la respuesta: es lo que justifica el cobro de la reparación.',
            'evidence.max' => "El archivo supera el máximo de {$maxMb} MB.",
            'evidence.mimetypes' => 'Sólo se admiten archivos PDF o imágenes JPG, PNG y WEBP.',
            'evidence.mimes' => 'La extensión del archivo no coincide con su contenido.',
        ];
    }
}
