<?php

use App\Http\Controllers\AccountRequestController;
use App\Http\Controllers\Admin\AccountRequestReviewController;
use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\OperationController;
use App\Http\Controllers\Admin\OrganizationController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\AssetSetController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\PasswordChangeController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\InspectionReportController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrganizationReportController;
use App\Http\Controllers\PublicSiteController;
use App\Http\Controllers\ShipmentController;
use App\Http\Controllers\Workshop\ReceptionController;
use App\Support\Permissions;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Zona pública — accesible sin autenticación
|--------------------------------------------------------------------------
*/
Route::get('/', [PublicSiteController::class, 'home'])->name('home');
Route::get('/servicios', [PublicSiteController::class, 'services'])->name('services');

Route::get('/solicitar-acceso', [AccountRequestController::class, 'create'])->name('access.request');
Route::post('/solicitar-acceso', [AccountRequestController::class, 'store'])
    ->middleware('throttle:5,60') // 5 solicitudes por hora e IP
    ->name('access.request.store');

/*
|--------------------------------------------------------------------------
| Autenticación
|--------------------------------------------------------------------------
*/
Route::middleware('guest')->group(function () {
    Route::get('/ingresar', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/ingresar', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('login.store');

    Route::get('/recuperar-clave', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/recuperar-clave', [PasswordResetController::class, 'email'])
        ->middleware('throttle:5,10')
        ->name('password.email');

    Route::get('/restablecer-clave/{token}', [PasswordResetController::class, 'reset'])->name('password.reset');
    Route::post('/restablecer-clave', [PasswordResetController::class, 'update'])
        ->middleware('throttle:10,10')
        ->name('password.update');
});

/*
|--------------------------------------------------------------------------
| Zona autenticada
|--------------------------------------------------------------------------
| El aislamiento por organización lo garantiza el global scope de los modelos;
| el middleware `permission` es la primera barrera y las Policies la segunda.
*/
Route::middleware(['auth'])->group(function () {
    Route::post('/salir', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    // Primer ingreso con la contraseña temporal que dio un administrador.
    Route::get('/cuenta/clave', [PasswordChangeController::class, 'edit'])->name('password.change');
    Route::put('/cuenta/clave', [PasswordChangeController::class, 'update'])
        ->middleware('throttle:10,10')
        ->name('password.change.update');

    Route::get('/panel', DashboardController::class)->name('dashboard');

    // Campana del encabezado. Cada usuario sólo alcanza sus propios avisos.
    Route::post('/notificaciones/leidas', [NotificationController::class, 'markAllRead'])->name('notifications.read_all');
    Route::post('/notificaciones/{notification}/abrir', [NotificationController::class, 'open'])
        ->whereUuid('notification')
        ->name('notifications.open');

    // --- Activos ---------------------------------------------------------
    Route::middleware('permission:'.Permissions::ASSETS_VIEW)->group(function () {
        Route::get('/activos', [AssetController::class, 'index'])->name('assets.index');
        // Antes que `/activos/{asset}`: si no, "exportar" se tomaría por un id.
        Route::get('/activos/exportar', [AssetController::class, 'export'])->name('assets.export');
        Route::get('/activos/{asset}', [AssetController::class, 'show'])->name('assets.show');
    });

    // Ficha técnica editable: superadministrador y taller.
    // Alta manual de equipamiento, sin esperar a que llegue en un envío.
    Route::post('/activos', [AssetController::class, 'store'])
        ->middleware('permission:'.Permissions::ASSETS_CREATE)
        ->name('assets.store');

    Route::put('/activos/{asset}', [AssetController::class, 'update'])
        ->middleware('permission:'.Permissions::ASSETS_UPDATE)
        ->name('assets.update');

    // --- Sets ------------------------------------------------------------
    Route::middleware('permission:'.Permissions::SETS_VIEW)->group(function () {
        Route::get('/sets', [AssetSetController::class, 'index'])->name('sets.index');
        Route::get('/sets/{set}', [AssetSetController::class, 'show'])->name('sets.show');
    });

    // --- Reporte por organización: status, vencimientos y trabajos --------
    Route::get('/reportes', [OrganizationReportController::class, 'index'])
        ->middleware('permission:'.Permissions::REPORTS_VIEW)
        ->name('org_reports.index');
    Route::get('/reportes/exportar', [OrganizationReportController::class, 'export'])
        ->middleware('permission:'.Permissions::REPORTS_EXPORT)
        ->name('org_reports.export');

    // --- FMR -------------------------------------------------------------
    Route::middleware('permission:'.Permissions::REPORTS_VIEW)->group(function () {
        Route::get('/informes', [InspectionReportController::class, 'index'])->name('reports.index');
        Route::get('/informes/{report}', [InspectionReportController::class, 'show'])->name('reports.show');
    });
    // Alta del informe y carga de los equipos que dictamina. Va antes de
    // finalizar porque es lo que construye el contenido del FMR.
    Route::middleware('permission:'.Permissions::REPORTS_CREATE)->group(function () {
        Route::post('/informes', [InspectionReportController::class, 'store'])->name('reports.store');
    });

    Route::middleware('permission:'.Permissions::REPORTS_UPDATE)->group(function () {
        Route::get('/informes/{report}/equipos-disponibles', [InspectionReportController::class, 'availableAssets'])
            ->name('reports.available_assets');
        Route::post('/informes/{report}/equipos', [InspectionReportController::class, 'addAssets'])
            ->name('reports.add_assets');
        Route::delete('/informes/{report}/equipos/{asset}', [InspectionReportController::class, 'removeAsset'])
            ->name('reports.remove_asset');
        // Dictamen en tanda: un FMR típico resuelve varios equipos de una.
        Route::post('/informes/{report}/dictamen', [InspectionReportController::class, 'updateLines'])
            ->name('reports.update_lines');
    });

    Route::post('/informes/{report}/finalizar', [InspectionReportController::class, 'finalize'])
        ->middleware('permission:'.Permissions::REPORTS_FINALIZE)
        ->name('reports.finalize');

    // --- Documentos ------------------------------------------------------
    // Un único camino de descarga, sea el documento de un FMR o de una pieza.
    Route::get('/documentos/{document}/descargar', [DocumentController::class, 'download'])
        ->middleware('permission:'.Permissions::DOCUMENTS_VIEW)
        ->name('documents.download');

    Route::middleware('permission:'.Permissions::DOCUMENTS_UPLOAD)->group(function () {
        Route::post('/informes/{report}/documentos', [DocumentController::class, 'storeForReport'])
            ->name('documents.store.report');
        Route::post('/activos/{asset}/documentos', [DocumentController::class, 'storeForAsset'])
            ->name('documents.store.asset');
    });

    Route::delete('/documentos/{document}', [DocumentController::class, 'destroy'])
        ->middleware('permission:'.Permissions::DOCUMENTS_DELETE)
        ->name('documents.destroy');

    // --- Envíos pre-cargados por el cliente -------------------------------
    Route::prefix('envios')->name('shipments.')->group(function () {
        Route::get('/', [ShipmentController::class, 'index'])
            ->middleware('permission:'.Permissions::SHIPMENTS_VIEW)
            ->name('index');

        Route::get('/{shipment}', [ShipmentController::class, 'show'])
            ->middleware('permission:'.Permissions::SHIPMENTS_VIEW)
            ->name('show');

        Route::middleware('permission:'.Permissions::SHIPMENTS_CREATE)->group(function () {
            Route::post('/', [ShipmentController::class, 'store'])->name('store');
            Route::post('/{shipment}/equipos', [ShipmentController::class, 'addItem'])->name('items.store');
            Route::delete('/{shipment}/equipos/{item}', [ShipmentController::class, 'removeItem'])->name('items.destroy');
            Route::post('/{shipment}/enviar', [ShipmentController::class, 'submit'])->name('submit');
        });

        Route::post('/{shipment}/revisar', [ShipmentController::class, 'review'])
            ->middleware('permission:'.Permissions::SHIPMENTS_AUTHORIZE)
            ->name('review');
    });

    // --- Taller ----------------------------------------------------------
    Route::prefix('taller')->name('workshop.')->group(function () {
        Route::get('/recepciones', [ReceptionController::class, 'index'])
            ->middleware('permission:'.Permissions::RECEPTIONS_VIEW)
            ->name('receptions.index');

        // Ingreso directo: llegó un equipo sin que el cliente cargara el envío.
        Route::middleware('permission:'.Permissions::RECEPTIONS_CREATE)->group(function () {
            Route::post('/recepciones', [ReceptionController::class, 'store'])
                ->name('receptions.store');
            Route::get('/recepciones/piezas/{organization}', [ReceptionController::class, 'assetsFor'])
                ->name('receptions.assets');
        });

        Route::post('/recepciones/{reception}/empezar', [ReceptionController::class, 'start'])
            ->middleware('permission:'.Permissions::RECEPTIONS_RELEASE)
            ->name('receptions.start');

        // Dictamen del trabajo y, si fue rechazado, la consulta al cliente.
        Route::middleware('permission:'.Permissions::RECEPTIONS_RELEASE)->group(function () {
            Route::post('/recepciones/{reception}/dictamen', [ReceptionController::class, 'resolve'])
                ->name('receptions.resolve');
            Route::post('/recepciones/{reception}/consultar-reparacion', [ReceptionController::class, 'requestRepair'])
                ->name('receptions.request_repair');
            Route::post('/recepciones/{reception}/sin-solucion', [ReceptionController::class, 'markUnrepairable'])
                ->name('receptions.unrepairable');
        });

        /*
         * Contestar la consulta de reparación queda FUERA del grupo anterior:
         * es la única acción del taller que un usuario cliente puede ejecutar,
         * y por eso lleva su propio permiso. El Policy verifica además que la
         * recepción esté efectivamente esperando respuesta.
         */
        Route::post('/recepciones/{reception}/autorizacion', [ReceptionController::class, 'recordRepairDecision'])
            ->middleware('permission:'.Permissions::RECEPTIONS_AUTHORIZE_REPAIR)
            ->name('receptions.repair_decision');

        Route::post('/recepciones/{reception}/despachar', [ReceptionController::class, 'dispatchReception'])
            ->middleware('permission:'.Permissions::RECEPTIONS_DISPATCH)
            ->name('receptions.dispatch');
    });

    // --- Administración --------------------------------------------------
    Route::prefix('administracion')->name('admin.')->group(function () {
        Route::get('/usuarios', [UserController::class, 'index'])
            ->middleware('permission:'.Permissions::USERS_VIEW)
            ->name('users.index');

        Route::middleware('permission:'.Permissions::USERS_MANAGE)->group(function () {
            Route::post('/usuarios', [UserController::class, 'store'])->name('users.store');
            Route::put('/usuarios/{user}', [UserController::class, 'update'])->name('users.update');
            Route::delete('/usuarios/{user}', [UserController::class, 'destroy'])->name('users.destroy');
            Route::post('/usuarios/{user}/restablecer-acceso', [UserController::class, 'resetAccess'])->name('users.reset_access');
        });

        Route::middleware('permission:'.Permissions::ACCOUNT_REQUESTS_MANAGE)->group(function () {
            Route::get('/solicitudes', [AccountRequestReviewController::class, 'index'])->name('account-requests.index');
            Route::put('/solicitudes/{accountRequest}', [AccountRequestReviewController::class, 'update'])->name('account-requests.update');
        });

        Route::get('/auditoria', [ActivityLogController::class, 'index'])
            ->middleware('permission:'.Permissions::AUDIT_VIEW)
            ->name('audit.index');

        /*
         * Empresas cliente. Sin baja: una organización con equipos e informes
         * no se borra sin romper la trazabilidad, se desactiva.
         */
        Route::middleware('permission:'.Permissions::ORGANIZATIONS_MANAGE)->group(function () {
            Route::get('/organizaciones', [OrganizationController::class, 'index'])->name('organizations.index');
            Route::post('/organizaciones', [OrganizationController::class, 'store'])->name('organizations.store');
            Route::put('/organizaciones/{organization}', [OrganizationController::class, 'update'])
                ->name('organizations.update');
            // Qué operaciones tiene cada cliente y cómo se llaman para él.
            Route::get('/organizaciones/{organization}/operaciones', [OrganizationController::class, 'operations'])
                ->name('organizations.operations');
            Route::put('/organizaciones/{organization}/operaciones', [OrganizationController::class, 'updateOperations'])
                ->name('organizations.operations.update');
        });

        // Tabla de operaciones: sus tiempos gobiernan vigencias y avisos.
        Route::middleware('permission:'.Permissions::CATALOGS_MANAGE)->group(function () {
            Route::get('/operaciones', [OperationController::class, 'index'])->name('operations.index');
            Route::post('/operaciones', [OperationController::class, 'store'])->name('operations.store');
            Route::put('/operaciones/{service}', [OperationController::class, 'update'])->name('operations.update');
        });
    });
});
