<?php

namespace App\Contracts\Repositories;

use App\Models\PushSubscription;
use Illuminate\Database\Eloquent\Model;

interface PushSubscriptionRepositoryInterface
{
    public function findByEndpointHash(string $endpointHash): ?PushSubscription;

    public function findByEndpointHashForUser(string $endpointHash, int $userId): ?PushSubscription;

    public function create(array $data): PushSubscription;

    public function update(Model $subscription, array $data): PushSubscription;

    public function destroy(Model $subscription): bool|null;
}
