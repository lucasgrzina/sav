<?php

namespace App\Exports\Users;

use App\Contracts\Exports\ExporterInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Criteria\Shared\DateRangeCriteria;
use App\Criteria\Users\UserSearchCriteria;
use App\Criteria\Users\UserStatusCriteria;
use App\Enums\ExportFormat;
use Maatwebsite\Excel\Facades\Excel;

class UsersExporter implements ExporterInterface
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly ExportFormat $format,
    ) {}

    public function export(array $filters, array $columns, string $filePath): string
    {
        $users = $this->userRepository->exportQuery(
            new UserSearchCriteria($filters['search'] ?? null),
            new UserStatusCriteria($filters['status'] ?? null),
            new DateRangeCriteria($filters['date_from'] ?? null, $filters['date_to'] ?? null),
        )->get();

        $exporter = new UsersExport($users, $columns);

        $writerType = match ($this->format) {
            ExportFormat::XLSX => \Maatwebsite\Excel\Excel::XLSX,
            ExportFormat::CSV  => \Maatwebsite\Excel\Excel::CSV,
            default            => throw new \InvalidArgumentException('Formato no soportado por UsersExporter'),
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
