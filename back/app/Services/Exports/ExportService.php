<?php

namespace App\Services\Exports;

use App\Contracts\Exports\ExportResolverInterface;
use App\Contracts\Repositories\ExportRepositoryInterface;
use App\Enums\ExportStatus;
use App\Jobs\ProcessExportJob;
use App\Models\Export;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class ExportService
{
    public function __construct(
        private readonly ExportRepositoryInterface $exportRepository,
        private readonly ExportResolverInterface   $exportResolver,
    ) {}

    public function initiate(
        User   $user,
        string $exportType,
        string $format,
        array  $filters  = [],
        array  $columns  = [],
        bool   $async    = false,
    ): Export {
        $filePath = $this->buildFilePath($user->guid, $format);
        $fileName = $this->buildFileName($exportType, $format);

        $export = $this->exportRepository->create([
            'user_id'    => $user->id,
            'type'       => $exportType,
            'format'     => $format,
            'status'     => ExportStatus::PENDING,
            'file_path'  => $filePath,
            'file_name'  => $fileName,
            'filters'    => $filters,
            'columns'    => $columns,
            'expires_at' => now()->addDays(7),
        ]);

        if ($async) {
            ProcessExportJob::dispatch($export->id);

            return $export;
        }

        return $this->process($export);
    }

    public function process(Export $export): Export
    {
        $this->exportRepository->updateStatus($export, ExportStatus::PROCESSING);

        try {
            $exporter = $this->exportResolver->resolve(
                $export->type->value,
                $export->format->value,
            );

            $exporter->export(
                $export->filters ?? [],
                $export->columns ?? [],
                $export->file_path,
            );

            return $this->exportRepository->updateStatus(
                $export,
                ExportStatus::COMPLETED,
            );
        } catch (\Throwable $e) {
            $this->exportRepository->updateStatus(
                $export,
                ExportStatus::FAILED,
                ['error_message' => $e->getMessage()],
            );

            throw $e;
        }
    }

    public function findByGuid(string $guid): ?Export
    {
        return $this->exportRepository->findByGuid($guid);
    }

    public function listForUser(User $user, int $perPage): LengthAwarePaginator
    {
        return $this->exportRepository->listForUser($user, $perPage);
    }

    private function buildFilePath(string $userGuid, string $format): string
    {
        $yearMonth = now()->format('Y-m');
        $uuid      = Str::uuid()->toString();

        return "exports/{$userGuid}/{$yearMonth}/{$uuid}.{$format}";
    }

    private function buildFileName(string $exportType, string $format): string
    {
        return "{$exportType}_" . now()->format('Ymd_His') . ".{$format}";
    }
}
