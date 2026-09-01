# Registro de decisiones técnicas

Formato: contexto → decisión → consecuencia.

---

## D1 — Monolito con Inertia en lugar de API + SPA

**Contexto.** El encargo pide Laravel y React. Cabían dos arquitecturas: API REST con SPA independiente, o monolito con Inertia.

**Decisión.** Monolito con Inertia. Endpoints API sólo cuando aparezca un consumidor externo real.

**Consecuencia.** La autorización vive en un solo lugar. No hay contratos que versionar ni tipos duplicados. La sesión es una cookie HttpOnly en lugar de un token en `localStorage`. A cambio, una futura aplicación móvil exigirá construir esos endpoints entonces.

---

## D2 — Piezas y genéricos en una sola tabla

**Contexto.** El sistema actual los separa en `ClientesPartes` y `ClientesGenericos`, con pantallas, filtros y vencimientos paralelos.

**Decisión.** Una tabla `assets` discriminada por `kind`, con columnas específicas nullable.

**Consecuencia.** Una Policy, un controlador, un conjunto de pruebas. El detalle omite los campos que no aplican. El coste es una tabla con columnas que no siempre se usan — aceptable frente a duplicar toda la capa.

---

## D3 — Estado de aptitud desnormalizado

**Contexto.** El estado se deriva del historial de inspecciones. Calcularlo al vuelo exige una subconsulta correlacionada por fila.

**Decisión.** Columnas `status`, `expires_at` y `current_inspection_report_id` en `assets`, recalculadas por una Action idempotente.

**Consecuencia.** Filtrar y ordenar 800+ piezas por estado usa un índice. La fuente de verdad sigue siendo `inspection_report_asset`, así que el estado siempre se puede reconstruir. El riesgo es la desincronización: se mitiga con la Action idempotente, invocada en cada cierre de FMR y ejecutable masivamente.

---

## D4 — UUID v7 público en las rutas

**Contexto.** El sistema actual expone identificadores secuenciales: `/ClientesPartes/view/526`, `/download/8498`. Cambiando el número se recorre el inventario y los documentos.

**Decisión.** UUID v7 en una columna `public_id`, con `getRouteKeyName()` apuntando a ella. Los identificadores internos nunca salen.

**Consecuencia.** No hay enumeración posible. Se eligió v7 sobre v4 porque es ordenable por tiempo, lo que mantiene los índices razonablemente compactos. Cuesta 16 bytes y un índice único por tabla.

---

## D5 — Aislamiento por scope global *más* Policy

**Contexto.** El aislamiento entre organizaciones es el requisito de seguridad más crítico.

**Decisión.** Dos mecanismos independientes: un scope global que filtra toda consulta, y una comprobación de organización en cada Policy.

**Consecuencia.** Redundante a propósito. Si alguien añade un `withoutGlobalScope()` sin pensarlo, la Policy sigue cortando. Nueve pruebas verifican el aislamiento recurso por recurso.

---

## D6 — 404 en lugar de 403 para recursos de otra organización

**Contexto.** Al pedir un recurso ajeno se puede responder 403 («existe, pero no puede verlo») o 404 («no existe para usted»).

**Decisión.** 404, como consecuencia natural del scope global.

**Consecuencia.** No se confirma la existencia de recursos ajenos. Un 403 permitiría deducir qué identificadores son válidos en otras organizaciones.

---

## D7 — El precinto vive en la línea de inspección

**Contexto.** El alias de ordenamiento `ClientesGenericosVencimientos.precinto` reveló que el sistema actual guarda el precinto en el registro de vencimiento, no en la pieza.

**Decisión.** Reproducirlo: columna `seal` en `inspection_report_asset`.

**Consecuencia.** El precinto queda asociado a la intervención que lo colocó, que es como funciona físicamente. Un precinto histórico no se pierde al colocar uno nuevo.

---

## D8 — Los hitos de taller son marcas de tiempo, no banderas

**Contexto.** El sistema actual usa `liberado` y `despachado` como booleanos.

**Decisión.** `released_at` / `released_by` y `dispatched_at` / `dispatched_by`.

**Consecuencia.** Se sabe cuándo y quién, no sólo si. «Pendiente» y «Fuera de plazo» se derivan contra la fecha comprometida. El coste es cuatro columnas donde había dos booleanos.

---

## D9 — `determines_fitness` como columna del servicio

**Contexto.** La regla más importante del negocio —qué inspecciones fijan la aptitud— estaba implícita en los datos: los servicios operativos aparecen sistemáticamente como «No Aplica».

**Decisión.** Hacerla explícita en `services.determines_fitness`, en vez de codificarla en PHP.

**Consecuencia.** Añadir un servicio certificante es un registro en una tabla, no un despliegue. Es también el punto que **debe validarse con el negocio** durante la migración.

---

## D10 — Tokens CSS planos en lugar de clases por tema

**Contexto.** Se necesitaba modo claro y oscuro. La alternativa habitual es `dark:` en cada clase de Tailwind.

**Decisión.** Variables CSS semánticas (`--surface-raised`, `--text-primary`) que se resuelven por tema. Los componentes nunca saben qué tema está activo.

**Consecuencia.** Un solo punto de verdad para el color. Añadir un tema es un bloque de variables, no tocar 200 componentes. El atributo `data-theme` gana sobre la preferencia del sistema en ambos sentidos, para que el conmutador funcione siempre.

---

## D11 — Registro selectivo de ECharts

**Contexto.** ECharts completo pesa cerca de 1 MB.

**Decisión.** `echarts/core` con registro explícito de barras, rejilla, tooltip y renderizador canvas. Además, fragmento separado en el bundle.

**Consecuencia.** 165 KB comprimidos, y sólo se descargan al entrar al panel. Añadir un tipo de gráfico exige registrarlo — es intencional.

---

## D12 — Sin repositorios genéricos

**Contexto.** Una práctica extendida es interponer repositorios entre los controladores y Eloquent.

**Decisión.** No usarlos. Scopes explícitos en los modelos y Actions para las operaciones con efectos.

**Consecuencia.** Menos indirección y menos archivos. Eloquent ya es la capa de abstracción. Si algún día hiciera falta cambiar de ORM, el trabajo sería el mismo con o sin repositorios.

---

## D13 — La contraseña de los datos demo no se guarda

**Contexto.** Los seeders suelen traer una contraseña fija como `password`, que acaba en producción.

**Decisión.** El seeder toma `DEMO_PASSWORD` del entorno; si no existe, genera una aleatoria y la muestra una sola vez por consola.

**Consecuencia.** No hay ninguna credencial en el repositorio. Cuesta que quien siembra deba anotarla, lo cual es justamente el punto.

---

## D14 — Modo estricto de Eloquent en desarrollo

**Contexto.** Los problemas de N+1 y los atributos no cargados aparecen tarde, normalmente en producción.

**Decisión.** `Model::shouldBeStrict()` activo en local.

**Consecuencia.** El desarrollo falla ruidosamente ante una relación no cargada. Producción no lleva la penalización.

---

## D15 — Sin módulos sin respaldo en la auditoría

**Contexto.** El encargo listaba módulos posibles: inventario de materiales, técnicos, talleres, órdenes de servicio.

**Decisión.** No implementarlos. La auditoría no encontró ninguna pantalla que los respalde, y el perfil disponible era de sólo consulta.

**Consecuencia.** El sistema entregado cubre lo que el negocio demostrablemente hace. Inventar tablas y flujos habría producido código que nadie usa y reglas equivocadas. Los módulos quedan documentados como pendientes de validación en `auditoria-sistema-actual.md`.
