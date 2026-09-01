# Plan de migración de datos

> **Estado:** diseñado, **no ejecutado**. Requiere acceso de sólo lectura a la base de datos y al almacén de archivos del sistema actual, que no formó parte de la auditoría autorizada.

## Correspondencia entre esquemas

| Sistema actual (inferido) | Nuevo sistema | Notas |
|---|---|---|
| `Clientes` | `organizations` | `short_code` desde la abreviatura usada en los informes |
| `Usuarios` | `users` | **Las contraseñas no se migran**: se fuerza restablecimiento |
| `Partes` | `catalog_items` (`kind='part'`) | Deduplicar por `descripcion` + `nro_parte` |
| `Genericos` | `catalog_items` (`kind='generic'`) | |
| `ClientesPartes` | `assets` (`kind='part'`) | Se genera `public_id` UUID nuevo |
| `ClientesGenericos` | `assets` (`kind='generic'`) | |
| `ClientesPartesVencimientos` | `inspection_report_asset` | Un registro de vencimiento = una línea de dictamen |
| `ClientesGenericosVencimientos` | `inspection_report_asset` | El `precinto` va a la columna `seal` |
| `InspeccionReportes` | `inspection_reports` | |
| `InspeccionReportesArchivos` | `inspection_report_files` | Los archivos se copian y **se renombran** |
| `Sets` | `asset_sets` + `asset_set_asset` | |
| `RecepcionEquipos` | `receptions` | `liberado`/`despachado` → marcas de tiempo |
| *(no existe)* | `services` | Extraer de las cadenas `NOMBRE (OIxx)` |
| *(no existe)* | `activity_logs` | No hay historial previo que migrar |

## Transformaciones que requieren decisión

**1. Códigos de estado.** `O` → `fit`, `W` → `expiring`, `D` → `unfit`. Se conserva la equivalencia en `AssetStatus::fromLegacyCode()` para que los enlaces antiguos sigan resolviendo.

**2. Servicios.** El sistema actual guarda el servicio como texto (`CERTIFICACION HAL (OI09)`). Hay que:
- extraer los valores distintos,
- crear un registro en `services` por cada uno,
- **decidir manualmente cuáles llevan `determines_fitness = true`** — esta decisión no puede automatizarse y es la que gobierna toda la aptitud.

**3. Hitos de recepción.** `liberado = 1` no dice cuándo. Opciones: usar la fecha del FMR asociado como aproximación, o dejar `released_at` con la fecha de migración y marcarlo. **Recomendado:** una columna auxiliar `migrated_milestone = true` para no confundir datos reales con aproximados.

**4. Archivos.** Los nombres actuales contienen información del cliente (`FMR4029 HAL 31JUL26 ...pdf`). En destino:
- el nombre visible se conserva en `original_name`,
- el archivo en disco se guarda con nombre aleatorio,
- se calcula `sha256` para detectar duplicados — se observaron archivos repetidos con el mismo nombre.

**5. Contraseñas.** **No se migran.** Al primer acceso, cada usuario recibe un enlace de restablecimiento. Migrar hashes de un esquema desconocido es un riesgo innecesario.

## Fases

**Fase 0 — Análisis previo (sin escribir nada)**
- Volcado de sólo lectura del origen.
- Conteos por tabla, para contrastar al final.
- Detección de: series duplicadas por organización, informes sin piezas, piezas sin ninguna inspección, archivos huérfanos.
- Extracción del catálogo de servicios para validación manual.

**Fase 1 — Catálogos**
Organizaciones, fabricantes, presiones, conexiones, servicios y catálogo de modelos. Es la fase que exige revisión humana: aquí se decide qué servicio certifica.

**Fase 2 — Activos**
Piezas y genéricos, con `public_id` generado. Se conserva el id de origen en una tabla auxiliar `migration_map` para poder rastrear y reejecutar.

**Fase 3 — Inspecciones**
Informes, líneas de dictamen y archivos. Es la fase de mayor volumen; se procesa por lotes con transacción por lote.

**Fase 4 — Sets y recepciones**

**Fase 5 — Recálculo y verificación**
Se ejecuta `RecalculateAssetStatus` sobre todo el inventario y **se compara el resultado con el estado que traía el origen**. Cualquier discrepancia indica que una regla se entendió mal — es la validación más valiosa de todo el plan.

**Fase 6 — Usuarios**
Alta sin contraseña y envío de enlaces de restablecimiento.

## Verificación

| Comprobación | Criterio |
|---|---|
| Conteo de activos por organización | Idéntico al origen |
| Conteo de informes | Idéntico |
| Conteo de archivos | Idéntico, menos duplicados detectados por checksum |
| Estados recalculados | **Coinciden con el origen en más del 99 %**; cada excepción se revisa a mano |
| Vencimientos | Idénticos al origen |
| Archivos en disco | Todos los `path` existen y el checksum coincide |
| Aislamiento | Ningún activo queda sin `organization_id` |

## Reversión

Cada fase se ejecuta dentro de una transacción y deja registro en `migration_map`. Ante un fallo:

1. Restaurar la base de destino desde la copia previa a la fase.
2. Corregir la transformación.
3. Reejecutar desde esa fase.

**El origen nunca se modifica.** La migración es de sólo lectura sobre el sistema actual, de principio a fin.

## Estrategia de corte

Recomendada: **doble escritura temporal no**; en su lugar, **ventana de congelamiento**.

1. Migración de prueba completa sobre un entorno de ensayo, con verificación.
2. Corrección de discrepancias y segunda pasada.
3. Ventana acordada (idealmente un fin de semana): el sistema actual pasa a sólo lectura.
4. Migración definitiva.
5. Verificación con la lista anterior.
6. Conmutación de DNS.
7. El sistema actual permanece accesible en sólo lectura durante 90 días.
