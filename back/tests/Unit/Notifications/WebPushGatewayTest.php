<?php

namespace Tests\Unit\Notifications;

use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\Data\OutboundMessage;
use App\Notifications\Data\PushContent;
use App\Notifications\Data\Recipient;
use App\Notifications\Enums\Channel;
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Gateways\WebPush\WebPushGateway;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\WebPush;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class WebPushGatewayTest extends TestCase
{
    use RefreshDatabase;

    private function createSubscription(int $userId, string $endpoint): PushSubscription
    {
        return PushSubscription::create([
            'user_id' => $userId,
            'endpoint' => $endpoint,
            'endpoint_hash' => hash('sha256', $endpoint),
            'p256dh' => 'p256dh-key',
            'auth_key' => 'auth-key',
            'content_encoding' => 'aes128gcm',
        ]);
    }

    private function message(int $userId, string $body = 'hola'): OutboundMessage
    {
        return new OutboundMessage(
            recipient: new Recipient(userId: $userId, phone: null, name: 'Juan', channel: Channel::Push),
            content: new PushContent(title: 'Título', body: $body, tag: 'alert-1', url: 'programas/1', data: []),
            channel: Channel::Push,
            idempotencyKey: 'key-1',
        );
    }

    private function report(string $endpoint, bool $success, ?int $status = null, ?string $body = null, string $reason = 'OK'): MessageSentReport
    {
        $request = new Request('POST', $endpoint);
        $response = $status !== null ? new Response($status, [], $body ?? '') : null;

        return new MessageSentReport($request, $response, $success, $reason);
    }

    public function test_a_single_subscription_sent_successfully(): void
    {
        $user = User::factory()->create();
        $subscription = $this->createSubscription($user->id, 'https://fcm.test/endpoint-1');

        $client = Mockery::mock(WebPush::class);
        $client->shouldReceive('queueNotification')->once();
        $client->shouldReceive('flush')->once()->andReturn((function () use ($subscription) {
            yield $this->report($subscription->endpoint, true, 201);
        })());

        $result = (new WebPushGateway($client))->send($this->message($user->id));

        $this->assertSame(DeliveryStatus::Sent, $result->status);
    }

    public function test_no_active_subscriptions_returns_failed_without_calling_the_client(): void
    {
        $user = User::factory()->create();

        $client = Mockery::mock(WebPush::class);
        $client->shouldNotReceive('queueNotification');
        $client->shouldNotReceive('flush');

        $result = (new WebPushGateway($client))->send($this->message($user->id));

        $this->assertSame(DeliveryStatus::Failed, $result->status);
        $this->assertSame('sin_suscripciones_activas', $result->failureReason);
    }

    public function test_two_subscriptions_one_success_one_4xx_still_counts_as_sent_and_keeps_the_failed_row(): void
    {
        $user = User::factory()->create();
        $subA = $this->createSubscription($user->id, 'https://fcm.test/a');
        $subB = $this->createSubscription($user->id, 'https://fcm.test/b');

        $client = Mockery::mock(WebPush::class);
        $client->shouldReceive('queueNotification')->twice();
        $client->shouldReceive('flush')->once()->andReturn((function () use ($subA, $subB) {
            yield $this->report($subA->endpoint, true, 201);
            yield $this->report($subB->endpoint, false, 400, 'bad request', 'Bad Request');
        })());

        $result = (new WebPushGateway($client))->send($this->message($user->id));

        $this->assertSame(DeliveryStatus::Sent, $result->status);
        $this->assertDatabaseHas('push_subscriptions', ['id' => $subB->id]);
    }

    public function test_expired_subscription_is_deleted_and_counts_as_failure_when_it_was_the_only_one(): void
    {
        $user = User::factory()->create();
        $subscription = $this->createSubscription($user->id, 'https://fcm.test/expired');

        $client = Mockery::mock(WebPush::class);
        $client->shouldReceive('queueNotification')->once();
        $client->shouldReceive('flush')->once()->andReturn((function () use ($subscription) {
            yield $this->report($subscription->endpoint, false, 410, '', 'Gone');
        })());

        $result = (new WebPushGateway($client))->send($this->message($user->id));

        $this->assertSame(DeliveryStatus::Failed, $result->status);
        $this->assertDatabaseMissing('push_subscriptions', ['id' => $subscription->id]);
    }

    public function test_all_subscriptions_failing_transiently_throws_for_the_queue_to_retry(): void
    {
        $user = User::factory()->create();
        $subscription = $this->createSubscription($user->id, 'https://fcm.test/timeout');

        $client = Mockery::mock(WebPush::class);
        $client->shouldReceive('queueNotification')->once();
        $client->shouldReceive('flush')->once()->andReturn((function () use ($subscription) {
            yield $this->report($subscription->endpoint, false, 503, 'oops', 'Service Unavailable');
        })());

        $this->expectException(RuntimeException::class);

        (new WebPushGateway($client))->send($this->message($user->id));
    }

    public function test_vapid_key_mismatch_logs_critical_and_keeps_the_subscription(): void
    {
        Log::spy();

        $user = User::factory()->create();
        $subscription = $this->createSubscription($user->id, 'https://fcm.test/vapid-mismatch');

        $client = Mockery::mock(WebPush::class);
        $client->shouldReceive('queueNotification')->once();
        $client->shouldReceive('flush')->once()->andReturn((function () use ($subscription) {
            yield $this->report($subscription->endpoint, false, 403, '{"error":"VapidPkHashMismatch"}', 'Forbidden');
        })());

        $result = (new WebPushGateway($client))->send($this->message($user->id));

        $this->assertSame(DeliveryStatus::Failed, $result->status);
        $this->assertDatabaseHas('push_subscriptions', ['id' => $subscription->id]);
        Log::shouldHaveReceived('critical')->once();
    }

    public function test_body_longer_than_the_limit_is_truncated_while_the_rest_stays_intact(): void
    {
        $user = User::factory()->create();
        $subscription = $this->createSubscription($user->id, 'https://fcm.test/truncate');

        $longBody = str_repeat('x', 600);
        $capturedPayload = null;

        $client = Mockery::mock(WebPush::class);
        $client->shouldReceive('queueNotification')
            ->once()
            ->withArgs(function ($subscriptionArg, $payload) use (&$capturedPayload) {
                $capturedPayload = $payload;

                return true;
            });
        $client->shouldReceive('flush')->once()->andReturn((function () use ($subscription) {
            yield $this->report($subscription->endpoint, true, 201);
        })());

        (new WebPushGateway($client))->send($this->message($user->id, $longBody));

        $decoded = json_decode($capturedPayload, true);
        $this->assertLessThan(600, strlen($decoded['body']));
        $this->assertSame('Título', $decoded['title']);
        $this->assertSame('alert-1', $decoded['tag']);
        $this->assertSame('programas/1', $decoded['url']);
    }

    public function test_multiple_expired_subscriptions_are_each_deleted_correctly_via_endpoint_matching(): void
    {
        $user = User::factory()->create();
        $subA = $this->createSubscription($user->id, 'https://fcm.test/multi-a');
        $subB = $this->createSubscription($user->id, 'https://fcm.test/multi-b');

        $client = Mockery::mock(WebPush::class);
        $client->shouldReceive('queueNotification')->twice();
        $client->shouldReceive('flush')->once()->andReturn((function () use ($subA, $subB) {
            yield $this->report($subB->endpoint, false, 404, '', 'Not Found');
            yield $this->report($subA->endpoint, false, 410, '', 'Gone');
        })());

        (new WebPushGateway($client))->send($this->message($user->id));

        $this->assertDatabaseMissing('push_subscriptions', ['id' => $subA->id]);
        $this->assertDatabaseMissing('push_subscriptions', ['id' => $subB->id]);
    }
}
