# Bloque 2 · Subproyecto 1 — Flujo del taller

Fecha: 27/09/2026 · Estado: aprobado en conversación, pendiente de revisión escrita.

El bloque 2 del negocio se dividió en cuatro subproyectos con spec propio:
**1. flujo del taller (este)**, 2. operaciones por empresa y operación especial,
3. permisos por usuario ("el jefe"), 4. informes por organización.
El renombre de "FMR" quedó pendiente del negocio y está fuera de alcance.

## Qué pidió el negocio

- Al enviar, el envío queda **Pendiente de recepción**. Cuando llega, el taller
  **da recepción** (confirma lo declarado) y los equipos pasan a **En taller**,
  en la cola de **Pendientes**.
- **En taller** abarca todo lo que no se despachó.
- Colas: Pendientes → En proceso → Aprobar / Rechazar.
- Una rechazada a la que se le consultó al cliente **sale de Rechazadas** y
  queda esperando.
- La consulta se manda a **todos los correos de la organización**, con un
  enlace que lleva a responderla.
- En Rechazadas hay una salida nueva: **sin solución** → se despacha.
- Si el cliente autoriza, la recepción va a la cola **Reparación**, que tiene
  **Iniciar** (como Pendientes) y vuelve a **En proceso** → Aprobar → Listas
  para despachar.

## Decisiones tomadas

| Tema | Decisión |
|---|---|
| Modelo | Hitos nuevos en `receptions`. La etapa se sigue derivando de los hechos; no hay columna `stage`. |
| Reparación rechazada | **Una sola reparación.** Si se rechaza, vuelve a Rechazadas como "reparación fallida" y la única salida es "Sin solución". No hay segunda consulta al cliente. |
| Enlace del mail | Abre el panel con login y va directo a **esa** recepción. La respuesta queda como canal `panel`. Sin enlaces que autoricen sin sesión. |
| Destinatarios | Todos los usuarios activos de la organización (Consulta incluidos) + `organizations.contact_email`, sin duplicados. |
| Sin solución | La decide el taller sin consultar al cliente. Motivo obligatorio y aviso a la organización (mismos destinatarios). |
| Dar recepción | Es el "autorizar envío" de hoy con otro nombre. La lógica no cambia. Se mantiene "Rechazar envío". |

## Recorrido

```
ENVÍO   Borrador → Pendiente de recepción ─[Dar recepción]→ Recibido   (o Rechazado)

EN TALLER  (todo lo que no se despachó)
 Pendientes ─[Iniciar]→ En proceso ─[Aprobar]→ Listas para despachar ─[Despachar]→ Despachada
                            │
                        [Rechazar]
                            ↓
                       Rechazadas ─[Consultar]→ Esperando cliente ─ NO ─→ Listas para despachar (no apto)
                            │                          │
                     [Sin solución]                    SÍ
                            ↓                          ↓
         Listas para despachar (no apto)          Reparación ─[Iniciar]→ En proceso (reparación)
                                                                           ├─[Aprobar]→ Listas para despachar
                                                                           └─[Rechazar]→ Rechazadas (reparación fallida;
                                                                                          sólo "Sin solución")
```

## Datos

Migración nueva sobre `receptions`, con dos hitos de tres columnas cada uno:

- `repair_failed_at` (datetime), `repair_failed_by` (FK users, null on delete), `repair_failure_notes` (text): se rechazó la reparación.
- `unrepairable_at` (datetime), `unrepairable_by` (FK users, null on delete), `unrepairable_notes` (text): el taller la marcó sin solución.

Los nombres internos del envío (`authorized`, `SHIPMENTS_AUTHORIZE`,
`AuthorizeShipment`) no cambian. Sólo cambia lo que ve el usuario, así que no
hay migración de datos.

## Etapas — `Reception::stage()`

Orden de evaluación (gana el hito más avanzado):

1. despachada + (cliente no autorizó **o** sin solución) → `returned_unrepaired` "Despachada sin reparar"
2. despachada → `dispatched`
3. `unrepairable_at` → `unrepairable` "Sin solución · a devolver" (nueva)
4. `repair_failed_at` → `repair_failed` "Rechazada · reparación fallida" (nueva)
5. `repaired_at` → `repaired`
6. `repair_started_at` → `in_repair` "En proceso · reparación" (cambia la etiqueta)
7. cliente no autorizó → `repair_declined`
8. cliente autorizó → `repair_authorized` "Reparación" (cambia la etiqueta; descripción: autorizada por el cliente, falta iniciarla)
9. consulta enviada → `awaiting_client` "Esperando al cliente"
10. rechazada / aprobada / en proceso / pendiente, como hoy.

`stageTone()`: `unrepairable` y `repair_failed` → `danger`.

## Colas (scopes y pestañas)

| Pestaña (`stage=`) | Scope | Contenido |
|---|---|---|
| En taller (`in_workshop`, por defecto) | `inWorkshop` | Sin despachar. Sin cambios. |
| Pendientes (`pending`) | `pending` | Sin cambios. |
| En proceso (`in_progress`) | `inProgress` | Trabajo empezado y sin dictamen **o** reparación iniciada sin terminar ni fallar. |
| Rechazadas (`rejected`) | `rejected` | `outcome = rejected`, sin despachar, sin `unrepairable_at`, y (sin consulta enviada **o** con reparación fallida). |
| Esperando cliente (`awaiting_client`) | `awaitingClient` | Sin cambios. |
| Reparación (`repair`) | `repairQueue` (nuevo) | Cliente autorizó, `repair_started_at` nulo, sin despachar. Reemplaza a la pestaña `in_repair`. |
| Listas para despachar (`ready`) | `readyToDispatch` | Suma `unrepairable_at` no nulo. |
| Despachadas (`dispatched`) | `dispatched` | Sin cambios. |

`scopeInRepair` desaparece: su contenido pasa a En proceso.
`isDispatchable()` suma `unrepairable_at !== null` y **tiene que coincidir con
`scopeReadyToDispatch()`**.

`stageCounts()` en `ReceptionController` se ajusta a las mismas claves.

## Acciones — `App\Actions\ChangeReceptionStage`

Nuevas:

- `failRepair(Reception, string $notes)`: exige reparación iniciada, sin
  terminar y sin fallar. El motivo es obligatorio. Registra `repair_failed_*`
  y `reception.repair_failed` en la bitácora.
- `markUnrepairable(Reception, string $notes)`: exige etapa `rejected` o
  `repair_failed`. El motivo es obligatorio. Dentro de la transacción registra
  `unrepairable_*` y llama a `RecalculateAssetStatus`. Después, fuera de la
  transacción, deja `reception.unrepairable` en la bitácora y avisa.

Guardas nuevas en las existentes:

- `requestRepairAuthorization`: rechaza si hay `unrepairable_at`. La
  reparación fallida ya queda bloqueada porque la consulta se envió antes.
- `markRepaired`: rechaza si hay `repair_failed_at`.
- `start` y `resolve`: sin cambios.

Rutas nuevas (grupo del taller, `authorize('release')`, igual que `reparada`):

- `POST /taller/recepciones/{reception}/reparacion-fallida` → `failRepair`
- `POST /taller/recepciones/{reception}/sin-solucion` → `markUnrepairable`

## Aptitud — `RecalculateAssetStatus`

`wasReturnedUnrepairedAfter()` también cuenta las recepciones con
`unrepairable_at` (comparando contra `unrepairable_at` cuando hay `$since`).
Así "sin solución" deja al equipo **no apto y sin vencimiento**, igual que el
rechazo del cliente. La columna de estado se sigue escribiendo sólo desde ahí.

## Avisos

- **Destinatarios**: un método privado en `ChangeReceptionStage` que devuelve
  los usuarios activos de la organización (sin filtrar por permiso) y, aparte,
  `contact_email` si no coincide con el de ningún usuario. A los usuarios se
  les manda con `Notification::send` y al correo de contacto con
  `Notification::route('mail', …)`.
- **Trampa**: un `AnonymousNotifiable` no tiene canal `database`. El `via()`
  de las dos notificaciones devuelve sólo `mail` cuando el destinatario es
  anónimo.
- `RepairAuthorizationRequested`: el enlace pasa a
  `/taller/recepciones?stage=all&reception=<public_id>` (mail y `toArray`).
- Nueva `ReceptionDeclaredUnrepairable` (mail + base de datos, encolada): nombra
  el equipo y la serie, da el motivo, aclara que vuelve **no apto** y que tiene
  que recertificarse antes de volver a servicio, y lleva el mismo enlace
  directo.
- Se mantiene el criterio actual: los avisos salen fuera de la transacción, así
  que si el correo falla, el hito igual queda registrado.

## Enlace directo

`ReceptionController::index` suma el filtro `reception` (public_id exacto) a
`TableQuery::filters`. El scope de organización ya hace que una recepción ajena
no aparezca. El login ya usa `redirect()->intended()`, así que después de
iniciar sesión el usuario vuelve al enlace.

## Pantalla `workshop/receptions.tsx`

- Pestañas según la tabla de colas.
- Reparación: botón **Iniciar** (llama a `reparar`, que es `sendToRepair`).
- En proceso, fila en etapa `in_repair`: **Aprobar** (`reparada`) y
  **Rechazar** (diálogo con motivo → `reparacion-fallida`).
- Rechazadas:
  - **Consultar al cliente**, oculto en etapa `repair_failed`.
  - **Sin solución**, diálogo con motivo obligatorio que advierte que el
    equipo vuelve no apto y que se le avisa al cliente.
- Cuando la URL trae `reception`, un aviso arriba de la tabla con "Ver todas"
  para limpiar el filtro.
- Se actualizan los tipos en `resources/js/types/index.ts`.

## Envío (sólo textos)

- `Shipment::STATUS_LABELS`: `submitted` → "Pendiente de recepción",
  `authorized` → "Recibido".
- Botón y diálogo: "Autorizar" → "**Dar recepción**". Flash, mensajes de
  validación de `AuthorizeShipment` y descripción de la bitácora ("Envío X
  recibido; se crearon N recepciones").
- `resources/js/pages/shipments/*`: los textos que dicen autorizar/autorizado.

## Documentación

- `docs/flujos-de-negocio.md`: el recorrido nuevo.
- `docs/cambios-devolucion-negocio.md`: la entrada del bloque 2, subproyecto 1.

## Pruebas (`tests/Feature`)

Tienen que seguir en verde las 243 actuales, ajustando las que dependían de las
etiquetas o de la pestaña `in_repair`
(`ReceptionWorkflowTest`, `RepairAuthorizationTest`). Nuevas:

1. Recorrido completo con reparación: rechazar → consultar → autorizar →
   Reparación → Iniciar → aparece en En proceso → Aprobar → Listas para despachar.
2. Reparación fallida → Rechazadas → volver a consultar al cliente da error de
   validación → Sin solución → despachar → equipo no apto y sin vencimiento.
3. Sin solución directo desde el primer rechazo, con motivo obligatorio.
4. No se puede marcar sin solución fuera de Rechazadas (esperando cliente,
   Reparación, aprobada).
5. Esperando cliente no aparece en Rechazadas.
6. Destinatarios de la consulta y de "sin solución": incluyen a Consulta y al
   correo de contacto, sin duplicar si ya es de un usuario, y no fallan con el
   canal `database` para el destinatario anónimo.
7. `?reception=` de otra organización no devuelve la fila.
8. `isDispatchable()` coincide con `readyToDispatch()` en todas las etapas.
9. Envío: la etiqueta dice "Pendiente de recepción"; "Dar recepción" crea las
   recepciones como hoy.

## Fuera de alcance

- Operaciones por equipo y "lo que pidió el cliente" en el envío (subproyecto 2).
- Permisos del jefe; quién puede autorizar reparaciones (subproyecto 3).
- Recepción parcial de un envío.
- Renombrar FMR.
