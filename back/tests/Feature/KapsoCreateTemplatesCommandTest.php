<?php

namespace Tests\Feature;

use App\Notifications\Templates\WhatsappTemplateCatalog;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KapsoCreateTemplatesCommandTest extends TestCase
{
    private const BASE = 'https://api.kapso.test';
    private const NUMBERS = self::BASE . '/platform/v1/whatsapp/phone_numbers';
    private const TEMPLATES = self::BASE . '/meta/whatsapp/v24.0/WABA999/message_templates';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'notifications.kapso.base_url' => self::BASE,
            'notifications.kapso.api_version' => 'v24.0',
            'notifications.kapso.api_key' => 'kapso-key',
            'notifications.kapso.phone_number_id' => 'PN123',
            'notifications.kapso.business_account_id' => null,
            'notifications.kapso.templates' => [
                'program.created' => ['name' => 'sav_program_created', 'language' => 'es'],
                'program.cancelled' => ['name' => 'sav_program_cancelled', 'language' => 'es'],
                'program.task_due' => ['name' => 'sav_program_task_due', 'language' => 'es'],
                'program.task_due@v2' => ['name' => 'sav_program_task_due_v2', 'language' => 'es'],
                'program.pdf_shared' => ['name' => 'sav_program_pdf_shared', 'language' => 'es'],
                'health_plan.month' => ['name' => 'sav_health_plan_month', 'language' => 'es'],
            ],
        ]);

        Http::preventStrayRequests();
    }

    private function fakeApi(): void
    {
        Http::fake(function (Request $request) {
            if (str_starts_with($request->url(), self::NUMBERS)) {
                return Http::response([
                    'data' => [
                        ['phone_number_id' => 'OTRO', 'business_account_id' => 'WABA000'],
                        ['phone_number_id' => 'PN123', 'business_account_id' => 'WABA999'],
                    ],
                    'meta' => ['page' => 1, 'total_pages' => 1],
                ]);
            }

            return Http::response(['id' => '162701986110', 'status' => 'PENDING', 'category' => 'UTILITY']);
        });
    }

    public function test_dry_run_shows_positional_numbered_templates_without_calling_the_api(): void
    {
        Http::fake();

        // One expectation only: expectsOutputToContain sets ORDERED, once-each expectations,
        // one per write, and the whole JSON payload goes out in a single line() call — so
        // several substrings from the same block can never all match. The full payload is
        // asserted precisely in test_sends_a_meta_template_payload_with_positional_examples.
        $this->artisan('kapso:create-templates', ['--dry-run' => true])
            ->expectsOutputToContain('"parameter_format": "POSITIONAL"')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_discovers_the_waba_from_the_configured_phone_number(): void
    {
        $this->fakeApi();

        $this->artisan('kapso:create-templates')
            ->expectsOutputToContain('WABA descubierto para PN123: WABA999')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->url() === self::TEMPLATES);
    }

    public function test_sends_a_meta_template_payload_with_positional_examples(): void
    {
        $this->fakeApi();

        $this->artisan('kapso:create-templates')->assertSuccessful();

        Http::assertSent(function (Request $request) {
            if ($request->url() !== self::TEMPLATES || ($request->data()['name'] ?? null) !== 'sav_program_task_due') {
                return false;
            }

            return $request->data() == [
                'name' => 'sav_program_task_due',
                'language' => 'es',
                'category' => 'UTILITY',
                'parameter_format' => 'POSITIONAL',
                'components' => [[
                    'type' => 'BODY',
                    'text' => 'Hola {{1}}, del programa "{{2}}": {{3}}',
                    // Positional examples are one row per component instance, hence [[...]].
                    'example' => ['body_text' => [['Lucas', 'Sincronización IATF', 'hoy toca retirar el dispositivo']]],
                ]],
            ];
        });
    }

    public function test_sends_the_v2_payload_with_a_footer_component(): void
    {
        $this->fakeApi();

        $this->artisan('kapso:create-templates')->assertSuccessful();

        Http::assertSent(function (Request $request) {
            if (($request->data()['name'] ?? null) !== 'sav_program_task_due_v2') {
                return false;
            }

            $components = $request->data()['components'];

            return count($components) === 2
                && $components[0]['type'] === 'BODY'
                && str_contains($components[0]['text'], 'Este es un aviso automático de SAV.')
                && count($components[0]['example']['body_text'][0]) === 8
                && $components[1] === ['type' => 'FOOTER', 'text' => 'Respondé BAJA para dejar de recibir avisos.'];
        });
    }

    public function test_v1_payloads_have_no_footer_component(): void
    {
        $this->fakeApi();

        $this->artisan('kapso:create-templates')->assertSuccessful();

        Http::assertSent(fn (Request $request) => ($request->data()['name'] ?? null) !== 'sav_program_task_due'
            || count($request->data()['components']) === 1);
    }

    public function test_only_creates_just_the_selected_template(): void
    {
        $this->fakeApi();

        $this->artisan('kapso:create-templates', ['--only' => ['program.task_due@v2']])->assertSuccessful();

        Http::assertSentCount(2); // 1 listado de números + solo v2
        Http::assertSent(fn (Request $request) => $request->url() !== self::TEMPLATES
            || $request->data()['name'] === 'sav_program_task_due_v2');
    }

    public function test_only_rejects_an_unknown_template(): void
    {
        Http::fake();

        $this->artisan('kapso:create-templates', ['--only' => ['program.task_due@v9']])
            ->expectsOutputToContain('--only desconocido: program.task_due@v9')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_dry_run_with_only_prints_the_footer_and_calls_nothing(): void
    {
        Http::fake();

        $this->artisan('kapso:create-templates', ['--dry-run' => true, '--only' => ['program.task_due@v2']])
            ->expectsOutputToContain('"type": "FOOTER"')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_every_catalog_version_has_a_name_in_the_shipped_config(): void
    {
        $config = require base_path('config/notifications.php');

        foreach (WhatsappTemplateCatalog::versions() as $type => $byVersion) {
            foreach (array_keys($byVersion) as $version) {
                $key = $version === 1 ? $type : "{$type}@v{$version}";
                $this->assertNotEmpty($config['kapso']['templates'][$key]['name'] ?? null, "Falta KAPSO config para {$key}");
            }
        }
    }

    public function test_creates_one_template_per_catalog_entry(): void
    {
        $this->fakeApi();

        $this->artisan('kapso:create-templates')->assertSuccessful();

        Http::assertSentCount(7); // 1 listado de números + 6 templates (5 tipos + task_due v2)
    }

    public function test_an_explicit_business_account_id_skips_the_lookup(): void
    {
        $this->fakeApi();

        $this->artisan('kapso:create-templates', ['--business-account-id' => 'WABA999'])
            ->assertSuccessful();

        Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), self::NUMBERS));
    }

    public function test_a_configured_business_account_id_skips_the_lookup(): void
    {
        config(['notifications.kapso.business_account_id' => 'WABA999']);
        $this->fakeApi();

        $this->artisan('kapso:create-templates')->assertSuccessful();

        Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), self::NUMBERS));
    }

    public function test_fails_when_the_phone_number_is_not_in_the_project(): void
    {
        config(['notifications.kapso.phone_number_id' => 'NO_EXISTE']);
        $this->fakeApi();

        $this->artisan('kapso:create-templates')
            ->expectsOutputToContain('no aparece en tu proyecto de Kapso')
            ->expectsOutputToContain('--business-account-id')
            ->assertFailed();

        Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), self::TEMPLATES));
    }

    public function test_rejects_an_invalid_category(): void
    {
        Http::fake();

        $this->artisan('kapso:create-templates', ['--category' => 'PROMO'])
            ->expectsOutputToContain('Categoría inválida')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_accepts_a_different_valid_category(): void
    {
        $this->fakeApi();

        $this->artisan('kapso:create-templates', ['--category' => 'marketing'])->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->url() !== self::TEMPLATES
            || ($request->data()['category'] ?? null) === 'MARKETING');
    }

    public function test_fails_when_a_template_name_is_missing_from_config(): void
    {
        config(['notifications.kapso.templates' => ['program.created' => ['name' => 'sav_program_created']]]);
        Http::fake();

        $this->artisan('kapso:create-templates', ['--dry-run' => true])
            ->expectsOutputToContain('Sin nombre de template configurado para program.cancelled')
            ->assertFailed();
    }

    public function test_reports_the_meta_error_and_keeps_going(): void
    {
        Http::fake(function (Request $request) {
            if (str_starts_with($request->url(), self::NUMBERS)) {
                return Http::response([
                    'data' => [['phone_number_id' => 'PN123', 'business_account_id' => 'WABA999']],
                    'meta' => ['page' => 1, 'total_pages' => 1],
                ]);
            }

            if (($request->data()['name'] ?? null) === 'sav_program_cancelled') {
                return Http::response([
                    'error' => ['code' => 100, 'error_user_msg' => 'Template name already exists'],
                ], 400);
            }

            return Http::response(['id' => '1', 'status' => 'PENDING']);
        });

        $this->artisan('kapso:create-templates')
            ->expectsOutputToContain('Template name already exists')
            ->assertFailed();

        // El fallo de uno no debe abortar a los demás.
        Http::assertSentCount(7);
    }

    public function test_fails_without_an_api_key(): void
    {
        config(['notifications.kapso.api_key' => null]);
        Http::fake();

        $this->artisan('kapso:create-templates')
            ->expectsOutputToContain('Falta KAPSO_API_KEY')
            ->assertFailed();

        Http::assertNothingSent();
    }
}
