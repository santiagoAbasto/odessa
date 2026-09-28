<?php

namespace App\Http\Controllers;

use App\Support\NotificationFeed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Avisos de la campana: abrir uno y marcar todos como leídos. */
class NotificationController extends Controller
{
    /**
     * Marca el aviso como leído y lleva a lo que avisa.
     *
     * Se busca entre los avisos del propio usuario: el de otro da 404, igual
     * que cualquier recurso ajeno en el sistema.
     */
    public function open(Request $request, string $notification): RedirectResponse
    {
        $aviso = $request->user()->notifications()->findOrFail($notification);

        $aviso->markAsRead();

        return redirect(NotificationFeed::target($aviso));
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }
}
