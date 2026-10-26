<?php

namespace Tests\Unit;

use App\Support\AlertMessagePlaceholders;
use PHPUnit\Framework\TestCase;

class AlertMessagePlaceholdersTest extends TestCase
{
    private const TEXT = "Grupo [grupo] de [establecimiento]:\n- Mañana, [fecha del día 0].\n- Luego, [fecha del día +1].";

    public function test_replaces_establishment_and_both_dates(): void
    {
        $out = AlertMessagePlaceholders::resolve(self::TEXT, 'La Estancia', '2026-09-24');

        $this->assertStringContainsString('de La Estancia:', $out);
        $this->assertStringContainsString('Mañana, jueves 24/09/2026.', $out);
        $this->assertStringContainsString('Luego, viernes 25/09/2026.', $out);
    }

    public function test_group_name_replaces_the_group_placeholder(): void
    {
        $out = AlertMessagePlaceholders::resolve('Para el grupo [grupo] de [establecimiento]', 'X', '2026-09-24', 'es', '  Lote Norte ', 45);

        $this->assertSame('Para el grupo Lote Norte de X', $out);
    }

    public function test_unnamed_group_falls_back_to_date_and_plural_count(): void
    {
        $out = AlertMessagePlaceholders::resolve('Para el grupo [grupo] de [establecimiento]', 'X', '2026-09-24', 'es', null, 45);

        $this->assertSame('Para el grupo del 24/09/2026 (45 animales) de X', $out);
    }

    public function test_unnamed_group_uses_singular_for_one_animal(): void
    {
        $out = AlertMessagePlaceholders::resolve('[grupo]', null, '2026-09-24', 'es', '', 1);

        $this->assertSame('del 24/09/2026 (1 animal)', $out);
    }

    public function test_unnamed_group_with_zero_animals_uses_plural(): void
    {
        $this->assertSame('del 24/09/2026 (0 animales)', AlertMessagePlaceholders::resolve('[grupo]', null, '2026-09-24', 'es', null, 0));
    }

    public function test_group_is_left_literal_without_group_data(): void
    {
        // Protocol simulation: no group name and no animal count.
        $this->assertSame('[grupo] X', AlertMessagePlaceholders::resolve('[grupo] [establecimiento]', 'X', '2026-09-24'));
    }

    public function test_group_name_wins_even_without_a_target_date(): void
    {
        $this->assertSame('Lote A', AlertMessagePlaceholders::resolve('[grupo]', null, null, 'es', 'Lote A', 3));
    }

    public function test_legacy_lote_and_unknown_placeholders_are_left_untouched(): void
    {
        $out = AlertMessagePlaceholders::resolve('[lote] [otra cosa] [establecimiento]', 'X', '2026-09-24', 'es', 'Lote A', 3);

        $this->assertSame('[lote] [otra cosa] X', $out);
    }

    public function test_missing_values_leave_their_placeholders_as_written(): void
    {
        $out = AlertMessagePlaceholders::resolve(self::TEXT, null, '2026-09-24');
        $this->assertStringContainsString('[establecimiento]', $out);
        $this->assertStringNotContainsString('[fecha del día', $out);

        $out = AlertMessagePlaceholders::resolve(self::TEXT, '  ', null);
        $this->assertSame(self::TEXT, $out);
    }

    public function test_null_and_plain_messages_pass_through(): void
    {
        $this->assertNull(AlertMessagePlaceholders::resolve(null, 'X', '2026-09-24'));
        $this->assertSame('Sin marcas', AlertMessagePlaceholders::resolve('Sin marcas', 'X', '2026-09-24'));
    }

    public function test_day_plus_one_crosses_month_boundary(): void
    {
        $out = AlertMessagePlaceholders::resolve('[fecha del día +1]', null, '2026-09-30');

        $this->assertSame('jueves 01/10/2026', $out);
    }
}
