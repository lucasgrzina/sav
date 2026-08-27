<?php

namespace App\Repositories;

use App\Contracts\Repositories\PushSubscriptionRepositoryInterface;
use App\Models\PushSubscription;
use Illuminate\Database\Eloquent\Model;

class PushSubscriptionRepositoryEloquent extends BaseRepositoryEloquent implements PushSubscriptionRepositoryInterface
{
    protected function model(): string
    {
        return PushSubscription::class;
    }

    public function findByEndpointHash(string $endpointHash): ?PushSubscription
    {
        return $this->newQuery()->where('endpoint_hash', $endpointHash)->first();
    }

    public function findByEndpointHashForUser(string $endpointHash, int $userId): ?PushSubscription
    {
        return $this->newQuery()
            ->where('endpoint_hash', $endpointHash)
            ->where('user_id', $userId)
            ->first();
    }

    public function create(array $data): PushSubscription
    {
        return $this->model->newQuery()->create($data);
    }

    public function update(Model $subscription, array $data): PushSubscription
    {
        $subscription->fill($data);
        $subscription->save();

        /** @var PushSubscription $subscription */
        return $subscription;
    }

    public function destroy(Model $subscription): bool|null
    {
        return $subscription->delete();
    }
}
