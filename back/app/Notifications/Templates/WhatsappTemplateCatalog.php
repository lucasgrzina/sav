<?php

namespace App\Notifications\Templates;

use App\Notifications\Enums\AlertType;
use InvalidArgumentException;

/**
 * Single source of truth for the WhatsApp template copy.
 *
 * The body of a WhatsApp template lives inside the provider (Twilio's Content API, Meta's
 * message_templates), while the message builders only supply the variables — so the same
 * copy inevitably exists on both sides. Declaring it once here is what keeps the two in
 * step: every provisioning command reads from this catalog, so a template created for
 * Twilio and one created for Kapso cannot drift apart, and neither can drift from the
 * placeholder count the builders actually send.
 *
 * Placeholders are ordinal ({{1}}, {{2}}, ...) because the gateways send positional
 * parameters. Renumbering them here silently reorders real messages: see
 * WhatsappTemplateCatalogTest, which pins each entry against its builder.
 */
final class WhatsappTemplateCatalog
{
    /** @return array<string, array{body: string, examples: list<string>}> */
    public static function definitions(): array
    {
        return [
            AlertType::ProgramCreated->value => [
                'body' => 'Hola {{1}}, se creó el programa "{{2}}".',
                'examples' => ['Lucas', 'Sincronización IATF'],
            ],
            AlertType::ProgramCancelled->value => [
                'body' => 'Hola {{1}}, se canceló el programa "{{2}}".',
                'examples' => ['Lucas', 'Sincronización IATF'],
            ],
            AlertType::ProgramTaskDue->value => [
                'body' => 'Hola {{1}}, del programa "{{2}}": {{3}}',
                'examples' => ['Lucas', 'Sincronización IATF', 'hoy toca retirar el dispositivo'],
            ],
            // DEC-07: este template es de tipo `twilio/media` (o `twilio/document`) en Twilio
            // Content Composer, no un template de texto simple. El body de texto solo declara
            // {{1}} y {{2}} — la URL del PDF viaja como una TERCERA variable posicional
            // ({{3}}) reservada para el header de medio del template, fuera del copy textual.
            // ProgramPdfShareMessageBuilder envía 3 variables a propósito; no es un mismatch
            // con placeholderCount(body) === 2 (ver ProgramPdfShareMessageBuilderTest).
            AlertType::ProgramPdfShared->value => [
                'body' => 'Hola {{1}}, te compartimos el PDF del programa "{{2}}".',
                'examples' => ['Lucas', 'Sincronización IATF'],
            ],
        ];
    }

    /** @return array{body: string, examples: list<string>} */
    public static function for(AlertType $type): array
    {
        return self::definitions()[$type->value]
            ?? throw new InvalidArgumentException("Sin copy de template para {$type->value}");
    }

    /**
     * Sample variables shaped exactly as a builder would send them: 1-based ordinal keys.
     *
     * @return array<int, string>
     */
    public static function exampleVariables(AlertType $type): array
    {
        $variables = [];

        foreach (self::for($type)['examples'] as $index => $example) {
            $variables[$index + 1] = $example;
        }

        return $variables;
    }

    /** Counts distinct {{n}} placeholders, so a repeated one is not double-counted. */
    public static function placeholderCount(string $body): int
    {
        preg_match_all('/\{\{(\d+)\}\}/', $body, $matches);

        return count(array_unique($matches[1]));
    }
}
