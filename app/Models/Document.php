<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Documento adjunto, tanto de un FMR como de una pieza.
 *
 * `path` apunta al disco privado. Nunca se sirve desde public/: la única
 * salida es el controlador de descarga, que autoriza y audita.
 */
class Document extends Model
{
    use BelongsToOrganization, HasFactory, HasPublicId, SoftDeletes;

    /** Categorías admitidas, con su etiqueta visible. */
    public const CATEGORIES = [
        'fmr_report' => 'Informe FMR',
        'certificate' => 'Certificado',
        'photo' => 'Fotografía',
        'datasheet' => 'Ficha técnica',
        // Respaldo de que el cliente autorizó una reparación cuando contestó
        // por fuera del sistema. Es lo que después justifica el cobro, así que
        // se guarda como categoría propia y no como "Otro".
        'repair_authorization' => 'Autorización de reparación',
        'other' => 'Otro',
    ];

    protected $fillable = [
        'organization_id', 'category', 'original_name', 'path',
        'mime_type', 'size_bytes', 'checksum', 'document_date', 'notes', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'document_date' => 'date',
            'size_bytes' => 'integer',
        ];
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? 'Otro';
    }

    public function humanSize(): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = (float) $this->size_bytes;
        $i = 0;

        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return round($size, $i === 0 ? 0 : 1).' '.$units[$i];
    }

    /** Representación para el frontend. */
    public function toPayload(): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->original_name,
            'category' => $this->category,
            'categoryLabel' => $this->categoryLabel(),
            'size' => $this->humanSize(),
            'documentDate' => $this->document_date?->toDateString(),
            'notes' => $this->notes,
            'uploadedBy' => $this->uploader?->name,
            'uploadedAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
