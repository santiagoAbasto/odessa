<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAccountRequestRequest;
use App\Models\AccountRequest;
use App\Models\User;
use App\Notifications\AccountRequestConfirmation;
use App\Notifications\AccountRequestReceived;
use App\Support\ActivityLogger;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;

class AccountRequestController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('public/request-access');
    }

    public function store(StoreAccountRequestRequest $request): RedirectResponse
    {
        $data = $request->safe()->except('website');

        // Se guarda siempre, incluso si ya existe una solicitud del mismo correo:
        // el administrador decide. Nunca se confirma al visitante si el correo
        // ya está registrado.
        $accountRequest = AccountRequest::create([
            ...$data,
            'ip_address' => $request->ip(),
        ]);

        ActivityLogger::log(
            action: 'account_request.created',
            subject: $accountRequest,
            description: "Solicitud de acceso de {$accountRequest->company}",
        );

        /**
         * Alerta inmediata en la aplicación (la campana) para quien puede
         * resolverla, así no tiene que entrar a comprobar si hay solicitudes
         * nuevas. El correo no sale por cada solicitud: va en un resumen un par
         * de veces al día (`odessa:resumen-solicitudes`), para no llenar la casilla.
         */
        $reviewers = User::query()
            ->where('is_active', true)
            ->whereNull('organization_id')
            ->whereHas('roles', fn ($q) => $q->whereIn('name', [
                Permissions::ROLE_SUPER_ADMIN,
            ]))
            ->get();

        Notification::send($reviewers, new AccountRequestReceived($accountRequest));

        /*
         * Constancia al solicitante, una por día y por correo. El formulario
         * es público y el límite por IP no frena a quien cambia de IP: sin
         * este tope serviría para llenarle la casilla a un tercero.
         */
        $yaConfirmado = AccountRequest::query()
            ->where('email', $accountRequest->email)
            ->whereKeyNot($accountRequest->getKey())
            ->where('created_at', '>=', now()->subDay())
            ->exists();

        if (! $yaConfirmado) {
            Notification::route('mail', $accountRequest->email)
                ->notify(new AccountRequestConfirmation($accountRequest));
        }

        return redirect()
            ->route('access.request')
            ->with('success', 'Recibimos su solicitud y le enviamos una constancia por correo. Un administrador la revisará y le escribirá a esa dirección.');
    }
}
