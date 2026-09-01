<?php

namespace App\Services;

use App\Contracts\Repositories\PushSubscriptionRepositoryInterface;
use App\Models\PushSubscription;
use App\Models\User;

class PushSubscriptionService
{
    public function __construct(private PushSubscriptionRepositoryInterface $repository) {}

    public function subscribe(User $user, array $data): PushSubscription
    {
        $endpointHash = hash('sha256', $data['endpoint']);
        $existing = $this->repository->findByEndpointHash($endpointHash);

        $payload = [
            'user_id' => $user->id, // DEC-06: siempre el usuario autenticado, pisa el owner previo
            'device_uuid' => $data['uuid'] ?? null,
            'endpoint' => $data['endpoint'],
            'endpoint_hash' => $endpointHash,
            'p256dh' => $data['keys']['p256dh'],
            'auth_key' => $data['keys']['auth'],
            'content_encoding' => $data['content_encoding'] ?? 'aes128gcm',
            'device_label' => $data['device_label'] ?? null,
            'device_updated_at' => $data['updated_at'] ?? null,
        ];

        return $existing === null
            ? $this->repository->create($payload)
            : $this->repository->update($existing, $payload);
    }

    public function unsubscribe(User $user, string $endpoint): bool
    {
        $subscription = $this->repository->findByEndpointHashForUser(
            hash('sha256', $endpoint),
            $user->id,
        );

        return $subscription !== null && (bool) $this->repository->destroy($subscription);
    }
}
