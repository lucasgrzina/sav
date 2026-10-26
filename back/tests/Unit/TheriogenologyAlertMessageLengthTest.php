<?php

namespace Tests\Unit;

use App\Notifications\Templates\TaskDueComposer;
use App\Support\AlertMessagePlaceholders;
use PHPUnit\Framework\TestCase;

class TheriogenologyAlertMessageLengthTest extends TestCase
{
    public function test_every_seeded_message_fits_the_free_text_cap_with_worst_case_placeholders(): void
    {
        $data = json_decode(
            file_get_contents(__DIR__ . '/../../database/scripts/theriogenology_iatf_migration.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $checked = 0;

        foreach ($data['protocols'] as $protocol) {
            foreach ($protocol['alerts'] as $index => $alert) {
                // Worst case: unnamed group (date + 2-digit animal count fallback), 40-char establishment.
                $resolved = AlertMessagePlaceholders::resolve(
                    $alert['text'],
                    str_repeat('x', 40),
                    '2026-09-24',
                    'es',
                    null,
                    45,
                );

                $this->assertLessThanOrEqual(
                    TaskDueComposer::FREE_TEXT_MAX,
                    mb_strlen($resolved),
                    "{$protocol['name']} alert #{$index} exceeds the free-text cap once resolved",
                );
                $checked++;
            }
        }

        $this->assertGreaterThan(0, $checked);
    }
}
