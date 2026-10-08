<?php

return [
    /**
     * Ventana preventiva de vencimiento. El sistema auditado muestra
     * "Flujo de vencimiento de partes de los próximos 60 días" y clasifica
     * como "Apto - Próximo a vencer" dentro de ese rango.
     */
    'expiry_warning_days' => (int) env('ODESSA_EXPIRY_WARNING_DAYS', 60),

    /**
     * Vigencia por defecto de una inspección cuando el servicio no define la suya.
     * Deducida de los datos: fecha FMR + 1 año = fecha de vencimiento.
     * SUPUESTO pendiente de validación con el área técnica.
     */
    'default_validity_days' => (int) env('ODESSA_DEFAULT_VALIDITY_DAYS', 365),

    /**
     * Ventana de recertificación.
     *
     * El equipo no puede perder la certificación mientras está en el taller,
     * así que hay que avisarle al cliente con tiempo suficiente para que el
     * ciclo completo —transporte, trabajo, devolución— entre antes de que
     * venza:
     *
     *     Fecha límite de envío = vencimiento − reparación − margen
     *
     * Son los valores por defecto de la operación. El tipo de equipo puede
     * pisar `turnaround_days` con el suyo, porque no todo tarda lo mismo.
     */
    'recertification' => [
        /** Lo que tarda el taller en trabajar un equipo, de punta a punta. */
        'turnaround_days' => (int) env('ODESSA_TURNAROUND_DAYS', 30),

        /** Colchón para transporte, autorización del envío e imprevistos. */
        'safety_margin_days' => (int) env('ODESSA_SAFETY_MARGIN_DAYS', 15),

        /** Con cuánta antelación a la fecha límite se empieza a avisar. */
        'notice_days' => (int) env('ODESSA_RECERT_NOTICE_DAYS', 30),

        /** Día de la semana del resumen para el superadministrador (1 = lunes). */
        'digest_day' => (int) env('ODESSA_RECERT_DIGEST_DAY', 1),

        /** Copia interna de todo aviso que se manda a un cliente. */
        'copy_to' => env('ODESSA_RECERT_COPY_TO'),
    ],

    /** Disco privado para documentos. Nunca debe apuntar a public/. */
    'documents_disk' => env('ODESSA_DOCUMENTS_DISK', 'documents'),

    'uploads' => [
        'max_size_kb' => (int) env('ODESSA_UPLOAD_MAX_KB', 20480),
        'allowed_mimes' => ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'],
        'allowed_extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'webp'],
    ],

    /** Minutos de validez de una URL firmada de descarga. */
    'download_link_ttl' => (int) env('ODESSA_DOWNLOAD_TTL', 5),

    /**
     * Credenciales del disparador del programador por HTTP.
     *
     * El hosting no ofrece cron de consola: sus dos pantallas de tareas
     * programadas sólo saben pedir una URL. Con usuario y clave cargados, la
     * ruta /cron queda habilitada; vacíos, responde 404, así no queda expuesta
     * por olvido donde no se usa.
     */
    'cron' => [
        'user' => env('ODESSA_CRON_USER'),
        'password' => env('ODESSA_CRON_PASSWORD'),
    ],

];
