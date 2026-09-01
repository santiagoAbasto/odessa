#!/usr/bin/env bash
set -Eeuo pipefail

# Uso:
# APP_ROOT="$HOME/odessaoil" PUBLIC_ROOT="$HOME/public_html" bash deploy/cpanel-deploy.sh
APP_ROOT="${APP_ROOT:?Defina APP_ROOT con la ruta absoluta del proyecto fuera de public_html}"
PUBLIC_ROOT="${PUBLIC_ROOT:?Defina PUBLIC_ROOT con la ruta absoluta del document root del dominio}"

if [[ "$APP_ROOT" != /* || "$PUBLIC_ROOT" != /* ]]; then
    echo "APP_ROOT y PUBLIC_ROOT deben ser rutas absolutas" >&2
    exit 1
fi

if [[ "$APP_ROOT" == "$PUBLIC_ROOT" || "$APP_ROOT" == "$PUBLIC_ROOT"/* ]]; then
    echo "El proyecto debe vivir fuera de public_html" >&2
    exit 1
fi

cd "$APP_ROOT"

test -f artisan
test -f composer.lock
test -f package-lock.json
test -f .env

php artisan down --retry=60 || true

composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
npm ci --ignore-scripts
npm run build
php artisan migrate --force
php artisan storage:link --force
php artisan optimize

mkdir -p "$PUBLIC_ROOT"

# Sin --delete: nunca borra contenido ajeno del dominio. El build de Vite sí
# se sustituye completamente para no acumular chunks con hash obsoleto.
mkdir -p "$PUBLIC_ROOT/build"
rsync -a --delete "$APP_ROOT/public/build/" "$PUBLIC_ROOT/build/"
rsync -a --exclude='build' --exclude='index.php' --exclude='storage' "$APP_ROOT/public/" "$PUBLIC_ROOT/"

cat > "$PUBLIC_ROOT/index.php" <<PHP
<?php

use Illuminate\\Foundation\\Application;
use Illuminate\\Http\\Request;

define('LARAVEL_START', microtime(true));

if (file_exists(\$maintenance = '$APP_ROOT/storage/framework/maintenance.php')) {
    require \$maintenance;
}

require '$APP_ROOT/vendor/autoload.php';

/** @var Application \$app */
\$app = require_once '$APP_ROOT/bootstrap/app.php';
\$app->handleRequest(Request::capture());
PHP

if [[ -e "$PUBLIC_ROOT/storage" && ! -L "$PUBLIC_ROOT/storage" ]]; then
    echo "$PUBLIC_ROOT/storage existe y no es un enlace; no se modificó" >&2
    exit 1
fi

ln -sfn "$APP_ROOT/storage/app/public" "$PUBLIC_ROOT/storage"

php artisan app:production-check
php artisan queue:restart
php artisan up

echo "Despliegue terminado. Verifique HTTPS, /up, login, correo y descarga de documentos."
