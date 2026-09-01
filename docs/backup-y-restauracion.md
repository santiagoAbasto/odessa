# Copias de seguridad y restauración

## Qué respaldar

| Elemento | Ubicación | Frecuencia | Retención |
|---|---|---|---|
| Base de datos | MySQL | Diaria | 30 días |
| Documentos | `storage/app/documents` | Diaria | 90 días |
| Variables de entorno | `.env` | En cada cambio | Permanente, fuera del servidor |
| Código | Git | Continua | — |

**Los documentos no están en la base de datos.** Un volcado de MySQL sin `storage/app/documents` deja registros apuntando a archivos inexistentes.

## Copia de la base de datos

```bash
mysqldump -u USUARIO -p --single-transaction --routines --triggers odessaoil | gzip > odessaoil-$(date +%F).sql.gz
```

`--single-transaction` evita bloquear las tablas durante el volcado.

## Copia de los documentos

```bash
tar -czf documentos-$(date +%F).tar.gz -C /var/www/odessaoil/storage/app documents
```

Para copias incrementales:

```bash
rsync -az --delete /var/www/odessaoil/storage/app/documents/ /destino/documentos/
```

## Automatización

`/etc/cron.d/odessaoil-backup`:

```
0 3 * * * www-data /usr/local/bin/odessaoil-backup.sh >> /var/log/odessaoil-backup.log 2>&1
```

`/usr/local/bin/odessaoil-backup.sh`:

```bash
#!/usr/bin/env bash
set -euo pipefail

DESTINO=/var/backups/odessaoil
FECHA=$(date +%F)
mkdir -p "$DESTINO"

mysqldump --defaults-file=/etc/mysql/backup.cnf --single-transaction \
  --routines --triggers odessaoil | gzip > "$DESTINO/db-$FECHA.sql.gz"

tar -czf "$DESTINO/docs-$FECHA.tar.gz" \
  -C /var/www/odessaoil/storage/app documents

find "$DESTINO" -name 'db-*.sql.gz'   -mtime +30 -delete
find "$DESTINO" -name 'docs-*.tar.gz' -mtime +90 -delete
```

Las credenciales van en `/etc/mysql/backup.cnf` con permisos `600`, **nunca** en la línea de comandos: quedarían en el historial y en `ps`.

## Restauración

**1. Poner la aplicación en mantenimiento**

```bash
php artisan down
```

**2. Restaurar la base de datos**

```bash
gunzip < db-2026-08-03.sql.gz | mysql -u USUARIO -p odessaoil
```

Si el esquema cambió desde la copia:

```bash
php artisan migrate --force
```

**3. Restaurar los documentos**

```bash
tar -xzf docs-2026-08-03.tar.gz -C /var/www/odessaoil/storage/app
```

```bash
chown -R www-data:www-data /var/www/odessaoil/storage/app/documents
```

**4. Limpiar cachés y levantar**

```bash
php artisan optimize:clear && php artisan up
```

## Verificación de la restauración

- [ ] El número de organizaciones, activos e informes coincide con lo esperado
- [ ] Un usuario conocido inicia sesión
- [ ] Un documento del historial se descarga y abre correctamente
- [ ] La auditoría conserva los registros previos
- [ ] Los estados de aptitud son coherentes con los vencimientos

Si los estados quedaron inconsistentes tras una restauración parcial, se recalculan desde el historial:

```bash
php artisan tinker --execute="App\Models\Asset::withoutGlobalScope('organization')->chunkById(200, fn(\$c) => \$c->each(fn(\$a) => app(App\Actions\RecalculateAssetStatus::class)(\$a)));"
```

## Prueba periódica

Una copia sin restauración probada no es una copia. **Cada trimestre**: restaure sobre un entorno de prueba y recorra la lista de verificación. Anote el tiempo que tomó — es su tiempo real de recuperación.
