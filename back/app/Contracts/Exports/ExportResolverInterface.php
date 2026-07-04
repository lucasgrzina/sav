<?php

namespace App\Contracts\Exports;

interface ExportResolverInterface
{
    /**
     * @param  string $exportType  Tipo de dataset a exportar (e.g. 'users').
     * @param  string $format      Formato de archivo (xlsx, csv, txt, pdf).
     * @return ExporterInterface
     * @throws \InvalidArgumentException Si el tipo o formato no están soportados.
     */
    public function resolve(string $exportType, string $format): ExporterInterface;

    /**
     * Retorna los formatos disponibles para un tipo de exportación dado.
     *
     * @return string[]
     */
    public function availableFormats(string $exportType): array;
}
