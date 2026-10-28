# Notas del servidor — sistema.odessaoil.com (LatinCloud)

Particularidades de este hosting que no son evidentes y que ya costaron
tiempo de averiguar. Leer antes de desplegar.

**No hay credenciales en este archivo, y no se agregan.** Usuario y clave de
base y de correo van únicamente en el `.env` del servidor (`chmod 600`).

---

## Datos del sitio

| | |
|---|---|
| Ruta del proyecto | `/www/odessaoil.com/sistema.odessaoil.com` |
| Document root | `/www/odessaoil.com/sistema.odessaoil.com/public` |
| Servidor web | Apache detrás de un proxy inverso |
| Acceso | WebSSH desde `my.latincloud.com` (no hay administrador de archivos) |
| FTP | `ftp.odessaoil.com`, puerto 21 |

---

## 1. Hay DOS PHP distintos, con extensiones distintas

Es la trampa principal de este servidor.

| | Versión | `ext-zip` |
|---|---|---|
| Consola (SSH, `php artisan`, `composer`) | 8.3 | **no** |
| Web (`FPM/FastCGI`, lo que atiende las páginas) | 8.4.3 | **sí** (1.22.4) |

Verificar una extensión con `php -m` por SSH **no dice nada** sobre lo que
tiene disponible la aplicación. Para el entorno web hay que mirar un
`phpinfo()` servido por HTTP.

### Consecuencia práctica: `composer install`

`openspout/openspout` declara `ext-zip` como requisito. Composer lo evalúa
contra el PHP de consola, no lo encuentra, y aborta. El requisito **sí** se
cumple donde el código realmente corre, así que la verificación se saltea a
propósito:

```bash
composer install --no-dev --optimize-autoloader --ignore-platform-req=ext-zip
```

No es un parche sucio: es la forma correcta de decirle a Composer que está
mirando el intérprete equivocado.

### Consecuencia práctica: exportación a Excel

`SpreadsheetExport::supportsXlsx()` resuelve el formato en tiempo de
ejecución con `extension_loaded('zip')`. Como las descargas salen por HTTP,
se evalúa contra el PHP web y **genera .xlsx real**. El respaldo a CSV existe
por si el sistema se mueve a un servidor sin la extensión; acá no se usa.

---

## 2. OPcache no revalida por fecha

```
opcache.validate_timestamps  Off
opcache.enable_cli           Off
```

PHP guarda el código compilado en memoria y **no revisa si los archivos
cambiaron**. Subir una corrección no la activa: el proceso sigue sirviendo la
versión vieja hasta que se recicla solo.

Es la causa de "subí el arreglo, corrí `config:cache`, y no pasó nada".
Incluye a `bootstrap/cache/config.php`, que también es un archivo PHP.

**Después de cada despliegue hay que reciclar PHP-FPM.** Ninguno de los dos
paneles tiene el botón, así que en la práctica es vaciar el opcache con el
archivo temporal de más abajo.
`opcache.enable_cli` está en `Off`, así que los comandos de artisan no se ven
afectados y no hace falta reiniciar para correr una migración.

Vale también para un cambio de una sola variable: si corrés `config:cache` sin
reiniciar, la consola usa el valor nuevo y la web el viejo. Eso se ve como un
problema de credenciales que no existe — pasó el 08/10/2026 rotando la clave
del cron, y el síntoma era un 401 con la clave correcta.

### No hay botón de reiniciar: vaciar el opcache

Pasó el 08/10/2026: ni `my.latincloud.com` ni `panel.elserver.com` mostraban la
opción, no hay procesos `php-fpm` a nombre del usuario y el hosting no da
ningún comando para recargarlos.

La salida es vaciar el opcache desde adentro. Un archivo PHP **nuevo** nunca
pasó por el opcache, así que se compila fresco aunque todo lo demás esté
cacheado, y desde ahí se puede vaciar el resto. Se crea, se pide y se borra en
una sola línea, para que no quede expuesto ni dependa de acordarse:

```bash
F="public/reset-$(LC_ALL=C tr -dc 'a-z0-9' < /dev/urandom | head -c 24).php" \
  && printf '<?php var_dump(function_exists("opcache_reset") ? opcache_reset() : "sin opcache");\n' > "$F" \
  && curl -s "https://sistema.odessaoil.com/$(basename "$F")"; echo; rm -f public/reset-*.php
```

Tiene que imprimir `bool(true)`. El nombre va al azar y el archivo se borra en
el mismo comando: crearlo y borrarlo en dos pasos separados termina en un
`reset-*.php` olvidado en `public/`, o —lo que pasó la primera vez— en borrarlo
antes de pedirlo, con lo cual no se ejecuta nada.

Otra opción, si se prefiere no tocar archivos: en el panel, cambiar la versión
de PHP del sitio y volverla a la original. Cada cambio recrea el pool.

---

## 3. La base de datos no está en el mismo contenedor

`DB_HOST=localhost` falla con:

```
SQLSTATE[HY000] [2002] No such file or directory
```

Busca un socket Unix local que no existe. Hay que apuntar a la IP interna
que figura en el panel, en *Bases de Datos → IP local*: `192.168.0.60`.

El panel muestra el servidor como `CloudServer:3306`, que no resuelve por
DNS desde el contenedor. Usar la IP.

El motor es **MariaDB**, compatible con MySQL: `DB_CONNECTION=mysql`.

---

## 4. La salida SMTP está bloqueada — resuelto con CloudSMTP

Desde el 08/10/2026 el correo sale por **CloudSMTP**: `smtp.correoseguro.co`,
puerto 587, StartTLS, autenticando con la casilla `noreply@odessaoil.com`.
Ojo con el servidor: al activar el servicio el panel muestra
`vsmtp.odessaoil.com`, pero su certificado está emitido a nombre de
`smtp.correoseguro.co` y la conexión falla por TLS. El correcto figura en
Correo Electrónico → la casilla → «Configuración de tu cuenta de correo».

Lo que sigue es el diagnóstico original, para entender por qué hizo falta.

Probados y cerrados: 587, 465, 25 y 2525 hacia afuera; 25, 587 y 1025 en
local. No es un problema de credenciales ni de configuración de Laravel.

**`sendmail` NO es una alternativa acá.** El `phpinfo()` muestra
`Path to sendmail = /usr/bin/sendmail -t`, pero eso es sólo la ruta que PHP
trae configurada por defecto: **el binario no está instalado**. Verificado con
`ls -l /usr/bin/sendmail` → *No such file or directory*. La directiva de
`phpinfo()` no prueba que el programa exista.

Quedan dos caminos:

1. **CloudSMTP** — servicio pago de LatinCloud, habilita la salida SMTP. Es el
   de menor fricción: no requiere código nuevo, ni dependencias, ni cambios de
   DNS. Sólo completar credenciales con `MAIL_MAILER=smtp`.
2. **Proveedor por API HTTPS** (Resend, Postmark, Brevo) — no usa puertos SMTP,
   así que atraviesa el bloqueo, y suma SPF/DKIM y registro de entregas, que
   para avisos de recertificación es deseable. Requiere instalar el paquete en
   el servidor (con el flag del punto 1) y agregar registros DNS al dominio.

`config/mail.php` ya tiene declarados los transportes `resend`, `postmark` y
`ses`, pero **ninguno tiene su paquete instalado**. Usar cualquiera de ellos
implica un `composer require` y un despliegue, no sólo tocar el `.env`.

**La salida HTTPS SÍ funciona** (verificado: `curl` a `api.resend.com` devuelve
`401`, o sea que llegó y fue rechazado por falta de credenciales, no por
bloqueo de red). El bloqueo es específico de los puertos SMTP.

```bash
curl -sS -o /dev/null -w "HTTPS saliente: %{http_code}\n" -m 15 https://api.resend.com/domains
```

Paquete necesario según el transporte, confirmado en `MailManager.php`:

| Transporte | Paquete |
|---|---|
| `resend` | `resend/resend-php` |
| `postmark` | `symfony/postmark-mailer` |

Ambos exigen verificar el dominio con registros DNS (SPF y DKIM) antes de
poder enviar.

Si alguna vez se pierde la salida, dejar `MAIL_MAILER=log`: los avisos quedan
escritos en `storage/logs/` en lugar de perderse en silencio.

---

## 5. Certificado SSL

Lo emite y lo renueva LatinCloud desde el panel (Let's Encrypt). Para
verificarlo:

```bash
echo | openssl s_client -connect sistema.odessaoil.com:443 \
  -servername sistema.odessaoil.com 2>/dev/null \
  | openssl x509 -noout -subject -issuer -dates
```

Tiene que decir `CN=sistema.odessaoil.com`, emitido por Let's Encrypt.

### Incidente del 20/10/2026 — abierto

Ese día el DNS de `odessaoil.com`, `www` y `sistema` pasó a apuntar a otro
proxy de LatinCloud, que entrega un certificado genérico autofirmado
(`CN=default`, vencido en 2018). El proxy anterior sigue sirviendo el de
Let's Encrypt, y los dos sirven el mismo código: es un problema de
aprovisionamiento del hosting, no del sistema ni del despliegue de ese día.

- **Síntoma:** el navegador muestra `NET::ERR_CERT_AUTHORITY_INVALID` y `curl`
  dice `self-signed certificate`. El panel sigue mostrando el SSL activado.
- **Lo resuelve el soporte de LatinCloud:** tiene que emitir o cargar el
  certificado del dominio en el proxy nuevo.
- **No apagar y prender el SSL del panel para forzarlo.** Se probó, no
  reemitió nada, y cada intento cuenta para los límites de Let's Encrypt.
- **Mientras dure, el vaciado del opcache (punto 2) falla en silencio:** usa
  `curl -s`, que corta por el certificado y no imprime nada. Agregarle `-k`
  hasta que el certificado vuelva a estar bien.

---

## 6. Tarea programada

Sin esta tarea **casi no sale correo**. No hay un proceso escuchando la cola:
cada minuto la tarea corre el planificador (`routes/console.php`), que vacía la
cola de correos (`queue:work --stop-when-empty`) y a las 07:00 genera los
avisos de recertificación.

### Es una URL, no un comando

Las pantallas de tareas programadas de los dos paneles sólo aceptan una URL:
este hosting no tiene cron de consola. Por eso el sistema expone la ruta
`/cron` (`routes/web.php`, nombre `cron.run`), que ejecuta `schedule:run`. Está
cargada así desde el 08/10/2026, para que corra cada minuto:

- **URL:** `https://sistema.odessaoil.com/cron`
- **Autenticación básica:** usuario y clave de `ODESSA_CRON_USER` y
  `ODESSA_CRON_PASSWORD` del `.env`, en los campos de autenticación del panel.
  Nunca en la URL: queda escrita en el panel, en los accesos del servidor y en
  cualquier referer.
- Si alguna de las dos variables está vacía, la ruta responde 404: no queda
  abierta por olvido. Acepta hasta 10 llamadas por minuto.

Para cambiar la clave: editarla con `nano .env`, correr `config:cache`, vaciar
el opcache (punto 2) y recién ahí actualizarla en el panel. Saltear el opcache
da un 401 con la clave correcta: es lo que pasó el 08/10/2026.

### Cómo saber si está andando

Cada corrida se agrega a `storage/logs/cron.log`. Tiene que haber una por
minuto:

```bash
tail -5 storage/logs/cron.log
```

Lo que **no** depende de la tarea:
- El aviso de la campana: se escribe en el momento.
- El correo de «olvidé mi contraseña»: no pasa por la cola, sale en el momento.
- El «Restablecer acceso» de Usuarios: no manda correo. Genera una contraseña
  temporal que el administrador le pasa al usuario.

Todos los demás correos esperan en la cola, **incluido el de bienvenida** que
sale al crear un usuario, con el enlace para elegir su contraseña (vale 7 días
y sirve una sola vez). Sin la tarea, el usuario se crea igual y la contraseña
temporal para WhatsApp funciona, pero el correo no sale hasta que la tarea
corra.

Para ver si quedaron correos esperando en la cola (tiene que tender a 0):

```bash
php artisan tinker --execute='echo DB::table("jobs")->count().PHP_EOL;'
```

Ojo: la primera vez que corra, manda de una vez todo lo que se haya acumulado.

---

## 7. Configurar el correo, paso a paso

1. **Salida SMTP.** Ver el punto 4: con los puertos cerrados, crear una casilla
   no alcanza. Hace falta **CloudSMTP** (en Correo Electrónico, la tarjeta
   «Tipo de servidor» → «Activa ahora tu CloudSMTP») o un proveedor por API.
   Pedirle a LatinCloud el servidor saliente y el puerto.
2. **Casilla remitente**, por ejemplo `noreply@odessaoil.com`, en Correo
   Electrónico → Usuarios de correo → Agregar.
3. **Probar la conexión desde el servidor** antes de tocar el `.env`
   (reemplazar el servidor y el puerto):

   ```bash
   php -r '$s=@fsockopen("SERVIDOR_SALIENTE",587,$e,$m,10); echo $s ? "ABIERTO\n" : "CERRADO: $m\n";'
   ```

   Si dice CERRADO, el problema sigue siendo de red y no tiene sentido
   configurar nada todavía.
4. **Completar el `.env`** con `nano .env`. La clave se escribe ahí, nunca por
   el chat:
   `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`,
   `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`. Para el puerto 587 va
   `MAIL_SCHEME=smtp`; para el 465, `MAIL_SCHEME=smtps`.
5. `php artisan config:cache` y **vaciar el opcache** (punto 2: no hay botón
   para reiniciar PHP-FPM).
6. **Prueba de envío** (reemplazar el correo):

   ```bash
   php artisan tinker --execute='Illuminate\Support\Facades\Mail::raw("Prueba de envío del sistema", fn ($m) => $m->to("TU@CORREO")->subject("Prueba Odessa"));'
   ```

   Revisar también la carpeta de spam. Si llega, los usuarios ya creados
   pueden pedir su enlace desde «¿Olvidó su contraseña?».

---

## Despliegue, paso a paso

`deploy/empaquetar.sh` arma el paquete y **ya incluye `vendor/` resuelto**
contra la plataforma correcta (`config.platform.php` en `composer.json` está
fijado a la versión de consola del servidor). En un despliegue normal no hace
falta correr Composer allá.

```bash
cd /www/odessaoil.com/sistema.odessaoil.com

# 1. Subir y descomprimir el paquete

# 2. Base de datos
php artisan migrate --force

# 3. Cachés
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 4. Vaciar el opcache  <-- ver punto 2, no es opcional
```

Sólo si por algún motivo hay que reinstalar dependencias en el servidor
—paquete incompleto, cambio de versión de PHP—, va con el flag del punto 1:

```bash
composer install --no-dev --optimize-autoloader --ignore-platform-req=ext-zip
```

## Después de desplegar

- [ ] `APP_DEBUG=false` en el `.env`
- [ ] No quedó ningún `test.php`, `phpinfo.php` ni similar en `public/`
- [ ] `.env` con `chmod 600`, y **fuera** de `public/`
- [ ] Se vació el opcache y el comando del punto 2 imprimió `bool(true)` (si
      no, seguís viendo la versión anterior)
- [ ] Las contraseñas de demostración se cambiaron después de las pruebas

El paquete se arma con lista blanca de carpetas, así que `deploy/`, `docs/`,
`tests/`, `.claude/` y el `.env` no pueden colarse. Si alguna vez se sube algo
a mano, revisar que no queden.

## Bloque 3 (29/09/2026): re-sembrar permisos al desplegar

El rol **Taller** ganó el permiso `shipments.create` (cargar envíos por una
organización). Como es un permiso nuevo asignado a un rol, hay que correr el
seeder de permisos en el despliegue, o el botón «Nuevo envío» no le va a
aparecer al taller:

```bash
php artisan db:seed --class=RolePermissionSeeder --force
```

Es idempotente y no toca las asignaciones usuario-rol. Además, este paquete
trae dos migraciones nuevas (`must_change_password` en usuarios y el ensanche
de `contact_email` a texto), así que el `php artisan migrate --force` es
obligatorio.

## Bloque 2 (27/09/2026): revisar la organización de los usuarios cliente

Hasta esta versión, el alta de usuarios desde la pantalla asignaba **todo
usuario cliente a la organización número 1**, se eligiera la que se eligiera
(detalle en `docs/cambios-devolucion-negocio.md`, bloque 2, punto 3). Después
de desplegar, listar los usuarios cliente con su empresa y confirmar uno por
uno que cada uno esté donde corresponde:

```bash
php artisan tinker --execute='App\Models\User::withoutGlobalScopes()->whereNotNull("organization_id")->with("organization:id,name")->orderBy("organization_id")->get()->each(fn ($u) => print($u->organization?->name." | ".$u->email." | alta ".$u->created_at.PHP_EOL));'
```

Si alguno quedó mal, se corrige desde la base (no hay pantalla para mudar
un usuario de empresa, a propósito): `organization_id` del usuario al id de
su organización.
