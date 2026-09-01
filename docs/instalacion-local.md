# Instalación local

## Requisitos

| Componente | Versión mínima |
|---|---|
| PHP | 8.3 (probado en 8.5) |
| Composer | 2.x |
| MySQL | 8.0 (probado en 9.x) |
| Node.js | 20 (probado en 24) |
| npm | 10 |

Extensiones de PHP: `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `ctype`, `json`, `tokenizer`, `xml`.

## Pasos

**1. Dependencias**

```bash
composer install && npm install
```

**2. Entorno**

```bash
cp .env.example .env && php artisan key:generate
```

**3. Base de datos**

```bash
mysql -u root -e "CREATE DATABASE odessaoil CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

Ajuste en `.env`:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=odessaoil
DB_USERNAME=root
DB_PASSWORD=
```

**4. Migraciones y datos de demostración**

```bash
php artisan migrate --seed
```

El seeder muestra por consola una contraseña generada al azar para los usuarios demo. **Anótela: no se vuelve a mostrar y no queda guardada en ningún archivo.**

Para fijarla usted mismo, defina `DEMO_PASSWORD` en `.env` antes de sembrar.

**5. Almacenamiento**

```bash
mkdir -p storage/app/documents && php artisan storage:link
```

`storage/app/documents` es el disco privado de informes. Nunca debe quedar accesible desde `public/`.

**6. Arranque**

En dos terminales:

```bash
php artisan serve
```

```bash
npm run dev
```

La aplicación queda en `http://localhost:8000`.

## Usuarios de demostración

Todos comparten la contraseña que mostró el seeder.

| Correo | Rol |
|---|---|
| `super@odessaoil.test` | Superadministrador |
| `admin@odessaoil.test` | Administrador de Odessa |
| `taller@odessaoil.test` | Responsable de taller |
| `tecnico@odessaoil.test` | Técnico |
| `recepcion@odessaoil.test` | Recepción |
| `admin.pdg@cliente.test` | Cliente · Administrador |
| `operador.pdg@cliente.test` | Cliente · Operador |
| `consulta.pdg@cliente.test` | Cliente · Consulta |

Existen los mismos perfiles de cliente con el sufijo `.sed` para la segunda organización de prueba. Úselos para comprobar el aislamiento: inicie sesión con uno y verá que no alcanza los datos del otro.

## Comandos habituales

```bash
php artisan test
```

```bash
npm run types
```

```bash
npm run build
```

```bash
./vendor/bin/pint
```

```bash
php artisan migrate:fresh --seed
```

## Problemas frecuentes

**`SQLSTATE[HY000] [2002] Connection refused`** — MySQL no está corriendo. En macOS con Homebrew: `brew services start mysql`.

**Los estilos no cargan** — falta `npm run dev` en una terminal aparte, o falta compilar con `npm run build`.

**`Vite manifest not found`** — ejecute `npm run build` al menos una vez.

**`Class not found` tras mover archivos** — `composer dump-autoload`.

**Las pruebas fallan por base de datos** — usan SQLite en memoria, configurado en `phpunit.xml`; no tocan MySQL.
