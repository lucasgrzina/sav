<?php

namespace Tests\Unit\Notifications;

use App\Models\Program;
use App\Models\Protocol;
use App\Notifications\Builders\ProgramPdfShareMessageBuilder;
use App\Notifications\Data\Recipient;
use App\Notifications\Data\TemplateContent;
use App\Notifications\Enums\AlertType;
use App\Notifications\Enums\Channel;
use App\Notifications\Models\Alert;
use Tests\TestCase;

class ProgramPdfShareMessageBuilderTest extends TestCase
{
    public function test_type_is_program_pdf_shared(): void
    {
        $this->assertSame(AlertType::ProgramPdfShared, (new ProgramPdfShareMessageBuilder())->type());
    }

    public function test_build_sends_name_protocol_and_pdf_share_url_as_positional_variables(): void
    {
        $protocol = new Protocol(['name' => 'Plan Vacunación Bovina']);
        $program = new Program();
        $program->setRelation('protocol', $protocol);

        $alert = new Alert();
        $alert->setRelation('subject', $program);
        $alert->payload = [
            'export_guid' => 'export-guid-1',
            'pdf_share_url' => 'https://sav.test/api/v1/programs/shared-pdf/export-guid-1?signature=abc',
        ];

        $recipient = new Recipient(userId: 1, phone: '5491122334455', name: 'Juan', channel: Channel::Whatsapp);

        $content = (new ProgramPdfShareMessageBuilder())->build($alert, $recipient);

        $this->assertInstanceOf(TemplateContent::class, $content);
        $this->assertSame(AlertType::ProgramPdfShared, $content->type);
        $this->assertSame([
            1 => 'Juan',
            2 => 'Plan Vacunación Bovina',
            3 => 'https://sav.test/api/v1/programs/shared-pdf/export-guid-1?signature=abc',
        ], $content->variables);
    }
}
