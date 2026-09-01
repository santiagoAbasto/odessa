<?php

/*
|--------------------------------------------------------------------------
| Punto de entrada para cPanel
|--------------------------------------------------------------------------
|
| Este archivo va en el document root del dominio (normalmente
| /home/odessaoiloso/public_html/index.php), NO dentro del proyecto.
|
| La aplicación vive fuera del document root, en /home/odessaoiloso/Odessaoil,
| para que nadie pueda pedir por HTTP el .env, la carpeta storage ni el código.
| Sólo lo que está en public_html queda expuesto.
|
| Reemplaza al public/index.php de Laravel y hace tres cosas más que él:
|   1. Busca vendor/ y bootstrap/ en la carpeta del proyecto, no en ../
|   2. Le dice a Laravel que el "public" real es este directorio, para que
|      asset(), storage:link y las descargas de documentos resuelvan bien.
|   3. Falla con un mensaje legible en vez de una pantalla en blanco si la
|      ruta del proyecto está mal.
|
*/

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

/*
|--------------------------------------------------------------------------
| Ruta del proyecto
|--------------------------------------------------------------------------
|
| Único valor a tocar si algún día se mueve la aplicación de carpeta.
|
| Se resuelve en dos pasos a propósito: primero la ruta absoluta, que es la
| que reportó el hosting; si no existe (por ejemplo tras migrar la cuenta a
| otro servidor y cambiar el nombre de usuario), se cae a la ruta relativa
| —el proyecto como hermano del document root—, que es la misma disposición.
|
*/

$basePath = '/home/odessaoiloso/Odessaoil';

if (! is_dir($basePath)) {
    $basePath = __DIR__.'/../Odessaoil';
}

if (! is_file($basePath.'/vendor/autoload.php')) {
    // El detalle va al log de errores de cPanel, no a la respuesta: la ruta
    // del servidor no tiene por qué verse desde fuera.
    error_log('[odessaoil] No se encontró vendor/autoload.php en: '.$basePath);

    http_response_code(500);
    exit('La aplicación no está disponible. Revise el log de errores.');
}

/*
|--------------------------------------------------------------------------
| Modo mantenimiento
|--------------------------------------------------------------------------
|
| `php artisan down` deja este archivo dentro del proyecto. Se comprueba
| antes de cargar nada más, que es justamente para lo que sirve.
|
*/

if (is_file($maintenance = $basePath.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

/*
|--------------------------------------------------------------------------
| Autoloader de Composer
|--------------------------------------------------------------------------
*/

require $basePath.'/vendor/autoload.php';

/*
|--------------------------------------------------------------------------
| Arranque
|--------------------------------------------------------------------------
|
| usePublicPath() es la línea que no está en el index.php original y sin la
| cual todo el sitio se ve sin estilos: por defecto Laravel asume que public
| es una carpeta del proyecto, y aquí el public servido es este directorio.
|
*/

/** @var Application $app */
$app = require_once $basePath.'/bootstrap/app.php';

$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());
