<!doctype html>
<html lang="es" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="{{ request()->is('/') || request()->is('servicios') || request()->is('solicitar-acceso') ? 'index,follow' : 'noindex,nofollow' }}">
    <meta name="description" content="Odessa Oil — inspección, certificación y trazabilidad de equipamiento de presión.">
    <title inertia>{{ config('app.name') }}</title>

    {{--
        Favicon. El SVG lo prefieren los navegadores modernos y escala sin pérdida;
        el .ico multirresolución (16/32/48) cubre los que no soportan SVG.
        `sizes="any"` evita que Chrome descargue el .ico teniendo el SVG.
    --}}
    <link rel="icon" href="/favicon.ico" sizes="32x32">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml" sizes="any">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">
    <meta name="theme-color" content="#1B2340">

    {{-- Evita el destello de tema claro antes de que cargue React. --}}
    <script nonce="{{ Vite::cspNonce() }}">
        (function () {
            try {
                var t = localStorage.getItem('odessa.theme');
                if (t === 'dark' || t === 'light') {
                    document.documentElement.setAttribute('data-theme', t);
                }
            } catch (e) {}
        })();
    </script>

    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @inertiaHead
</head>
<body class="h-full antialiased">
    @inertia
</body>
</html>
