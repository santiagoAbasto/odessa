# Manual de usuario

Para clientes de Odessa Oil que consultan el estado de su equipamiento.

## Obtener acceso

El acceso lo otorga un administrador de Odessa. Desde la portada, **Solicitar acceso**: complete nombre, correo y empresa. Recibirá respuesta al correo indicado.

**Nunca elegirá su contraseña en ese formulario.** Cuando su cuenta esté creada recibirá un enlace para definirla usted mismo.

## Iniciar sesión

En `/ingresar`, correo y contraseña. Si la olvidó, **¿Olvidó su contraseña?** le envía un enlace.

Tras cinco intentos fallidos la cuenta se bloquea temporalmente: un minuto la primera vez, y más si insiste. Es una protección, no un error.

## El panel

Lo primero que ve al entrar. Responde tres preguntas:

- **¿Cuánto equipamiento tengo y en qué estado?** Las tarjetas superiores. Cada una lleva a su listado.
- **¿Qué vence pronto?** El gráfico de los próximos 60 días y la lista de próximos a vencer.
- **¿Qué se inspeccionó últimamente?** Los informes recientes.

## Estados de aptitud

| Estado | Significado |
|---|---|
| **Apto** | Certificación vigente |
| **Próximo a vencer** | Apto, pero vence en 60 días o menos |
| **No apto** | Dictamen negativo, o certificación vencida |
| **Sin inspección** | Aún no tiene un dictamen registrado |

El estado siempre aparece con texto además de color.

## Activos

*Activos* reúne todo su equipamiento. Arriba, un conmutador entre **Partes** (piezas con ficha técnica) y **Genéricos** (equipamiento con marca y modelo).

**Buscar.** La caja de búsqueda recorre descripción, número de serie, código y referencia. Desde cualquier pantalla, `⌘K` (o `Ctrl+K`) lleva el foco al buscador global.

**Filtrar.** El botón *Filtros* abre estado, serie, código, referencia, número de FMR y rango de vencimiento. El contador indica cuántos hay activos.

**Ajustar la vista.** *Densidad* cambia la altura de las filas; *Columnas* permite ocultar las que no le interesen. Ambas preferencias se recuerdan.

**Ordenar.** Clic en el encabezado. Un segundo clic invierte el orden.

## Ficha de una pieza

Clic en cualquier fila.

Arriba: **estado**, **vencimiento** con los días que faltan en texto claro, y el **informe que determinó ese estado**.

Debajo, identificación y ficha técnica. Los campos sin dato no se muestran.

A la derecha, la **bitácora**: todas las intervenciones, de la más reciente a la más antigua. Cada entrada indica el resultado, el servicio, la vigencia y sus documentos.

**Detalle importante:** las entradas marcadas **«Determina aptitud»** son las que fijaron el estado. Una intervención operativa posterior queda registrada, pero *no* cambia el estado ni renueva la vigencia. Por eso una pieza puede tener una intervención del mes pasado y seguir mostrando un vencimiento calculado desde una certificación anterior.

## Sets de equipo

Un set agrupa las piezas que se despliegan juntas.

Al abrirlo, lo primero es la **disponibilidad**: si todas sus piezas están vigentes, el set puede salir a operar. Si no, se indica cuántas lo impiden y el desglose por estado.

Debajo, la composición completa en orden de armado. Cualquier fila lleva a la ficha de esa pieza.

## Informes FMR

Cada informe agrupa la inspección de varias piezas en una fecha.

En el detalle verá la cabecera con las métricas, la tabla de **piezas con su dictamen individual** —un mismo informe puede tener resultados distintos según la pieza— y los documentos descargables.

## Documentos

Clic en el nombre del archivo. La descarga queda registrada con su usuario y la fecha, por trazabilidad.

Sólo puede descargar documentos de su propia organización.

## Preferencias

En la barra superior:

- **Tema.** Claro, oscuro o según su sistema operativo.
- **Menú lateral.** *Contraer* lo reduce a iconos y recuerda el estado.
- **Cuenta.** Sus datos y el cierre de sesión.

## Accesibilidad

- Tabulador para recorrer la interfaz; el foco siempre es visible.
- `⌘K` / `Ctrl+K` para el buscador.
- El primer tabulador de cada página ofrece «Saltar al contenido».
- El estado nunca se comunica sólo con color.
- Si su sistema tiene reducción de movimiento activada, las transiciones se desactivan.

## Preguntas frecuentes

**Veo una intervención reciente pero el vencimiento no cambió.**
Es correcto. Sólo las inspecciones con dictamen Apto o No Apto renuevan la vigencia. Los servicios operativos quedan en la bitácora sin alterarla.

**¿Por qué una pieza figura «No apta» si el último informe dice «Apto»?**
Porque esa certificación ya venció. El estado considera el dictamen *y* su fecha de vencimiento.

**Necesito acceso para un compañero.**
Si su rol es *Cliente · Administrador*, puede crearlo desde *Administración → Usuarios*. Si no, pídalo a su administrador o mediante el formulario público.

**No encuentro una pieza que sé que existe.**
Compruebe que está en la pestaña correcta (Partes o Genéricos) y que no tiene filtros activos — el botón *Filtros* muestra un contador.
