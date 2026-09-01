<?php

namespace App\Http\Requests;

use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación de carga de archivos.
 *
 * `mimetypes` comprueba el tipo REAL del contenido, no la extensión ni lo que
 * declara el navegador. Se exige además que la extensión coincida, para que un
 * ejecutable renombrado no llegue nunca al disco.
 */
class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Document::class);
    }

    public function rules(): array
    {
        $maxKb = (int) config('odessa.uploads.max_size_kb');

        return [
            'file' => [
                'required',
                'file',
                "max:{$maxKb}",
                'mimetypes:'.implode(',', config('odessa.uploads.allowed_mimes')),
                'mimes:'.implode(',', config('odessa.uploads.allowed_extensions')),
            ],
            'category' => ['required', Rule::in(array_keys(Document::CATEGORIES))],
            'document_date' => ['nullable', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function messages(): array
    {
        $maxMb = round(((int) config('odessa.uploads.max_size_kb')) / 1024);

        return [
            'file.required' => 'Seleccione un archivo.',
            'file.max' => "El archivo supera el máximo de {$maxMb} MB.",
            'file.mimetypes' => 'Sólo se admiten archivos PDF o imágenes JPG, PNG y WEBP.',
            'file.mimes' => 'La extensión del archivo no coincide con su contenido.',
            'category.required' => 'Indique el tipo de documento.',
            'document_date.before_or_equal' => 'La fecha del documento no puede ser futura.',
        ];
    }
}
