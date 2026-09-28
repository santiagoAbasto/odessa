<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'organization_id', 'phone', 'job_title', 'is_active', 'must_change_password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPublicId, HasRoles, Notifiable, SoftDeletes;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Personal interno de Odessa Oil: no está atado a ninguna organización cliente. */
    public function isStaff(): bool
    {
        return $this->organization_id === null;
    }

    public function isClientUser(): bool
    {
        return $this->organization_id !== null;
    }

    /**
     * ¿Puede ver los datos de esta organización?
     * El staff ve todas; un usuario de cliente sólo la suya.
     */
    public function canAccessOrganization(Organization|int|null $organization): bool
    {
        if ($organization === null) {
            return false;
        }

        if ($this->isStaff()) {
            return true;
        }

        $id = $organization instanceof Organization ? $organization->getKey() : $organization;

        return $this->organization_id === $id;
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }
}
