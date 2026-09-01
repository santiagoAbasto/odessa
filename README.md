# Odessa Oil

Plataforma de inspección, certificación y trazabilidad de equipamiento de presión.

Reconstrucción de un sistema existente, precedida de una auditoría funcional documentada en [`docs/auditoria-sistema-actual.md`](docs/auditoria-sistema-actual.md).

## Stack

Laravel 13 · PHP 8.3+ · MySQL 8+ · Inertia 3 · React 19 · TypeScript estricto · Tailwind 4 · Vite 8

## Puesta en marcha

```bash
composer install && npm install && cp .env.example .env && php artisan key:generate
```

```bash
php artisan migrate --seed
```

```bash
npm run dev
```

El seeder muestra por consola una contraseña generada al azar para los usuarios demo. Anótela: no se vuelve a mostrar.

Detalle completo en [`docs/instalacion-local.md`](docs/instalacion-local.md).

## Comandos

| Comando | Uso |
|---|---|
| `php artisan test` | Suite completa |
| `npm run types` | Comprobación de tipos |
| `npm run build` | Compilación de producción |
| `./vendor/bin/pint` | Formateo de PHP |

## Documentación

**Auditoría del sistema anterior**
- [Auditoría funcional](docs/auditoria-sistema-actual.md) — inventario de pantallas, campos, reglas deducidas y áreas no verificadas
- [Problemas y oportunidades](docs/problemas-y-oportunidades.md) — 30 hallazgos con su solución

**Diseño**
- [Propuesta de rediseño](docs/propuesta-rediseño.md)
- [Arquitectura técnica](docs/arquitectura-tecnica.md)
- [Modelo de datos](docs/modelo-de-datos.md)
- [Mapa de pantallas](docs/mapa-de-pantallas.md)
- [Flujos de negocio](docs/flujos-de-negocio.md)
- [Matriz de roles y permisos](docs/matriz-roles-permisos.md)
- [Decisiones técnicas](docs/decisiones-tecnicas.md)

**Operación**
- [Instalación local](docs/instalacion-local.md)
- [Despliegue a producción](docs/despliegue-produccion.md)
- [Copias y restauración](docs/backup-y-restauracion.md)
- [Plan de migración](docs/plan-de-migracion.md)
- [Criterios de aceptación](docs/criterios-de-aceptacion.md)

**Manuales**
- [Manual de usuario](docs/manual-usuario.md)
- [Manual del administrador](docs/manual-administrador.md)

## La regla que gobierna el sistema

El estado de aptitud de una pieza se deriva **únicamente** de la última inspección finalizada cuyo resultado sea *Apto* o *No Apto*. Las intervenciones operativas quedan registradas en la bitácora pero no alteran el estado ni renuevan la vigencia.

Implementada en `App\Actions\RecalculateAssetStatus` y cubierta por diez pruebas en `tests/Unit/AssetStatusTest.php`.

## Seguridad

- Aislamiento por organización mediante scope global **y** Policy, con nueve pruebas dedicadas
- UUID v7 en todas las rutas públicas; los identificadores internos nunca salen
- Documentos en disco privado fuera de `public/`, con autorización y auditoría por descarga
- Limitación de intentos con retroceso progresivo, por correo e IP
- CSP, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy` y HSTS bajo HTTPS
- Registro de auditoría con filtrado de secretos

No hay credenciales en el repositorio. `.env.example` deja todos los valores sensibles vacíos.

## Estado

82 pruebas, 212 aserciones, todas en verde. TypeScript compila en modo estricto sin errores.

Pendientes conocidos en [`docs/propuesta-rediseño.md`](docs/propuesta-rediseño.md#prioridades).
