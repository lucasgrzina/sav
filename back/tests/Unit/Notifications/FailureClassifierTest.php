<?php

namespace Tests\Unit\Notifications;

use App\Notifications\Enums\Channel;
use App\Notifications\Enums\FailureCode;
use App\Notifications\Exceptions\NotificationConfigurationException;
use App\Notifications\Exceptions\RecipientContactNotFoundException;
use App\Notifications\Exceptions\TemplateNotConfiguredException;
use App\Notifications\Services\FailureClassifier;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class FailureClassifierTest extends TestCase
{
    /** @return array<string, array{string, string|int, FailureCode}> */
    public static function mappedCodes(): array
    {
        return [
            'meta invalid number' => ['meta', 131026, FailureCode::InvalidNumber],
            'meta rejected (24h window)' => ['meta', 131047, FailureCode::ProviderRejected],
            'meta misconfigured (template)' => ['meta', 132001, FailureCode::ServiceMisconfigured],
            'meta code as string' => ['meta', '131026', FailureCode::InvalidNumber],
            'twilio invalid number' => ['twilio', 21211, FailureCode::InvalidNumber],
            'twilio opted out' => ['twilio', 21610, FailureCode::OptedOut],
            'twilio rejected' => ['twilio', 63016, FailureCode::ProviderRejected],
            'twilio misconfigured' => ['twilio', 20003, FailureCode::ServiceMisconfigured],
        ];
    }

    #[DataProvider('mappedCodes')]
    public function test_maps_provider_codes(string $provider, string|int $code, FailureCode $expected): void
    {
        $this->assertSame($expected, (new FailureClassifier())->fromProviderCode($provider, $code));
    }

    public function test_an_unmapped_code_is_unknown_and_logged(): void
    {
        Log::spy();

        $this->assertSame(FailureCode::Unknown, (new FailureClassifier())->fromProviderCode('meta', 999999));

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('FailureClassifier: código de proveedor sin mapear', ['provider' => 'meta', 'code' => 999999]);
    }

    public function test_a_missing_code_is_unknown(): void
    {
        $this->assertSame(FailureCode::Unknown, (new FailureClassifier())->fromProviderCode('twilio', null));
    }

    public function test_a_provider_without_a_map_is_unknown(): void
    {
        $this->assertSame(FailureCode::Unknown, (new FailureClassifier())->fromProviderCode('nope', 21211));
    }

    public function test_classifies_throwables(): void
    {
        $classifier = new FailureClassifier();

        $this->assertSame(FailureCode::ServiceMisconfigured, $classifier->fromThrowable(new NotificationConfigurationException('x')));
        $this->assertSame(FailureCode::ServiceMisconfigured, $classifier->fromThrowable(new TemplateNotConfiguredException('x')));
        $this->assertSame(FailureCode::NoWhatsappContact, $classifier->fromThrowable(new RecipientContactNotFoundException('x'), Channel::Whatsapp));
        $this->assertSame(FailureCode::NoEmailContact, $classifier->fromThrowable(new RecipientContactNotFoundException('x'), Channel::Email));
        $this->assertSame(FailureCode::Unknown, $classifier->fromThrowable(new RecipientContactNotFoundException('x'), Channel::Push));
        $this->assertSame(FailureCode::ProviderUnreachable, $classifier->fromThrowable(new RuntimeException('timeout')));
    }
}
