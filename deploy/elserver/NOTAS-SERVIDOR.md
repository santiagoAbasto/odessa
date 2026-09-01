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

**Después de cada despliegue hay que reiniciar PHP-FPM desde el panel.**
`opcache.enable_cli` está en `Off`, así que los comandos de artisan no se ven
afectados y no hace falta reiniciar para correr una migración.

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

## 4. La salida SMTP está bloqueada

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

Mientras no haya salida, dejar `MAIL_MAILER=log`: los avisos quedan escritos
en `storage/logs/` en lugar de perderse en silencio.

---

## 5. Certificado SSL — PENDIENTE

`sistema.odessaoil.com` presenta el certificado por defecto del servidor:
autofirmado, emitido para `default`, vencido el 15/09/2018. El panel figura
con "SSL Activado" pero la emisión de Let's Encrypt nunca se ejecutó.

El navegador marca el sitio como **No seguro**. Reclamado a Soporte.

Verificar cuando digan que está resuelto:

```bash
echo | openssl s_client -connect sistema.odessaoil.com:443 \
  -servername sistema.odessaoil.com 2>/dev/null \
  | openssl x509 -noout -subject -issuer -dates
```

Tiene que decir `CN=sistema.odessaoil.com`, emitido por Let's Encrypt.

> Aparte, y sin relación con el sistema: el dominio principal **sin** `www`
> también tiene el certificado vencido (desde el 19/11/2024). `www` está bien.

---

## 6. Tarea programada — PENDIENTE

Los avisos de recertificación dependen del planificador. Sin esto, **no se
envía ningún aviso**. Configurar en el panel, cada minuto:

```
cd /www/odessaoil.com/sistema.odessaoil.com && php artisan schedule:run >> /dev/null 2>&1
```

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

# 4. Reiniciar PHP-FPM desde el panel  <-- ver punto 2, no es opcional
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
- [ ] Se reinició PHP-FPM (si no, seguís viendo la versión anterior)
- [ ] Las contraseñas de demostración se cambiaron después de las pruebas

El paquete se arma con lista blanca de carpetas, así que `deploy/`, `docs/`,
`tests/`, `.claude/` y el `.env` no pueden colarse. Si alguna vez se sube algo
a mano, revisar que no queden.
