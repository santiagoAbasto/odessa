# Arquitectura técnica

## Stack

| Capa | Tecnología | Versión instalada |
|---|---|---|
| Framework | Laravel | 13.x |
| Lenguaje | PHP | 8.5 |
| Base de datos | MySQL | 9.x (compatible con 8.0+) |
| Puente | Inertia.js | 3.x |
| Interfaz | React | 19.2 |
| Tipos | TypeScript | estricto |
| Empaquetado | Vite | 8 con Rolldown |
| Estilos | Tailwind CSS | 4 (`@theme`) |
| Componentes | Radix UI | primitivas accesibles |
| Tablas | TanStack Table | 8 |
| Gráficos | Apache ECharts | 6, con registro selectivo |
| Movimiento | Motion para React | 12 |
| Iconos | Lucide React | — |
| Avisos | Sonner | — |
| Fechas | date-fns | 4, con locale `es` |
| Permisos | spatie/laravel-permission | 8 |
| Pruebas | PHPUnit | 12 |

## Monolito moderno, no API separada

Laravel renderiza y React pinta; Inertia los une. No hay API REST intermedia porque no aporta nada aquí: no existe aplicación móvil ni integración externa. Cuando aparezcan, se añadirán endpoints puntuales bajo `/api`, no un reemplazo completo.

**Consecuencias:** la autorización vive en un solo lugar, no hay que versionar contratos y las sesiones son cookies HttpOnly en lugar de tokens en `localStorage`.

## Organización del backend

```
app/
  Actions/                  Operaciones de negocio con efectos
    RecalculateAssetStatus  Deriva la aptitud de una pieza
    FinalizeInspectionReport Cierra un FMR y propaga vencimientos
    ChangeReceptionStage    Liberación y despacho
  Enums/                    Estados como tipos, no como cadenas sueltas
  Http/
    Controllers/            Delgados: autorizan, consultan y renderizan
    Middleware/             SecurityHeaders, EnsureUserIsActive, Inertia
    Requests/               Validación y mensajes en español
  Models/
    Concerns/               BelongsToOrganization, HasPublicId
  Policies/                 Autorización por recurso
  Support/                  Permissions, ActivityLogger, TableQuery
```

**Actions en lugar de servicios genéricos.** Cada Action hace una cosa, es invocable, se inyecta y se prueba sola. No hay clases `AssetService` con veinte métodos sin relación.

**Sin repositorios.** Eloquent con scopes explícitos ya abstrae la persistencia; una capa más sólo añadiría indirección.

## Organización del frontend

```
resources/js/
  components/
    brand/      Logo y marca
    ui/         Primitivas: Button, Badge, Panel, Field, Dialog…
    tables/     DataTable, FilterBar
    charts/     ECharts con registro selectivo
    layout/     Definición de navegación
  layouts/      AppLayout, PublicLayout, AuthLayout
  pages/        Una carpeta por dominio; la ruta la resuelve Inertia
  hooks/        usePermissions
  lib/          cn, format
  types/        Tipos compartidos con el backend
```

**Sin lógica de negocio en los componentes.** Las reglas viven en PHP. React recibe datos ya resueltos (`statusLabel`, `statusTone`, `daysToExpiry`) y los pinta. Un cambio en la regla de aptitud no toca ni un archivo `.tsx`.

## Flujo de una petición

```
Navegador
   ↓  visita Inertia (XHR con X-Inertia)
Middleware  → SecurityHeaders → auth → EnsureUserIsActive → permission:
   ↓
Controlador → authorize() vía Policy
   ↓
Eloquent    → scope global de organización (aislamiento)
   ↓
Inertia::render(componente, props)
   ↓
React       → hidrata sólo el componente de la página
```

## Rendimiento

| Técnica | Dónde |
|---|---|
| Paginación en servidor | Todos los listados; `per_page` validado contra lista blanca |
| Eager loading | `with()` explícito en cada listado y detalle |
| Modo estricto de Eloquent | En local, rompe ante N+1 y atributos no cargados |
| Índices compuestos | Encabezados por `organization_id` |
| Propiedades diferidas | `Inertia::defer()` en agregados del panel y contadores |
| División del bundle | ECharts y vendor en fragmentos aparte |
| Registro selectivo de ECharts | Sólo barras, rejilla y tooltip |
| Carga diferida por página | `import.meta.glob` sin `eager` |
| Debounce de búsqueda | 350 ms en las barras de filtro |
| Estado desnormalizado | Evita subconsulta por fila al filtrar por aptitud |

**Tamaño del bundle inicial** (gzip): ~11 KB de aplicación + ~107 KB de vendor. ECharts (165 KB) sólo se descarga al entrar al panel.

## Seguridad

Detallada en [`propuesta-rediseño.md`](propuesta-rediseño.md#seguridad). En resumen:

- Aislamiento por scope global **más** comprobación en cada Policy.
- UUID v7 en rutas; los IDs internos nunca salen.
- Documentos en disco privado con `serve => false`, fuera de `public/`.
- CSP, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`, HSTS bajo HTTPS.
- Sesión cifrada, regenerada tras autenticar, cookie HttpOnly y SameSite.
- Limitación de intentos con retroceso progresivo por correo + IP.
- Auditoría de accesos, cambios de estado y descargas, con filtrado de secretos.

## Decisiones y sus alternativas

| Decisión | Alternativa descartada | Motivo |
|---|---|---|
| Inertia | API REST + SPA | Duplicaría la autorización y el modelo de datos sin necesidad |
| Estado derivado en columna | Vista SQL o cálculo al vuelo | Filtrar y ordenar 800+ filas exigiría subconsulta correlacionada |
| Una tabla `assets` | Tablas separadas por tipo | Duplicaría consultas, Policies y pantallas |
| `select` nativo estilizado | Radix Select | Menos JavaScript y mejor comportamiento en móvil para listas cortas |
| Tokens CSS planos | Clases de Tailwind por tema | Un solo punto de verdad; el tema no toca los componentes |
| Stack de fuentes del sistema | Fuente web | Cero peticiones y sin salto de texto al cargar |
