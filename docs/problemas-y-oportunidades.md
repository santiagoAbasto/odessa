# Problemas detectados y oportunidades de mejora

Cada punto indica **dónde se observó** y **cómo se resolvió** en el nuevo sistema.

---

## 1. Usabilidad

| # | Problema observado | Dónde | Solución aplicada |
|---|---|---|---|
| U1 | La fila de la tabla no es clicable; sólo un ícono de 16 px | Todos los listados | Fila completa clicable, con acciones explícitas donde hacen falta |
| U2 | Estado comunicado sólo con un ícono de color, sin texto | Partes, Genéricos | Badge con **icono + texto**; el color nunca es el único portador |
| U3 | La columna *Estado* ordena por fecha de vencimiento | `/clientes-partes` | Cada columna ordena por lo que muestra |
| U4 | Filtros y búsqueda comprimidos en la fila de encabezado | Listados | Barra de filtros propia, con panel de filtros avanzados |
| U5 | «En taller» y «Despachados» son dos URL separadas | Recepción | Una tabla con pestañas de etapa; se comparan sin recargar |
| U6 | El filtro `liberado` es texto libre para un booleano | Recepción | Filtro con opciones cerradas |
| U7 | Liberar y despachar exigen abrir otra pantalla | Recepción | Acciones en la fila, con confirmación |
| U8 | La biblioteca documental muestra 30 filas planas sin agrupar | Detalle de parte | Bitácora cronológica con el FMR determinante señalado |
| U9 | No se distingue el FMR que fijó el estado del resto | Detalle de parte | Marca «Determina aptitud» explícita |
| U10 | El listado de sets no muestra el estado agregado | Sets | Panel de disponibilidad: el set está listo sólo si todas sus piezas lo están |
| U11 | La tarjeta *Procesadas* enlaza al listado sin filtro | Inicio | Cada indicador enlaza a su listado ya filtrado |
| U12 | El gráfico de vencimientos no es accionable | Inicio | Gráfico con barras enlazables y tabla alternativa |
| U13 | Recepción no informa total de registros ni página | Recepción | Paginación con totales en todos los listados |
| U14 | Campos vacíos ocupan espacio con controles deshabilitados | Detalle de parte | Los campos sin valor se omiten |
| U15 | Recarga completa de página en cada navegación | Todo | Inertia: navegación sin recarga, con barra de progreso |

## 2. Accesibilidad

| # | Problema | Solución aplicada |
|---|---|---|
| A1 | Formularios sin `<label>`; sólo *placeholder* | Todo campo lleva etiqueta visible asociada por `id` |
| A2 | Campo de correo como `type="text"` | `type="email"` con `autocomplete` correcto |
| A3 | Sin indicación de campo obligatorio | Marca visual y `(obligatorio)` para lectores de pantalla |
| A4 | Errores no asociados al campo | `aria-describedby` + `role="alert"` |
| A5 | Sin salto al contenido | Enlace «Saltar al contenido» como primer elemento tabulable |
| A6 | Orden de tabla sin estado semántico | `aria-sort` en cada encabezado ordenable |
| A7 | Gráfico sin alternativa textual | Tabla equivalente para lectores de pantalla |
| A8 | Sin soporte de movimiento reducido | `prefers-reduced-motion` respetado en toda la interfaz |

## 3. Seguridad

| # | Problema | Riesgo | Solución aplicada |
|---|---|---|---|
| S1 | IDs secuenciales en la URL (`/view/526`, `/download/8498`) | Enumeración del inventario y de los documentos | UUID v7 público en todas las rutas |
| S2 | Sin bloqueo visible tras intentos fallidos | Fuerza bruta | Limitación por correo + IP con retroceso progresivo (1 → 5 → 15 min) |
| S3 | Sin evidencia de política de contraseñas | Contraseñas débiles | Mínimo 12 caracteres, mayúsculas, minúsculas, números y verificación contra filtraciones conocidas |
| S4 | Sin protección antibot en el alta pública | Spam de solicitudes | Honeypot + límite de 5 por hora e IP |
| S5 | Sin cabeceras de seguridad observadas | XSS, clickjacking | CSP, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`, HSTS |
| S6 | Descarga por endpoint con id secuencial | Acceso cruzado a documentos | Disco privado fuera de `public/`, Policy por organización y auditoría de cada descarga |
| S7 | Sin trazabilidad visible de accesos | Falta de rendición de cuentas | Registro de auditoría con usuario, acción, entidad, IP y agente |

## 4. Procesos repetitivos que se simplificaron

| Proceso observado | Antes | Ahora |
|---|---|---|
| Saber si un equipo puede salir a operar | Abrir el set y revisar 25 piezas a mano | Panel de disponibilidad del set, de un vistazo |
| Encontrar una pieza por serie | Filtro por columna, sin búsqueda global | Búsqueda global con `⌘K` y búsqueda libre en varios campos |
| Alternar piezas y genéricos | Dos entradas de menú separadas | Un listado con conmutador de tipo |
| Comparar etapas del taller | Cambiar la URL a mano | Pestañas de etapa |
| Ver el historial de una pieza | Tabla plana de documentos | Bitácora con dictamen, servicio, vigencia y archivos por entrada |
| Ajustar la vista a la tarea | Densidad y columnas fijas | Densidad conmutable y selector de columnas, recordados por navegador |

## 5. Oportunidades no implementadas

Quedan documentadas porque son valiosas, pero **no había respaldo en la auditoría** para diseñarlas sin inventar reglas:

- Notificaciones automáticas antes del vencimiento (falta definir destinatarios y antelación).
- Exportación a Excel/CSV en segundo plano (falta definir columnas y permisos).
- Solicitud de servicio iniciada por el cliente (no existe en el sistema actual).
- Firma digital de informes.
- Aplicación móvil para carga en taller.
- Lectura de código QR grabado en la pieza para abrir su ficha.
