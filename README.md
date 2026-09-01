# Odessa Oil

Portal empresarial para inspección, certificación y trazabilidad de equipos y
componentes sometidos a presión. Centraliza activos, informes FMR, documentos,
envíos, recepciones, reparaciones y auditoría con aislamiento por organización.

## Funcionalidad

- Inventario de piezas, conjuntos y catálogo técnico.
- Envíos, recepción de taller, reparación, liberación y despacho.
- Informes de inspección con vigencia y cálculo automático de aptitud.
- Documentos privados autorizados por usuario y organización.
- Usuarios, roles, permisos, solicitudes de acceso y auditoría.

## Stack

- PHP 8.3+ y Laravel 13
- MySQL 8+
- Inertia 3, React 19 y TypeScript
- Tailwind CSS 4, Vite 8 y PHPUnit 12

## Requisitos

- PHP con `pdo_mysql`, `fileinfo`, `mbstring`, `openssl`, `xml` y `zip`
- Composer 2
- Node.js y npm compatibles con `package-lock.json`
- MySQL

## Instalación local

```bash
git clone https://github.com/santiagoAbasto/odessa.git
cd odessa
composer install
npm ci
cp .env.example .env
php artisan key:generate
```

Configure la base de datos y el correo en `.env` y ejecute:

```bash
php artisan migrate --seed
npm run build
php artisan serve
```

La contraseña demo se genera al ejecutar el seeder y sólo se muestra una vez.
Consulte la [guía de instalación](docs/instalacion-local.md) para más detalle.

## Desarrollo y verificación

```bash
composer run dev
```

```bash
php artisan test
npm run types
npm run build
./vendor/bin/pint --test
composer validate --strict
```

## Seguridad

- Sesiones regeneradas, cookies cifradas y protección CSRF.
- Cookies `HttpOnly`, `SameSite` y `Secure` bajo HTTPS.
- Rate limiting de login y recuperación de contraseña.
- Permisos, Policies y aislamiento por organización.
- UUID públicos; los IDs internos no aparecen en rutas.
- Documentos fuera de `public/` con descarga autorizada y auditada.
- CSP con nonce, HSTS, `nosniff`, anti-framing y no-cache en sesiones.
- Filtrado de contraseñas, tokens y secretos en la auditoría.

El repositorio excluye secretos, datos privados, respaldos, logs, dependencias y
artefactos. Nunca fuerce su inclusión aunque Git los ignore.

## Producción

La raíz web debe apuntar exclusivamente a `public/`. En cPanel, la aplicación
debe permanecer fuera de `public_html`.

- [Despliegue y cPanel](docs/despliegue-produccion.md)
- [Copias y restauración](docs/backup-y-restauracion.md)

Antes de habilitar tráfico:

```bash
php artisan migrate --force
php artisan optimize
php artisan app:production-check
```

Configure también el worker de colas y `php artisan schedule:run`.

## Documentación

- [Arquitectura](docs/arquitectura-tecnica.md)
- [Modelo de datos](docs/modelo-de-datos.md)
- [Flujos de negocio](docs/flujos-de-negocio.md)
- [Roles y permisos](docs/matriz-roles-permisos.md)
- [Criterios de aceptación](docs/criterios-de-aceptacion.md)
- [Manual de usuario](docs/manual-usuario.md)
- [Manual del administrador](docs/manual-administrador.md)

## Datos privados

`storage/app/documents` no forma parte del código. El backup debe incluir por
separado la base de datos y ese directorio. Los paquetes de `dist/` tampoco se
versionan.

## Licencia

Proyecto privado. No distribuir código, documentación ni datos sin autorización.
