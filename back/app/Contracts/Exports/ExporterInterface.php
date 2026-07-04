<?php

namespace App\Contracts\Exports;

interface ExporterInterface
{
    /**
     * Ejecuta la exportación y retorna el path relativo del archivo generado.
     *
     * @param  array  $filters  Filtros de búsqueda (mismos que el listado).
     * @param  array  $columns  Columnas a incluir. Vacío = todas.
     * @param  string $filePath Path relativo donde guardar (disco local).
     * @return string           Path relativo del archivo generado.
     */
    public function export(array $filters, array $columns, string $filePath): string;

    /**
     * Retorna la extensión de archivo que produce este exporter (xlsx, csv, txt, pdf).
     */
    public function getExtension(): string;

    /**
     * Retorna el MIME type del archivo producido.
     */
    public function getMimeType(): string;
}
