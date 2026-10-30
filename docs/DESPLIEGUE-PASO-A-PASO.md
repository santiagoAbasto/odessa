# Subir a producción — paso a paso

> Guía para publicar esta versión en **sistema.odessaoil.com** sin sorpresas.
> Cada paso dice **qué vas a ver** y **por qué está bien**, para que nadie piense
> que algo falla.
>
> Tiempo estimado: ~10 minutos. No se toca ninguna base más que la de Odessa.

---

## Qué trae esta versión (para tener presente al verificar)

- **Servicio** fuera de la ficha técnica · **"FMR" → "Informe"** en todo el sistema.
- **Código por empresa** del equipo nuevo (`HASA-0001`, `HASA-0002`…).
- **Vencimientos por tipo** en el encabezado (COS anual + COC conformidad).
- **Aptitud por tipo**: la pieza es apta solo si está al día en todas sus certificaciones.
- **Deshacer aprobación** (solo superadmin) — **trae un permiso nuevo**.
- **Recepción parcial** + aviso por correo al operador — **trae una migración**.

> Dos cosas de esta versión **no existían antes** y por eso el deploy tiene dos
> pasos extra respecto de un deploy normal: la **migración** (paso 5) y el
> **re-sembrado de permisos** (paso 6). Si los salteás, "deshacer" no aparece y
> la recepción parcial da error de columna. Están marcados con ⚠️.

---

## 0. Antes de empezar (en tu Mac)

Confirmá que está todo commiteado y que los tests pasan:

```bash
cd "/Users/user/Projects/PROYECTO ODESSA/Odessaoil" && git status --short && php artisan test
```

**Qué vas a ver:** `git status` sin archivos pendientes y el test en verde
(`passed`). Si hay cambios sin commitear, commitealos primero.

---

## 1. Empaquetar (en tu Mac)

```bash
cd "/Users/user/Projects/PROYECTO ODESSA/Odessaoil" && bash deploy/empaquetar.sh
```

**Qué hace:** valida, corre los tipos, compila el front con Vite, instala las
dependencias de producción en una carpeta aparte y arma el `.zip`. **No toca** tu
`vendor` local ni mete `.env`, documentos ni la base.

**Qué vas a ver al final:**

```
Paquete sin secretos ni documentos: .../dist/odessaoil-AAAAMMDD-HHMMSS.zip
```

Guardá ese nombre de archivo; es el paquete que vas a subir. Sacá su huella para
compararla después:

```bash
cd "/Users/user/Projects/PROYECTO ODESSA/Odessaoil/dist" && ls -t *.zip | head -1 && shasum -a 256 "$(ls -t *.zip | head -1)"
```

---

## 2. Respaldar la base de producción (red de seguridad)

Antes de migrar, un respaldo de **solo** la base de Odessa. Entrá por la consola
web (WebSSH desde `my.latincloud.com`) y, en `~/sistema.odessaoil.com`:

```bash
php artisan db:backup 2>/dev/null || mysqldump --single-transaction --no-tablespaces -h 192.168.0.60 -u "$DB_USER" -p"$DB_PASSWORD" ff972b_odessa > ~/odessa-backup-$(date +%Y%m%d-%H%M%S).sql
```

> El usuario y la clave salen del `.env` del servidor (`$DB_USER`, `$DB_PASSWORD`),
> no se escriben a mano acá. Si preferís, hacé el respaldo desde phpMyAdmin del
> panel: solo la base `ff972b_odessa`.

**Qué vas a ver:** un archivo `.sql` con tamaño mayor a cero. Guardalo; es el
punto de retorno si algo sale mal.

---

## 3. Subir el paquete al servidor

`scp` a una ruta relativa **falla** en este hosting (la carpeta de inicio del
SFTP es otra). Lo que funciona es mandar el archivo por una tubería y verificar
la huella. **Desde tu Mac** (reemplazá el nombre del zip por el real):

```bash
ZIP="dist/odessaoil-AAAAMMDD-HHMMSS.zip"
ssh sistemaodessaoil@ssh.latincloud.app 'cat > /www/odessaoil.com/sistema.odessaoil.com/deploy.zip' < "/Users/user/Projects/PROYECTO ODESSA/Odessaoil/$ZIP"
```

Verificá que llegó entero (la huella tiene que ser **idéntica** a la del paso 1).
En el servidor:

```bash
cd ~/sistema.odessaoil.com && sha256sum deploy.zip
```

**Qué vas a ver:** la misma cadena de 64 caracteres que sacaste en tu Mac. Si no
coincide, volvé a subir: el archivo llegó cortado.

---

## 4. Descomprimir sobre la aplicación

En el servidor, en `~/sistema.odessaoil.com`:

```bash
unzip -o deploy.zip && rm -f deploy.zip
```

**Qué vas a ver:** una lista larga de archivos `inflating: …`. Es normal que
diga `replacing`/`overwriting`: estás reemplazando el código viejo por el nuevo.

**Por qué está bien:** el `.zip` **no** trae `.env` ni `storage/`, así que tu
configuración, tus documentos y tus sesiones **quedan intactos**. Solo cambia el
código y el front compilado.

---

## 5. ⚠️ Migrar (una columna nueva, aditiva)

```bash
php artisan migrate --force
```

**Qué vas a ver:**

```
INFO  Running migrations.
2026_10_30_120000_add_not_arrived_to_shipment_items .......... DONE
```

**Por qué está bien:** solo **agrega** dos columnas opcionales a `shipment_items`
(para la recepción parcial). No borra ni cambia datos existentes. Si ya migraste
antes y volvés a correrlo, dirá `Nothing to migrate` — también está bien.

---

## 6. ⚠️ Re-sembrar permisos (para que aparezca "Deshacer")

```bash
php artisan db:seed --class=RolePermissionSeeder --force
```

**Qué vas a ver:** `INFO  Seeding database.` y vuelve al prompt sin errores.

**Por qué está bien:** el seeder es **idempotente** — crea el permiso nuevo
(`receptions.revert`) y se lo asigna al superadministrador, sin tocar los demás
roles ni los usuarios. Correrlo de más no rompe nada.

---

## 7. Vaciar el OPcache (activar el código nuevo)

Este hosting **no revalida por fecha**: aunque subas el código, sigue sirviendo
la versión vieja hasta reciclar PHP. No hay botón de reinicio, así que se vacía
con un archivo temporal que se crea, se pide y se borra en una sola línea:

```bash
F="public/reset-$(LC_ALL=C tr -dc 'a-z0-9' < /dev/urandom | head -c 24).php" \
  && printf '<?php var_dump(function_exists("opcache_reset") ? opcache_reset() : "sin opcache");\n' > "$F" \
  && curl -s "https://sistema.odessaoil.com/$(basename "$F")"; echo; rm -f public/reset-*.php
```

**Qué vas a ver:** `bool(true)`. Eso confirma que el código nuevo ya está activo.

> **No corras `config:cache`.** La config se deja sin cachear a propósito en este
> servidor; cachearla sin reciclar FPM genera el falso "problema de credenciales".

---

## 8. Verificar (que todo quedó funcionando)

Abrí el sitio y comprobá, en orden:

1. **Entra la portada** → `https://sistema.odessaoil.com` carga sin error.
2. **Ingresás** → `/ingresar` funciona.
3. **Ficha de un equipo** → el encabezado muestra **Código con prefijo de empresa**
   y, si tiene inspecciones, los **vencimientos por tipo** (COS / COC).
4. **Ficha técnica → Editar** → ya **no** aparece el campo "Servicio".
5. **Gestión de equipos** (como superadmin) → en una pieza aprobada aparece el
   botón **Deshacer**.
6. **Dar recepción** a un envío → se puede **destildar** un equipo y pedir motivo.
7. En ningún lado dice **"FMR"**: ahora es **"Informe"**.

**Qué vas a ver:** todo lo de arriba. Si "Deshacer" no aparece, repetí el paso 6
(faltó el re-sembrado). Si la recepción parcial da error de columna, repetí el
paso 5 (faltó migrar).

> **Correos de la recepción parcial:** el aviso al operador sale **encolado** y lo
> despacha el cron del servidor (el que ya corre `schedule:run` cada minuto). No
> es instantáneo: puede tardar hasta un minuto. Que no salga en el acto **no es**
> una falla.

---

## 9. Si algo sale mal — volver atrás

El código viejo se recupera subiendo el **paquete anterior** (quedó en tu
`dist/`, p. ej. `odessaoil-20261028-114022.zip`) con los pasos 3–4–7. La base se
restaura, **solo si hizo falta**, desde el respaldo del paso 2:

```bash
mysql -h 192.168.0.60 -u "$DB_USER" -p"$DB_PASSWORD" ff972b_odessa < ~/odessa-backup-AAAAMMDD-HHMMSS.sql
```

La migración de esta versión es aditiva (solo agrega columnas), así que volver al
código anterior **no exige** revertirla: las columnas nuevas quedan sin usar y no
molestan.

---

*Checklist rápido: `empaquetar.sh` → subir + sha256 → `unzip` → `migrate --force`
→ `db:seed RolePermissionSeeder --force` → vaciar opcache (`bool(true)`) →
verificar.*
