<?php

namespace Tests\Unit\Notifications;

use App\Models\Client;
use App\Models\Establishment;
use App\Models\Program;
use App\Models\Protocol;
use App\Models\Vet;
use App\Notifications\Builders\ProgramTaskDueMessageBuilder;
use App\Notifications\Data\EmailContent;
use App\Notifications\Data\Payloads\ProgramTaskPayload;
use App\Notifications\Data\PushContent;
use App\Notifications\Data\Recipient;
use App\Notifications\Data\TemplateContent;
use App\Notifications\Enums\Channel;
use App\Notifications\Models\Alert;
use App\Notifications\Support\WhatsappVariableSanitizer;
use App\Notifications\Templates\TaskDueComposer;
use App\Notifications\Templates\TaskDueContext;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Tests\TestCase;

class TaskDueComposerTest extends TestCase
{
    private function context(
        string $protocol = 'Plan Vacunación Bovina',
        ?string $freeText = 'Vacunar',
        bool $multiGroup = false,
        string $taskDate = '2026-09-24',
        string $taskTime = '09:00',
        string $scheduledLocal = '2026-09-23 08:00:00',
        string $locale = 'es',
    ): TaskDueContext {
        return new TaskDueContext(
            vetName: 'Agropecuaria ID', clientName: 'Cliente SA', establishmentName: 'Estancia La Invernada', protocolName: $protocol,
            multiGroup: $multiGroup, targetDate: '2026-09-23', taskDescription: 'Colecta de embriones', taskDate: $taskDate, taskTime: $taskTime,
            scheduledAtLocal: CarbonImmutable::parse($scheduledLocal, 'America/Argentina/Buenos_Aires'), freeText: $freeText,
            timezone: 'America/Argentina/Buenos_Aires', locale: $locale,
        );
    }

    private function v2(TaskDueContext $ctx, string $name = 'María'): array
    {
        return (new TaskDueComposer())->compose($ctx, $name, 2)->variables;
    }

    public function test_v1_keeps_the_original_variables_and_renders_the_catalog_text(): void
    {
        $message = (new TaskDueComposer())->compose($this->context(), 'Juan', 1);

        $this->assertSame(1, $message->version);
        $this->assertSame([1 => 'Juan', 2 => 'Plan Vacunación Bovina', 3 => 'Vacunar'], $message->variables);
        $this->assertSame('Hola Juan, del programa "Plan Vacunación Bovina": Vacunar', $message->text);
    }

    public function test_v2_renders_the_spec_example_literally(): void
    {
        $message = (new TaskDueComposer())->compose($this->context(protocol: 'Superovulación P500', freeText: 'Traer termo y camiseta de colecta.'), 'María', 2);

        $this->assertSame(2, $message->version);
        $this->assertSame(
            "Hola María, te escribe Agropecuaria ID por el programa \"Superovulación P500\" (Cliente SA, Estancia La Invernada).\n"
            . "Tarea: Colecta de embriones\n"
            . "Cuándo: mañana, jueves 24/09/2026 a las 09:00\n"
            . "Indicaciones: Traer termo y camiseta de colecta.\n"
            . "Este es un aviso automático de SAV.\n\n"
            . 'Respondé BAJA para dejar de recibir avisos.',
            $message->text,
        );
        $this->assertSame(range(1, 8), array_keys($message->variables));
    }

    public function test_unknown_versions_are_rejected_instead_of_guessed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new TaskDueComposer())->compose($this->context(), 'Juan', 3);
    }

    /** @return array<string, array{string, string}> */
    public static function relativeProvider(): array
    {
        // Send date is 2026-09-23 (local); task date varies.
        return [
            'same day' => ['2026-09-23', 'hoy, miércoles 23/09/2026 a las 09:00'],
            'tomorrow' => ['2026-09-24', 'mañana, jueves 24/09/2026 a las 09:00'],
            'day after' => ['2026-09-25', 'pasado mañana, viernes 25/09/2026 a las 09:00'],
            'in n days' => ['2026-09-30', 'en 7 días, miércoles 30/09/2026 a las 09:00'],
            'yesterday' => ['2026-09-22', 'ayer, martes 22/09/2026 a las 09:00'],
            'n days ago' => ['2026-09-18', 'hace 5 días, viernes 18/09/2026 a las 09:00'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('relativeProvider')]
    public function test_v2_when_line_has_relative_day_and_always_the_concrete_date(string $taskDate, string $expected): void
    {
        $this->assertSame($expected, $this->v2($this->context(taskDate: $taskDate))[7]);
    }

    public function test_v2_when_uses_the_task_time_not_the_alert_time(): void
    {
        $when = $this->v2($this->context(taskTime: '14:30', scheduledLocal: '2026-09-23 07:15:00'))[7];

        $this->assertStringEndsWith('a las 14:30', $when);
        $this->assertStringNotContainsString('07:15', $when);
    }

    public function test_v2_when_is_stable_across_retries(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-23 22:00:00'));
        $first = $this->v2($this->context())[7];
        $this->travelTo(CarbonImmutable::parse('2026-09-26 10:00:00'));

        $this->assertSame($first, $this->v2($this->context())[7]);
        $this->travelBack();
    }

    public function test_group_suffix_only_with_multiple_groups(): void
    {
        $this->assertSame('Plan', $this->v2($this->context(protocol: 'Plan'))[3]);
        $this->assertSame('Plan - grupo del 23/09/2026', $this->v2($this->context(protocol: 'Plan', multiGroup: true))[3]);
    }

    public function test_free_text_is_sanitized_truncated_and_falls_back(): void
    {
        $this->assertSame('uno dos tres cuatro', $this->v2($this->context(freeText: "  uno\n\ndos\t\ttres   cuatro \r\n"))[8]);
        $this->assertSame('Sin indicaciones adicionales.', $this->v2($this->context(freeText: ''))[8]);
        $this->assertSame('Sin indicaciones adicionales.', $this->v2($this->context(freeText: null))[8]);
        $this->assertSame('Sin indicaciones adicionales.', $this->v2($this->context(freeText: " \n\t "))[8]);

        $long = $this->v2($this->context(freeText: str_repeat('á', TaskDueComposer::FREE_TEXT_MAX + 100)))[8];
        $this->assertSame(TaskDueComposer::FREE_TEXT_MAX, mb_strlen($long));
        $this->assertStringEndsWith('...', $long);
    }

    public function test_recipient_name_falls_back_to_equipo(): void
    {
        $this->assertSame('equipo', $this->v2($this->context(), '  ')[1]);
    }

    public function test_weekday_uses_the_explicit_locale_not_the_app_locale(): void
    {
        app()->setLocale('en');

        $this->assertStringContainsString('jueves', $this->v2($this->context())[7]);
    }

    public function test_variables_never_contain_newlines_or_tabs(): void
    {
        $vars = $this->v2($this->context(protocol: "Pla\nn", freeText: "a\tb\nc"));

        foreach ($vars as $value) {
            $this->assertDoesNotMatchRegularExpression('/[\r\n\t]/', $value);
        }
    }

    public function test_sanitizer_cuts_multibyte_text_without_breaking_characters(): void
    {
        $this->assertSame('ññ...', WhatsappVariableSanitizer::clean(str_repeat('ñ', 20), 5));
        $this->assertSame('fallback', WhatsappVariableSanitizer::clean(null, 5, 'fallback'));
    }

    public function test_effective_version_follows_the_flag_and_the_payload(): void
    {
        $composer = new TaskDueComposer();
        $v2 = new ProgramTaskPayload('guid', 'msg', contextVersion: 2);
        $legacy = new ProgramTaskPayload('guid', 'msg');

        config(['notifications.templates.program_task_due_v2' => false]);
        $this->assertSame(1, $composer->effectiveVersion());
        $this->assertSame(1, $composer->effectiveVersion($v2));
        $this->assertSame(1, $composer->effectiveVersion($legacy));

        config(['notifications.templates.program_task_due_v2' => true]);
        $this->assertSame(2, $composer->effectiveVersion());
        $this->assertSame(2, $composer->effectiveVersion($v2));
        $this->assertSame(1, $composer->effectiveVersion($legacy));
    }

    private function builderAlert(array $payload): Alert
    {
        $program = new Program();
        $program->setRelation('protocol', new Protocol(['name' => 'Superovulación P500']));
        $program->setRelation('vet', new Vet(['name' => 'Agropecuaria ID']));
        $program->setRelation('client', new Client(['name' => 'Cliente SA']));
        $program->setRelation('establishment', new Establishment(['name' => 'Estancia La Invernada']));

        $alert = new Alert();
        $alert->setRelation('subject', $program);
        $alert->scheduled_at = CarbonImmutable::parse('2026-09-23 11:00:00', 'UTC'); // 08:00 Buenos Aires
        $alert->payload = $payload;

        return $alert;
    }

    private function v2Payload(): array
    {
        return [
            'protocolTaskAlertGuid' => 'a', 'message' => 'Traer termo y camiseta de colecta.', 'targetDate' => '2026-09-23',
            'taskDescription' => 'Colecta de embriones', 'taskDate' => '2026-09-24', 'taskTime' => '09:00',
            'multiGroup' => false, 'contextVersion' => 2,
        ];
    }

    public function test_the_text_the_builder_sends_is_the_text_the_composer_previews(): void
    {
        config(['notifications.templates.program_task_due_v2' => true]);
        $recipient = new Recipient(userId: 1, phone: '5491122334455', name: 'María', channel: Channel::Whatsapp);

        $content = (new ProgramTaskDueMessageBuilder())->build($this->builderAlert($this->v2Payload()), $recipient);
        $previewed = (new TaskDueComposer())->compose(
            $this->context(protocol: 'Superovulación P500', freeText: 'Traer termo y camiseta de colecta.', scheduledLocal: '2026-09-23 08:00:00'),
            'María',
            2,
        );

        $this->assertInstanceOf(TemplateContent::class, $content);
        $this->assertSame($previewed->variables, $content->variables);
        $this->assertSame(2, $content->version);
        $this->assertSame('program.task_due@v2', $content->templateKey());
    }

    public function test_flag_off_keeps_v1_for_every_channel_even_with_a_v2_payload(): void
    {
        config(['notifications.templates.program_task_due_v2' => false]);
        $alert = $this->builderAlert($this->v2Payload());
        $builder = new ProgramTaskDueMessageBuilder();

        $whatsapp = $builder->build($alert, new Recipient(userId: 1, phone: '5491122334455', name: 'María', channel: Channel::Whatsapp));
        $email = $builder->build($alert, new Recipient(userId: 1, phone: null, email: 'm@x.com', name: 'María', channel: Channel::Email));
        $push = $builder->build($alert, new Recipient(userId: 1, phone: null, name: 'María', channel: Channel::Push));

        $this->assertInstanceOf(TemplateContent::class, $whatsapp);
        $this->assertSame(1, $whatsapp->version);
        $this->assertSame([1 => 'María', 2 => 'Superovulación P500', 3 => 'Traer termo y camiseta de colecta.'], $whatsapp->variables);
        $this->assertInstanceOf(EmailContent::class, $email);
        $this->assertSame('Recordatorio: Superovulación P500', $email->subject);
        $this->assertSame('Hola María, Traer termo y camiseta de colecta.', $email->body);
        $this->assertInstanceOf(PushContent::class, $push);
        $this->assertSame('Superovulación P500: Traer termo y camiseta de colecta.', $push->body);
    }

    public function test_flag_on_email_and_push_carry_the_same_content_as_whatsapp(): void
    {
        config(['notifications.templates.program_task_due_v2' => true]);
        $alert = $this->builderAlert($this->v2Payload());
        $builder = new ProgramTaskDueMessageBuilder();

        $email = $builder->build($alert, new Recipient(userId: 1, phone: null, email: 'm@x.com', name: 'María', channel: Channel::Email));
        $push = $builder->build($alert, new Recipient(userId: 1, phone: null, name: 'María', channel: Channel::Push));

        $this->assertInstanceOf(EmailContent::class, $email);
        $this->assertSame('Recordatorio: Colecta de embriones — Superovulación P500', $email->subject);
        foreach (['Cliente SA', 'Estancia La Invernada', 'Tarea: Colecta de embriones', 'Cuándo: mañana, jueves 24/09/2026 a las 09:00', 'Indicaciones: Traer termo y camiseta de colecta.'] as $line) {
            $this->assertStringContainsString($line, $email->body);
        }
        $this->assertInstanceOf(PushContent::class, $push);
        $this->assertSame(
            'Superovulación P500 · Estancia La Invernada: Colecta de embriones, mañana, jueves 24/09/2026 a las 09:00',
            $push->body,
        );
    }

    public function test_flag_on_legacy_alert_keeps_v1_everywhere(): void
    {
        config(['notifications.templates.program_task_due_v2' => true]);
        $alert = $this->builderAlert(['protocolTaskAlertGuid' => 'a', 'message' => 'Vacunar']);
        $builder = new ProgramTaskDueMessageBuilder();

        $whatsapp = $builder->build($alert, new Recipient(userId: 1, phone: '5491122334455', name: 'Juan', channel: Channel::Whatsapp));
        $email = $builder->build($alert, new Recipient(userId: 1, phone: null, email: 'j@x.com', name: 'Juan', channel: Channel::Email));

        $this->assertSame(1, $whatsapp->version);
        $this->assertSame('Hola Juan, Vacunar', $email->body);
    }
}
