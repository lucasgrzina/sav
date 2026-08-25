<?php

namespace Tests\Unit\Notifications;

use App\Models\Program;
use App\Models\Protocol;
use App\Notifications\Builders\ProgramCreatedMessageBuilder;
use App\Notifications\Data\EmailContent;
use App\Notifications\Data\Recipient;
use App\Notifications\Data\TemplateContent;
use App\Notifications\Enums\AlertType;
use App\Notifications\Enums\Channel;
use App\Notifications\Models\Alert;
use Tests\TestCase;

class ProgramCreatedMessageBuilderTest extends TestCase
{
    public function test_type_is_program_created(): void
    {
        $this->assertSame(AlertType::ProgramCreated, (new ProgramCreatedMessageBuilder())->type());
    }

    public function test_build_uses_protocol_name_and_recipient_name(): void
    {
        $protocol = new Protocol(['name' => 'Plan Vacunación Bovina']);

        $program = new Program();
        $program->setRelation('protocol', $protocol);

        $alert = new Alert();
        $alert->setRelation('subject', $program);
        $alert->payload = ['pdf_download_url' => 'https://sav.test/v1/programs/some-guid/download-pdf?signature=abc'];

        $recipient = new Recipient(userId: 1, phone: '5491122334455', name: 'Juan', channel: Channel::Whatsapp);

        $content = (new ProgramCreatedMessageBuilder())->build($alert, $recipient);

        $this->assertInstanceOf(TemplateContent::class, $content);
        // The builder names the template by AlertType; resolving it to a provider-specific
        // identifier (a Twilio contentSid, a Meta template name) is each gateway's job.
        $this->assertSame(AlertType::ProgramCreated, $content->type);
        $this->assertSame([
            '1' => 'Juan',
            '2' => 'Plan Vacunación Bovina',
            '3' => 'https://sav.test/v1/programs/some-guid/download-pdf?signature=abc',
        ], $content->variables);
    }

    public function test_build_returns_email_content_for_the_email_channel(): void
    {
        $protocol = new Protocol(['name' => 'Plan Vacunación Bovina']);
        $program = new Program();
        $program->setRelation('protocol', $protocol);

        $alert = new Alert();
        $alert->setRelation('subject', $program);

        $recipient = new Recipient(userId: 1, phone: null, name: 'Juan', channel: Channel::Email, email: 'juan@example.com');

        $content = (new ProgramCreatedMessageBuilder())->build($alert, $recipient);

        $this->assertInstanceOf(EmailContent::class, $content);
        $this->assertStringContainsString('Plan Vacunación Bovina', $content->subject);
        $this->assertStringContainsString('Juan', $content->body);
    }
}
