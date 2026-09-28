# Bloque 2 · Subproyecto 4 — Informes por organización

Fecha: 27/09/2026. Diseño y plan en un solo documento. Los supuestos van marcados.

## Qué pidió el negocio

«Informes status, vencimiento y trabajos por organización». A las dos preguntas abiertas respondió «las dos cosas»: el informe va **en pantalla y en Excel**, y los **trabajos** incluyen **recepciones e informes FMR emitidos**.

## Decisiones

| Tema | Decisión |
|---|---|
| Dónde | Pantalla nueva «Reportes» (`/reportes`) en el menú Operación, con descarga a Excel. Se llama «Reportes» y no «Informes» para no confundirla con los informes FMR. |
| Quién | Cualquiera que vea informes (`reports.view`). El Excel usa `reports.export`. Lo tienen los cuatro roles, así que no hace falta sembrar permisos nuevos. |
| Alcance | Un cliente ve sólo lo suyo; lo garantiza el scope de organización, igual que en el resto del sistema. El personal de Odessa elige la organización, o ve todas juntas. |
| Status | Cantidad de equipos por estado de aptitud. |
| Vencimientos | Cuántos vencen en 30, 60 y 90 días (acumulado), cuántos ya están **fuera de tiempo para mandarlos** y el listado de los próximos, ordenado por la fecha límite de envío. |
| Trabajos | Ahora en el taller, por cola (pendientes, en proceso, rechazadas, esperando cliente, reparación, listas). En el período: recibidos, despachados, devueltos sin reparar y FMR emitidos, con el detalle de cada uno. |
| Período | Filtro desde / hasta, que se aplica a los trabajos. *Supuesto*: por defecto, los últimos 90 días. |
| Excel | Un solo archivo con cuatro hojas: Resumen (organización, período y todos los indicadores), Vencimientos, Trabajos y FMR emitidos. Si el servidor no pudiera generar .xlsx, las secciones van una tras otra en un CSV. |

## Tareas

1. `SpreadsheetExport::downloadSheets(filename, sheets)`: varias hojas en XLSX y secciones sucesivas en CSV. Reusa el estilo de encabezado y la fila fija.
2. `OrganizationReportController`:
   - `index` renderiza `reports-organization/index`.
   - `export` descarga el Excel.
   - Un único método privado arma los datos a partir de `(orgId|null, desde, hasta)`, así la pantalla y el Excel no pueden divergir.
3. Rutas `/reportes` y `/reportes/exportar`, más el ítem en `navigation.ts`.
4. Página `resources/js/pages/reports-organization/index.tsx`:
   - Filtros (organización sólo para el personal interno, desde y hasta) y el botón Excel.
   - Tres paneles: Estado, Vencimientos y Trabajos.
5. Pruebas (`tests/Feature/OrganizationReportTest.php`):
   - el cliente ve sólo lo suyo aunque mande otra organización;
   - el personal interno filtra por organización;
   - los tramos de vencimiento son acumulados;
   - la cuenta de fuera de tiempo;
   - los trabajos del período (recibidos, despachados, sin reparar, FMR);
   - el Excel se descarga.
6. Bitácora del bloque 2.
