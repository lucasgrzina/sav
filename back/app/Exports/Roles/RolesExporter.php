<?php

namespace App\Exports\Roles;

use App\Contracts\Exports\ExporterInterface;
use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Criteria\Shared\DateRangeCriteria;
use App\Criteria\Shared\SearchCriteria;
use App\Enums\ExportFormat;
use Maatwebsite\Excel\Facades\Excel;

class RolesExporter implements ExporterInterface
{
    public function __construct(
        private readonly RoleRepositoryInterface $roleRepository,
        private readonly ExportFormat $format,
    ) {}

    public function export(array $filters, array $columns, string $filePath): string
    {
        $roles = $this->roleRepository->exportQuery(
            new SearchCriteria($filters['search'] ?? null),
            new DateRangeCriteria($filters['date_from'] ?? null, $filters['date_to'] ?? null),
        )->get();

        $exporter = new RolesExport($roles, $columns);

        $writerType = match ($this->format) {
            ExportFormat::XLSX => \Maatwebsite\Excel\Excel::XLSX,
            ExportFormat::CSV  => \Maatwebsite\Excel\Excel::CSV,
            default            => throw new \InvalidArgumentException('Formato no soportado por RolesExporter'),
        };

        Excel::store($exporter, $filePath, 'local', $writerType);

        return $filePath;
    }

    public function getExtension(): string
    {
        return $this->format->value;
    }

    public function getMimeType(): string
    {
        return $this->format->mimeType();
    }
}
