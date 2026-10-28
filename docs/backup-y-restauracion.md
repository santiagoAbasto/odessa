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

**4. Regenerar cachés, vaciar el opcache y levantar**

```bash
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan up
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
