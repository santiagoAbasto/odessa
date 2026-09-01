# Mapa de pantallas del nuevo sistema

## Zona pública — sin sesión

| Ruta | Pantalla | Contenido |
|---|---|---|
| `/` | Portada | Propuesta de valor, servicios, proceso de cinco etapas, diagrama técnico |
| `/servicios` | Servicios | Detalle de cada tipo de intervención |
| `/solicitar-acceso` | Solicitud de acceso | Formulario validado con honeypot y límite por IP |
| `/ingresar` | Inicio de sesión | Correo y contraseña, con enlace de recuperación |
| `/recuperar-clave` | Recuperar contraseña | Envío de enlace, respuesta idéntica exista o no el correo |
| `/restablecer-clave/{token}` | Nueva contraseña | Definición con política de complejidad |

**No se expone ningún dato de clientes en la zona pública.** El sitio describe el servicio; el inventario vive tras la sesión.

## Zona autenticada

| Ruta | Pantalla | Permiso | Reemplaza a |
|---|---|---|---|
| `/panel` | Panel de control | *(sesión)* | `/usuarios/home` de ambos sistemas |
| `/activos` | Activos | `assets.view` | `/clientes-partes` + `/clientes-genericos` |
| `/activos/{uuid}` | Detalle de activo | `assets.view` | `/ClientesPartes/view/{id}` |
| `/sets` | Sets de equipo | `sets.view` | `/sets` |
| `/sets/{uuid}` | Detalle de set | `sets.view` | `/Sets/view/{id}` |
| `/informes` | Informes FMR | `reports.view` | `/inspeccion-reportes` |
| `/informes/{uuid}` | Detalle de FMR | `reports.view` | `/inspeccion-reportes/view/{id}` |
| `/informes/{uuid}/finalizar` | Cierre de FMR | `reports.finalize` | *(no verificado)* |
| `/documentos/{uuid}/descargar` | Descarga | `documents.view` | `/inspeccion-reportes-archivos/download/{id}` |
| `/taller/recepciones` | Recepción de equipos | `receptions.view` | `/recepcion-equipos` |
| `/taller/recepciones/{uuid}/liberar` | Liberación | `receptions.release` | *(no verificado)* |
| `/taller/recepciones/{uuid}/despachar` | Despacho | `receptions.dispatch` | *(no verificado)* |
| `/administracion/usuarios` | Usuarios | `users.view` | *(no verificado)* |
| `/administracion/solicitudes` | Solicitudes de acceso | `account_requests.manage` | *(no verificado)* |
| `/administracion/auditoria` | Auditoría | `audit.view` | *(no existía)* |

## Detalle de cada pantalla

### Panel de control

- **Objetivo:** responder en un vistazo cuánto equipamiento está apto y qué está trabado en taller.
- **Contenido:** total de activos, tarjetas por estado, gráfico de vencimientos a 60 días, pipeline de taller, próximos a vencer e informes recientes.
- **Cada indicador enlaza** a su listado ya filtrado.
- **Carga diferida:** los agregados llegan después del primer pintado; mientras tanto se muestran esqueletos.
- **Permisos:** el bloque de taller sólo aparece con `receptions.view`.

### Activos

- **Conmutador:** Partes / Genéricos, en lugar de dos entradas de menú.
- **Columnas (partes):** parte, número de serie, referencia del cliente, código KPK, FMR, vencimiento, estado.
- **Columnas (genéricos):** genérico con marca y modelo, identificador, resto igual.
- **Filtros:** búsqueda libre, estado, serie, código KPK, referencia, número de FMR, rango de vencimiento; marca y modelo en genéricos.
- **Orden:** por descripción, serie, código, referencia, vencimiento y estado.
- **Vista:** densidad compacta / normal / cómoda y selector de columnas, recordados por navegador.
- **Estados vacíos:** mensaje con acción para quitar filtros.

### Detalle de activo

- **Cabecera:** estado de aptitud, vencimiento con días restantes en texto, y el FMR determinante enlazado.
- **Identificación:** serie, código KPK, referencias, número de parte.
- **Ficha técnica:** fabricante, presiones de test y trabajo, servicio y conexiones. Los campos sin valor se omiten.
- **Bitácora:** cronológica, con dictamen, servicio, vigencia, precinto, hallazgos y documentos por entrada. Las entradas que fijan la aptitud llevan marca explícita.

### Sets de equipo

- **Listado:** tarjetas con nombre y cantidad de piezas.
- **Detalle:** panel de disponibilidad que responde *si el equipo puede salir a operar*, con desglose por estado y la composición ordenada por posición de armado.

### Informes FMR

- **Listado:** número, equipo, fecha, cantidad de piezas y de documentos, estado.
- **Detalle:** cabecera con métricas, tabla de piezas con **dictamen individual**, y documentos descargables.
- **Cierre:** botón de finalizar con confirmación, visible sólo con permiso y sólo si el estado lo admite.

### Recepción de equipos

- **Resumen:** contadores de pendientes, fuera de plazo, urgentes, liberadas y despachadas, cada uno enlazado.
- **Pestañas de etapa:** En taller, Pendientes, Liberadas, Despachadas, Todas.
- **Columnas:** urgencia, pieza, FMR, ingreso, comprometido con marca de fuera de plazo, aptitud, etapa y acciones.
- **Acciones en la fila:** liberar y despachar, con confirmación y registro automático.

### Administración

- **Usuarios:** listado con organización, roles, último acceso y estado. Alta en modal, sin elegir contraseña. Baja lógica con confirmación.
- **Solicitudes de acceso:** tarjetas por estado, con aprobación asociada a una organización.
- **Auditoría:** tabla filtrable por acción, texto y rango de fechas, con los cambios desplegables.

## Navegación

- **Sidebar** colapsable, con estado recordado y tooltips al contraerse. Los grupos vacíos por permisos no se pintan.
- **Barra superior** con buscador global (`⌘K`), selector de tema, notificaciones y menú de cuenta.
- **Migas de pan** en todas las pantallas internas.
- **Móvil:** el sidebar se convierte en panel deslizante que se cierra al navegar.
