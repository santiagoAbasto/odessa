<?php

namespace App\Support;

/**
 * Traducción del registro de auditoría a lenguaje corriente.
 *
 * El código interno (`asset.viewed`, `auth.login`) sirve para filtrar y para
 * consultar la base, pero no se le muestra a nadie: quien audita es una
 * persona del negocio, no un programador. Cada acción se presenta como una
 * frase en pasado —lo que la persona hizo— más el área a la que pertenece.
 *
 * Si aparece una acción sin traducir, se degrada con elegancia: se muestra el
 * código legible en vez de romper la pantalla. Es preferible una fila fea a
 * una auditoría incompleta.
 */
class AuditActions
{
    /**
     * code => [frase en pasado, área, tono]
     *
     * El tono ordena la lectura visual: lo que borra o rechaza en rojo, lo que
     * aprueba en verde, lo que sólo consulta en gris.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    private const MAP = [
        // --- Acceso ---
        'auth.login' => ['Inició sesión', 'Acceso', 'neutral'],
        'auth.logout' => ['Cerró sesión', 'Acceso', 'neutral'],
        'auth.login_failed' => ['Intento de acceso fallido', 'Acceso', 'danger'],
        'auth.password_reset' => ['Cambió su contraseña', 'Acceso', 'warning'],

        // --- Piezas ---
        'asset.viewed' => ['Consultó una pieza', 'Piezas', 'neutral'],
        'asset.created' => ['Dio de alta una pieza', 'Piezas', 'success'],
        'asset.updated' => ['Modificó los datos de una pieza', 'Piezas', 'warning'],
        'asset.deleted' => ['Dio de baja una pieza', 'Piezas', 'danger'],
        'asset.status_recalculated' => ['Se recalculó el estado de una pieza', 'Piezas', 'neutral'],
        'asset.exported' => ['Descargó el listado en Excel', 'Piezas', 'warning'],
        'recertification.notified' => ['Se avisó al cliente de una recertificación', 'Piezas', 'warning'],

        // --- Informes de inspección (FMR) ---
        'report.created' => ['Creó un informe de inspección', 'Inspecciones', 'success'],
        'report.updated' => ['Modificó un informe de inspección', 'Inspecciones', 'warning'],
        'report.finalized' => ['Finalizó un informe de inspección', 'Inspecciones', 'success'],

        // --- Envíos del cliente ---
        'shipment.created' => ['Creó un envío al taller', 'Envíos', 'success'],
        'shipment.submitted' => ['Remitió un envío al taller', 'Envíos', 'neutral'],
        'shipment.authorized' => ['Autorizó un envío', 'Envíos', 'success'],
        'shipment.rejected' => ['Rechazó un envío', 'Envíos', 'danger'],

        // --- Taller ---
        'reception.created' => ['Registró la recepción de un equipo', 'Taller', 'success'],
        'reception.started' => ['Empezó a trabajar un equipo', 'Taller', 'warning'],
        'reception.approved' => ['Aprobó el trabajo de un equipo', 'Taller', 'success'],
        'reception.rejected' => ['Rechazó el trabajo de un equipo', 'Taller', 'danger'],
        'reception.repair_requested' => ['Le pidió al cliente que autorice una reparación', 'Taller', 'warning'],
        'reception.repair_approved' => ['El cliente autorizó una reparación', 'Taller', 'success'],
        'reception.repair_declined' => ['El cliente no autorizó una reparación', 'Taller', 'danger'],
        'reception.sent_to_repair' => ['Envió un equipo a reparación', 'Taller', 'warning'],
        'reception.repaired' => ['Terminó la reparación de un equipo', 'Taller', 'success'],
        // Histórico: antes de separar aprobación de rechazo.
        'reception.released' => ['Liberó un equipo terminado', 'Taller', 'success'],
        'reception.dispatched' => ['Despachó un equipo al cliente', 'Taller', 'success'],

        // --- Documentos ---
        'document.uploaded' => ['Subió un archivo', 'Documentos', 'success'],
        'document.downloaded' => ['Descargó un archivo', 'Documentos', 'neutral'],
        'document.deleted' => ['Eliminó un archivo', 'Documentos', 'danger'],

        // --- Operaciones del taller ---
        'operation.created' => ['Creó una operación', 'Administración', 'success'],
        'operation.updated' => ['Modificó los tiempos de una operación', 'Administración', 'warning'],

        // --- Administración ---
        'user.created' => ['Creó un usuario', 'Administración', 'success'],
        'user.updated' => ['Modificó un usuario', 'Administración', 'warning'],
        'user.deactivated' => ['Desactivó un usuario', 'Administración', 'danger'],
        'account_request.received' => ['Se recibió una solicitud de cuenta', 'Administración', 'neutral'],
        'account_request.approved' => ['Aprobó una solicitud de cuenta', 'Administración', 'success'],
        'account_request.rejected' => ['Rechazó una solicitud de cuenta', 'Administración', 'danger'],
    ];

    /** Frase en pasado. Ante un código desconocido, lo vuelve legible. */
    public static function label(string $action): string
    {
        return self::MAP[$action][0] ?? self::humanize($action);
    }

    /** Área del sistema, para agrupar y filtrar. */
    public static function area(string $action): string
    {
        return self::MAP[$action][1] ?? 'Otros';
    }

    public static function tone(string $action): string
    {
        return self::MAP[$action][2] ?? 'neutral';
    }

    /**
     * Opciones del filtro, ya traducidas y ordenadas alfabéticamente por la
     * frase visible —no por el código, que es lo que hacía que el desplegable
     * saliera en un orden sin sentido para quien lo lee.
     *
     * @param  iterable<string>  $actions
     * @return array<int, array{value: string, label: string, area: string}>
     */
    public static function options(iterable $actions): array
    {
        $options = [];

        foreach ($actions as $action) {
            $options[] = [
                'value' => $action,
                'label' => self::label($action),
                'area' => self::area($action),
            ];
        }

        usort($options, fn (array $a, array $b) => [$a['area'], $a['label']] <=> [$b['area'], $b['label']]);

        return $options;
    }

    /** `asset.status_recalculated` -> `Asset · status recalculated`. */
    private static function humanize(string $action): string
    {
        [$area, $verb] = array_pad(explode('.', $action, 2), 2, '');

        return trim(ucfirst(str_replace('_', ' ', $area)).' · '.str_replace('_', ' ', $verb), ' ·');
    }
}
