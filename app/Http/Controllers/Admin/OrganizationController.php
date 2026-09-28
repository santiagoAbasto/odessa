<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Support\ActivityLogger;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Alta y edición de las empresas cliente.
 *
 * No hay baja: una organización con equipos, informes y recepciones no se
 * borra sin romper la trazabilidad de todo eso. Se desactiva, y así sus
 * usuarios pierden el acceso pero el historial queda intacto.
 *
 * No lleva Policy propia. Las demás la tienen porque comprueban pertenencia a
 * la organización, y acá el recurso ES la organización: no hay nada que
 * comparar, alcanza con el permiso de la ruta.
 */
class OrganizationController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = TableQuery::filters($request, ['search', 'status']);

        $organizations = Organization::query()
            // Los conteos son lo que se mira: cuántos equipos y cuánta gente
            // tiene cada empresa. Con withCount no hay N+1.
            ->withCount(['users', 'assets', 'receptions'])
            ->when($filters['search'] ?? null, fn (Builder $q, string $v) => $q
                ->where(fn (Builder $i) => $i
                    ->where('name', 'like', TableQuery::like($v))
                    ->orWhere('short_code', 'like', TableQuery::like($v))
                    ->orWhere('tax_id', 'like', TableQuery::like($v))))
            ->when(($filters['status'] ?? null) === 'active', fn (Builder $q) => $q->where('is_active', true))
            ->when(($filters['status'] ?? null) === 'inactive', fn (Builder $q) => $q->where('is_active', false))
            ->orderBy('name')
            ->paginate(TableQuery::perPage($request))
            ->withQueryString()
            ->through(fn (Organization $o) => [
                'id' => $o->public_id,
                'name' => $o->name,
                'shortCode' => $o->short_code,
                'taxId' => $o->tax_id,
                'contactEmail' => $o->contact_email,
                'contactPhone' => $o->contact_phone,
                'isActive' => $o->is_active,
                'usersCount' => $o->users_count,
                'assetsCount' => $o->assets_count,
                'receptionsCount' => $o->receptions_count,
            ]);

        return Inertia::render('admin/organizations', [
            'organizations' => $organizations,
            'filters' => $filters,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $organization = Organization::create($data + ['is_active' => true]);

        ActivityLogger::log(
            action: 'organization.created',
            subject: $organization,
            description: "Alta de la organización {$organization->name}",
            new: $data,
            organizationId: $organization->getKey(),
        );

        return back()->with('success', 'Organización creada.');
    }

    public function update(Request $request, Organization $organization): RedirectResponse
    {
        $data = $this->validated($request, $organization);
        $before = $organization->only(['name', 'short_code', 'tax_id', 'contact_email', 'contact_phone', 'is_active']);

        $organization->update($data);

        ActivityLogger::log(
            action: 'organization.updated',
            subject: $organization,
            description: "Modificación de la organización {$organization->name}",
            old: $before,
            new: $organization->only(array_keys($before)),
            organizationId: $organization->getKey(),
        );

        return back()->with('success', 'Organización actualizada.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Organization $organization = null): array
    {
        // Antes de validar, para que la comprobación de unicidad compare
        // contra lo que realmente se va a guardar.
        $request->merge(['short_code' => strtoupper(trim((string) $request->input('short_code')))]);

        return $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'],
            /*
             * El código corto identifica a la empresa en listados y
             * exportaciones, así que no puede repetirse. Se guarda en
             * mayúsculas para que "hal" y "HAL" no convivan como distintos.
             */
            'short_code' => [
                'required', 'string', 'max:16', 'alpha_dash',
                Rule::unique('organizations', 'short_code')
                    ->ignore($organization?->getKey())
                    ->withoutTrashed(),
            ],
            'tax_id' => ['nullable', 'string', 'max:32'],
            'contact_email' => ['nullable', 'email:filter', 'max:180'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'is_active' => ['boolean'],
        ], [
            'name.required' => 'Indique el nombre de la empresa.',
            'short_code.required' => 'Indique un código corto.',
            'short_code.unique' => 'Ya hay una organización con ese código.',
            'short_code.alpha_dash' => 'El código admite letras, números y guiones.',
        ]);
    }
}
