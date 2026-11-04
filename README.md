# Odessa Oil

Plataforma web para la **inspección, certificación y trazabilidad de equipos a
presión**. Registra el inventario de cada cliente, sus informes de inspección y
vencimientos, y el recorrido del equipo por el taller, con aislamiento estricto
entre organizaciones y auditoría de cada cambio.

## Qué resuelve

- **Inventario** de piezas, equipos y **sets** (equipos armados con varias piezas).
- **Informes de inspección** con vigencia por tipo de certificación (COS, COC…),
  edición y baja con motivo y auditoría, y borradores que el cliente no ve.
- **Aptitud derivada**: el estado de un equipo se calcula a partir de sus
  inspecciones; nunca se asigna a mano.
- **Fecha límite de envío**: vencimiento menos días de taller y margen de
  seguridad, para que el equipo no pierda la certificación mientras se repara.
- **Taller**: envíos del cliente, recepción, reparación, liberación y despacho.
- **Reportes y Excel** de vencimientos, por empresa y por equipo.
- **Documentos privados**, usuarios, roles y registro de auditoría.

## Roles

| Rol | Alcance |
|---|---|
| Superadministrador | Todo el sistema. |
| Taller | Inventario, informes, recepciones y envíos de todas las empresas. |
| Cliente · Operador | Lo propio: consulta, carga de envíos y decisión sobre reparaciones. |
| Cliente · Consulta | Lo propio, solo lectura. |

El detalle está en la [matriz de roles y permisos](docs/matriz-roles-permisos.md).

## Stack

PHP 8.3+ · Laravel 13 · MySQL 8+ · Inertia 3 · React 19 · TypeScript ·
Tailwind CSS 4 · Vite 8 · PHPUnit 12.

## Puesta en marcha

Requisitos: PHP 8.3+ con `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `xml` y
`zip`; Composer 2; Node.js 20+; MySQL 8+.

```bash
git clone https://github.com/santiagoAbasto/odessa.git && cd odessa
composer install && npm install
cp .env.example .env && php artisan key:generate
```

Cree la base `odessaoil`, complete `DB_USERNAME` y `DB_PASSWORD` en `.env` y ejecute:

```bash
php artisan migrate --seed  # roles, catálogo y datos de ejemplo (usuarios *@odessaoil.test)
composer run dev            # servidor, cola, logs y Vite
```

La clave de los usuarios de ejemplo sale de `DEMO_PASSWORD`; si está vacía, se
genera y se muestra una sola vez.

Más detalle en la [guía de instalación local](docs/instalacion-local.md).

## Calidad

```bash
php artisan test            # suite completa (incluye un recorrido de todas las pantallas)
npm run types               # TypeScript estricto
npm run build               # build de producción
./vendor/bin/pint --test    # estilo de código
```

Las pruebas usan SQLite en memoria: no tocan ninguna base real.

## Estructura

```
app/Actions/       casos de uso: alta de equipos, finalizar informe, recálculo de aptitud
app/Http/          controladores y validaciones
app/Models/        modelos de dominio; junto a Policies/, Enums/ y Notifications/
app/Support/       permisos, auditoría, planillas y resumen de certificaciones
resources/js/      Inertia + React (pages/ y components/)
database/          migraciones y seeders
tests/Feature/     pruebas de comportamiento
deploy/            empaquetado de producción
docs/              documentación funcional y técnica
```

## Seguridad

- Permisos y Policies sobre cada acción, más aislamiento por organización en el
  modelo: un cliente solo alcanza lo suyo aunque adivine un identificador.
- Identificadores públicos UUID; los IDs internos no aparecen en rutas.
- Documentos fuera de `public/`, con descarga autorizada y auditada.
- Sesiones y cookies endurecidas, CSRF, límite de intentos de acceso, CSP con
  nonce, HSTS y anti-framing.
- La auditoría filtra contraseñas, tokens y secretos.

El repositorio no contiene secretos, datos de clientes, respaldos ni logs.
**No fuerce la inclusión** de `.env`, `storage/` ni `dist/`.

## Despliegue

El paquete se arma con `bash deploy/empaquetar.sh` (lista blanca: nunca incluye
`.env`, documentos ni pruebas). Después se sube, se migra y se vacía la caché de
PHP. La guía completa, con verificación y vuelta atrás, está en
[Despliegue paso a paso](docs/DESPLIEGUE-PASO-A-PASO.md); las copias de
seguridad, en [Copias y restauración](docs/backup-y-restauracion.md).

Las notificaciones por correo se encolan: producción necesita un proceso que
ejecute `php artisan schedule:run` cada minuto.

## Documentación

[Arquitectura](docs/arquitectura-tecnica.md) ·
[Modelo de datos](docs/modelo-de-datos.md) ·
[Flujos de negocio](docs/flujos-de-negocio.md) ·
[Criterios de aceptación](docs/criterios-de-aceptacion.md) ·
[Manual de usuario](docs/manual-usuario.md) ·
[Manual del administrador](docs/manual-administrador.md)

## Licencia

Software propietario. No se autoriza su uso, copia ni distribución sin permiso.
