# Manual del administrador

Para personal de Odessa Oil con acceso al taller o a la administración.

## Roles

Son cuatro. Los roles internos separados que se supusieron en la auditoría (técnico, recepción, responsable de taller) se descartaron: el negocio confirmó que el taller hace todo su circuito sin separación de funciones.

| Rol | Alcance |
|---|---|
| **Superadministrador** | Todo: usuarios, roles, organizaciones, productos y solicitudes de acceso |
| **Taller** | Opera la base: equipos, envíos, gestión de equipos en el taller, informes, documentos, operaciones y auditoría. No administra usuarios ni roles |
| **Cliente · Operador** | Consulta lo de su empresa, carga envíos al taller, comenta y decide si autoriza una reparación |
| **Cliente · Consulta** | Sólo lectura de lo de su empresa, incluido dónde están sus equipos en el taller |

Un usuario interno de Odessa (sin organización asignada) alcanza los datos de todas las organizaciones. Un usuario de cliente queda confinado a la suya.

**Permisos sueltos.** El superadministrador puede sumarle a un usuario cliente puntual, por encima de su rol, sólo tres cosas: cargar envíos, autorizar reparaciones y comentar. Nunca un permiso de taller ni de administración, para que dar un permiso no sea una forma de escalar.

## Gestión de equipos en el taller

En *Taller → Gestión de equipos*. Cada fila es el paso de un equipo por el taller (una *recepción*). Nace al autorizar un envío del cliente o por un ingreso directo, sin envío previo.

**Las pestañas** filtran por etapa, todas sobre la misma tabla. Los contadores superiores son enlaces.

**Etapas:**
1. *Pendiente*: recibida, todavía no se empezó.
2. *En proceso*: el taller la está trabajando.
3. *Aprobada*: el trabajo se aprobó. Lista para despachar.
4. *Despachada*: se envió al cliente.

Si el trabajo se **rechaza**, el motivo es obligatorio y hay dos salidas:

- **Consultar al cliente** si autoriza la reparación, que se le cobra a él. Se consulta una sola vez y la etapa pasa a *Esperando al cliente*. Si autoriza, el equipo vuelve a *Pendiente* y se re-trabaja. Si no, queda *Sin reparar · a devolver*.
- **Marcarla sin solución**, con motivo obligatorio y aviso a la empresa. Queda *Sin solución · a devolver*.

En los dos casos se despacha como *Despachada sin reparar*, y el equipo queda **No apto** y sin vencimiento aunque su último informe diga Apto. No se descarta: es del cliente.

**La respuesta del cliente** la carga él desde el panel o, si contestó por correo, teléfono o WhatsApp, la transcribe el taller. En ese caso la evidencia es obligatoria: es lo que justifica el cobro si después se discute. El canal no se elige, se deduce de quién la carga.

No se salta un hito ni se repite uno cumplido, y no se puede despachar un rechazado sobre el que nadie decidió. Cada acción pide confirmación y registra quién y cuándo. Las filas fuera de plazo —pasada la fecha comprometida— aparecen marcadas.

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

Sólo el superadministrador.

**Alta.** Botón *Nuevo usuario*. Complete nombre, correo y roles. **No se elige contraseña.** El usuario recibe por dos caminos:

- **Un correo de bienvenida** con un enlace para elegir su contraseña. Vale 7 días y sirve una sola vez. El correo nunca lleva la contraseña.
- **Una contraseña temporal**, que se muestra una única vez al crear el usuario, con un botón para copiarla y mandarla por WhatsApp. Al entrar con ella, el sistema le exige elegir una propia.

Usar cualquiera de los dos anula el otro.

**Restablecer acceso** genera una contraseña temporal nueva, cierra sus sesiones y anula cualquier enlace pendiente. No manda correo: la temporal se le pasa por WhatsApp.

El campo *Organización* determina el alcance: vacío crea personal interno de Odessa; con valor, ata al usuario a esa empresa.

**Roles.** Un usuario de cliente sólo puede recibir roles de cliente: el servidor rechaza un rol interno aunque se manipule el formulario.

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
| `auth.password_reset` / `auth.welcome_accepted` / `auth.password_changed` | Contraseña elegida por recuperación, por bienvenida o tras una temporal |
| `asset.viewed` / `asset.created` / `asset.updated` / `asset.exported` | Equipos |
| `document.uploaded` / `document.downloaded` / `document.deleted` | Adjuntos, certificados incluidos |
| `report.created` / `inspection_report.finalized` | Informes |
| `shipment.created` / `shipment.submitted` / `shipment.authorized` / `shipment.rejected` | Envíos al taller |
| `reception.started` / `reception.approved` / `reception.rejected` / `reception.dispatched` | Hitos de taller |
| `reception.repair_requested` / `reception.repair_approved` / `reception.repair_declined` / `reception.unrepairable` | Consulta de reparación y sin solución |
| `user.created` / `user.updated` / `user.deactivated` / `user.access_reset` / `user.permissions_updated` | Gestión de usuarios |
| `organization.*` / `product.*` / `operation.*` | Catálogos y empresas |
| `account_request.created` / `account_request.reviewed` | Solicitudes de acceso |

Cada evento guarda usuario, acción, entidad, fecha, IP y agente. Los cambios se despliegan con *Ver cambios*.

**Las contraseñas, tokens y secretos nunca llegan al registro**: se filtran antes de guardarse.

La ven el superadministrador y el taller. Los clientes no tienen acceso.

## Configuración operativa

En `.env`:

```dotenv
ODESSA_EXPIRY_WARNING_DAYS=60
ODESSA_DEFAULT_VALIDITY_DAYS=365
ODESSA_TURNAROUND_DAYS=30
ODESSA_SAFETY_MARGIN_DAYS=15
ODESSA_RECERT_NOTICE_DAYS=30
```

**`ODESSA_EXPIRY_WARNING_DAYS`** define cuántos días antes del vencimiento una pieza pasa a *Próximo a vencer*.

**`ODESSA_DEFAULT_VALIDITY_DAYS`** es la vigencia por defecto cuando el servicio no define la suya.

**Los avisos de recertificación** no se guían por el vencimiento sino por la fecha límite de envío: `vencimiento − días de taller − margen`. Un equipo puede estar todavía certificado y ya fuera de tiempo para mandarlo. `ODESSA_TURNAROUND_DAYS` es lo que tarda el taller (cada producto o operación puede pisarlo), `ODESSA_SAFETY_MARGIN_DAYS` cubre transporte e imprevistos, y `ODESSA_RECERT_NOTICE_DAYS` es con cuánta anticipación a esa fecha se empieza a avisar. Los avisos salen a las 07:00, una vez por equipo y vencimiento, y no se mandan si el equipo ya está en el taller.

Tras cambiar cualquiera de estos valores en el servidor: `php artisan config:cache` y vaciar el opcache (ver `deploy/elserver/NOTAS-SERVIDOR.md`).

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
tail -f storage/logs/laravel.log
```

Los correos no salen en el momento: esperan en una cola que vacía la tarea programada, cada minuto. En producción esa tarea es la ruta `/cron` que llama el panel del hosting; si los correos no llegan, lo primero es mirar `storage/logs/cron.log` (ver `deploy/elserver/NOTAS-SERVIDOR.md`, punto 6). En desarrollo, la cola se vacía a mano:

```bash
php artisan queue:work --stop-when-empty
```

Copias de seguridad: ver [`backup-y-restauracion.md`](backup-y-restauracion.md).
