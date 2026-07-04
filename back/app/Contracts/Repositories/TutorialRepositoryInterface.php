<?php

namespace App\Contracts\Repositories;

use App\Models\Tutorial;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

interface TutorialRepositoryInterface
{
    public function listOrdered(): Collection;

    public function findByGuid(string $guid): ?Tutorial;

    public function create(array $data): Tutorial;

    public function update(Model $model, array $data): Model;

    public function destroy(Model $model): bool|null;
}
