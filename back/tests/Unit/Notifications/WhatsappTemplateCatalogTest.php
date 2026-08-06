<?php

namespace Tests\Unit\Notifications;

use App\Models\Program;
use App\Models\Protocol;
use App\Notifications\Builders\ProgramCancelledMessageBuilder;
use App\Notifications\Builders\ProgramCreatedMessageBuilder;
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
            'program.created' => [ProgramCreatedMessageBuilder::class, AlertType::ProgramCreated],
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

        WhatsappTemplateCatalog::for(AlertType::HealthPlanMonth);
    }
}
