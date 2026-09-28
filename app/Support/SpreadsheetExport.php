<?php

namespace App\Support;

use Closure;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\CSV\Options as CsvOptions;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Descarga de listados como planilla.
 *
 * Se escribe en streaming y no se arma el archivo en memoria: una empresa con
 * miles de piezas reventaría el límite de PHP en hosting compartido. El
 * contenido se produce con un generador, así que la consulta también se
 * recorre por tandas y nunca se carga entera.
 *
 * El formato depende de lo que el servidor pueda hacer. Un .xlsx es un ZIP
 * por dentro, así que sin la extensión `zip` de PHP no hay forma de generarlo.
 * En ese caso se entrega un CSV con BOM, que Excel abre igual y respeta los
 * acentos. Se prefiere degradar el formato antes que devolverle un error 500
 * a quien sólo quería bajarse su listado.
 */
class SpreadsheetExport
{
    /** ¿Puede este servidor generar un .xlsx? */
    public static function supportsXlsx(): bool
    {
        return extension_loaded('zip');
    }

    /**
     * @param  array<int, string>  $headings
     * @param  Closure(): iterable<array<int, mixed>>  $rows
     * @param  bool|null  $asXlsx  Fuerza el formato. Sólo para pruebas: en uso
     *                             real lo decide lo que el servidor soporte.
     */
    public static function download(
        string $filename,
        array $headings,
        Closure $rows,
        ?bool $asXlsx = null,
    ): StreamedResponse {
        $xlsx = $asXlsx ?? self::supportsXlsx();

        // La extensión del nombre tiene que coincidir con el contenido: un
        // .xlsx que por dentro es CSV hace que Excel muestre un aviso feo.
        $filename = self::withExtension($filename, $xlsx ? 'xlsx' : 'csv');

        $callback = $xlsx
            ? fn () => self::writeXlsx($headings, $rows)
            : fn () => self::writeCsv($headings, $rows);

        return response()->streamDownload($callback, $filename, [
            'Content-Type' => $xlsx
                ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                : 'text/csv; charset=UTF-8',
            // Sin esto, un proxy intermedio puede servir una copia vieja del
            // archivo a otro usuario, y son datos de una empresa concreta.
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Varias hojas en un mismo archivo: un reporte con secciones.
     *
     * Sin la extensión `zip` no hay hojas posibles, así que el CSV lleva las
     * secciones una tras otra, cada una con su título, separadas por una
     * fila en blanco.
     *
     * @param  list<array{name: string, headings: array<int, string>, rows: Closure(): iterable<array<int, mixed>>}>  $sheets
     */
    public static function downloadSheets(string $filename, array $sheets, ?bool $asXlsx = null): StreamedResponse
    {
        $xlsx = $asXlsx ?? self::supportsXlsx();
        $filename = self::withExtension($filename, $xlsx ? 'xlsx' : 'csv');

        $callback = function () use ($xlsx, $sheets) {
            if ($xlsx) {
                $writer = new XlsxWriter(tap(new XlsxOptions, fn ($o) => $o->DEFAULT_COLUMN_WIDTH = 20));
                $writer->openToFile('php://output');

                foreach ($sheets as $i => $sheet) {
                    if ($i > 0) {
                        $writer->addNewSheetAndMakeItCurrent();
                    }

                    // Excel limita el nombre de la hoja a 31 caracteres.
                    $writer->getCurrentSheet()->setName(mb_substr($sheet['name'], 0, 31));
                    $writer->getCurrentSheet()->setSheetView((new SheetView)->setFreezeRow(2));
                    $writer->addRow(Row::fromValues($sheet['headings'], self::headingStyle()));

                    foreach ($sheet['rows']() as $row) {
                        $writer->addRow(Row::fromValues($row));
                    }
                }

                $writer->close();

                return;
            }

            $writer = new CsvWriter(tap(new CsvOptions, fn ($o) => $o->SHOULD_ADD_BOM = true));
            $writer->openToFile('php://output');

            foreach ($sheets as $i => $sheet) {
                if ($i > 0) {
                    $writer->addRow(Row::fromValues([]));
                }

                $writer->addRow(Row::fromValues([$sheet['name']]));
                $writer->addRow(Row::fromValues($sheet['headings']));

                foreach ($sheet['rows']() as $row) {
                    $writer->addRow(Row::fromValues($row));
                }
            }

            $writer->close();
        };

        return response()->streamDownload($callback, $filename, [
            'Content-Type' => $xlsx
                ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                : 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * @param  array<int, string>  $headings
     * @param  Closure(): iterable<array<int, mixed>>  $rows
     */
    private static function writeXlsx(array $headings, Closure $rows): void
    {
        $options = new XlsxOptions;
        $options->DEFAULT_COLUMN_WIDTH = 20;

        $writer = new XlsxWriter($options);
        $writer->openToFile('php://output');

        // La primera fila queda fijada: al bajar por miles de filas, los
        // encabezados tienen que seguir a la vista.
        $writer->getCurrentSheet()->setSheetView((new SheetView)->setFreezeRow(2));

        $writer->addRow(Row::fromValues($headings, self::headingStyle()));

        foreach ($rows() as $row) {
            $writer->addRow(Row::fromValues($row));
        }

        $writer->close();
    }

    /**
     * @param  array<int, string>  $headings
     * @param  Closure(): iterable<array<int, mixed>>  $rows
     */
    private static function writeCsv(array $headings, Closure $rows): void
    {
        $options = new CsvOptions;
        // El BOM es lo que hace que Excel reconozca el UTF-8; sin él los
        // acentos y las eñes salen rotos al abrir el archivo.
        $options->SHOULD_ADD_BOM = true;

        $writer = new CsvWriter($options);
        $writer->openToFile('php://output');

        $writer->addRow(Row::fromValues($headings));

        foreach ($rows() as $row) {
            $writer->addRow(Row::fromValues($row));
        }

        $writer->close();
    }

    private static function headingStyle(): Style
    {
        return (new Style)
            ->setFontBold()
            ->setCellAlignment(CellAlignment::LEFT);
    }

    /**
     * Nombre de archivo seguro y descriptivo: incluye a quién pertenecen los
     * datos y cuándo se generaron, porque estas planillas terminan circulando
     * por correo y sin eso no hay forma de saber cuál es cuál.
     *
     * La extensión la decide `download()` según lo que el servidor soporte.
     */
    public static function filename(string $base, ?string $organization = null): string
    {
        $parts = array_filter([
            $base,
            $organization ? str($organization)->slug()->toString() : null,
            now()->format('Y-m-d'),
        ]);

        return implode('-', $parts).'.xlsx';
    }

    private static function withExtension(string $filename, string $extension): string
    {
        return preg_replace('/\.[A-Za-z0-9]+$/', '', $filename).'.'.$extension;
    }
}
