<?php

namespace App\Contracts\Repositories;

use App\Enums\ExportStatus;
use App\Models\Export;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ExportRepositoryInterface
{
    public function create(array $data): Export;
    public function findByGuid(string $guid): ?Export;
    public function updateStatus(Export $export, ExportStatus $status, array $extra = []): Export;
    public function listForUser(User $user, int $perPage): LengthAwarePaginator;
    public function deleteExpired(): int;
}
