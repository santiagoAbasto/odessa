<?php

namespace App\Support;

/**
 * Catálogo único de permisos y roles.
 *
 * Los cuatro roles se confirmaron con el negocio el 12/08/2026:
 *
 *   Superadministrador  hace todo.
 *   Taller              ingresa y edita la base de datos; autoriza los envíos.
 *   Cliente · Operador  pre-carga los equipos que envía y consulta en sólo lectura.
 *   Cliente · Consulta  sólo lectura.
 *
 * Se descartaron los roles internos que se habían supuesto durante la auditoría
 * (técnico, recepción y responsable de taller por separado): el negocio confirmó
 * que el taller opera como un único perfil.
 */
final class Permissions
{
    // --- Activos --------------------------------------------------------
    public const ASSETS_VIEW = 'assets.view';

    public const ASSETS_CREATE = 'assets.create';

    public const ASSETS_UPDATE = 'assets.update';

    public const ASSETS_DELETE = 'assets.delete';

    public const ASSETS_EXPORT = 'assets.export';

    // --- Sets -----------------------------------------------------------
    public const SETS_VIEW = 'sets.view';

    public const SETS_MANAGE = 'sets.manage';

    // --- FMR ------------------------------------------------------------
    public const REPORTS_VIEW = 'reports.view';

    public const REPORTS_CREATE = 'reports.create';

    public const REPORTS_UPDATE = 'reports.update';

    public const REPORTS_FINALIZE = 'reports.finalize';

    public const REPORTS_CANCEL = 'reports.cancel';

    public const REPORTS_EXPORT = 'reports.export';

    // --- Documentos -----------------------------------------------------
    public const DOCUMENTS_VIEW = 'documents.view';

    public const DOCUMENTS_UPLOAD = 'documents.upload';

    public const DOCUMENTS_DELETE = 'documents.delete';

    // --- Taller ---------------------------------------------------------
    public const RECEPTIONS_VIEW = 'receptions.view';

    public const RECEPTIONS_CREATE = 'receptions.create';

    public const RECEPTIONS_UPDATE = 'receptions.update';

    public const RECEPTIONS_RELEASE = 'receptions.release';

    public const RECEPTIONS_DISPATCH = 'receptions.dispatch';

    /**
     * Contestar si se autoriza una reparación.
     *
     * Es el único permiso operativo que tiene un cliente sobre el taller, y
     * está separado del resto a propósito: la reparación se la cobran a él,
     * así que la decisión es suya. Odessa también lo tiene, para registrar la
     * respuesta cuando llega por teléfono o correo en vez de por el panel.
     */
    public const RECEPTIONS_AUTHORIZE_REPAIR = 'receptions.authorize_repair';

    // --- Envíos pre-cargados por el cliente -----------------------------
    public const SHIPMENTS_VIEW = 'shipments.view';

    public const SHIPMENTS_CREATE = 'shipments.create';

    public const SHIPMENTS_AUTHORIZE = 'shipments.authorize';

    // --- Bitácora -------------------------------------------------------
    public const COMMENTS_VIEW = 'comments.view';

    public const COMMENTS_CREATE = 'comments.create';

    public const COMMENTS_INTERNAL = 'comments.internal';

    // --- Administración -------------------------------------------------
    public const USERS_VIEW = 'users.view';

    public const USERS_MANAGE = 'users.manage';

    public const ROLES_MANAGE = 'roles.manage';

    public const ORGANIZATIONS_VIEW = 'organizations.view';

    public const ORGANIZATIONS_MANAGE = 'organizations.manage';

    public const ACCOUNT_REQUESTS_MANAGE = 'account_requests.manage';

    public const CATALOGS_MANAGE = 'catalogs.manage';

    public const AUDIT_VIEW = 'audit.view';

    public const SETTINGS_MANAGE = 'settings.manage';

    // --- Roles ----------------------------------------------------------
    public const ROLE_SUPER_ADMIN = 'super_admin';

    public const ROLE_WORKSHOP = 'workshop';

    public const ROLE_CLIENT_OPERATOR = 'client_operator';

    public const ROLE_CLIENT_VIEWER = 'client_viewer';

    /** @return list<string> */
    public static function all(): array
    {
        $constants = (new \ReflectionClass(self::class))->getConstants();

        return array_values(array_filter(
            $constants,
            fn (string $value, string $name) => ! str_starts_with($name, 'ROLE_'),
            ARRAY_FILTER_USE_BOTH
        ));
    }

    /** @return array<string, list<string>> */
    public static function roleMatrix(): array
    {
        /**
         * Cliente · Consulta: acceso de sólo lectura a su propia información.
         * No crea, no edita, no sube archivos.
         */
        $clientViewer = [
            self::ASSETS_VIEW,
            self::ASSETS_EXPORT,
            self::SETS_VIEW,
            self::REPORTS_VIEW,
            self::REPORTS_EXPORT,
            self::DOCUMENTS_VIEW,
            self::COMMENTS_VIEW,
            self::SHIPMENTS_VIEW,
            /*
             * Ver sus equipos en el taller. El aislamiento lo garantiza el
             * scope de organización: un cliente sólo alcanza lo suyo.
             *
             * Hace falta para que el operador pueda contestar la consulta de
             * reparación desde el panel, y de paso responde la pregunta que
             * todo cliente hace igual por teléfono: dónde está mi equipo.
             */
            self::RECEPTIONS_VIEW,
        ];

        /**
         * Cliente · Operador: lo mismo, más la pre-carga de los equipos que
         * envía. Sigue sin poder tocar el inventario: el envío es una
         * declaración que el taller debe autorizar.
         */
        $clientOperator = [
            ...$clientViewer,
            self::SHIPMENTS_CREATE,
            self::COMMENTS_CREATE,
            // La reparación se le cobra al cliente: la decisión es suya.
            self::RECEPTIONS_AUTHORIZE_REPAIR,
        ];

        /**
         * Taller: ingresa y edita la base de datos operativa completa, y
         * autoriza los envíos de los clientes. No administra usuarios ni roles.
         */
        $workshop = [
            self::ASSETS_VIEW, self::ASSETS_CREATE, self::ASSETS_UPDATE, self::ASSETS_EXPORT,
            self::SETS_VIEW, self::SETS_MANAGE,
            self::REPORTS_VIEW, self::REPORTS_CREATE, self::REPORTS_UPDATE,
            self::REPORTS_FINALIZE, self::REPORTS_CANCEL, self::REPORTS_EXPORT,
            self::DOCUMENTS_VIEW, self::DOCUMENTS_UPLOAD, self::DOCUMENTS_DELETE,
            self::RECEPTIONS_VIEW, self::RECEPTIONS_CREATE, self::RECEPTIONS_UPDATE,
            self::RECEPTIONS_RELEASE, self::RECEPTIONS_DISPATCH,
            // Para transcribir la respuesta que el cliente dio por fuera.
            self::RECEPTIONS_AUTHORIZE_REPAIR,
            self::SHIPMENTS_VIEW, self::SHIPMENTS_AUTHORIZE,
            self::COMMENTS_VIEW, self::COMMENTS_CREATE, self::COMMENTS_INTERNAL,
            self::ORGANIZATIONS_VIEW,
            self::CATALOGS_MANAGE,
            self::AUDIT_VIEW,
        ];

        return [
            self::ROLE_SUPER_ADMIN => self::all(),
            self::ROLE_WORKSHOP => $workshop,
            self::ROLE_CLIENT_OPERATOR => $clientOperator,
            self::ROLE_CLIENT_VIEWER => $clientViewer,
        ];
    }

    /** @return array<string, string> */
    public static function roleLabels(): array
    {
        return [
            self::ROLE_SUPER_ADMIN => 'Superadministrador',
            self::ROLE_WORKSHOP => 'Taller',
            self::ROLE_CLIENT_OPERATOR => 'Cliente · Operador',
            self::ROLE_CLIENT_VIEWER => 'Cliente · Consulta',
        ];
    }

    /** Roles que pertenecen a una organización cliente. */
    public static function clientRoles(): array
    {
        return [self::ROLE_CLIENT_OPERATOR, self::ROLE_CLIENT_VIEWER];
    }

    /**
     * Permisos que el superadmin puede sumarle a un usuario cliente puntual,
     * por encima de su rol (bloque 2, 27/09/2026: «rol cliente para
     * cualquiera de los dos, poder darles de alta permisos»).
     *
     * Es una lista cerrada a propósito: nunca un permiso del taller ni de
     * administración, para que dar un permiso no sea una forma de escalar.
     *
     * @return array<string, string>
     */
    public static function clientGrantable(): array
    {
        return [
            self::SHIPMENTS_CREATE => 'Cargar envíos al taller',
            self::RECEPTIONS_AUTHORIZE_REPAIR => 'Autorizar reparaciones',
            self::COMMENTS_CREATE => 'Comentar en la bitácora',
        ];
    }
}
