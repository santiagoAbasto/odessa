<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Support\ActivityLogger;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tabla de operaciones del taller.
 *
 * Cada operación tiene dos tiempos que gobiernan reglas del negocio:
 *
 *   · `validity_days`   — cuánto vale la certificación que emite.
 *   · `turnaround_days` — cuánto tarda el taller en hacerla.
 *
 * El segundo es el que determina con cuánta anticipación hay que avisarle al
 * cliente que mande el equipo. Tenerlos editables desde la pantalla evita que
 * cambiar un plazo dependa de tocar el código, que es exactamente lo que pidió
 * el negocio al decir que quiere poder ponerlos "menor o mayor".
 */
class OperationController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->can(Permissions::CATALOGS_MANAGE), 403);

        return Inertia::render('admin/operations', [
            'operations' => Service::query()
                ->orderByDesc('determines_fitness')
                ->orderBy('name')
                ->get()
                ->map(fn (Service $service) => [
                    'id' => $service->getKey(),
                    'code' => $service->code,
                    'name' => $service->name,
                    'determinesFitness' => $service->determines_fitness,
                    'validityDays' => $service->validity_days,
                    'turnaroundDays' => $service->turnaround_days,
                    // Lo que se aplica de verdad cuando el campo está vacío.
                    'effectiveTurnaround' => $service->turnaroundDays(),
                    'isActive' => $service->is_active,
                ])
                ->all(),
            'defaults' => [
                'turnaroundDays' => (int) config('odessa.recertification.turnaround_days'),
                'validityDays' => (int) config('odessa.default_validity_days'),
                'safetyMarginDays' => (int) config('odessa.recertification.safety_margin_days'),
            ],
        ]);
    }

    public function update(Request $request, Service $service): RedirectResponse
    {
        abort_unless($request->user()->can(Permissions::CATALOGS_MANAGE), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'code' => ['required', 'string', 'max:20', Rule::unique('services', 'code')->ignore($service)],
            'determines_fitness' => ['required', 'boolean'],
            // 1..3650: un plazo de cero rompería el cálculo de la fecha límite
            // y uno de diez años es siempre un error de tipeo.
            'validity_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'turnaround_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'is_active' => ['required', 'boolean'],
        ], [
            'code.unique' => 'Ya existe otra operación con ese código.',
            'turnaround_days.min' => 'Una operación no puede tardar menos de un día.',
            'turnaround_days.max' => 'Un año de taller es casi seguro un error de carga.',
        ]);

        $before = $service->only(array_keys($data));

        $service->update($data);

        ActivityLogger::log(
            action: 'operation.updated',
            subject: $service,
            description: "Se modificó la operación {$service->name}",
            old: $before,
            new: $data,
        );

        return back()->with('success', "Operación «{$service->name}» actualizada.");
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can(Permissions::CATALOGS_MANAGE), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'code' => ['required', 'string', 'max:20', 'unique:services,code'],
            'determines_fitness' => ['required', 'boolean'],
            'validity_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'turnaround_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ], [
            'code.unique' => 'Ya existe una operación con ese código.',
        ]);

        $service = Service::create($data + ['is_active' => true]);

        ActivityLogger::log(
            action: 'operation.created',
            subject: $service,
            description: "Se creó la operación {$service->name}",
        );

        return back()->with('success', "Operación «{$service->name}» creada.");
    }
}
