# Flujos de negocio

Reconstruidos a partir de la auditoría. Cada flujo indica qué está confirmado y qué es supuesto.

## 1. Ciclo de vida de una pieza

```mermaid
stateDiagram-v2
    [*] --> SinInspeccion: alta en el sistema
    SinInspeccion --> Apta: FMR final con dictamen Apto
    SinInspeccion --> NoApta: FMR final con dictamen No Apto

    Apta --> ProximaAVencer: faltan 60 días o menos
    ProximaAVencer --> NoApta: se alcanza la fecha de vencimiento
    ProximaAVencer --> Apta: nueva certificación
    NoApta --> Apta: nueva certificación con dictamen Apto
    Apta --> NoApta: inspección con dictamen No Apto

    Apta --> Apta: servicio operativo (No Aplica)
    NoApta --> NoApta: servicio operativo (No Aplica)
```

**Confirmado:** los cuatro estados, la ventana de 60 días y que los servicios «No Aplica» no alteran el estado.
**Supuesto (S1):** la vigencia exacta por tipo de servicio.

## 2. Proceso de inspección

```mermaid
sequenceDiagram
    participant C as Cliente
    participant R as Recepción
    participant T as Técnico
    participant J as Responsable de taller

    C->>R: envía la pieza
    R->>R: registra el ingreso (fecha comprometida, urgencia)
    R->>T: deriva a inspección
    T->>T: ejecuta el servicio y documenta hallazgos
    T->>T: emite el informe preliminar
    J->>J: revisa y finaliza el FMR
    Note over J: al finalizar se calculan los vencimientos<br/>y se actualiza el estado de cada pieza
    J->>R: libera la pieza
    R->>C: despacha
    Note over C: el portal refleja el nuevo estado al instante
```

**Confirmado:** las etapas de recepción, liberación y despacho; la existencia de informes preliminares y finales.
**Supuesto (S3):** quién libera y quién despacha.

## 3. Cómo se determina la aptitud

Es la regla más importante y la que el sistema actual declara de forma explícita.

```
Para cada pieza:
  1. Tomar todas sus líneas de FMR
  2. Descartar las de informes que no estén en estado final
  3. Descartar los resultados "No Aplica" y "Sin Procesar"
  4. De las que quedan, tomar la de fecha de inspección más reciente
     (ante empate, la de mayor identificador)
  5. Si no queda ninguna          → Sin inspección
     Si el dictamen es No Apto    → No apta
     Si es Apto y ya venció       → No apta
     Si es Apto y vence en ≤60 d  → Próxima a vencer
     En otro caso                 → Apta
```

Implementado en `App\Actions\RecalculateAssetStatus`, con diez pruebas que cubren cada rama.

**Punto que más se malinterpreta:** una intervención operativa posterior *no* invalida ni renueva el dictamen vigente. Una pieza certificada en enero y con un servicio operativo en julio sigue apta hasta el vencimiento de enero.

## 4. Pipeline de taller

```mermaid
stateDiagram-v2
    [*] --> EnTaller: ingreso registrado
    EnTaller --> Liberada: el responsable valida la inspección
    Liberada --> Despachada: recepción entrega la pieza
    Despachada --> [*]
```

- **En taller:** ingresada, sin liberar.
- **Liberada:** inspección validada, aún en planta.
- **Despachada:** entregada al cliente.

«Pendiente» y «Fuera de plazo» **no son etapas**: se calculan comparando los hitos contra la fecha comprometida.

**Reglas aplicadas:** no se puede despachar sin liberar, ni repetir un hito ya cumplido. Cada hito guarda quién y cuándo.

## 5. Alta de una cuenta

```mermaid
sequenceDiagram
    participant V as Visitante
    participant S as Sitio público
    participant A as Administrador

    V->>S: completa la solicitud (nombre, correo, empresa)
    S->>S: valida, aplica honeypot y límite por IP
    S->>A: la solicitud queda pendiente de revisión
    A->>A: revisa y decide
    alt Aprobada
        A->>A: asocia a una organización
        A->>A: crea el usuario con sus roles
        Note over A: contraseña aleatoria descartable;<br/>el usuario define la suya por correo
    else Rechazada
        A->>A: registra el motivo
    end
```

**Confirmado:** el formulario público no pide contraseña; el alta la hace un administrador.

Aprobar una solicitud **no crea el usuario automáticamente**: registra la decisión. El alta se hace desde *Usuarios*, deliberadamente, para que quede claro quién otorgó qué rol.

## 6. Aislamiento de datos

```mermaid
flowchart TD
    A[Petición] --> B{¿Hay sesión?}
    B -->|No| C[Redirige al login]
    B -->|Sí| D{¿Usuario activo?}
    D -->|No| E[Cierra sesión]
    D -->|Sí| F{¿Tiene el permiso de la ruta?}
    F -->|No| G[403]
    F -->|Sí| H[Scope global por organización]
    H --> I{¿La Policy autoriza sobre este recurso?}
    I -->|No| J[403 o 404]
    I -->|Sí| K[Respuesta]
```

Un usuario de cliente queda confinado a su organización por el scope global; el personal interno de Odessa no, pero sus Policies siguen gobernando qué puede hacer.

Un recurso de otra organización devuelve **404, no 403**: no se confirma siquiera que exista.
