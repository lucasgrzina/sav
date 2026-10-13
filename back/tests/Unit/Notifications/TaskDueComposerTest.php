<?php

namespace Tests\Unit\Notifications;

use App\Models\Program;
use App\Models\Protocol;
use App\Notifications\Builders\ProgramTaskDueMessageBuilder;
use App\Notifications\Data\Payloads\ProgramTaskPayload;
use App\Notifications\Data\Recipient;
use App\Notifications\Data\TemplateContent;
use App\Notifications\Enums\Channel;
use App\Notifications\Models\Alert;
use App\Notifications\Templates\TaskDueComposer;
use App\Notifications\Templates\TaskDueContext;
use InvalidArgumentException;
use Tests\TestCase;

class TaskDueComposerTest extends TestCase
{
    private function context(string $protocol = 'Plan Vacunación Bovina', ?string $freeText = 'Vacunar'): TaskDueContext
    {
        return new TaskDueContext(
            vetName: 'Vet', clientName: 'Cliente', establishmentName: 'Estancia', protocolName: $protocol,
            multiGroup: false, targetDate: '2030-06-10', taskDescription: 'Tarea', taskDate: '2030-06-10', taskTime: '08:00',
            scheduledAtLocal: null, freeText: $freeText, timezone: 'UTC', locale: 'es',
        );
    }

    public function test_v1_keeps_the_original_variables_and_renders_the_catalog_text(): void
    {
        $message = (new TaskDueComposer())->compose($this->context(), 'Juan', 1);

        $this->assertSame(1, $message->version);
        $this->assertSame([1 => 'Juan', 2 => 'Plan Vacunación Bovina', 3 => 'Vacunar'], $message->variables);
        $this->assertSame('Hola Juan, del programa "Plan Vacunación Bovina": Vacunar', $message->text);
    }

    public function test_effective_version_is_1_until_v2_exists(): void
    {
        $composer = new TaskDueComposer();

        $this->assertSame(1, $composer->effectiveVersion());
        $this->assertSame(1, $composer->effectiveVersion(new ProgramTaskPayload('guid', 'msg', contextVersion: 2)));
    }

    public function test_unknown_versions_are_rejected_instead_of_guessed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new TaskDueComposer())->compose($this->context(), 'Juan', 2);
    }

    public function test_the_text_the_builder_sends_is_the_text_the_composer_previews(): void
    {
        $program = new Program();
        $program->setRelation('protocol', new Protocol(['name' => 'Plan Vacunación Bovina']));
        $alert = new Alert();
        $alert->setRelation('subject', $program);
        $alert->payload = ['protocolTaskAlertGuid' => 'a', 'message' => 'Vacunar'];
        $recipient = new Recipient(userId: 1, phone: '5491122334455', name: 'Juan', channel: Channel::Whatsapp);

        $content = (new ProgramTaskDueMessageBuilder())->build($alert, $recipient);
        $previewed = (new TaskDueComposer())->compose($this->context(), 'Juan', 1);

        $this->assertInstanceOf(TemplateContent::class, $content);
        $this->assertSame($previewed->variables, $content->variables);
        $this->assertSame($previewed->version, $content->version);
    }
}
