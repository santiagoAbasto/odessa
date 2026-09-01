# Despliegue a producción

## Antes de desplegar

- [ ] `APP_ENV=production` y `APP_DEBUG=false`
- [ ] `APP_KEY` generada y distinta de la de desarrollo
- [ ] `APP_URL` con el dominio real y `https://`
- [ ] Certificado SSL válido
- [ ] Usuario de MySQL sin privilegios de administrador
- [ ] `storage/` y `bootstrap/cache/` con permisos de escritura
- [ ] `storage/app/documents` **fuera** del directorio público
- [ ] Correo saliente configurado (la recuperación de contraseña depende de él)
- [ ] Copia de seguridad automática programada
- [ ] PHP CLI y PHP del dominio usan la misma versión (8.3 o superior)
- [ ] Extensiones `pdo_mysql`, `fileinfo`, `mbstring`, `openssl`, `tokenizer` y `xml`
- [ ] El proyecto completo está fuera de `public_html`

## Variables de entorno

```dotenv
APP_NAME="Odessa Oil"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://sudominio.com
APP_LOCALE=es

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax

CACHE_STORE=database
QUEUE_CONNECTION=database

FILESYSTEM_DISK=local
ODESSA_DOCUMENTS_DISK=documents

MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME="${APP_NAME}"

ODESSA_EXPIRY_WARNING_DAYS=60
ODESSA_DEFAULT_VALIDITY_DAYS=365
```

Use [`deploy/.env.cpanel.example`](../deploy/.env.cpanel.example) como plantilla
completa. No la renombre dentro del repositorio: copie su contenido al `.env`
privado del servidor y complete allí los secretos. `APP_KEY` se genera **una sola
vez** con `php artisan key:generate`; cambiarla invalida sesiones, cookies y datos
cifrados. Para una rotación controlada, conserve temporalmente la clave anterior
en `APP_PREVIOUS_KEYS`.

`SESSION_SECURE_COOKIE=true` exige HTTPS. Sin certificado, nadie podrá iniciar sesión.

## Secuencia de despliegue

En cPanel use el script versionado, que mantiene el código privado fuera del
document root, compila assets, migra, sincroniza únicamente `public/`, crea el
enlace de storage y ejecuta la verificación obligatoria:

```bash
APP_ROOT="/home/odessaoiloso/Odessaoil" \
PUBLIC_ROOT="$HOME/public_html" \
bash "/home/odessaoiloso/Odessaoil/deploy/cpanel-deploy.sh"
```

El script no toca el `.env`, no borra archivos ajenos de `public_html` y no
modifica el entorno local. Para un despliegue manual equivalente:

```bash
php artisan down --render=errors::503
```

```bash
git pull origin main
```

```bash
composer install --no-dev --optimize-autoloader
```

```bash
npm ci && npm run build
```

```bash
php artisan migrate --force
```

```bash
php artisan optimize
```

```bash
php artisan app:production-check
```

```bash
php artisan up
```

Tras cada despliegue, `php artisan optimize:clear` si algo se comporta de forma extraña por caché antigua.

## Nginx

```nginx
server {
    listen 443 ssl http2;
    server_name sudominio.com;
    root /var/www/odessaoil/public;

    ssl_certificate     /etc/letsencrypt/live/sudominio.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/sudominio.com/privkey.pem;

    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # El disco de documentos vive fuera de public/, pero se bloquea por si acaso.
    location ~ ^/(storage/app|\.env|composer\.(json|lock)) {
        deny all;
        return 404;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    client_max_body_size 25M;
    gzip on;
    gzip_types text/css application/javascript application/json image/svg+xml;
}

server {
    listen 80;
    server_name sudominio.com;
    return 301 https://$host$request_uri;
}
```

## Apache

`public/.htaccess` ya viene con Laravel. Requisitos: `mod_rewrite`, `AllowOverride All` sobre `public/` y `DocumentRoot` apuntando a `public/`, **nunca** a la raíz del proyecto.

```apache
<VirtualHost *:443>
    ServerName sudominio.com
    DocumentRoot /var/www/odessaoil/public

    <Directory /var/www/odessaoil/public>
        AllowOverride All
        Require all granted
    </Directory>

    SSLEngine on
    SSLCertificateFile    /etc/letsencrypt/live/sudominio.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/sudominio.com/privkey.pem
</VirtualHost>
```

## cPanel

cPanel suele apuntar el dominio a `public_html`. La estructura soportada es:

```text
/home/USUARIO/odessaoil/          aplicación, .env, vendor y storage privados
/home/USUARIO/public_html/        sólo index.php, .htaccess y assets públicos
/home/USUARIO/public_html/storage -> ../odessaoil/storage/app/public
```

Los documentos FMR permanecen en `odessaoil/storage/app/documents` y **no** se
enlazan. Se descargan exclusivamente mediante el controlador autenticado, su
Policy y auditoría. El enlace `public_html/storage` sólo expone
`storage/app/public`.

Si *Domains → Document Root* permite apuntar directamente a
`$HOME/odessaoil/public`, puede hacerlo y ejecutar `php artisan storage:link`;
en ese caso no hace falta sincronizar a `public_html`.

En *Setup Node.js App* no hace falta nada: los assets se compilan antes de subir.

Configure estos dos Cron Jobs con la ruta real del PHP de cPanel. El primero
ejecuta tareas programadas; el segundo procesa correos encolados sin dejar un
worker permanente, que muchos planes compartidos terminan automáticamente:

```
* * * * * cd /home/usuario/odessaoil && php artisan schedule:run >> /dev/null 2>&1
* * * * * cd /home/usuario/odessaoil && php artisan queue:work --stop-when-empty --tries=3 --timeout=60 >> storage/logs/queue.log 2>&1
```

Sin el segundo cron las solicitudes quedan guardadas, pero los correos de aviso
no salen. Revise periódicamente `php artisan queue:failed`.

## Autenticación, sesiones y tokens

- La aplicación web usa sesión cifrada y cookie `Secure`, `HttpOnly` y
  `SameSite=Lax`; no expone tokens API ni necesita Sanctum.
- Laravel aplica CSRF a todas las rutas mutables de `web.php`.
- El ID de sesión se regenera al iniciar y se invalida al cerrar sesión o al
  desactivar una cuenta.
- Login: cinco fallos por combinación correo/IP activan bloqueo progresivo;
  además existe límite por ruta.
- Los tokens de recuperación se almacenan con hash, vencen a los 60 minutos,
  tienen throttle y se eliminan diariamente mediante el scheduler.
- Al crear un usuario se envía un token de establecimiento de contraseña; la
  clave aleatoria interna nunca se entrega.
- No anuncie 2FA al cliente: las columnas están reservadas para una futura
  implementación, pero el segundo factor no forma parte del flujo actual.

Antes de entregar, pruebe el SMTP real: alta de usuario, correo recibido, enlace
de un solo uso, contraseña fuerte y posterior inicio/cierre de sesión.

## Colas con Supervisor (VPS)

`/etc/supervisor/conf.d/odessaoil-worker.conf`:

```ini
[program:odessaoil-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/odessaoil/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/odessaoil/storage/logs/worker.log
stopwaitsecs=3600
```

```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl start odessaoil-worker:*
```

## Verificación posterior

- [ ] `https://sudominio.com` carga la portada pública
- [ ] `/ingresar` permite autenticar
- [ ] El panel muestra datos
- [ ] Un documento se descarga correctamente
- [ ] `curl -I https://sudominio.com` devuelve `Strict-Transport-Security` y `Content-Security-Policy`
- [ ] `curl https://sudominio.com/storage/app/documents/` devuelve 403 o 404
- [ ] `/up` responde correctamente
- [ ] `storage/logs/laravel.log` sin errores
- [ ] `php artisan app:production-check` termina con código 0
- [ ] Alta de usuario envía el correo para establecer contraseña
- [ ] `php artisan queue:failed` no muestra trabajos fallidos
