<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAccountRequestRequest;
use App\Models\AccountRequest;
use App\Models\User;
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
         * Aviso a quien puede resolverla: correo más alerta en la aplicación,
         * para no tener que entrar a comprobar si hay solicitudes nuevas.
         * Va encolado, así el visitante no espera al servidor de correo.
         */
        $reviewers = User::query()
            ->where('is_active', true)
            ->whereNull('organization_id')
            ->whereHas('roles', fn ($q) => $q->whereIn('name', [
                Permissions::ROLE_SUPER_ADMIN,
            ]))
            ->get();

        Notification::send($reviewers, new AccountRequestReceived($accountRequest));

        return redirect()
            ->route('access.request')
            ->with('success', 'Recibimos su solicitud. Un administrador la revisará y le escribirá al correo indicado.');
    }
}
