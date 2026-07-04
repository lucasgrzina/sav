<?php

namespace App\Exports\Pdf;

use App\Contracts\Exports\ExporterInterface;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

abstract class BasePdfExporter implements ExporterInterface
{
    /**
     * Título que aparece en el encabezado del PDF.
     */
    abstract protected function title(): string;

    /**
     * Definición de todas las columnas posibles para este tipo de exportación.
     * Formato: ['clave' => 'Etiqueta visible']
     */
    abstract protected function allColumnDefinitions(): array;

    /**
     * Obtiene los datos ya filtrados del repositorio correspondiente.
     */
    abstract protected function fetchData(array $filters): Collection;

    /**
     * Mapea un registro del modelo a un array de valores escalares,
     * respetando el orden de $activeKeys.
     *
     * @param  mixed    $record
     * @param  string[] $activeKeys  Claves de las columnas seleccionadas
     * @return string[]
     */
    abstract protected function mapRow(mixed $record, array $activeKeys): array;

    /**
     * Vista Blade a renderizar. Sobreescribir en la subclase para usar
     * una plantilla personalizada en lugar de la genérica.
     */
    protected function view(): string
    {
        return 'exports.generic';
    }

    protected function paperSize(): string        { return 'a4'; }
    protected function paperOrientation(): string { return 'landscape'; }

    final public function export(array $filters, array $columns, string $filePath): string
    {
        $data = $this->fetchData($filters);

        $allDefs = $this->allColumnDefinitions();
        $activeColumns = empty($columns)
            ? $allDefs
            : array_intersect_key($allDefs, array_flip($columns));

        $rows = $data
            ->map(fn ($record) => $this->mapRow($record, array_keys($activeColumns)))
            ->all();

        $pdf = Pdf::loadView($this->view(), [
            'title'         => $this->title(),
            'activeColumns' => $activeColumns,  // ['clave' => 'Etiqueta']
            'rows'          => $rows,           // array de arrays de strings
            'total'         => count($rows),
        ])->setPaper($this->paperSize(), $this->paperOrientation());

        Storage::disk('local')->put($filePath, $pdf->output());

        return $filePath;
    }

    final public function getExtension(): string { return 'pdf'; }
    final public function getMimeType(): string  { return 'application/pdf'; }
}
