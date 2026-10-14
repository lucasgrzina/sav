<?php

namespace Tests\Unit\ProgramAlerts;

use App\Notifications\Models\OptOut;
use App\Services\ProgramAlerts\RecipientDiagnostics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsProgramAlertFixture;
use Tests\TestCase;

class RecipientDiagnosticsTest extends TestCase
{
    use BuildsProgramAlertFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildProgramFixture();
    }

    public function test_reports_ok_missing_and_invalid_whatsapp_with_masked_numbers(): void
    {
        $ok = $this->makeResponsible(whatsapp: '5491122334455');
        $missing = $this->makeResponsible(whatsapp: null);
        $invalid = $this->makeResponsible(whatsapp: '12345');

        $result = (new RecipientDiagnostics())->diagnose(collect([$ok, $missing, $invalid]));

        $this->assertSame('ok', $result[$ok->guid]->whatsappStatus);
        $this->assertSame('+54 ••• •••• 4455', $result[$ok->guid]->masked);
        $this->assertSame('missing', $result[$missing->guid]->whatsappStatus);
        $this->assertNull($result[$missing->guid]->masked);
        $this->assertSame('invalid_number', $result[$invalid->guid]->whatsappStatus);
        $this->assertSame('invalid_number', $result[$invalid->guid]->contactStatus());
    }

    public function test_email_fallback_needs_the_config_and_an_alert_enabled_email_contact(): void
    {
        $withEmail = $this->makeResponsible(whatsapp: null, email: 'maria@example.com');
        $withoutEmail = $this->makeResponsible(whatsapp: null);

        $result = (new RecipientDiagnostics())->diagnose(collect([$withEmail, $withoutEmail]));

        $this->assertTrue($result[$withEmail->guid]->emailFallback);
        $this->assertSame('m•••@example.com', $result[$withEmail->guid]->emailMasked);
        $this->assertSame('email_only', $result[$withEmail->guid]->contactStatus());
        $this->assertFalse($result[$withoutEmail->guid]->emailFallback);
        $this->assertSame('missing', $result[$withoutEmail->guid]->contactStatus());

        config(['notifications.fallback.whatsapp' => []]);
        $this->assertFalse((new RecipientDiagnostics())->diagnose(collect([$withEmail]))[$withEmail->guid]->emailFallback);
    }

    public function test_opt_out_is_detected_per_whatsapp_number_only(): void
    {
        $optedOut = $this->makeResponsible(whatsapp: '5491122334455');
        $other = $this->makeResponsible(whatsapp: '5491166667777');
        OptOut::create(['phone' => '+54 9 11 2233-4455', 'channel' => 'whatsapp']); // normalized by the model
        OptOut::create(['phone' => '5491166667777', 'channel' => 'sms']); // another channel: irrelevant

        $result = (new RecipientDiagnostics())->diagnose(collect([$optedOut, $other]));

        $this->assertTrue($result[$optedOut->guid]->optedOut);
        $this->assertSame('opted_out', $result[$optedOut->guid]->contactStatus());
        $this->assertFalse($result[$other->guid]->optedOut);
    }

    public function test_it_queries_opt_outs_once_and_contacts_once_for_any_number_of_profiles(): void
    {
        $profiles = collect(range(1, 6))->map(fn () => $this->makeResponsible(whatsapp: '5491122334455', email: 'x@example.com'));

        DB::enableQueryLog();
        (new RecipientDiagnostics())->diagnose($profiles);
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $this->assertSame(1, $queries->filter(fn ($q) => str_contains($q, 'opt_outs'))->count());
        $this->assertSame(1, $queries->filter(fn ($q) => str_contains($q, 'from "contacts"'))->count());
    }
}
