<?php

namespace Tests\Unit\Notifications;

use App\Notifications\Data\DeliveryResult;
use App\Notifications\Data\OutboundMessage;
use App\Notifications\Data\Payloads\ProgramTaskPayload;
use App\Notifications\Data\Recipient;
use App\Notifications\Data\SuppressionReason;
use App\Notifications\Data\TemplateContent;
use App\Notifications\Data\TextContent;
use App\Notifications\Enums\AlertType;
use App\Notifications\Enums\Channel;
use App\Notifications\Enums\DeliveryStatus;
use Tests\TestCase;

class NotificationDataObjectsTest extends TestCase
{
    public function test_alert_type_values_match_the_five_origins(): void
    {
        $this->assertSame('program.task_due', AlertType::ProgramTaskDue->value);
        $this->assertSame('program.created', AlertType::ProgramCreated->value);
        $this->assertSame('program.cancelled', AlertType::ProgramCancelled->value);
        $this->assertSame('health_plan.month', AlertType::HealthPlanMonth->value);
        $this->assertSame('event.reminder', AlertType::EventReminder->value);
    }

    public function test_outbound_message_carries_recipient_and_template_content(): void
    {
        $recipient = new Recipient(
            userId: 1,
            phone: '5491122334455',
            name: 'Juan',
            channel: Channel::Whatsapp,
        );

        $message = new OutboundMessage(
            recipient: $recipient,
            content: new TemplateContent(type: AlertType::ProgramCreated, variables: ['1' => 'Juan']),
            channel: Channel::Whatsapp,
            idempotencyKey: 'alert-1-user-1-whatsapp',
        );

        $this->assertSame($recipient, $message->recipient);
        $this->assertInstanceOf(TemplateContent::class, $message->content);
        $this->assertSame(Channel::Whatsapp, $message->channel);
    }

    public function test_text_content_is_a_valid_message_content(): void
    {
        $content = new TextContent('hola');

        $this->assertSame('hola', $content->body);
    }

    public function test_delivery_result_sent_failed_and_suppressed_factories(): void
    {
        $sent = DeliveryResult::sent('SM123');
        $this->assertSame(DeliveryStatus::Sent, $sent->status);
        $this->assertSame('SM123', $sent->providerMessageId);

        $failed = DeliveryResult::failed('invalid number');
        $this->assertSame(DeliveryStatus::Failed, $failed->status);
        $this->assertSame('invalid number', $failed->failureReason);

        $suppressed = DeliveryResult::suppressed(SuppressionReason::OptedOut);
        $this->assertSame(DeliveryStatus::Suppressed, $suppressed->status);
        $this->assertSame(SuppressionReason::OptedOut->value, $suppressed->failureReason);
    }

    public function test_program_task_payload_casts_from_array_via_laravel_data(): void
    {
        $payload = ProgramTaskPayload::from([
            'protocolTaskAlertGuid' => 'alert-guid-1',
            'message' => 'Vacunar',
        ]);

        $this->assertSame('alert-guid-1', $payload->protocolTaskAlertGuid);
        $this->assertSame('Vacunar', $payload->message);
    }
}
