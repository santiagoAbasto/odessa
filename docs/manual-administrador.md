# Manual del administrador

Para personal de KPK Works con acceso al taller o a la administración.

## Roles

| Rol | Alcance |
|---|---|
| **Superadministrador** | Todo, incluida la gestión de roles |
| **Administrador de Odessa** | Operación completa, usuarios, organizaciones, catálogos y auditoría |
| **Responsable de taller** | Operación, cierre de informes y liberación de piezas |
| **Técnico** | Inspección, informes y carga de documentos |
| **Recepción** | Ingreso de piezas y despacho |

Un usuario interno de Odessa (sin organización asignada) alcanza los datos de todas las organizaciones. Un usuario de cliente queda confinado a la suya.

## Recepción de equipos

En *Taller → Recepción de equipos*.

**Los contadores superiores** son enlaces: pendientes, fuera de plazo, urgentes, liberadas y despachadas.

**Las pestañas** filtran por etapa. A diferencia del sistema anterior, todas viven en la misma tabla y se comparan sin cambiar de pantalla.

**Etapas:**
1. *En taller* — ingresada, sin liberar.
2. *Liberada* — inspección validada, aún en planta.
3. *Despachada* — entregada al cliente.

**Liberar** (responsable de taller) confirma que la inspección está validada. **Despachar** (recepción) registra la entrega. No se puede despachar sin liberar antes, ni repetir un hito.

Ambas acciones piden confirmación y registran automáticamente quién y cuándo.

Las filas fuera de plazo —pasada la fecha comprometida sin liberar— aparecen marcadas.

## Cerrar un informe FMR

Requiere el permiso *finalizar informes*.

En el detalle del informe, botón **Finalizar**. Antes de confirmar, revise que cada pieza tenga el dictamen correcto: **al finalizar, el informe deja de ser editable.**

Al confirmar, el sistema:
1. Calcula el vencimiento de cada línea con dictamen, si no se fijó a mano: fecha del informe más la vigencia del servicio.
2. Recalcula el estado de todas las piezas incluidas.
3. Registra la operación en la auditoría.

Todo ocurre dentro de una transacción: o se finaliza entero, o no se finaliza.

Un informe no se puede finalizar sin piezas asociadas, ni dos veces.

## Usuarios

En *Administración → Usuarios*.

**Alta.** Botón *Nuevo usuario*. Complete nombre, correo y roles. **No se elige contraseña**: se envía un enlace para que el usuario defina la suya.

El campo *Organización* determina el alcance: vacío crea personal interno de Odessa; con valor, ata al usuario a esa empresa.

**Roles.** Un usuario sólo puede otorgar roles de su propio alcance. Un administrador de cliente no puede otorgar roles internos ni crear usuarios en otra organización: el servidor lo impide aunque se manipule el formulario.

**Baja.** *Desactivar* es una baja lógica: el usuario pierde el acceso en su siguiente petición, pero se conserva todo su historial de auditoría. Nadie puede desactivarse a sí mismo.

## Solicitudes de acceso

En *Administración → Solicitudes de acceso*, agrupadas por estado.

Al **aprobar**, seleccione la organización a la que pertenece el solicitante. La aprobación registra la decisión pero **no crea el usuario**: eso se hace desde *Usuarios*, deliberadamente, para que quede claro quién otorgó qué rol.

Al **rechazar**, puede dejar una nota interna. Nunca se informa al solicitante de si su correo ya existía en el sistema.

## Auditoría

En *Administración → Auditoría*. Registro de sólo lectura; no se puede editar ni borrar desde la aplicación.

Se registra:

| Acción | Cuándo |
|---|---|
| `auth.login` / `auth.logout` | Cada sesión |
| `auth.password_reset` | Restablecimiento de contraseña |
| `asset.viewed` | Consulta de la ficha de una pieza |
| `document.downloaded` | Cada descarga |
| `inspection_report.finalized` | Cierre de un FMR |
| `reception.released` / `reception.dispatched` | Hitos de taller |
| `user.created` / `user.updated` / `user.deactivated` | Gestión de usuarios |
| `account_request.created` / `account_request.reviewed` | Solicitudes de acceso |

Cada evento guarda usuario, acción, entidad, fecha, IP y agente. Los cambios se despliegan con *Ver cambios*.

**Las contraseñas, tokens y secretos nunca llegan al registro**: se filtran antes de guardarse.

Los administradores de cliente sólo ven la auditoría de su propia organización.

## Configuración operativa

En `.env`:

```dotenv
ODESSA_EXPIRY_WARNING_DAYS=60
ODESSA_DEFAULT_VALIDITY_DAYS=365
```

**`ODESSA_EXPIRY_WARNING_DAYS`** define cuántos días antes del vencimiento una pieza pasa a *Próximo a vencer*.

**`ODESSA_DEFAULT_VALIDITY_DAYS`** es la vigencia por defecto cuando el servicio no define la suya.

La vigencia por servicio se configura en la tabla `services`, columna `validity_days`.

## La columna que gobierna toda la aptitud

En `services`, la columna **`determines_fitness`** decide si un servicio emite dictamen Apto/No Apto y renueva la vigencia, o si sólo queda registrado en la bitácora.

Marcar mal esta columna hace que una intervención operativa renueve certificaciones que no debería, o que una certificación real no actualice el estado. **Revísela cada vez que se dé de alta un servicio nuevo.**

Tras cambiarla, recalcule el inventario afectado:

```bash
php artisan tinker --execute="App\Models\Asset::withoutGlobalScope('organization')->chunkById(200, fn(\$c) => \$c->each(fn(\$a) => app(App\Actions\RecalculateAssetStatus::class)(\$a)));"
```

## Mantenimiento

```bash
php artisan optimize:clear
```

```bash
php artisan queue:work
```

```bash
tail -f storage/logs/laravel.log
```

Copias de seguridad: ver [`backup-y-restauracion.md`](backup-y-restauracion.md).
