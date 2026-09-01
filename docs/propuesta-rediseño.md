# Propuesta de rediseño — Odessa Oil

## Resumen ejecutivo

El sistema actual funciona y contiene información valiosa: más de 800 piezas, 1.900 genéricos, cientos de informes y siete años de historial. El problema no es lo que guarda, sino **cuánto trabajo cuesta sacarle una respuesta**.

Tres carencias concretas, todas verificadas en la auditoría:

1. **No se puede saber si un equipo está listo para operar.** Los sets agrupan hasta 25 piezas, pero el listado no muestra estado agregado: hay que abrir el set y revisar una por una.
2. **La información está partida en dos sistemas.** Portal y taller son dominios separados, con menús distintos, para los mismos datos.
3. **Los identificadores son secuenciales y públicos.** `/ClientesPartes/view/526` y `/download/8498` permiten recorrer el inventario y los documentos cambiando un número.

La propuesta es una sola aplicación, con la misma información, reorganizada alrededor de las preguntas que el negocio hace de verdad.

---

## Problemas actuales

Ver [`problemas-y-oportunidades.md`](problemas-y-oportunidades.md) para el listado completo con evidencia. Resumen por categoría:

- **Usabilidad:** 15 problemas, desde filas no clicables hasta acciones escondidas en otra pantalla.
- **Accesibilidad:** 8 problemas, empezando por formularios sin etiquetas.
- **Seguridad:** 7 problemas, encabezados por la enumeración de identificadores.

---

## Solución propuesta

### Nueva arquitectura de información

El sistema actual organiza por **tipo de registro** y por **dominio técnico**:

```
Portal                        Taller
├── Partes de Cliente         ├── Partes de Cliente
├── Genéricos de Clientes     └── Recepción de Equipos
├── FMR
└── Sets
```

El nuevo organiza por **momento del trabajo**, en una sola aplicación:

```
Operación                     Taller              Administración
├── Panel                     └── Recepción       ├── Usuarios
├── Activos (partes|genéricos)                    ├── Solicitudes
├── Sets de equipo                                └── Auditoría
└── Informes FMR
```

Un usuario de cliente no ve *Taller* ni *Administración*: el menú se compone según sus permisos, no según el dominio en el que entró.

### Módulos implementados

| Módulo | Reemplaza a | Qué cambia |
|---|---|---|
| **Sitio público** | *(no existía)* | Presencia abierta con servicios, proceso y solicitud de acceso |
| **Panel** | `/usuarios/home` × 2 | Un solo panel; cada indicador enlaza a su listado filtrado |
| **Activos** | Partes + Genéricos | Un listado con conmutador; filtros avanzados, densidad y columnas configurables |
| **Detalle de activo** | `/ClientesPartes/view` | Bitácora que señala el FMR determinante y agrupa documentos por intervención |
| **Sets** | `/sets` | Panel de disponibilidad: responde si el equipo puede operar |
| **Informes FMR** | `/inspeccion-reportes` | Dictamen por pieza visible en el propio informe; cierre con confirmación |
| **Recepción** | `/recepcion-equipos` | Pestañas de etapa en una tabla; liberar y despachar desde la fila |
| **Usuarios** | *(no verificado)* | Altas sin contraseña, roles con prevención de escalada |
| **Solicitudes de acceso** | *(no verificado)* | Revisión explícita, asociación a organización |
| **Auditoría** | *(no existía)* | Registro consultable de accesos, cambios y descargas |

### Flujos optimizados

**«¿Puede salir el Equipo #3?»**
- *Antes:* Sets → abrir set → revisar 25 filas → anotar las vencidas. ~26 interacciones.
- *Ahora:* Sets → abrir set → leer el panel superior. 2 interacciones.

**«¿Qué le pasó a la pieza con serie X?»**
- *Antes:* Partes → filtrar por serie → clic en el ícono de ojo → leer 30 filas planas de documentos.
- *Ahora:* `⌘K` → escribir la serie → Enter → bitácora cronológica con el dictamen vigente destacado.

**«¿Qué está trabado en taller?»**
- *Antes:* editar la URL con `?liberado=0&despachado=0`, sin totales ni comparación.
- *Ahora:* pestaña *Pendientes* con contadores y filtro de fuera de plazo.

**«Liberar y despachar una pieza»**
- *Antes:* localizar la pieza y pasar a otra pantalla por cada hito.
- *Ahora:* acción en la fila, con confirmación y registro automático de quién y cuándo.

---

## Seguridad

| Medida | Implementación |
|---|---|
| Aislamiento por organización | Scope global de Eloquent **más** comprobación en cada Policy |
| Prevención de IDOR | UUID v7 en rutas; un recurso ajeno devuelve 404, no 403 |
| CSRF | Token por sesión en todos los formularios |
| Sesión | Cifrada, regenerada al autenticar, cookie HttpOnly con SameSite |
| Contraseñas | Hash bcrypt; mínimo 12 caracteres, mayúsculas, minúsculas, números y verificación contra filtraciones |
| Fuerza bruta | Limitación por correo + IP con retroceso 1 → 5 → 15 minutos |
| Enumeración de usuarios | Mensajes idénticos en login y en recuperación exista o no el correo |
| Documentos | Disco privado con `serve => false`, fuera de `public/`; sólo salen por un controlador que autoriza y audita |
| Cabeceras | CSP, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`, HSTS bajo HTTPS |
| Asignación masiva | `$fillable` explícito; los campos derivados sólo por `forceFill` desde su Action |
| Inyección SQL | Eloquent con parámetros; el orden se valida contra lista blanca |
| Escalada de privilegios | Los roles que puede otorgar un usuario se intersecan con los que él mismo posee |
| Sesión de usuario desactivado | Cortada en la petición siguiente, sin esperar el vencimiento de la cookie |
| Secretos | Sólo en `.env`; `.env.example` sin valores; auditoría con lista negra de campos |

**Pendiente:** 2FA para administradores. Las columnas están migradas y cifradas; falta el flujo de alta y verificación.

---

## Prioridades

| Fase | Contenido | Estado |
|---|---|---|
| 1 | Modelo de datos, autenticación, roles, aislamiento | **Terminada** |
| 2 | Activos, informes, sets, documentos | **Terminada** |
| 3 | Recepción de taller con hitos | **Terminada** |
| 4 | Administración y auditoría | **Terminada** |
| 5 | Migración de datos históricos | Pendiente — requiere acceso a la base de origen |
| 6 | Alta y edición de FMR desde la interfaz | Pendiente — requiere validar las reglas de numeración |
| 7 | Notificaciones y exportaciones | Pendiente — requiere definición del negocio |
| 8 | 2FA para administradores | Pendiente — esquema listo |

---

## Riesgos

| Riesgo | Impacto | Mitigación |
|---|---|---|
| Los supuestos S1–S7 resultan incorrectos | Alto | Están parametrizados y marcados en el código; corregirlos es configuración, no rediseño |
| La vigencia varía por servicio de forma no observada | Medio | `services.validity_days` ya lo admite por servicio |
| La numeración de FMR tiene reglas ocultas | Medio | La creación de FMR no se implementó a ciegas |
| Existen módulos administrativos no auditados | Medio | El modelo admite extensión; no se inventaron tablas sin respaldo |
| Los datos históricos no migran limpiamente | Alto | El plan de migración contempla validación previa y ejecución reversible |

---

## Dependencias

- **Para migrar:** volcado o acceso de sólo lectura a la base de datos de origen y a los archivos del disco.
- **Para completar la auditoría:** cuentas de administrador, responsable de taller y técnico, preferentemente en un entorno de pruebas.
- **Para validar reglas:** una sesión con el área técnica sobre los supuestos S1–S7.

---

## Criterios de aceptación

Ver [`criterios-de-aceptacion.md`](criterios-de-aceptacion.md) — cada criterio indica su prueba automatizada.
