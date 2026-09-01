# Auditoría funcional del sistema actual — KPK Works

**Fecha de la auditoría:** 3 de agosto de 2026
**Alcance:** navegación de sólo lectura sobre los entornos productivos, con la cuenta autorizada por el propietario del sistema.
**Método:** recorrido de pantallas, inspección del DOM (nombres de campos, alias de ordenamiento, opciones de filtro) y de los enlaces generados por la interfaz.

> **Restricciones respetadas.** No se creó, modificó ni eliminó ningún registro. No se enviaron formularios que generen operaciones. No se descargaron documentos ni datos masivos. No se intentó eludir permisos ni acceder a información de otras organizaciones. Las credenciales utilizadas no aparecen en este repositorio, ni en código, ni en pruebas, ni en seeders, ni en `.env.example`.

---

## 1. Acceso obtenido y cobertura

| Entorno | URL | Acceso | Perfil observado |
|---|---|---|---|
| Portal de clientes | `portal.odessaoil.com` | Sí | Usuario de cliente, sólo consulta |
| Sistema de taller | `taller.odessaoil.com` | Sí, parcial | Mismo usuario; ve *Partes de Cliente* y *Recepción de Equipos* |

La misma cuenta accede a ambos dominios, pero con menús distintos. El sistema de taller devolvió *"Usuario no autorizado para acceder a esta página"* al intentar entrar directamente a una ruta profunda, lo que confirma que **la autorización se evalúa por pantalla**, no sólo al iniciar sesión.

### Áreas que NO pudieron verificarse

El perfil disponible es de consulta. Ninguna pantalla ofreció alta, edición, eliminación ni cambio de estado. Por lo tanto quedan **sin verificar**:

- Creación y edición de partes, genéricos y sets.
- Creación, edición y cierre de FMR (numeración, revisiones, flujo preliminar → final).
- Carga de documentos y sus reglas de nomenclatura.
- Registro de recepciones, liberación y despacho.
- Administración de usuarios, roles, permisos y configuraciones.
- Aprobación de solicitudes de cuenta.
- Gestión de técnicos, inventario de materiales y talleres.
- Notificaciones y exportaciones.

**Para completar la auditoría harían falta:** una cuenta de administrador de taller, una de técnico y una de responsable de taller, preferentemente sobre un entorno de pruebas con datos ficticios.

---

## 2. Inventario de pantallas

### 2.1 Portal de clientes

| # | Pantalla | Ruta | Acceso | Objetivo |
|---|---|---|---|---|
| 1 | Login | `/usuarios/login` | Público | Autenticación |
| 2 | Recuperar contraseña | `/usuarios/recuperar-clave` | Público | Envío de enlace |
| 3 | Solicitud de cuenta | `/usuarios/registrar` | Público | Alta de solicitud |
| 4 | Inicio | `/usuarios/home` | Cliente | Indicadores de aptitud |
| 5 | Partes de Clientes | `/clientes-partes` | Cliente | Listado de piezas |
| 6 | Detalle de parte | `/ClientesPartes/view/{id}` | Cliente | Ficha + biblioteca documental |
| 7 | Genéricos de Clientes | `/clientes-genericos` | Cliente | Listado de equipamiento genérico |
| 8 | Detalle de genérico | `/ClientesGenericos/view/{id}` | Cliente | Ficha + historial |
| 9 | FMR | `/inspeccion-reportes` | Cliente | Listado de informes |
| 10 | Detalle de FMR | `/inspeccion-reportes/view/{id}` | Cliente | Cabecera, piezas y archivos |
| 11 | Sets | `/sets` | Cliente | Agrupaciones de piezas |
| 12 | Detalle de set | `/Sets/view/{id}` | Cliente | Composición del set |
| 13 | Descarga de documento | `/inspeccion-reportes-archivos/download/{id}` | Cliente | Entrega del PDF |
| 14 | Perfil | `/usuarios/profile` | Autenticado | Datos del usuario |

### 2.2 Sistema de taller

| # | Pantalla | Ruta | Acceso | Objetivo |
|---|---|---|---|---|
| 15 | Inicio de taller | `/usuarios/home` | Recepción | Indicadores del pipeline |
| 16 | Recepción de Equipos | `/recepcion-equipos` | Recepción | Piezas en taller y despachadas |
| 17 | Partes de Cliente | `/clientes-partes` | Recepción | Consulta cruzada de piezas |

---

## 3. Detalle por pantalla

### 3.1 Login (`/usuarios/login`)

- **Campos:** `mail` (texto), `clave` (password), token CSRF y `_method` ocultos.
- **Validaciones:** ninguna del lado cliente. Los campos **no** llevan `required`, ni `type="email"`, ni etiquetas `<label>`.
- **Acciones:** *Entrar*, *¿Olvidó su contraseña?*, *Solicite Una!*.
- **Comportamiento:** admite `?redirect=` para volver a la pantalla pretendida.
- **Problemas detectados:**
  - Sin etiquetas asociadas: un lector de pantalla sólo anuncia el *placeholder*, que desaparece al escribir (incumple WCAG 3.3.2).
  - El campo de correo es `type="text"`: en móvil no aparece el teclado de correo.
  - No se observó indicación de bloqueo tras intentos fallidos.

### 3.2 Solicitud de cuenta (`/usuarios/registrar`)

- **Campos:** `mail`, `nombre`, `telefono`, `empresa`. Sin contraseña — **el alta la realiza un administrador**.
- **Validaciones:** ninguna en el cliente; ningún campo marcado como obligatorio.
- **Problemas detectados:** sin etiquetas, sin ayuda contextual, sin protección antibot visible, sin indicación de qué pasa después del envío.

### 3.3 Inicio del portal (`/usuarios/home`)

- **Indicadores:** *Apto*, *Apto - Próximo a vencer*, *No Apto* y *Procesadas*.
- **Enlaces:** cada tarjeta apunta a `/clientes-partes?estado=O|W|D`. **Los códigos de estado del sistema son `O`, `W` y `D`.**
- **Gráfico:** «Flujo de vencimiento de partes de los próximos 60 días» → **la ventana preventiva es de 60 días**.
- **Problemas detectados:** el gráfico no es accionable; la tarjeta *Procesadas* enlaza al listado sin filtro, lo que confunde su significado.

### 3.4 Partes de Clientes (`/clientes-partes`)

- **Columnas:** Parte, Nro Parte, Nro Serie, Ref Cliente, FMR, F. Vencimiento, Estado, Código KPK, Acciones.
- **Filtros por columna:** `parte_descripcion`, `nro_parte`, `nro_serie`, `ref_cliente`, `nro_inspeccion_reporte`, `vencimiento`, `estado_id`, `codigo_kpk`, y un `cliente_id` **oculto**.
- **Opciones de estado:** `O` = Apto, `W` = Proximo a vencer, `D` = No Apto.
- **Ordenamiento:** por encabezado, vía `?sort=<Tabla.columna>&direction=asc`. Los alias exponen el esquema real:
  `Partes.descripcion`, `Partes.nro_parte`, `nro_serie`, `ref_cliente`, `InspeccionReportes.numero`, `ClientesPartesVencimientos.fecha_vencimiento`, `codigo_kpk`.
- **Paginación:** servidor, 20 filas por página. En la cuenta auditada: **829 registros en 42 páginas**.
- **Acciones por fila:** ver detalle e ícono de descarga directa del último informe.
- **Problemas detectados:**
  - La fila completa no es clicable; sólo el ícono diminuto de ojo.
  - La columna *Estado* se ordena por `fecha_vencimiento`, no por estado: el orden no coincide con lo que muestra la columna.
  - El estado se comunica con un ícono coloreado sin texto asociado.
  - El filtro de estado y el de búsqueda conviven en la misma fila de encabezado, muy comprimidos.

### 3.5 Detalle de parte (`/ClientesPartes/view/{id}`)

- **Campos de identificación:** cliente, vencimiento, estado, `nro_serie`, `codigo_kpk`, `ref_cliente`, `ref_fabricante`.
- **Campos de catálogo:** parte, `nro_parte`, nombre.
- **Ficha técnica:** `fabricante_id`, `presion_test_id`, `presion_trabajo_id`, `parte_servicio_id`, `conexion_superior_id`, `conexion_inferior_id`, `conexion_lateral_1_id`, `conexion_lateral_2_id`.
  → Los sufijos `_id` confirman que son **catálogos normalizados**, no texto libre.
- **Todos los campos son de sólo lectura** para este perfil.
- **Mensaje clave observado:**
  > «La Inspección Nro. N es la última que determinó el estado (Apto/No Apto) de esta parte. La inspección vence en N días.»
- **Biblioteca documental:** tabla con Tipo, Nombre, Fecha FMR, **Estado en FMR**, **Servicio en FMR**, Acciones. Se observaron hasta 30 documentos en una sola pieza, con historial desde 2019.
- **Valores de «Estado en FMR»:** `Apto`, `No Apto`, `No Aplica`, `Sin Procesar`.
- **Valores de «Servicio en FMR»:** cadenas del tipo `NOMBRE (OIxx)` — nombre del servicio más un código operativo.
- **Problemas detectados:**
  - La biblioteca no se pagina ni se filtra: 30 filas planas, sin agrupar por año.
  - No hay distinción visual entre el FMR que determinó el estado y el resto.
  - Los campos vacíos ocupan espacio con controles deshabilitados en lugar de omitirse.

### 3.6 Genéricos de Clientes (`/clientes-genericos`)

- **Columnas:** Generico, Marca, Modelo, ID / Nro de Serie, Descripcion, **Precinto**, Ref del Cliente, FMR, F. Vencimiento, Estado.
- **Filtros:** `generico_descripcion`, `marca`, `modelo`, `identificador`, `descripcion`, `precinto`, `ref_cliente`, `nro_inspeccion_reporte`, `vencimiento`, `estado_id`, más `cliente_id` y `ref_interna` ocultos.
- **Alias de orden:** `Genericos.descripcion`, `ClientesGenericosVencimientos.precinto`, `ClientesGenericosVencimientos.fecha_vencimiento`.
  → El **precinto vive en el registro de vencimiento**, no en la pieza: cambia con cada inspección.
- **Volumen:** 1.932 registros en 97 páginas.

### 3.7 FMR (`/inspeccion-reportes` y su detalle)

- **Cabecera:** `numero`, `cantidad_partes`, `cliente`, `equipo`, `fecha`, `inspeccion_reportes_estado_id`.
- **Listado de piezas dentro del informe:** `ref_cliente`, `fecha_vencimiento`, `codigo_kpk`, `configuracion_servicio`, `estado`.
  → **Cada pieza lleva su propio estado y su propio vencimiento dentro del mismo informe.**
- **Archivos:** Tipo, Nombre, Fecha Archivo, Acciones.
- **Nomenclatura observada en los archivos:** `FMR{número} {abreviatura de cliente} {DDMMMAA} {descripción}.pdf`, con sufijos `- Preliminar` y `- Final`.
  → El informe **se emite en dos etapas** antes de quedar firme.
- **Numeración:** mayoritariamente correlativa, con sufijos de letra para revisiones (`3231`, `3231A`).
- **Volumen:** 110 páginas de informes.

### 3.8 Sets (`/sets`)

- **Columnas:** Nombre, Cant. Partes, Acciones. Filtros: `nombre`, `cliente_id` oculto.
- **Contenido:** 14 sets, nombrados como equipos completos (`Equipo #N (FABRICANTE medida-presión)`) o como conjuntos funcionales.
- **Tamaño:** entre 3 y 25 piezas por set.
- **Problemas detectados:** el listado no muestra el **estado agregado** del set. Para saber si un equipo puede salir a operar hay que abrirlo y revisar pieza por pieza. Es la carencia funcional más significativa detectada.

### 3.9 Recepción de Equipos (`/recepcion-equipos`)

- **Columnas:** `U` (urgente), FMR, Fecha, Prometido, Parte de Cliente, Ult. FMR, Venc. FMR, E. FMR, Liberado.
- **Filtros:** `inspeccion_reporte_numero`, `fecha`, `fecha_prometida`, `cliente_parte`, `liberado`.
- **Alias de orden:** `urgente`, `InspeccionReportes.numero`, `fecha`, `fecha_prometida`, **`clientes_parte.descripcion_taller`**, `InspeccionReportesVencimientos.numero`, `ClientesPartesVencimientos.fecha_vencimiento`, `ClientesPartesVencimientos.estado`, `liberado`.
  → Existe una **descripción específica de taller**, distinta de la que ve el cliente.
- **Vistas por querystring:** `?despachado=0`, `?despachado=1`, `?liberado=0&despachado=0`, `?liberado=1&despachado=0`, `?urgente=1&despachado=0`.
- **Indicadores del inicio de taller:** Pendientes en fecha, Pendientes vencidos, Procesados en fecha, Procesados vencidos, Urgentes, Despachados (3.586 históricos).
  → «Pendiente» y «Procesado» **no son columnas**: se derivan comparando `liberado`/`despachado` contra `fecha_prometida`.
- **Problemas detectados:**
  - «En taller» y «Despachados» son dos vistas separadas por URL; no se pueden comparar.
  - El filtro `liberado` es un campo de texto libre para un valor booleano.
  - No hay acciones en la fila: liberar y despachar exigen otra pantalla.
  - El listado no informa el total de registros ni la página actual.

---

## 4. Tecnología del sistema auditado

Detectada por los recursos que carga la interfaz y por el estilo de las rutas:

- **Backend:** PHP con CakePHP (rutas `Controlador/accion/{id}`, tokens `_csrfToken`, campos `_method`).
- **Frontend:** jQuery 3.3.1, Bootstrap 4, DataTables, Select2, SweetAlert, Summernote, jQuery File Upload, Perfect Scrollbar, Moment.js.
- **Renderizado:** servidor, con recarga completa en cada navegación.
- **Sesión:** cookie con token CSRF por formulario.

Se identificó la estructura de datos subyacente **únicamente a partir de los alias de ordenamiento y los nombres de campo expuestos por la interfaz**. No se accedió al código fuente ni a la base de datos.

Tablas inferidas: `Partes`, `ClientesPartes`, `ClientesPartesVencimientos`, `Genericos`, `ClientesGenericos`, `ClientesGenericosVencimientos`, `InspeccionReportes`, `InspeccionReportesVencimientos`, `InspeccionReportesArchivos`, `Sets`, `RecepcionEquipos`, `Usuarios`.

---

## 5. Reglas de negocio deducidas

Cada regla indica su nivel de confianza.

| # | Regla | Confianza |
|---|---|---|
| R1 | Una organización cliente posee piezas (`partes`) y equipamiento (`genéricos`); el usuario sólo ve las de su organización. | **Confirmada** — filtro `cliente_id` oculto en todos los listados |
| R2 | Un FMR agrupa varias piezas y adjunta uno o más documentos. | **Confirmada** — campo `cantidad_partes` y tabla de archivos |
| R3 | Dentro de un FMR, **cada pieza tiene su propio resultado y su propio vencimiento**. | **Confirmada** — un mismo FMR aparece con «Estado en FMR» distinto en piezas distintas |
| R4 | Sólo los resultados **Apto** y **No Apto** determinan el estado de la pieza; «No Aplica» y «Sin Procesar» quedan en la bitácora sin alterarlo. | **Confirmada** — el sistema lo declara textualmente en el detalle |
| R5 | La ventana preventiva de vencimiento es de **60 días**. | **Confirmada** — título del gráfico y estado `W` |
| R6 | La vigencia habitual de una certificación es de **1 año** desde la fecha del FMR. | **Alta** — verificada en los registros observados, pero podría variar por servicio |
| R7 | Los servicios se identifican con un código `OIxx` y **no todos emiten dictamen**. | **Alta** — los servicios operativos aparecen sistemáticamente como «No Aplica» |
| R8 | El FMR se emite en dos etapas: preliminar y final. | **Alta** — nomenclatura de archivos |
| R9 | Las revisiones de un FMR se numeran con sufijo de letra. | **Media** — patrón observado (`3231` → `3231A`) |
| R10 | Un set agrupa piezas sin alterar su identidad ni su trazabilidad individual. | **Confirmada** |
| R11 | El pipeline de taller es: ingreso → liberación → despacho, con marca de urgencia y fecha comprometida. | **Confirmada** — filtros `liberado` y `despachado` |
| R12 | «Pendiente» / «Procesado» se derivan de los hitos contra `fecha_prometida`, no son un estado almacenado. | **Alta** |
| R13 | El precinto de un genérico se registra **por inspección**, no por pieza. | **Confirmada** — `ClientesGenericosVencimientos.precinto` |
| R14 | El alta de usuarios la hace un administrador: el solicitante nunca elige su contraseña. | **Confirmada** — el formulario público no pide contraseña |
| R15 | La autorización se evalúa por pantalla, no sólo al iniciar sesión. | **Confirmada** — mensaje «Usuario no autorizado para acceder a esta página» |

### Supuestos pendientes de validación

Estos puntos **no pudieron deducirse** y se implementaron con un valor por defecto configurable, marcado en el código:

- **S1.** Vigencia exacta por tipo de servicio (se usó 365 días como valor por defecto, parametrizable por servicio).
- **S2.** Reglas de numeración y revisión de FMR.
- **S3.** Quién puede liberar frente a quién puede despachar (se asignó liberar al responsable de taller y despachar a recepción; es un supuesto).
- **S4.** Si existe un flujo formal de aprobación/rechazo de FMR más allá de preliminar/final.
- **S5.** Si el cliente puede iniciar solicitudes de servicio desde el portal.
- **S6.** Existencia de inventario de materiales, gestión de técnicos y múltiples talleres — **no se encontró ninguna pantalla que los respalde**.
- **S7.** Reglas de notificación por vencimiento.

---

## 6. Módulos hipotéticos que NO existen en el sistema auditado

El encargo planteaba una lista de módulos posibles. Tras la auditoría, **no se encontró evidencia** de:

- Inventario y materiales
- Gestión de técnicos
- Múltiples talleres
- Órdenes o solicitudes iniciadas por el cliente
- Centro de notificaciones
- Exportaciones
- Historial de cambios visible al usuario
- Configuraciones

Puede que existan tras el perfil administrativo. El nuevo sistema se diseñó para admitirlos sin romper el modelo, pero **no se implementaron módulos sin respaldo en la auditoría**.
