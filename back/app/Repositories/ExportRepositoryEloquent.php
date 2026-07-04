<?php

namespace App\Repositories;

use App\Contracts\Repositories\ExportRepositoryInterface;
use App\Enums\ExportStatus;
use App\Models\Export;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;

class ExportRepositoryEloquent extends BaseRepositoryEloquent implements ExportRepositoryInterface
{
    protected function model(): string
    {
        return Export::class;
    }

    public function create(array $data): Export
    {
        return $this->model->newQuery()->create($data);
    }

    public function findByGuid(string $guid): ?Export
    {
        return $this->newQuery()->where('guid', $guid)->first();
    }

    public function updateStatus(Export $export, ExportStatus $status, array $extra = []): Export
    {
        $export->update(array_merge(['status' => $status], $extra));

        return $export->fresh();
    }

    public function listForUser(User $user, int $perPage): LengthAwarePaginator
    {
        return $this->newQuery()
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    public function deleteExpired(): int
    {
        $expired = $this->newQuery()
            ->where('expires_at', '<', now())
            ->whereNotNull('file_path')
            ->get();

        foreach ($expired as $export) {
            Storage::disk('local')->delete($export->file_path);
        }

        return $this->newQuery()
            ->where('expires_at', '<', now())
            ->delete();
    }
}
