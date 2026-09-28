# Bloque 2 · Subproyecto 2 — Operaciones por empresa y operación especial

Fecha: 27/09/2026. Diseño y plan en un solo documento: el usuario pidió ejecutar el bloque completo y los supuestos se marcan como tales para revisarlos con el negocio.

## Qué pidió el negocio

- «Operaciones por empresa, es correcta la definición: Odessa lo define por cada cliente». Cada operación tiene título, días de vigencia y una descripción breve de en qué consiste el trabajo, «porque quizás para un cliente tenga un nombre y para otro sea otro».
- Al crear un envío y cargar los equipos, el cliente elige **por cada equipo** qué operaciones de las que tiene autorizadas aplican (ej. uno torque, otro torque y prueba hidráulica): «cada equipo tiene su solicitud».
- **Operación especial**: algo que no está establecido para esa empresa. Requiere una descripción cuando se solicita. El cliente tiene las dos opciones.
- Lo que se pidió tiene que verse claro en **Envíos al taller** («debería salir toda la info»).

## Decisiones

| Tema | Decisión |
|---|---|
| Catálogo | Sigue siendo **uno solo** (`services`), con sus reglas: si dictamina aptitud y el tiempo de taller. Se le suma `description` (en qué consiste). |
| Por empresa | Pivote `organization_service`: habilita la operación para esa empresa y permite **pisar** nombre, descripción y días de vigencia. Si un campo queda vacío, vale el del catálogo. |
| Quién lo define | Permiso `organizations.manage` (superadministrador), desde la pantalla de Organizaciones. *Supuesto*: es configuración comercial, no del taller. |
| Pedido por equipo | Pivote `shipment_item_operations` (equipo del envío ↔ operación) + `shipment_items.special_operation` (texto). Cada equipo exige **al menos una** operación habilitada o una operación especial descripta. |
| Validación | Sólo se aceptan operaciones habilitadas para la empresa del envío y activas en el catálogo. Una operación de otra empresa o deshabilitada da error de validación. |
| Vigencia | Al finalizar un FMR, los días de vigencia salen primero del **ajuste de la empresa** para esa operación, después del catálogo, después del valor por defecto. |
| Nombres | El cliente ve el nombre de su empresa. El personal de Odessa ve ese nombre seguido del código interno, así reconoce la operación aunque se llame distinto en cada cliente. |
| Envíos viejos | Los equipos ya cargados sin operaciones siguen siendo válidos y se muestran como «Sin operaciones indicadas». La exigencia vale para lo que se cargue de acá en adelante. |
| Taller | La fila de la recepción muestra qué pidió el cliente para ese equipo (operaciones y operación especial). |
| Descripción del pedido | El campo «Observaciones» del envío pasa a llamarse «Qué pide (descripción breve)» y se muestra destacado en el detalle del envío. |

## Datos

- `services.description` — text, nullable.
- `organization_service` — `id`, `organization_id` (FK, cascade), `service_id` (FK, cascade), `name` (nullable, 120), `description` (text nullable), `validity_days` (smallint nullable), timestamps. Tiene índice único `(organization_id, service_id)`.
- `shipment_item_operations` — `id`, `shipment_item_id` (FK, cascade), `service_id` (FK, restrict), índice único del par.
- `shipment_items.special_operation` — text, nullable.

## Tareas

1. **Migración y modelos.** `Organization::operations()` es `belongsToMany(Service)` con pivot `name, description, validity_days`. `ShipmentItem::operations()` es `belongsToMany(Service, 'shipment_item_operations')`. `Reception::shipmentItem()` es `hasOne(ShipmentItem)`. `Service::clientName()` / `clientDescription()` usan el pivot si está cargado. Se agrega `description` a los fillable de `Service`.
2. **Catálogo global**: `OperationController` valida y devuelve `description`, y `admin/operations.tsx` suma el campo.
3. **Por empresa**:
   - `GET /administracion/organizaciones/{organization}/operaciones` devuelve el catálogo activo con el estado y los ajustes de esa empresa, en JSON para el diálogo.
   - `PUT` a la misma ruta hace `sync`.
   - En `admin/organizations.tsx`, el botón «Operaciones» abre un diálogo con una fila por operación: casilla de habilitada y los campos de nombre, descripción y vigencia.
   - Todo queda registrado en la bitácora (`organization.operations_updated`).
4. **Pedido por equipo**:
   - `ShipmentController::addItem` valida `operations[]` y `special_operation` y exige al menos una de las dos.
   - `show` entrega `operationOptions` (las habilitadas, con el nombre de la empresa) y, por cada equipo, `operations[]` y `specialOperation`.
   - `shipments/show.tsx`:
     - En el alta del equipo: casillas con las operaciones habilitadas y su descripción, más la casilla «Operación especial», que despliega un texto obligatorio.
     - En la tabla, la columna «Solicitud».
5. **Taller**: `ReceptionController::index` carga `shipmentItem.operations` y la organización con sus operaciones, y entrega `requested` (lista de nombres) y `specialOperation`. Los dos se muestran bajo el nombre de la pieza.
6. **Vigencia**: `FinalizeInspectionReport` resuelve los días con el ajuste de la empresa.
7. **Textos**: «Observaciones» del envío pasa a «Qué pide (descripción breve)», y ese texto se muestra en el detalle.
8. **Pruebas** (`tests/Feature/OrganizationOperationsTest.php`):
   - el superadmin habilita operaciones con nombre y vigencia propios, y el taller recibe 403;
   - el cliente agrega un equipo con operaciones habilitadas;
   - una operación no habilitada da error;
   - sin operación ni operación especial da error;
   - la operación especial sola se acepta;
   - el cliente ve el nombre de su empresa;
   - el FMR usa la vigencia de la empresa;
   - la fila del taller trae lo pedido;
   - la descripción del catálogo se guarda.
9. **Documentación**: bitácora del bloque 2.
