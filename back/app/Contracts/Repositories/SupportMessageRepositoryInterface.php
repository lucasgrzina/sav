<?php

namespace App\Contracts\Repositories;

use App\Models\SupportMessage;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

interface SupportMessageRepositoryInterface
{
    public function findByGuid(string $guid): ?SupportMessage;
    public function findByGuidWithReplies(string $guid): ?SupportMessage;
    public function listForUser(User $user, int $perPage): LengthAwarePaginator;
    public function listAll(int $perPage): LengthAwarePaginator;
    public function create(array $data): SupportMessage;
    public function updateStatus(SupportMessage $message, string $status): SupportMessage;
    public function close(SupportMessage $message, array $data): SupportMessage;
    public function destroy(Model $model): bool|null;
}
