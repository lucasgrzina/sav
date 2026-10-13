<?php

namespace Tests\Unit\Notifications;

use App\Models\Program;
use App\Models\Protocol;
use App\Notifications\Builders\ProgramCancelledMessageBuilder;
use App\Notifications\Builders\ProgramTaskDueMessageBuilder;
use App\Notifications\Contracts\AlertMessageBuilder;
use App\Notifications\Data\Recipient;
use App\Notifications\Data\TemplateContent;
use App\Notifications\Enums\AlertType;
use App\Notifications\Enums\Channel;
use App\Notifications\Models\Alert;
use App\Notifications\Templates\WhatsappTemplateCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WhatsappTemplateCatalogTest extends TestCase
{
    /** @return array<string, array{class-string<AlertMessageBuilder>, AlertType}> */
    public static function builderProvider(): array
    {
        return [
            'program.cancelled' => [ProgramCancelledMessageBuilder::class, AlertType::ProgramCancelled],
            'program.task_due' => [ProgramTaskDueMessageBuilder::class, AlertType::ProgramTaskDue],
        ];
    }

    private function alert(AlertType $type): Alert
    {
        $program = new Program();
        $program->setRelation('protocol', new Protocol(['name' => 'Plan Vacunación Bovina']));

        $alert = new Alert();
        $alert->setRelation('subject', $program);
        $alert->payload = ['protocolTaskAlertGuid' => 'alert-guid-1', 'message' => 'Vacunar'];

        return $alert;
    }

    private function recipient(): Recipient
    {
        return new Recipient(userId: 1, phone: '5491122334455', name: 'Juan', channel: Channel::Whatsapp);
    }

    /**
     * The guard that matters: a template declares N placeholders and its builder sends N
     * variables. If they drift, WhatsApp still delivers — with the values in the wrong slots
     * ("Hola Plan Vacunación, se creó el programa Juan") and no technical error anywhere.
     */
    #[DataProvider('builderProvider')]
    public function test_the_catalog_declares_exactly_the_placeholders_its_builder_sends(
        string $builderClass,
        AlertType $type,
    ): void {
        /** @var AlertMessageBuilder $builder */
        $builder = new $builderClass();
        $content = $builder->build($this->alert($type), $this->recipient());

        $this->assertInstanceOf(TemplateContent::class, $content);

        $declared = WhatsappTemplateCatalog::placeholderCount(WhatsappTemplateCatalog::for($type)['body']);

        $this->assertSame(
            $declared,
            count($content->variables),
            "El template de {$type->value} declara {$declared} placeholders y el builder manda " . count($content->variables),
        );
    }

    /**
     * Positional parameters are read in order, so the keys must be 1..n with no gaps.
     * They come out as ints: PHP coerces the numeric string keys the builders write.
     */
    #[DataProvider('builderProvider')]
    public function test_builder_variables_are_contiguous_ordinals(string $builderClass, AlertType $type): void
    {
        /** @var AlertMessageBuilder $builder */
        $builder = new $builderClass();
        $content = $builder->build($this->alert($type), $this->recipient());

        $this->assertSame(range(1, count($content->variables)), array_keys($content->variables));
    }

    #[DataProvider('builderProvider')]
    public function test_every_placeholder_has_an_example_value(string $builderClass, AlertType $type): void
    {
        $definition = WhatsappTemplateCatalog::for($type);

        $this->assertCount(
            WhatsappTemplateCatalog::placeholderCount($definition['body']),
            $definition['examples'],
        );
    }

    #[DataProvider('builderProvider')]
    public function test_the_builder_type_matches_the_catalog_entry(string $builderClass, AlertType $type): void
    {
        /** @var AlertMessageBuilder $builder */
        $builder = new $builderClass();

        $this->assertSame($type, $builder->type());
    }

    public function test_catalog_keys_are_all_valid_alert_types(): void
    {
        foreach (array_keys(WhatsappTemplateCatalog::definitions()) as $value) {
            $this->assertNotNull(AlertType::tryFrom($value), "'{$value}' no es un AlertType");
        }
    }

    public function test_example_variables_are_keyed_from_one(): void
    {
        $this->assertSame(
            ['1' => 'Lucas', '2' => 'Sincronización IATF'],
            WhatsappTemplateCatalog::exampleVariables(AlertType::ProgramCreated),
        );
    }

    public function test_placeholder_count_ignores_repetitions(): void
    {
        $this->assertSame(2, WhatsappTemplateCatalog::placeholderCount('{{1}} y {{2}}, otra vez {{1}}'));
        $this->assertSame(0, WhatsappTemplateCatalog::placeholderCount('sin variables'));
    }

    public function test_an_alert_type_without_copy_is_rejected_loudly(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        WhatsappTemplateCatalog::for(AlertType::EventReminder);
    }

    /**
     * HealthPlanMonth no está en builderProvider(): su builder (HealthPlanMonthMessageBuilder)
     * recalcula actividades pendientes contra la base de datos (RF-02) y no puede construirse
     * con los modelos "en memoria" que usa alert()/recipient() acá — tiene su propio test
     * dedicado (HealthPlanMonthMessageBuilderTest). Esto solo fija el contrato del catálogo:
     * 7 variables posicionales (destinatario, mes, actividades, plan, categoría, cliente,
     * establecimiento), mismo orden que consume el builder.
     */
    public function test_health_plan_month_declares_seven_placeholders_with_matching_examples(): void
    {
        $definition = WhatsappTemplateCatalog::for(AlertType::HealthPlanMonth);

        $this->assertSame(7, WhatsappTemplateCatalog::placeholderCount($definition['body']));
        $this->assertCount(7, WhatsappTemplateCatalog::exampleVariables(AlertType::HealthPlanMonth));
    }

    public function test_health_plan_month_is_a_valid_catalog_entry(): void
    {
        $this->assertNotNull(AlertType::tryFrom(AlertType::HealthPlanMonth->value));
        $this->assertArrayHasKey(AlertType::HealthPlanMonth->value, WhatsappTemplateCatalog::definitions());
    }

    /**
     * ProgramPdfShared is intentionally NOT in builderProvider(): its builder sends 3
     * variables (name, protocol, pdf_share_url) while the text body only declares 2
     * placeholders — the 3rd is consumed by the template's media header in Twilio Content
     * Composer (DEC-07), not by the text copy. Asserting it against the generic
     * "declared === sent" invariant above would be a false failure.
     */
    public function test_program_pdf_shared_declares_two_text_placeholders_reserving_a_third_for_the_media_header(): void
    {
        $definition = WhatsappTemplateCatalog::for(AlertType::ProgramPdfShared);

        $this->assertSame(2, WhatsappTemplateCatalog::placeholderCount($definition['body']));
    }

    public function test_program_pdf_shared_is_a_valid_catalog_entry(): void
    {
        $this->assertNotNull(AlertType::tryFrom(AlertType::ProgramPdfShared->value));
        $this->assertArrayHasKey(AlertType::ProgramPdfShared->value, WhatsappTemplateCatalog::definitions());
    }

    /**
     * ProgramCreated is intentionally NOT in builderProvider(): its builder now sends 3
     * variables (name, protocol, pdf_download_url) while the text body only declares 2
     * placeholders — the 3rd is consumed by the template's call-to-action button in Twilio
     * Content Composer, not by the text copy. Asserting it against the generic
     * "declared === sent" invariant above would be a false failure.
     */
    public function test_program_created_declares_two_text_placeholders_reserving_a_third_for_the_cta_button(): void
    {
        $definition = WhatsappTemplateCatalog::for(AlertType::ProgramCreated);

        $this->assertSame(2, WhatsappTemplateCatalog::placeholderCount($definition['body']));
    }

    public function test_versions_keep_v1_identical_to_definitions_and_add_task_due_v2(): void
    {
        foreach (WhatsappTemplateCatalog::definitions() as $type => $definition) {
            $this->assertSame($definition, WhatsappTemplateCatalog::versions()[$type][1]);
        }

        $this->assertSame(2, WhatsappTemplateCatalog::latestVersion(AlertType::ProgramTaskDue));
        $this->assertSame(1, WhatsappTemplateCatalog::latestVersion(AlertType::ProgramCancelled));
        $this->assertArrayNotHasKey('footer', WhatsappTemplateCatalog::for(AlertType::ProgramTaskDue, 1));
    }

    public function test_an_unknown_version_is_rejected_loudly(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        WhatsappTemplateCatalog::for(AlertType::ProgramTaskDue, 9);
    }

    public function test_task_due_v2_has_contiguous_placeholders_one_to_eight_each_with_an_example(): void
    {
        $definition = WhatsappTemplateCatalog::for(AlertType::ProgramTaskDue, 2);

        preg_match_all('/\{\{(\d+)\}\}/', $definition['body'], $matches);
        $numbers = array_values(array_unique(array_map('intval', $matches[1])));
        sort($numbers);

        $this->assertSame(range(1, 8), $numbers);
        $this->assertCount(8, $definition['examples']);
        $this->assertSame(range(1, 8), array_keys(WhatsappTemplateCatalog::exampleVariables(AlertType::ProgramTaskDue, 2)));
    }

    public function test_task_due_v2_body_neither_starts_nor_ends_with_a_variable(): void
    {
        $body = WhatsappTemplateCatalog::for(AlertType::ProgramTaskDue, 2)['body'];

        $this->assertDoesNotMatchRegularExpression('/^\{\{\d+\}\}/', $body);
        $this->assertDoesNotMatchRegularExpression('/\{\{\d+\}\}\s*$/', $body);
    }

    public function test_task_due_v2_footer_fits_meta_limits_and_has_no_variables(): void
    {
        $footer = WhatsappTemplateCatalog::for(AlertType::ProgramTaskDue, 2)['footer'];

        $this->assertLessThanOrEqual(60, mb_strlen($footer));
        $this->assertStringNotContainsString('{{', $footer);
    }

    public function test_task_due_v2_body_fits_1024_chars_with_examples_and_in_the_worst_case(): void
    {
        $example = WhatsappTemplateCatalog::render(AlertType::ProgramTaskDue, 2, WhatsappTemplateCatalog::exampleVariables(AlertType::ProgramTaskDue, 2));
        $this->assertLessThanOrEqual(1024, mb_strlen($example));

        // DEC-13 sanitizer caps: recipient 60, company 60, protocol 90, client 60, establishment 60, task 120, when 80, free text 200.
        $worst = [];
        foreach ([60, 60, 90, 60, 60, 120, 80, 200] as $i => $max) {
            $worst[$i + 1] = str_repeat('x', $max);
        }

        $this->assertLessThanOrEqual(1024, mb_strlen(WhatsappTemplateCatalog::render(AlertType::ProgramTaskDue, 2, $worst)));
    }

    public function test_render_substitutes_variables_and_appends_the_footer_after_a_blank_line(): void
    {
        $rendered = WhatsappTemplateCatalog::render(
            AlertType::ProgramTaskDue,
            2,
            WhatsappTemplateCatalog::exampleVariables(AlertType::ProgramTaskDue, 2),
        );

        $this->assertStringStartsWith('Hola María, te escribe Agropecuaria ID por el programa "Superovulación P500" (Cliente SA, Estancia La Invernada).', $rendered);
        $this->assertStringContainsString("\nTarea: Colecta de embriones\nCuándo: mañana, jueves 24/09/2026 a las 09:00\nIndicaciones: Traer termo y camiseta de colecta.\n", $rendered);
        $this->assertStringEndsWith("Este es un aviso automático de SAV.\n\nRespondé BAJA para dejar de recibir avisos.", $rendered);
        $this->assertStringNotContainsString('{{', $rendered);
    }

    public function test_render_v1_has_no_footer(): void
    {
        $this->assertSame(
            'Hola Lucas, se canceló el programa "X".',
            WhatsappTemplateCatalog::render(AlertType::ProgramCancelled, 1, [1 => 'Lucas', 2 => 'X']),
        );
    }

    public function test_the_v2_flag_is_off_by_default(): void
    {
        $config = require base_path('config/notifications.php');

        $this->assertFalse($config['templates']['program_task_due_v2']);
    }

    public function test_with_the_flag_on_the_task_due_builder_sends_exactly_the_v2_placeholders(): void
    {
        config(['notifications.templates.program_task_due_v2' => true]);

        $alert = $this->alert(AlertType::ProgramTaskDue);
        $alert->payload = ['protocolTaskAlertGuid' => 'a', 'message' => 'Vacunar', 'taskDescription' => 'Tarea', 'taskDate' => '2030-06-10', 'taskTime' => '08:00', 'contextVersion' => 2];

        $content = (new ProgramTaskDueMessageBuilder())->build($alert, $this->recipient());

        $this->assertInstanceOf(TemplateContent::class, $content);
        $this->assertSame(2, $content->version);
        $this->assertSame(
            WhatsappTemplateCatalog::placeholderCount(WhatsappTemplateCatalog::for(AlertType::ProgramTaskDue, 2)['body']),
            count($content->variables),
        );
        $this->assertSame(range(1, 8), array_keys($content->variables));
    }

    public function test_a_legacy_alert_stays_on_v1_even_with_the_flag_on(): void
    {
        config(['notifications.templates.program_task_due_v2' => true]);

        $content = (new ProgramTaskDueMessageBuilder())->build($this->alert(AlertType::ProgramTaskDue), $this->recipient());

        $this->assertInstanceOf(TemplateContent::class, $content);
        $this->assertSame(1, $content->version);
        $this->assertCount(3, $content->variables);
    }
}
