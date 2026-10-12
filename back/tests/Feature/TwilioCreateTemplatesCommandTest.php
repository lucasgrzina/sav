<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TwilioCreateTemplatesCommandTest extends TestCase
{
    private const ENDPOINT = 'https://content.twilio.com/v1/Content';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'notifications.twilio.sid' => 'AC123',
            'notifications.twilio.token' => 'token',
        ]);

        Http::preventStrayRequests();
    }

    public function test_dry_run_v2_prints_the_body_with_the_footer_appended(): void
    {
        Http::fake();

        $this->artisan('twilio:create-templates', ['--dry-run' => true, '--only' => ['program.task_due@v2']])
            ->expectsOutputToContain('Este es un aviso automático de SAV.\n\nRespondé BAJA para dejar de recibir avisos.')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_only_v2_creates_a_single_content_template_and_prints_the_env_line(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['sid' => 'HX_NEW_V2'])]);

        $this->artisan('twilio:create-templates', ['--only' => ['program.task_due@v2']])
            ->expectsOutputToContain('TWILIO_TEMPLATE_PROGRAM_TASK_DUE_V2=HX_NEW_V2')
            ->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            $data = $request->data();

            return $data['friendly_name'] === 'sav_program_task_due_v2'
                && count($data['variables']) === 8
                && str_ends_with($data['types']['twilio/text']['body'], "Este es un aviso automático de SAV.\n\nRespondé BAJA para dejar de recibir avisos.");
        });
    }

    public function test_v1_templates_do_not_get_a_footer(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['sid' => 'HX1'])]);

        $this->artisan('twilio:create-templates', ['--only' => ['program.task_due']])->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->data()['friendly_name'] === 'sav_program_task_due'
            && ! str_contains($request->data()['types']['twilio/text']['body'], 'BAJA'));
    }

    public function test_only_rejects_an_unknown_template(): void
    {
        Http::fake();

        $this->artisan('twilio:create-templates', ['--only' => ['nope']])
            ->expectsOutputToContain('--only desconocido: nope')
            ->assertFailed();

        Http::assertNothingSent();
    }
}
