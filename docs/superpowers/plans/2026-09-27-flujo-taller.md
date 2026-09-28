# Flujo del taller — plan de implementación

> **Para agentes:** ejecutar tarea por tarea con superpowers:executing-plans. Los pasos usan casillas (`- [ ]`).

**Objetivo:** implementar el spec `docs/superpowers/specs/2026-09-27-flujo-taller-design.md`: reparación única que vuelve a En proceso, salida "sin solución", colas nuevas, consulta a toda la organización y "Dar recepción".

**Arquitectura:** dos hitos nuevos en `receptions` (`repair_failed_*`, `unrepairable_*`). La etapa se sigue derivando en `Reception::stage()` y las colas son scopes. Las acciones viven en `App\Actions\ChangeReceptionStage`; los avisos, en `app/Notifications`. La interfaz es `resources/js/pages/workshop/receptions.tsx`.

**Stack:** Laravel 13, Inertia + React 19 + TypeScript, Spatie Permission, PHPUnit con SQLite en memoria (`QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`).

**Comandos de verificación** (desde la raíz): `vendor/bin/phpunit`, `vendor/bin/phpunit --filter X`, `npm run types`, `npm run build`.
**Nunca** `php artisan migrate:fresh --env=testing`: no existe `.env.testing` y borra la base local.

---

## Mapa de archivos

| Archivo | Cambio |
|---|---|
| `database/migrations/2026_09_27_100000_add_repair_failure_and_unrepairable.php` | nuevo: 6 columnas |
| `app/Models/Reception.php` | casts, relaciones, helpers, `stage()`, etiquetas, scopes, `url()` |
| `app/Actions/ChangeReceptionStage.php` | `failRepair`, `markUnrepairable`, `notifyOrganization`, guardas |
| `app/Actions/RecalculateAssetStatus.php` | "sin solución" como fuente de no apto |
| `app/Notifications/RepairAuthorizationRequested.php` | `via()` anónimo y enlace directo |
| `app/Notifications/ReceptionDeclaredUnrepairable.php` | nuevo |
| `app/Http/Controllers/Workshop/ReceptionController.php` | filtro `reception`, pestaña `repair`, flags, 2 endpoints, conteos |
| `routes/web.php` | 2 rutas en el grupo `RECEPTIONS_RELEASE` |
| `app/Support/AssetTimeline.php` | 2 hitos, enlace directo |
| `resources/js/types/index.ts` | `ReceptionRow` |
| `resources/js/pages/workshop/receptions.tsx` | pestañas, botones, `NotesActionButton` |
| `app/Models/Shipment.php`, `app/Actions/AuthorizeShipment.php`, `app/Http/Controllers/ShipmentController.php`, `resources/js/pages/shipments/*.tsx` | textos de "Dar recepción" |
| `tests/Feature/WorkshopFlowTest.php` | nuevo |
| `tests/Feature/RepairAuthorizationTest.php` | destinatarios |
| `docs/flujos-de-negocio.md`, `docs/cambios-devolucion-negocio.md` | recorrido y bitácora |

---

### Tarea 1: migración y modelo

**Archivos:** migración nueva y `app/Models/Reception.php`. Prueba en `tests/Feature/WorkshopFlowTest.php`.

- [ ] **Paso 1: prueba que falla (etapas y colas)**

```php
<?php

namespace Tests\Feature;

use App\Models\Reception;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkshopFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function recepcion(array $hitos = [], ?\App\Models\Organization $org = null): Reception
    {
        $org ??= $this->organization();
        $r = Reception::create([
            'organization_id' => $org->getKey(),
            'asset_id' => $this->asset($org)->getKey(),
            'received_at' => now()->subDays(5)->toDateString(),
        ]);
        $r->forceFill($hitos)->save();

        return $r->fresh();
    }

    /** Hitos acumulados de cada etapa, del más temprano al más avanzado. */
    private function hitos(string $etapa): array
    {
        $rechazada = ['started_at' => now()->subDays(4), 'released_at' => now()->subDays(3),
            'outcome' => 'rejected', 'outcome_notes' => 'Rosca gastada.'];
        $consultada = $rechazada + ['repair_requested_at' => now()->subDays(3)];
        $autorizada = $consultada + ['repair_decision' => 'approved', 'repair_decided_at' => now()->subDays(2)];
        $enReparacion = $autorizada + ['repair_started_at' => now()->subDay()];

        return match ($etapa) {
            'pending' => [],
            'in_progress' => ['started_at' => now()->subDay()],
            'approved' => ['started_at' => now()->subDays(2), 'released_at' => now()->subDay(), 'outcome' => 'approved'],
            'rejected' => $rechazada,
            'awaiting_client' => $consultada,
            'repair_declined' => $consultada + ['repair_decision' => 'declined', 'repair_decided_at' => now()->subDays(2), 'repair_decision_notes' => 'No.'],
            'repair_authorized' => $autorizada,
            'in_repair' => $enReparacion,
            'repaired' => $enReparacion + ['repaired_at' => now()],
            'repair_failed' => $enReparacion + ['repair_failed_at' => now(), 'repair_failure_notes' => 'Sigue perdiendo.'],
            'unrepairable' => $rechazada + ['unrepairable_at' => now(), 'unrepairable_notes' => 'Cuerpo fisurado.'],
        };
    }

    public function test_cada_combinacion_de_hitos_da_su_etapa(): void
    {
        foreach (['pending', 'in_progress', 'approved', 'rejected', 'awaiting_client', 'repair_declined',
            'repair_authorized', 'in_repair', 'repaired', 'repair_failed', 'unrepairable'] as $etapa) {
            $this->assertSame($etapa, $this->recepcion($this->hitos($etapa))->stage(), $etapa);
        }

        $sinSolucionDespachada = $this->recepcion($this->hitos('unrepairable') + ['dispatched_at' => now()]);
        $this->assertSame('returned_unrepaired', $sinSolucionDespachada->stage());
    }

    public function test_las_colas_agrupan_segun_el_recorrido_nuevo(): void
    {
        $r = fn (string $etapa) => $this->recepcion($this->hitos($etapa));
        $ids = fn (string $scope) => Reception::query()->{$scope}()->pluck('id');

        $enProceso = $r('in_progress');
        $enReparacion = $r('in_repair');
        $rechazada = $r('rejected');
        $esperando = $r('awaiting_client');
        $fallida = $r('repair_failed');
        $autorizada = $r('repair_authorized');
        $sinSolucion = $r('unrepairable');

        // La reparación en curso se trabaja como cualquier otro trabajo.
        $this->assertEqualsCanonicalizing([$enProceso->id, $enReparacion->id], $ids('inProgress')->all());
        // Lo consultado sale de Rechazadas; la reparación fallida vuelve.
        $this->assertEqualsCanonicalizing([$rechazada->id, $fallida->id], $ids('rejected')->all());
        $this->assertEqualsCanonicalizing([$autorizada->id], $ids('repairQueue')->all());
        $this->assertTrue($ids('readyToDispatch')->contains($sinSolucion->id));
        $this->assertFalse($ids('rejected')->contains($esperando->id));
    }

    public function test_despachable_y_listas_para_despachar_coinciden_en_todas_las_etapas(): void
    {
        foreach (['pending', 'in_progress', 'approved', 'rejected', 'awaiting_client', 'repair_declined',
            'repair_authorized', 'in_repair', 'repaired', 'repair_failed', 'unrepairable'] as $etapa) {
            $r = $this->recepcion($this->hitos($etapa));
            $this->assertSame(
                $r->isDispatchable(),
                Reception::query()->readyToDispatch()->whereKey($r->getKey())->exists(),
                $etapa,
            );
        }
    }
}
```

- [ ] **Paso 2:** `vendor/bin/phpunit --filter WorkshopFlowTest`. Tiene que fallar por columnas inexistentes.

- [ ] **Paso 3: migración**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dos salidas nuevas del circuito de rechazo, pedidas por el negocio el 27/09/2026:
 *
 *  - la reparación también se dictamina: si no dio resultado se rechaza, y la
 *    pieza vuelve a Rechazadas (se intenta una sola vez);
 *  - el taller puede determinar que una pieza rechazada no tiene solución y
 *    devolverla sin consultar al cliente.
 *
 * Son hitos y no una columna de estado: la etapa se sigue derivando de los
 * hechos registrados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receptions', function (Blueprint $table) {
            $table->timestamp('repair_failed_at')->nullable()->after('repaired_at')
                ->comment('La reparación se rechazó al dictaminarla');
            $table->foreignId('repair_failed_by')->nullable()->after('repair_failed_at')
                ->constrained('users')->nullOnDelete();
            $table->text('repair_failure_notes')->nullable()->after('repair_failed_by');

            $table->timestamp('unrepairable_at')->nullable()->after('repair_failure_notes')
                ->comment('El taller determinó que no tiene solución');
            $table->foreignId('unrepairable_by')->nullable()->after('unrepairable_at')
                ->constrained('users')->nullOnDelete();
            $table->text('unrepairable_notes')->nullable()->after('unrepairable_by');
        });
    }

    public function down(): void
    {
        Schema::table('receptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('repair_failed_by');
            $table->dropConstrainedForeignId('unrepairable_by');
            $table->dropColumn(['repair_failed_at', 'repair_failure_notes', 'unrepairable_at', 'unrepairable_notes']);
        });
    }
};
```

- [ ] **Paso 4: modelo**

  - En `casts()`: `'repair_failed_at' => 'datetime'` y `'unrepairable_at' => 'datetime'`.
  - Relaciones: `repairFailedBy()` → `belongsTo(User::class, 'repair_failed_by')` y `unrepairableBy()` → `belongsTo(User::class, 'unrepairable_by')`.
  - Métodos:

```php
public function repairFailed(): bool { return $this->repair_failed_at !== null; }

public function isUnrepairable(): bool { return $this->unrepairable_at !== null; }

/** Sale sin reparar: el cliente no autorizó o el taller determinó que no tenía arreglo. */
public function leavesUnrepaired(): bool { return $this->repairWasDeclined() || $this->isUnrepairable(); }

/** Enlace directo a esta recepción. Lo usan los avisos y el hilo del equipo. */
public function url(): string { return '/taller/recepciones?stage=all&reception='.$this->public_id; }
```

  - `stage()`, las primeras ramas en este orden:

```php
$this->dispatched_at !== null && $this->leavesUnrepaired() => 'returned_unrepaired',
$this->dispatched_at !== null => 'dispatched',
$this->unrepairable_at !== null => 'unrepairable',
$this->repair_failed_at !== null => 'repair_failed',
$this->repaired_at !== null => 'repaired',
$this->repair_started_at !== null => 'in_repair',
// …el resto sin cambios
```

  - Etiquetas: `awaiting_client` "Esperando al cliente", `repair_authorized` "Reparación", `in_repair` "En proceso · reparación", `repair_failed` "Rechazada · reparación fallida", `unrepairable` "Sin solución · a devolver". Van con sus descripciones y con tono `danger` en `stageTone()`.
  - `isDispatchable()`: `repaired_at !== null || leavesUnrepaired() || (isResolved() && ! wasRejected())`.
  - Scopes:

```php
public function scopeInProgress(Builder $query): Builder
{
    return $query->where(fn (Builder $q) => $q
        ->where(fn (Builder $w) => $w->open()->whereNotNull('started_at'))
        ->orWhere(fn (Builder $r) => $r->inRepair()));
}

public function scopeRejected(Builder $query): Builder
{
    return $query->where('outcome', self::OUTCOME_REJECTED)
        ->whereNull('repaired_at')->whereNull('unrepairable_at')->whereNull('dispatched_at')
        ->where(fn (Builder $q) => $q->whereNull('repair_requested_at')->orWhereNotNull('repair_failed_at'));
}

public function scopeInRepair(Builder $query): Builder
{
    return $query->whereNotNull('repair_started_at')->whereNull('repaired_at')
        ->whereNull('repair_failed_at')->whereNull('dispatched_at');
}

public function scopeRepairQueue(Builder $query): Builder
{
    return $query->where('repair_decision', self::REPAIR_APPROVED)
        ->whereNull('repair_started_at')->whereNull('dispatched_at');
}
// readyToDispatch: agregar ->orWhereNotNull('unrepairable_at') dentro del where agrupado.
```

- [ ] **Paso 5:** `vendor/bin/phpunit --filter WorkshopFlowTest` en verde. Después la suite completa: `ReceptionWorkflowTest` y `RepairAuthorizationTest` tienen que seguir en verde.

### Tarea 2: acciones, aptitud y avisos

**Archivos:** `ChangeReceptionStage.php`, `RecalculateAssetStatus.php`, las dos notificaciones y `RepairAuthorizationTest.php`.

- [ ] **Paso 1: pruebas que fallan** (se agregan a `WorkshopFlowTest`)

```php
public function test_la_reparacion_fallida_solo_admite_sin_solucion_y_deja_no_apto(): void
{
    Notification::fake();
    $r = $this->recepcion($this->hitos('in_repair'));
    $r->asset->forceFill(['status' => AssetStatus::Fit, 'expires_at' => now()->addYear()])->save();
    $taller = $this->staffUser(Permissions::ROLE_WORKSHOP);

    $this->actingAs($taller)->post("/taller/recepciones/{$r->public_id}/reparacion-fallida")
        ->assertSessionHasErrors('repair_failure_notes');
    $this->actingAs($taller)->post("/taller/recepciones/{$r->public_id}/reparacion-fallida", [
        'repair_failure_notes' => 'Sigue perdiendo presión.',
    ])->assertRedirect();
    $this->assertSame('repair_failed', $r->fresh()->stage());

    // Una sola reparación: no hay segunda consulta ni se puede dar por reparada.
    $this->actingAs($taller)->post("/taller/recepciones/{$r->public_id}/consultar-reparacion")
        ->assertSessionHasErrors('repair_requested_at');
    $this->actingAs($taller)->post("/taller/recepciones/{$r->public_id}/reparada")
        ->assertSessionHasErrors('repaired_at');

    $this->actingAs($taller)->post("/taller/recepciones/{$r->public_id}/sin-solucion", [
        'unrepairable_notes' => 'Cuerpo fisurado.',
    ])->assertRedirect();
    $this->actingAs($taller)->post("/taller/recepciones/{$r->public_id}/despachar")->assertRedirect();

    $this->assertSame('returned_unrepaired', $r->fresh()->stage());
    $activo = $r->asset->fresh();
    $this->assertSame(AssetStatus::Unfit, $activo->status);
    $this->assertNull($activo->expires_at);
}

public function test_sin_solucion_directo_desde_el_primer_rechazo_exige_motivo(): void
{
    Notification::fake();
    $r = $this->recepcion($this->hitos('rejected'));
    $taller = $this->staffUser(Permissions::ROLE_WORKSHOP);

    $this->actingAs($taller)->post("/taller/recepciones/{$r->public_id}/sin-solucion")
        ->assertSessionHasErrors('unrepairable_notes');
    $this->actingAs($taller)->post("/taller/recepciones/{$r->public_id}/sin-solucion", [
        'unrepairable_notes' => 'No hay repuesto.',
    ])->assertRedirect();

    $this->assertSame('unrepairable', $r->fresh()->stage());
    $this->assertDatabaseHas('activity_logs', ['action' => 'reception.unrepairable']);
}

public function test_sin_solucion_no_se_marca_fuera_de_rechazadas(): void
{
    $taller = $this->staffUser(Permissions::ROLE_WORKSHOP);

    foreach (['awaiting_client', 'repair_authorized', 'approved', 'in_repair'] as $etapa) {
        $r = $this->recepcion($this->hitos($etapa));
        $this->actingAs($taller)->post("/taller/recepciones/{$r->public_id}/sin-solucion", [
            'unrepairable_notes' => 'x',
        ])->assertSessionHasErrors('unrepairable_at');
    }
}

public function test_el_recorrido_completo_con_reparacion_vuelve_a_en_proceso(): void
{
    Notification::fake();
    $r = $this->recepcion($this->hitos('repair_authorized'));
    $taller = $this->staffUser(Permissions::ROLE_WORKSHOP);
    $ids = fn (string $stage) => collect($this->actingAs($taller)->get("/taller/recepciones?stage={$stage}")
        ->viewData('page')['props']['receptions']['data'])->pluck('id');

    $this->assertTrue($ids('repair')->contains($r->public_id));

    $this->actingAs($taller)->post("/taller/recepciones/{$r->public_id}/reparar")->assertRedirect();
    $this->assertTrue($ids('in_progress')->contains($r->public_id));

    $this->actingAs($taller)->post("/taller/recepciones/{$r->public_id}/reparada")->assertRedirect();
    $this->assertTrue($ids('ready')->contains($r->public_id));
}

public function test_la_consulta_y_el_sin_solucion_llegan_a_toda_la_organizacion(): void
{
    Notification::fake();
    $org = $this->organization();
    $org->forceFill(['contact_email' => 'compras@cliente.test'])->save();
    $operador = $this->clientUser($org, Permissions::ROLE_CLIENT_OPERATOR);
    $consulta = $this->clientUser($org, Permissions::ROLE_CLIENT_VIEWER);
    $ajeno = $this->clientUser($this->organization());
    $taller = $this->staffUser(Permissions::ROLE_WORKSHOP);

    $r = $this->recepcion($this->hitos('rejected'), $org);
    $this->actingAs($taller)->post("/taller/recepciones/{$r->public_id}/consultar-reparacion")->assertRedirect();

    Notification::assertSentTo([$operador, $consulta], RepairAuthorizationRequested::class);
    Notification::assertNotSentTo($ajeno, RepairAuthorizationRequested::class);
    Notification::assertSentOnDemand(RepairAuthorizationRequested::class,
        fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'compras@cliente.test');

    $otra = $this->recepcion($this->hitos('rejected'), $org);
    $this->actingAs($taller)->post("/taller/recepciones/{$otra->public_id}/sin-solucion", [
        'unrepairable_notes' => 'No hay repuesto.',
    ])->assertRedirect();

    Notification::assertSentTo([$operador, $consulta], ReceptionDeclaredUnrepairable::class);
    Notification::assertSentOnDemand(ReceptionDeclaredUnrepairable::class);
}

public function test_el_correo_de_contacto_no_se_duplica_si_ya_es_de_un_usuario(): void
{
    Notification::fake();
    $org = $this->organization();
    $operador = $this->clientUser($org);
    $org->forceFill(['contact_email' => strtoupper($operador->email)])->save();

    $r = $this->recepcion($this->hitos('rejected'), $org);
    $this->actingAs($this->staffUser())->post("/taller/recepciones/{$r->public_id}/consultar-reparacion");

    Notification::assertSentOnDemandTimes(RepairAuthorizationRequested::class, 0);
}

/** Sin fake: el destinatario anónimo no puede ir por el canal database. */
public function test_el_aviso_al_correo_de_contacto_no_rompe_el_canal_de_la_aplicacion(): void
{
    $org = $this->organization();
    $org->forceFill(['contact_email' => 'compras@cliente.test'])->save();
    $operador = $this->clientUser($org);

    $r = $this->recepcion($this->hitos('rejected'), $org);
    $this->actingAs($this->staffUser())->post("/taller/recepciones/{$r->public_id}/consultar-reparacion")
        ->assertRedirect()->assertSessionHasNoErrors();

    $this->assertSame(1, $operador->notifications()->count());
}
```

  Se agregan los imports `App\Enums\AssetStatus`, `App\Notifications\ReceptionDeclaredUnrepairable`, `App\Notifications\RepairAuthorizationRequested` e `Illuminate\Support\Facades\Notification`.

- [ ] **Paso 2:** actualizar `RepairAuthorizationTest::test_se_avisa_a_quien_puede_contestar`. Pasa a llamarse `test_se_avisa_a_toda_la_organizacion` y usa `assertSentTo($consulta, …)` en vez de `assertNotSentTo`. Ahora **todos** los usuarios de la organización reciben la consulta.

- [ ] **Paso 3:** correr las pruebas nuevas y verificar que fallan.

- [ ] **Paso 4: implementación en `ChangeReceptionStage`**

```php
public function failRepair(Reception $reception, ?string $notes): Reception
{
    if ($reception->stage() !== 'in_repair') {
        throw ValidationException::withMessages(['repair_failed_at' => 'Sólo se rechaza una reparación en curso.']);
    }
    if (blank($notes)) {
        throw ValidationException::withMessages(['repair_failure_notes' => 'Indique por qué se rechaza la reparación.']);
    }

    DB::transaction(fn () => $reception->forceFill([
        'repair_failed_at' => now(), 'repair_failed_by' => Auth::id(), 'repair_failure_notes' => $notes,
    ])->save());

    ActivityLogger::log(action: 'reception.repair_failed', subject: $reception,
        description: 'Se rechazó la reparación', new: ['notes' => $notes]);

    return $reception;
}

public function markUnrepairable(Reception $reception, ?string $notes): Reception
{
    if (! in_array($reception->stage(), ['rejected', 'repair_failed'], true)) {
        throw ValidationException::withMessages([
            'unrepairable_at' => 'Sólo se marca sin solución una pieza rechazada que no esté esperando al cliente.',
        ]);
    }
    if (blank($notes)) {
        throw ValidationException::withMessages(['unrepairable_notes' => 'Indique por qué no tiene solución.']);
    }

    DB::transaction(function () use ($reception, $notes) {
        $reception->forceFill([
            'unrepairable_at' => now(), 'unrepairable_by' => Auth::id(), 'unrepairable_notes' => $notes,
        ])->save();

        if ($reception->asset !== null) {
            ($this->recalculate)($reception->asset);
        }
    });

    ActivityLogger::log(action: 'reception.unrepairable', subject: $reception,
        description: 'Se marcó sin solución', new: ['notes' => $notes]);

    $this->notifyOrganization($reception, new ReceptionDeclaredUnrepairable($reception));

    return $reception;
}

private function notifyOrganization(Reception $reception, Notification $notification): void
{
    $users = User::withoutGlobalScope('organization')
        ->where('organization_id', $reception->organization_id)
        ->where('is_active', true)
        ->get();

    if ($users->isNotEmpty()) {
        Notifier::send($users, $notification);
    }

    $contact = Organization::query()->whereKey($reception->organization_id)->value('contact_email');

    if (filled($contact) && ! $users->contains(fn (User $u) => strcasecmp($u->email, $contact) === 0)) {
        Notifier::route('mail', $contact)->notify($notification);
    }
}
```

  - Imports: `Illuminate\Notifications\Notification`, `Illuminate\Support\Facades\Notification as Notifier`, `App\Models\Organization` y `App\Notifications\ReceptionDeclaredUnrepairable`.
  - `notifyClientOfRepairRequest` se borra y `requestRepairAuthorization` pasa a llamar a `notifyOrganization($reception, new RepairAuthorizationRequested($reception))`.
  - Guardas nuevas:
    - En `requestRepairAuthorization`, antes de comprobar `repairWasRequested()`: con `isUnrepairable()` responde "ya se marcó sin solución"; con `repairFailed()` responde "la reparación ya se intentó: sólo queda marcarla sin solución". Las dos van con la clave `repair_requested_at`.
    - En `markRepaired`: si `repairFailed()`, responde "La reparación se rechazó: ya no puede darse por terminada" con la clave `repaired_at`.

- [ ] **Paso 5: `RecalculateAssetStatus::wasReturnedUnrepairedAfter`**

```php
return DB::table('receptions')
    ->where('asset_id', $asset->getKey())
    ->whereNull('deleted_at')
    ->where(fn ($q) => $q
        ->where(fn ($d) => $d->where('repair_decision', Reception::REPAIR_DECLINED)
            ->when($since !== null, fn ($w) => $w->where('repair_decided_at', '>=', $since)))
        ->orWhere(fn ($u) => $u->whereNotNull('unrepairable_at')
            ->when($since !== null, fn ($w) => $w->where('unrepairable_at', '>=', $since))))
    ->exists();
```

  En el docblock, la regla 7 suma "o que el taller marcó sin solución".

- [ ] **Paso 6: notificaciones**
  - `RepairAuthorizationRequested::via()` devuelve `$notifiable instanceof AnonymousNotifiable ? ['mail'] : ['mail', 'database']`.
  - Enlace: `url($this->reception->url())` en el mail y `$this->reception->url()` en `toArray`.
  - `ReceptionDeclaredUnrepairable`, con la misma estructura:
    - Asunto: "Equipo sin reparación posible — {equipo}".
    - Cuerpo: el motivo (`unrepairable_notes`), que se devuelve **no apto** y que tiene que recertificarse antes de volver a servicio.
    - Acción: "Ver en el sistema".
    - `toArray`: `type` es `reception_unrepairable`.

- [ ] **Paso 7:** controlador y rutas (van acá porque las pruebas pegan contra HTTP).
  - Rutas dentro del grupo `RECEPTIONS_RELEASE`:
    - `POST /recepciones/{reception}/reparacion-fallida` → `failRepair` (`receptions.repair_failed`)
    - `POST /recepciones/{reception}/sin-solucion` → `markUnrepairable` (`receptions.unrepairable`)
  - Endpoints nuevos:
    - Los dos llaman a `$this->authorize('release', $reception)` y validan `['nullable','string','max:2000']`, `failRepair` sobre `repair_failure_notes` y `markUnrepairable` sobre `unrepairable_notes`.
    - Mensajes: "Reparación rechazada. La pieza vuelve a Rechazadas." y "Pieza marcada sin solución. Queda lista para devolver, no apta."
  - En `index`:
    - Filtro `reception`, con `->when($filters['reception'] ?? null, fn (Builder $q, string $v) => $q->where('receptions.public_id', $v))`.
    - En el `match` de etapas, `'repair' => $query->repairQueue()` reemplaza a `'in_repair'`.
    - Campos nuevos en la fila: `repairFailedAt`, `repairFailureNotes`, `unrepairableAt`, `unrepairableNotes`.
    - Flags de acciones (todos con `$user->can('release', $r)`):
      - `requestRepair`: `stage() === 'rejected'`
      - `markRepaired`: `stage() === 'in_repair'`
      - `failRepair`: `stage() === 'in_repair'`
      - `markUnrepairable`: etapa en `['rejected','repair_failed']`
  - `stageCounts()` reemplaza `in_repair` por `'repair' => Reception::query()->repairQueue()->count()`.

- [ ] **Paso 8:** `vendor/bin/phpunit` completo en verde.

### Tarea 3: filtro directo e hilo del equipo

- [ ] **Paso 1: prueba** (en `WorkshopFlowTest`)

```php
public function test_el_enlace_directo_muestra_solo_esa_recepcion_y_respeta_la_organizacion(): void
{
    $org = $this->organization();
    $propia = $this->recepcion($this->hitos('awaiting_client'), $org);
    $otra = $this->recepcion($this->hitos('awaiting_client'), $org);
    $ajena = $this->recepcion($this->hitos('awaiting_client'));
    $cliente = $this->clientUser($org);

    $ids = fn (string $id) => collect($this->actingAs($cliente)
        ->get("/taller/recepciones?stage=all&reception={$id}")
        ->viewData('page')['props']['receptions']['data'])->pluck('id');

    $this->assertSame([$propia->public_id], $ids($propia->public_id)->all());
    $this->assertSame([], $ids($ajena->public_id)->all());
}
```

- [ ] **Paso 2: `AssetTimeline::receptions()`**
  - Eager load: `repairFailedBy:id,name` y `unrepairableBy:id,name`.
  - Hitos:
    - `repair_started_at` pasa a decir "Se inició la reparación".
    - Después de `repaired_at`: `['repair_failed_at','repairFailedBy','x','danger','Reparación rechazada',$reception->repair_failure_notes]` y `['unrepairable_at','unrepairableBy','x','danger','Marcado sin solución',$reception->unrepairable_notes]`.
    - El despacho usa `leavesUnrepaired()` en lugar de `repairWasDeclined()`.
  - Los `href` pasan a `$reception->url()`.

- [ ] **Paso 3:** `vendor/bin/phpunit` en verde.

### Tarea 4: pantalla del taller

**Archivos:** `resources/js/types/index.ts` y `resources/js/pages/workshop/receptions.tsx`.

- [ ] **Paso 1: `ReceptionRow`**
  - La unión de etapas suma `'repair_failed' | 'unrepairable'`.
  - Campos: `repairFailedAt`, `repairFailureNotes`, `unrepairableAt` y `unrepairableNotes`, todos `string | null`.
  - `can` suma `failRepair: boolean` y `markUnrepairable: boolean`.

- [ ] **Paso 2: pestañas y resumen**
  - `StageCounts.in_repair` pasa a llamarse `repair`.
  - La pestaña `{ value: 'repair', label: 'Reparación' }` va después de "Esperando al cliente".
  - En el resumen, la tarjeta "Reparación" usa `counts.repair` y `?stage=repair`.
  - El diagrama del comentario se actualiza.

- [ ] **Paso 3: `NotesActionButton` genérico**
  - Reemplaza a `RejectButton` y se usa tres veces: rechazar el trabajo, rechazar la reparación y marcar sin solución.
  - Props: `url`, `field`, `extra` (datos fijos, por ejemplo `{ outcome: 'rejected' }`), `label`, `icon`, `title`, `description`, `notesLabel`, `confirmLabel` y `variant`.
  - Implementación: `useForm({ ...extra, [field]: '' })`, con el `Textarea` requerido y el error en `errors[field]`.

- [ ] **Paso 4: botones**
  - Empezar pasa a llamarse **Iniciar**.
  - `sendToRepair` → **Iniciar**, con el ícono Play y la descripción "El cliente autorizó. La pieza pasa a En proceso para repararla; al terminar se aprueba o se rechaza.".
  - `markRepaired` → **Aprobar**.
  - `failRepair` → **Rechazar** (`NotesActionButton` sobre `reparacion-fallida`, campo `repair_failure_notes`).
  - `markUnrepairable` → **Sin solución** (`NotesActionButton` sobre `sin-solucion`, campo `unrepairable_notes`, variante danger). La descripción avisa que vuelve no apto y que se le avisa al cliente.
  - La nota bajo la etapa muestra `unrepairableNotes ?? repairFailureNotes ?? outcomeNotes` (este último sólo si fue rechazada).

- [ ] **Paso 5: aviso del enlace directo.** Si viene `filters.reception`, arriba de la tabla se muestra "Mostrando una sola recepción" con un botón "Ver todas" que lleva a `/taller/recepciones`.

- [ ] **Paso 6:** `npm run types` y `npm run build` sin errores. Recorrer la pantalla en el navegador (preview) con un usuario de taller: Rechazadas → Sin solución, Reparación → Iniciar y la pestaña En proceso.

### Tarea 5: textos de "Dar recepción"

- [ ] **Paso 1:**
  - `Shipment::STATUS_LABELS`: `submitted` pasa a "Pendiente de recepción" y `authorized` a "Recibido".
  - `AuthorizeShipment`:
    - Mensajes: "Sólo se puede dar recepción a los envíos pendientes de recepción." y "Sólo se pueden rechazar los envíos pendientes de recepción."
    - Bitácora: "Envío X recibido; se crearon N recepciones".
- [ ] **Paso 2:** revisar los textos visibles con `grep -rn "utoriz" app/Http/Controllers/ShipmentController.php resources/js/pages/shipments`. Cambiar "Autorizar"/"autorizado" por "Dar recepción"/"recibido" solo en lo que ve el usuario, sin tocar claves ni rutas.
- [ ] **Paso 3:** prueba en `WorkshopFlowTest`: con un envío `submitted`, `statusLabel()` devuelve "Pendiente de recepción".
- [ ] **Paso 4:** `vendor/bin/phpunit` y `npm run types` en verde.

### Tarea 6: documentación y commit

- [ ] Agregar el recorrido nuevo en `docs/flujos-de-negocio.md` y la entrada del bloque 2, subproyecto 1, en `docs/cambios-devolucion-negocio.md`.
- [ ] Verificación final: `vendor/bin/phpunit`, `npm run types`, `npm run build` y `grep -rniI kpk --exclude-dir=node_modules --exclude-dir=vendor --exclude-dir=.git .` (sólo pueden aparecer las dos excepciones).
- [ ] Commit: `feat: flujo del taller — reparación única, sin solución y consulta a toda la organización`.
