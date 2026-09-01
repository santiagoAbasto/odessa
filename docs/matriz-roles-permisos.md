# Matriz de roles y permisos

> **Actualizado el 12/08/2026** con la definición confirmada por el negocio. La versión anterior manejaba ocho roles derivados de la auditoría; el negocio confirmó que son cuatro.

## Los cuatro roles

| Rol | Ámbito | Qué hace |
|---|---|---|
| **Superadministrador** | Global | Todo, sin restricciones |
| **Taller** | Todas las organizaciones | Ingresa y edita la base de datos; autoriza los envíos de los clientes |
| **Cliente · Operador** | Su organización | Sólo lectura, **más** la pre-carga de los equipos que envía |
| **Cliente · Consulta** | Su organización | Sólo lectura |

### Qué cambió respecto de la auditoría

Durante la auditoría se habían supuesto roles internos separados (técnico, recepción y responsable de taller). El negocio confirmó que **el taller opera como un único perfil**: la separación de funciones entre liberar y despachar queda eliminada.

Esto resuelve el supuesto **S3** que estaba abierto.

También se eliminó el rol *Cliente · Administrador*: la administración de usuarios queda reservada al superadministrador.

## Matriz completa

Leyenda: ● permiso otorgado · — sin permiso

| Permiso | Superadmin | Taller | Cli. Operador | Cli. Consulta |
|---|:--:|:--:|:--:|:--:|
| `assets.view` | ● | ● | ● | ● |
| `assets.create` | ● | ● | — | — |
| `assets.update` | ● | ● | — | — |
| `assets.delete` | ● | — | — | — |
| `assets.export` | ● | ● | ● | ● |
| `sets.view` | ● | ● | ● | ● |
| `sets.manage` | ● | ● | — | — |
| `reports.view` | ● | ● | ● | ● |
| `reports.create` | ● | ● | — | — |
| `reports.update` | ● | ● | — | — |
| `reports.finalize` | ● | ● | — | — |
| `reports.cancel` | ● | ● | — | — |
| `reports.export` | ● | ● | ● | ● |
| `documents.view` | ● | ● | ● | ● |
| `documents.upload` | ● | ● | — | — |
| `documents.delete` | ● | ● | — | — |
| `receptions.view` | ● | ● | — | — |
| `receptions.create` | ● | ● | — | — |
| `receptions.update` | ● | ● | — | — |
| `receptions.release` | ● | ● | — | — |
| `receptions.dispatch` | ● | ● | — | — |
| `shipments.view` | ● | ● | ● | ● |
| `shipments.create` | ● | — | ● | — |
| `shipments.authorize` | ● | ● | — | — |
| `comments.view` | ● | ● | ● | ● |
| `comments.create` | ● | ● | ● | — |
| `comments.internal` | ● | ● | — | — |
| `users.view` | ● | — | — | — |
| `users.manage` | ● | — | — | — |
| `roles.manage` | ● | — | — | — |
| `organizations.view` | ● | ● | — | — |
| `organizations.manage` | ● | — | — | — |
| `account_requests.manage` | ● | — | — | — |
| `catalogs.manage` | ● | ● | — | — |
| `audit.view` | ● | ● | — | — |
| `settings.manage` | ● | — | — | — |

## La única diferencia entre los dos roles de cliente

`shipments.create`. Ambos consultan exactamente lo mismo; sólo el operador puede declarar los equipos que envía al taller.

## Dónde se aplica cada permiso

El permiso se comprueba en **cuatro capas independientes**:

1. **Ruta** — el middleware corta antes de llegar al controlador.
2. **Policy** — comprueba permiso **y** pertenencia a la organización.
3. **Consulta** — el scope global confina los resultados a la organización del usuario.
4. **Interfaz** — la navegación y los botones se pintan según los permisos.

La capa 4 es **sólo presentación**. Las pruebas verifican que llegar por URL directa a una acción sin permiso devuelve 403, con el botón oculto o no.

## Prevención de escalada de privilegios

- Un usuario sólo puede otorgar roles de su propio alcance; los internos se descartan en el servidor.
- Un usuario de cliente no puede crear usuarios en otra organización: el identificador del payload se ignora.
- `roles.manage` es exclusivo del superadministrador.

### Invariantes que no dependen de permisos

**Nadie puede desactivar su propia cuenta.** Esta comprobación vive en el controlador, no en la Policy.

El motivo importa: el superadministrador atraviesa todas las Policies mediante `Gate::before`. Si la guarda dependiera de la Policy, justamente la única cuenta capaz de dejar el sistema sin administrador sería la que podría saltársela. Está cubierto por prueba.

## Correspondencia con el sistema anterior

| Rol anterior (supuesto) | Rol confirmado |
|---|---|
| Administrador de Odessa | Superadministrador |
| Responsable de taller | Taller |
| Técnico | Taller |
| Recepción | Taller |
| Cliente · Administrador | *(eliminado)* |
| Cliente · Operador | Cliente · Operador |
| Cliente · Consulta | Cliente · Consulta |
