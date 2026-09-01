<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Registro de auditoría.
 *
 * `$changes` se filtra contra una lista negra antes de persistirse: nunca deben
 * llegar contraseñas, tokens ni secretos de 2FA al log.
 */
class ActivityLogger
{
    private const REDACTED = [
        'password', 'password_confirmation', 'remember_token', 'two_factor_secret',
        'two_factor_recovery_codes', 'api_token', 'token', 'secret', '_token',
    ];

    public static function log(
        string $action,
        ?Model $subject = null,
        ?string $description = null,
        ?array $old = null,
        ?array $new = null,
        ?int $organizationId = null,
    ): ActivityLog {
        $user = Auth::user();

        $changes = null;
        if ($old !== null || $new !== null) {
            $changes = array_filter([
                'old' => $old ? self::scrub($old) : null,
                'new' => $new ? self::scrub($new) : null,
            ], fn ($v) => $v !== null);
        }

        return ActivityLog::create([
            'organization_id' => $organizationId
                ?? ($subject->organization_id ?? null)
                ?? $user?->organization_id,
            'user_id' => $user?->getKey(),
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'description' => $description,
            'changes' => $changes,
            'ip_address' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 512),
        ]);
    }

    private static function scrub(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), self::REDACTED, true)) {
                $data[$key] = '[redactado]';
            } elseif (is_array($value)) {
                $data[$key] = self::scrub($value);
            }
        }

        return $data;
    }
}
