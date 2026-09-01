<?php

namespace Tests\Feature;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PushSubscriptionControllerTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'uuid' => 'subscription-uuid-1',
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
            'keys' => [
                'p256dh' => 'p256dh-key-value',
                'auth' => 'auth-key-value',
            ],
            'content_encoding' => 'aes128gcm',
            'device_label' => 'Chrome en Android',
            'updated_at' => now()->toIso8601String(),
        ], $overrides);
    }

    public function test_store_creates_a_new_subscription(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/push/subscriptions', $this->validPayload());

        $response->assertStatus(200)->assertJsonMissing(['id']);
        $cloudId = $response->json('data.cloud_id');
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $cloudId,
        );

        $this->assertDatabaseHas('push_subscriptions', [
            'guid' => $cloudId,
            'user_id' => $user->id,
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
        ]);
    }

    public function test_store_upserts_when_the_same_endpoint_is_posted_twice(): void
    {
        $user = User::factory()->create();
        $payload = $this->validPayload();

        $first = $this->actingAs($user, 'sanctum')->postJson('/api/v1/push/subscriptions', $payload);
        $second = $this->actingAs($user, 'sanctum')->postJson('/api/v1/push/subscriptions', $payload);

        $first->assertStatus(200);
        $second->assertStatus(200);
        $this->assertSame($first->json('data.cloud_id'), $second->json('data.cloud_id'));
        $this->assertSame(1, PushSubscription::count());
    }

    public function test_store_reassigns_an_endpoint_already_owned_by_another_user(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $payload = $this->validPayload();

        $this->actingAs($ownerA, 'sanctum')->postJson('/api/v1/push/subscriptions', $payload)->assertStatus(200);
        $this->actingAs($ownerB, 'sanctum')->postJson('/api/v1/push/subscriptions', $payload)->assertStatus(200);

        $this->assertSame(1, PushSubscription::count());
        $this->assertDatabaseHas('push_subscriptions', [
            'endpoint' => $payload['endpoint'],
            'user_id' => $ownerB->id,
        ]);
    }

    public function test_store_ignores_a_user_id_sent_in_the_body(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/push/subscriptions', $this->validPayload(['user_id' => $otherUser->id]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['user_id']);
    }

    public function test_store_requires_authentication(): void
    {
        $this->postJson('/api/v1/push/subscriptions', $this->validPayload())->assertStatus(401);
    }

    public function test_store_validates_required_fields_with_spanish_messages(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/push/subscriptions', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['endpoint', 'keys']);
    }

    public function test_destroy_deletes_an_existing_own_subscription(): void
    {
        $user = User::factory()->create();
        $payload = $this->validPayload();
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/push/subscriptions', $payload)->assertStatus(200);

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/push/subscriptions', ['endpoint' => $payload['endpoint']]);

        $response->assertStatus(204);
        $this->assertSame(0, PushSubscription::count());
    }

    public function test_destroy_returns_404_for_a_non_existent_subscription(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/push/subscriptions', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/nope']);

        $response->assertStatus(404)->assertJsonMissing(['id']);
    }

    public function test_destroy_returns_404_for_a_subscription_owned_by_another_user_and_does_not_delete_it(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $payload = $this->validPayload();
        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/push/subscriptions', $payload)->assertStatus(200);

        $response = $this->actingAs($otherUser, 'sanctum')
            ->deleteJson('/api/v1/push/subscriptions', ['endpoint' => $payload['endpoint']]);

        $response->assertStatus(404);
        $this->assertSame(1, PushSubscription::count());
        $this->assertDatabaseHas('push_subscriptions', [
            'endpoint' => $payload['endpoint'],
            'user_id' => $owner->id,
        ]);
    }

    public function test_destroy_requires_authentication(): void
    {
        $this->deleteJson('/api/v1/push/subscriptions', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/x'])
            ->assertStatus(401);
    }
}
