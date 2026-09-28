# Bloque 2 · Subproyecto 3 — Permisos por usuario

Fecha: 27/09/2026. Diseño y plan en un solo documento. Los supuestos van marcados como tales para confirmarlos con el negocio.

## Qué pidió el negocio

- «Cliente Consulta sería como un auditor»: ve qué trabajos están en curso y pendientes y audita el progreso de sus equipos, pero no puede generar envíos. **Así funciona hoy**, no cambia nada.
- «Rol cliente para cualquiera de los dos, poder darles de alta permisos». El ejemplo: el superadmin crea una organización y sus usuarios; uno es un Cliente-Operador normal, y su jefe tiene ciertos permisos más.
- «Podría cargar a base de datos»: a un usuario de consulta se le puede habilitar puntualmente la carga.

## Decisiones

| Tema | Decisión |
|---|---|
| Mecanismo | Los dos roles de cliente siguen siendo la base. El superadmin puede sumarle a un usuario cliente **permisos adicionales** puntuales. Se guardan como permisos directos de Spatie, que el sistema ya lee (`getAllPermissions`) sin tocar Policies ni menús. |
| Qué se puede dar | Una lista cerrada, pensada para clientes: cargar envíos al taller, autorizar reparaciones y comentar en la bitácora. Nunca un permiso del taller o de administración: la lista la valida el servidor. |
| Quién los da | Sólo quien administra roles (`roles.manage`, el superadmin), igual que los roles. |
| Personal interno | No lleva permisos adicionales: si llegan, se ignoran y los que tuviera se limpian. |
| Lo que ya da el rol | Se muestra tildado y bloqueado («incluido en el rol»). No se guarda como adicional. |
| El «jefe» | *Pendiente del negocio.* Hoy un Operador ya tiene todo lo que un cliente puede tener, así que el jefe no tiene nada que sumar. Para que se distinga hay que definir qué **no** puede el operador normal. Propuesta: autorizar reparaciones, porque se cobran. Con el mecanismo hecho, es un cambio de una línea en la matriz más una migración que se lo dé a los operadores actuales. |

## Tareas

1. `Permissions::clientGrantable()` devuelve permiso → etiqueta.
2. `StoreUserRequest` y `UpdateUserRequest`: `extra_permissions` (arreglo) y `extra_permissions.*` (`Rule::in` sobre la lista).
3. `UserController`:
   - `syncExtraPermissions()` se llama desde `store` y desde `update` cuando el campo viene. Queda en la bitácora como `user.permissions_updated` cuando cambia algo.
   - `index` suma `extraPermissions` por usuario (con eager load de `permissions`), `grantablePermissions`, `roleGrants` (rol → permisos de la lista que ya incluye) y `can.grantPermissions`.
4. `admin/users/index.tsx`:
   - En el diálogo, la sección «Permisos adicionales», sólo para usuarios cliente y sólo si el actor puede otorgarlos.
   - En la tabla, una insignia por cada permiso adicional.
5. Pruebas (`tests/Feature/UserExtraPermissionsTest.php`):
   - dar «cargar envíos» a un usuario Consulta le permite crear un envío;
   - un permiso fuera de la lista da error;
   - al personal interno no se le guarda nada;
   - quitarlo revoca;
   - el listado lo muestra.
6. Bitácora del bloque 2.
