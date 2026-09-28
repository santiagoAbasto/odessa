# Devolución del negocio — 12/08/2026

Registro de la primera devolución tras la presentación, con lo que se implementó y lo que quedó pendiente de confirmar.

---

## Lo pedido

1. **Roles.** El superadministrador hace todo. El taller ingresa y edita la base de datos. El cliente operador pre-carga los equipos que envía —que el taller autoriza— y consulta en sólo lectura. El cliente consulta accede en sólo lectura.
2. **Aviso por correo** de las solicitudes de acceso, además de la alerta dentro de la aplicación.
3. **Carga de archivos** por piezas y por FMR, según la regla de negocio.

---

## Supuestos que esta devolución resuelve

Tres de los siete supuestos abiertos quedaron cerrados:

| # | Supuesto | Resolución |
|---|---|---|
| **S3** | ¿Quién libera frente a quién despacha? | **El taller hace ambas.** Se elimina la separación de funciones |
| **S5** | ¿El cliente puede iniciar solicitudes de servicio? | **Sí** — pre-carga los equipos que envía, sujeto a autorización del taller |
| **S6** | ¿Existen roles internos separados? | **No.** El taller es un único perfil |

Siguen abiertos: **S1** (vigencia por tipo de servicio), **S2** (numeración y revisión de FMR), **S4** (aprobación más allá de preliminar/final) y **S7** (reglas de notificación por vencimiento).

---

## 1. Roles

De ocho roles a cuatro. Ver [`matriz-roles-permisos.md`](matriz-roles-permisos.md) para la matriz completa.

**Decisión que conviene revisar:** se eliminó el rol *Cliente · Administrador*, porque la definición no lo menciona. Hoy la administración de usuarios es exclusiva del superadministrador. Si un cliente debe poder dar de alta a su propia gente, hay que decirlo: es un permiso, no un rediseño.

---

## 2. Aviso de solicitudes de acceso

Al recibirse una solicitud desde el sitio público, se notifica al superadministrador por **dos canales a la vez**: correo electrónico y alerta dentro de la aplicación.

- El envío va **encolado**: el visitante no espera al servidor de correo ni ve un error si el correo falla.
- El correo incluye nombre, empresa, correo de contacto, teléfono y comentario, con enlace directo a la pantalla de revisión.
- Sólo se notifica a usuarios **internos y activos**. Ningún usuario de cliente recibe avisos de altas de otras empresas.

### Para que los correos salgan de verdad

En desarrollo `MAIL_MAILER=log` escribe el correo en `storage/logs/laravel.log` en lugar de enviarlo. Para producción hay que configurar el servidor SMTP en `.env`:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=
```

Y dejar corriendo el procesador de la cola, porque la notificación está encolada:

```bash
php artisan queue:work
```

En un VPS esto se gestiona con Supervisor; en cPanel, con un cron. Está documentado en [`despliegue-produccion.md`](despliegue-produccion.md).

---

## 3. Archivos adjuntos

### La regla de negocio que se aplicó

Se admite adjuntar en **dos niveles**, porque responden a preguntas distintas:

| Nivel | Qué va aquí | Por qué |
|---|---|---|
| **Del FMR** | Informe firmado, certificado de la inspección | Cubre a **todas** las piezas del informe. Es el documento del acto de inspección |
| **De la pieza** | Ficha del fabricante, certificado de origen, fotografías | Acompaña al activo **toda su vida**, con independencia de cualquier inspección |

La distinción no es cosmética: un certificado de FMR caduca con la inspección; la ficha del fabricante no. Ponerlos en el mismo sitio obligaría a mirar la fecha para saber cuál es cuál.

En el detalle de una pieza se ven ambos: los del FMR aparecen dentro de cada entrada de la bitácora, y los propios en su panel aparte.

### Quién puede subir

Sólo **taller** y **superadministrador**. Los clientes ven y descargan, pero no suben — es coherente con «acceso a la base de datos sólo lectura».

### Medidas de seguridad aplicadas

- El archivo se guarda con **nombre aleatorio**; el original sólo se conserva como dato. Un nombre malicioso nunca toca el sistema de archivos.
- El tipo se valida por el **contenido real**, no por la extensión ni por lo que declara el navegador, y además se exige que ambos coincidan.
- Se calcula **sha256** para detectar duplicados y verificar integridad.
- Se guardan en un **disco privado fuera de `public/`**. No existe URL directa: la única salida es el controlador de descarga, que autoriza y audita.
- Máximo 20 MB. Sólo PDF, JPG, PNG y WEBP.
- Al eliminar un documento se borra el binario de verdad; el registro queda con baja lógica para conservar la trazabilidad.

### Cambio técnico

Los documentos pasaron de una tabla exclusiva de FMR a una tabla **polimórfica única**. El motivo: los documentos son lo más sensible del sistema, y tener dos caminos de subida y descarga duplicaría la superficie donde puede colarse un fallo de acceso.

---

## 4. Envíos pre-cargados por el cliente

Módulo nuevo, derivado del punto 1.

```
Cliente crea el envío (borrador)
        ↓  declara los equipos que manda
Remite al taller  →  esperando autorización
        ↓
Taller revisa
    ├── Autoriza  → se crean las recepciones; entran al pipeline
    └── Rechaza   → con motivo visible para el cliente
```

### Reglas aplicadas

- **Hasta que el taller autoriza, un envío es sólo una declaración.** Nada entra al inventario ni al pipeline antes de eso.
- Un envío remitido **deja de ser editable** por el cliente.
- No se puede remitir un envío **sin equipos**.
- No se puede autorizar ni rechazar **dos veces**.
- Rechazar **exige un motivo**.
- El cliente sólo puede declarar **piezas de su propia organización**; intentarlo con una ajena se rechaza.

### El equipo declarado que aún no existe

El cliente puede declarar una pieza que todavía no está registrada, describiéndola. Al autorizar, **el taller la da de alta** en el inventario y crea su recepción.

Se resolvió así deliberadamente: el alta de inventario sigue siendo responsabilidad del taller —«taller ingresa y edita la base de datos»—, pero el cliente no queda bloqueado esperando a que alguien registre la pieza antes de poder declarar el envío.

En la pantalla del taller esas líneas aparecen marcadas como **«Alta pendiente»**, para que se vea qué se va a crear antes de confirmar.

---

## Bug encontrado y corregido

El cambio de roles destapó un fallo real que estaba latente.

**El superadministrador podía desactivar su propia cuenta**, dejando potencialmente el sistema sin ningún administrador.

La guarda «nadie se desactiva a sí mismo» vivía en la Policy, pero el superadministrador atraviesa todas las Policies mediante `Gate::before`. Es decir: la única cuenta capaz de causar el daño era justamente la única que podía saltarse la comprobación.

La guarda se movió al controlador, como invariante y no como permiso. Cubierto por prueba.

No se había detectado antes porque la prueba usaba un rol administrativo intermedio que ya no existe.

---

## Los estados del taller no son lineales

El negocio corrigió el recorrido: *«tener en cuenta que los estados no son lineales»*. No se refería a ciclos ni a vueltas atrás, sino a que **el recorrido se bifurca dos veces**, y la segunda bifurcación no la decide el taller.

```
Pendiente → En proceso → Aprobada ──────────────────────────→ Despachada
                       ↘ Rechazada → A reparar ─┬─ SÍ → Reparada ↗
                                  (lo decide    │
                                   el cliente)  └─ NO → Despachada sin reparar
```

### Por qué la decide el cliente

Porque la reparación se le cobra. Hasta ahora el taller mandaba a reparar por su cuenta y esa decisión no quedaba registrada en ningún lado, con dos consecuencias:

- **No había con qué respaldar el cobro.** Si el cliente después discutía el arreglo, no existía constancia de que lo hubiera autorizado.
- **Una recepción rechazada podía quedar trabada para siempre.** Si el cliente no quería reparar, el equipo no tenía salida: despacharlo estaba bloqueado —correctamente— y no existía ninguna otra opción.

### Qué pasa si el cliente no autoriza

El negocio lo definió textual: *«en general el equipo se devuelve sin reparar; la opción válida sería Despachado y marcado como No Apto»*.

Es decir, no se descarta: **vuelve al cliente tal como está, sin certificar**. La recepción termina en «Despachada sin reparar», que se distingue de un despacho normal para que nadie lea como cierre feliz lo que es un equipo que salió no apto.

### La respuesta puede llegar por dos vías

El cliente contesta desde su panel, o Odessa registra lo que recibió por teléfono o correo. Se guarda **por qué vía llegó**, y en el segundo caso **es obligatorio adjuntar el respaldo** —captura del correo o del mensaje—, porque es lo que justifica el cobro.

El canal no se elige libremente: el sistema rechaza que Odessa declare «desde el panel del cliente». Si hubiera venido del panel, lo habría cargado el cliente. Sin esa restricción, marcar «panel» sería un atajo para saltearse la evidencia y el registro dejaría de valer.

### Efecto secundario deseable

Para poder contestar, el cliente necesita ver sus equipos en el taller. Eso responde de paso la pregunta que todo cliente hace igual por teléfono: **dónde está mi equipo**. Sigue sin poder operar ninguna etapa del taller.

---

## Estado

| Elemento | Estado |
|---|---|
| Cuatro roles con su matriz | Implementado y probado |
| Aviso por correo y en la aplicación | Implementado y probado |
| Adjuntos por FMR y por pieza | Implementado y probado |
| Módulo de envíos con autorización | Implementado y probado |
| Corrección de la auto-desactivación | Implementado y probado |
| Bifurcación de la reparación con decisión del cliente | Implementado y probado |
| Devolución sin reparar como salida válida | Implementado y probado |
| Evidencia adjunta de la autorización | Implementado y probado |

---

## Pendiente de confirmar

1. **¿El cliente debe poder gestionar sus propios usuarios?** Hoy no puede; la definición no lo menciona.
2. ~~**¿El código heredado del sistema anterior debe renombrarse?**~~ **Resuelto.** El negocio pidió que no quede ningún rastro de la marca anterior. La columna pasó a llamarse `code` y la interfaz muestra sólo «Código». Los valores grabados en las piezas no se tocaron: cambió la etiqueta, no el dato.
3. **¿Qué hace el taller con un envío parcialmente correcto?** Hoy es todo o nada: autoriza o rechaza el envío completo. Si hace falta aceptar unos equipos y rechazar otros, es un cambio acotado.
4. **¿Debe avisarse al cliente cuando su envío se autoriza o rechaza?** Hoy lo ve al entrar. El mecanismo de notificación ya está montado y reutilizable.
