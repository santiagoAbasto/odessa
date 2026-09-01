<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad.
 *
 * La CSP permite 'unsafe-inline' en estilos porque Tailwind inyecta estilos
 * en runtime para algunas utilidades. En producción los scripts inline sólo
 * se aceptan con el nonce generado por Vite; en local se relaja para HMR.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), interest-cohort=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');

        // Evita que páginas autenticadas y formularios con tokens CSRF queden
        // guardados en cachés compartidas o en el historial del navegador.
        if ($request->user() || $request->is('ingresar', 'recuperar-clave', 'restablecer-clave/*')) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Pragma', 'no-cache');
        }

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        $response->headers->set('Content-Security-Policy', $this->policy());

        return $response;
    }

    private function policy(): string
    {
        $nonce = Vite::cspNonce();
        $scriptSrc = app()->isLocal()
            ? "'self' 'unsafe-inline' 'unsafe-eval'"
            : "'self' 'nonce-{$nonce}'";

        $connectSrc = app()->isLocal()
            ? "'self' ws: wss: http://localhost:* http://127.0.0.1:*"
            : "'self'";

        return implode('; ', [
            "default-src 'self'",
            "script-src {$scriptSrc}",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "connect-src {$connectSrc}",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }
}
