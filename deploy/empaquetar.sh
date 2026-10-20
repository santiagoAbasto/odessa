#!/usr/bin/env bash
# No modifica vendor local ni empaqueta secretos o documentos.
set -euo pipefail
cd "$(dirname "$0")/.."
PROJECT_ROOT="$PWD"
composer validate --strict
npm run types
npm run build
STAGING="$(mktemp -d "${TMPDIR:-/tmp}/odessaoil-package.XXXXXX")"
trap 'rm -rf -- "$STAGING"' EXIT
umask 077
mkdir -p "$PROJECT_ROOT/dist"
OUTPUT="$PROJECT_ROOT/dist/odessaoil-$(date +%Y%m%d-%H%M%S).zip"
test ! -e "$OUTPUT"

# Lista positiva: .env, ZIP, logs y datos de storage nunca se copian.
# `lang` lleva las traducciones de los correos: sin ella, los textos que agrega
# Laravel (el pie del botón, "Todos los derechos reservados") salen en inglés.
for ITEM in app artisan bootstrap config database lang public resources routes composer.json composer.lock package.json package-lock.json vite.config.js; do
    rsync -a --exclude='.DS_Store' --exclude='*.sqlite*' \
        --exclude='cache/*.php' --exclude='storage' --exclude='hot' \
        "$ITEM" "$STAGING/"
done
mkdir -p "$STAGING/bootstrap/cache" "$STAGING/storage/app/documents" \
    "$STAGING/storage/app/private" "$STAGING/storage/app/public" \
    "$STAGING/storage/framework/cache/data" "$STAGING/storage/framework/sessions" \
    "$STAGING/storage/framework/views" "$STAGING/storage/logs"
cd "$STAGING"
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts
zip -qr "$OUTPUT" .
echo "Paquete sin secretos ni documentos: $OUTPUT"
echo 'Extraer fuera de public_html; configurar .env y migrar en el servidor.'
