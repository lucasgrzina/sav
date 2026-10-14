<?php

namespace Tests\Unit\Notifications;

use App\Notifications\Support\PhoneMasker;
use App\Notifications\Support\PhoneValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PhoneValidatorTest extends TestCase
{
    /** @return array<string, array{string, bool}> */
    public static function numbers(): array
    {
        return [
            'AR mobile with the 9 marker' => ['5491122334455', true],
            'AR without the 9 marker' => ['541122334455', true],
            'AR too short' => ['54112233', false],
            'AR too long' => ['549112233445566', false],
            'MX mobile' => ['5215512345678', true],
            'MX too short' => ['5255123', false],
            'CL mobile' => ['56912345678', true],
            'CL too long' => ['569123456789', false],
            'CO mobile' => ['573001234567', true],
            'CO too short' => ['5730012345', false],
            'PE mobile' => ['51912345678', true],
            'PE too long' => ['519123456789', false],
            'BR mobile (11 digits subscriber)' => ['5511987654321', true],
            'BR landline-sized' => ['551187654321', true],
            'BR too short' => ['5511987', false],
            'unknown country within E.164 bounds' => ['4915112345678', true],
            'unknown country too short' => ['4915112', false],
            'over E.164 max' => ['1234567890123456', false],
            'leading zero' => ['0112233445566', false],
            'non digits' => ['54911abc4455', false],
            'empty' => ['', false],
        ];
    }

    #[DataProvider('numbers')]
    public function test_plausibility_by_country(string $digits, bool $expected): void
    {
        $this->assertSame($expected, PhoneValidator::isPlausible($digits));
    }

    public function test_calling_code_uses_the_longest_known_prefix(): void
    {
        $this->assertSame('54', PhoneValidator::callingCode('5491122334455'));
        $this->assertNull(PhoneValidator::callingCode('4915112345678'));
    }

    public function test_masks_a_phone_keeping_only_country_code_and_last_four_digits(): void
    {
        $this->assertSame('+54 ••• •••• 4455', PhoneMasker::mask('5491122334455'));
        $this->assertSame('+52 ••• •••• 5678', PhoneMasker::mask('5215512345678'));
    }

    public function test_masks_an_unknown_country_without_leaking_a_prefix(): void
    {
        $this->assertSame('+••• ••• •••• 5678', PhoneMasker::mask('4915112345678'));
    }

    public function test_masks_an_email(): void
    {
        $this->assertSame('m•••@dominio.com', PhoneMasker::maskEmail('maria@dominio.com'));
        $this->assertSame('•••', PhoneMasker::maskEmail('not-an-email'));
    }
}
