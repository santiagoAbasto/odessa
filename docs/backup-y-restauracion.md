# Copias de seguridad y restauración

Escrito para producción en LatinCloud (`sistema.odessaoil.com`). Las
particularidades de ese servidor —dos PHP, base en otro contenedor, sin cron de
consola— están en [`deploy/elserver/NOTAS-SERVIDOR.md`](../deploy/elserver/NOTAS-SERVIDOR.md).

## Qué respaldar

| Elemento | Ubicación | Frecuencia | Retención |
|---|---|---|---|
| Base de datos | MariaDB, en otro contenedor | Semanal como mínimo | 30 días |
| Documentos | `storage/app/documents` | Semanal como mínimo | 90 días |
| Variables de entorno | `.env` | En cada cambio | Permanente, fuera del servidor |
| Código | Git | Continua | — |

**Los documentos no están en la base de datos.** Un volcado de la base sin
`storage/app/documents` deja registros apuntando a archivos inexistentes, y
ahí viven los certificados.

**Una copia que queda en el mismo servidor no es una copia.** Si se pierde el
hosting, se pierde con él. Cada copia se baja a otra máquina.

## Credenciales de la base, una sola vez

El volcado necesita usuario y clave. **Nunca en la línea de comandos:**
quedarían en el historial y en la lista de procesos. Van en un archivo propio,
fuera de `public/`, que sólo lee su dueño:

```bash
nano ~/.odessa-backup.cnf
```

```ini
[client]
host=IP_INTERNA_DE_LA_BASE
user=USUARIO
password=CLAVE
```

```bash
chmod 600 ~/.odessa-backup.cnf
```

La IP interna es la misma de `DB_HOST` en el `.env`: la base no está en el
contenedor web, así que `localhost` no sirve.

## Hacer la copia

Por WebSSH, en la carpeta del sitio:

```bash
cd /www/odessaoil.com/sistema.odessaoil.com && mkdir -p ~/backups
```

La primera vez, confirmar que el servidor tiene la herramienta de volcado
(en MariaDB puede llamarse `mariadb-dump`):

```bash
command -v mysqldump mariadb-dump
```

Si no aparece ninguna, pedirle la exportación al soporte de LatinCloud: no
tiene sentido seguir con el resto.

```bash
mysqldump --defaults-extra-file=$HOME/.odessa-backup.cnf --single-transaction --routines --triggers NOMBRE_DE_LA_BASE | gzip > ~/backups/db-$(date +%F).sql.gz
```

`--single-transaction` evita bloquear las tablas durante el volcado.

```bash
tar -czf ~/backups/docs-$(date +%F).tar.gz -C storage/app documents
```

Después, **desde la Mac**, bajar las dos copias del día:

```bash
scp 'USUARIO@SERVIDOR:backups/*-AAAA-MM-DD.*' ~/Backups/Odessa/
```

Y borrar en el servidor las que ya se bajaron, para no llenar el hosting.

## Automatización

**Todavía no está armada.** Este hosting no tiene cron de consola: las tareas
programadas sólo llaman a una URL (punto 6 de las notas del servidor), así que
el `cron.d` habitual no existe acá. Hasta resolverlo, la copia se hace a mano.

Para automatizarla, el camino es una tarea en `routes/console.php`: la ruta
`/cron` ya corre el planificador cada minuto. Aun así, la copia tendría que
salir del servidor, y eso implica un destino externo que hoy no está definido.

## Restauración

**1. Poner la aplicación en mantenimiento**

```bash
php artisan down
```

**2. Restaurar la base de datos**

```bash
gunzip < ~/backups/db-2026-10-20.sql.gz | mysql --defaults-extra-file=$HOME/.odessa-backup.cnf NOMBRE_DE_LA_BASE
```

Si el esquema cambió desde la copia:

```bash
php artisan migrate --force
```

**3. Restaurar los documentos**

```bash
tar -xzf ~/backups/docs-2026-10-20.tar.gz -C storage/app
```

Comprobar con `ls -l storage/app/documents` que los archivos quedaron del
mismo dueño que el resto de `storage/`: si no, la web no los puede leer.

**4. Limpiar cachés, vaciar el opcache y levantar**

```bash
php artisan config:clear && php artisan route:clear && php artisan view:clear && php artisan up
```

Sin vaciar el opcache (punto 2 de las notas del servidor), la web puede seguir
mostrando lo anterior.

## Verificación de la restauración

- [ ] El número de organizaciones, activos e informes coincide con lo esperado
- [ ] Un usuario conocido inicia sesión
- [ ] Un documento del historial se descarga y abre correctamente
- [ ] La auditoría conserva los registros previos
- [ ] Los estados de aptitud son coherentes con los vencimientos

Si los estados quedaron inconsistentes tras una restauración parcial, se
recalculan desde el historial:

```bash
php artisan tinker --execute="App\Models\Asset::withoutGlobalScope('organization')->chunkById(200, fn(\$c) => \$c->each(fn(\$a) => app(App\Actions\RecalculateAssetStatus::class)(\$a)));"
```

## Prueba periódica

Una copia sin restauración probada no es una copia. **Cada trimestre**:
restaure sobre un entorno de prueba —nunca sobre producción— y recorra la
lista de verificación. Anote el tiempo que tomó: es su tiempo real de
recuperación.

## Reiniciar producción a inicial (limpiar las pruebas)

Para dejar la base como el primer día: sin pruebas, con el inventario original
del cliente recargado y sólo con los usuarios que se indiquen. Lo hace
`odessa:reiniciar-a-inicial` (versión del 05/11/2026). **No usar
`odessa:cargar-produccion` para esto**: ese comando es de la puesta en marcha,
vacía también las operaciones y las empresas y deja sólo a un usuario.

Qué garantiza el comando:

- **Una sola base.** `--base` tiene que coincidir con la base conectada; si no,
  no hace nada. Nunca recorre las tablas del servidor: vacía una lista fija
  (la que figura en el código y en la corrida de prueba).
- **Sin `--confirmar` no borra:** cuenta las filas de cada tabla y lista qué
  usuarios y empresas se conservan y cuáles se borran.
- **Una sola transacción.** El inventario se lee entero antes de tocar nada; un
  archivo roto o una falla a mitad de camino dejan la base como estaba.
- **Se conserva** la configuración: operaciones, sus días de taller, su
  habilitación por empresa, roles y permisos, y la empresa del cliente. **Se
  vacían** los informes, recepciones, envíos, documentos (y sus archivos),
  auditoría, equipos, modelos de producto, fabricantes, presiones, conexiones,
  sesiones, colas y las empresas y usuarios de prueba. Cierra la sesión de todos.
- **Exige** que quede un superadministrador entre los usuarios conservados, que
  esos usuarios existan y que no cuelguen de una empresa que se borra.

Procedimiento (en el servidor, parado en `~/sistema.odessaoil.com`):

**1. Backup completo de esa base** (a un `.sql`; después `gzip -t` y contar los
`CREATE TABLE`, porque `mysqldump | gzip` deja un `.gz` vacío si falla):

```bash
DB=$(grep '^DB_DATABASE=' .env | cut -d= -f2) && F=~/backups/antes-de-limpiar-$(date +%Y%m%d-%H%M).sql && mysqldump --defaults-extra-file=$HOME/.odessa-backup.cnf --single-transaction --no-tablespaces "$DB" > "$F" && gzip -k "$F" && gzip -t "$F.gz" && echo "CREATE TABLE: $(grep -c '^CREATE TABLE' "$F")" && ls -l "$F.gz"
```

**2. Subir el inventario original** (`hasa.json`, el que se cargó el 29/10) a
`~/hasa.json`, fuera de `public/`.

**3. Corrida de prueba** (no borra nada). Revisar que se conserven sólo los dos
usuarios y la empresa del cliente:

```bash
php artisan odessa:reiniciar-a-inicial ~/hasa.json --base="$DB" --conservar=dd@odessaoil.com,CORREO_DE_AGUSTIN
```

**4. Aplicar**, repitiendo el mismo comando con `--confirmar`.

**5. Verificar y sacar el dump de la base limpia** (el «inicial»), con el mismo
`mysqldump` del paso 1 cambiando el nombre del archivo.

Si algo no está bien, se restaura el dump del paso 1 sobre **esa** base (ver
«Restaurar la base de datos» más arriba). El código nuevo del comando tiene que
estar desplegado antes (y el opcache vaciado).
