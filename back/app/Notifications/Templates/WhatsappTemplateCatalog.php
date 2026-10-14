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
            // DEC-XX: este template lleva un botón call-to-action ("Descargar el programa")
            // con URL dinámica en Twilio Content Composer. El body de texto sigue declarando
            // solo {{1}} y {{2}} — la URL de descarga viaja como una TERCERA variable
            // posicional ({{3}}) que el botón consume, fuera del copy textual. Mismo patrón
            // que ProgramPdfShared (DEC-07): ProgramCreatedMessageBuilder envía 3 variables a
            // propósito; no es un mismatch con placeholderCount(body) === 2 (ver
            // WhatsappTemplateCatalogTest).
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
            AlertType::HealthPlanMonth->value => [
                'body' => 'Hola {{1}}, en {{2}} corresponde: {{3}} — plan "{{4}}" ({{5}}) de {{7}}, {{6}}.',
                'examples' => ['Lucas', 'julio', 'Vacunación Aftosa, Desparasitación', 'Plan Ganadero Anual', 'Bovinos', 'Estancia La Esperanza', 'Establecimiento Norte'],
            ],
        ];
    }

    /**
     * Every version of every template, keyed type => version => definition. Version 1 is
     * exactly definitions(); later versions are declared here. A definition may carry a
     * `footer`: Meta renders it as a separate FOOTER component (max 60 chars, no variables).
     *
     * @return array<string, array<int, array{body: string, examples: list<string>, footer?: string}>>
     */
    public static function versions(): array
    {
        $versions = [];

        foreach (self::definitions() as $type => $definition) {
            $versions[$type] = [1 => $definition];
        }

        // v2 (DU-06): richer copy plus an opt-out footer. The body ends in static text because
        // Meta may reject templates whose body ends in a variable.
        $versions[AlertType::ProgramTaskDue->value][2] = [
            'body' => "Hola {{1}}, te escribe {{2}} por el programa \"{{3}}\" ({{4}}, {{5}}).\n"
                . "Tarea: {{6}}\n"
                . "Cuándo: {{7}}\n"
                . "Indicaciones: {{8}}\n"
                . 'Este es un aviso automático de SAV.',
            'examples' => [
                'María',
                'Agropecuaria ID',
                'Superovulación P500',
                'Cliente SA',
                'Estancia La Invernada',
                'Colecta de embriones',
                'mañana, jueves 24/09/2026 a las 09:00',
                'Traer termo y camiseta de colecta.',
            ],
            'footer' => 'Respondé BAJA para dejar de recibir avisos.',
        ];

        return $versions;
    }

    public static function latestVersion(AlertType $type): int
    {
        $versions = self::versions()[$type->value]
            ?? throw new InvalidArgumentException("Sin copy de template para {$type->value}");

        return max(array_keys($versions));
    }

    /** @return array{body: string, examples: list<string>, footer?: string} */
    public static function for(AlertType $type, int $version = 1): array
    {
        return self::versions()[$type->value][$version]
            ?? throw new InvalidArgumentException("Sin copy de template para {$type->value} v{$version}");
    }

    /**
     * Sample variables shaped exactly as a builder would send them: 1-based ordinal keys.
     *
     * @return array<int, string>
     */
    public static function exampleVariables(AlertType $type, int $version = 1): array
    {
        $variables = [];

        foreach (self::for($type, $version)['examples'] as $index => $example) {
            $variables[$index + 1] = $example;
        }

        return $variables;
    }

    /**
     * Renders the template copy with the variables a builder sent, i.e. the text the
     * recipient sees (stored in alert_recipients.rendered_body for traceability). The
     * footer, when the version has one, goes last after a blank line.
     *
     * @param array<int, string> $variables
     * @throws InvalidArgumentException when the type/version has no template copy
     */
    public static function render(AlertType $type, int $version, array $variables): string
    {
        $definition = self::for($type, $version);

        $body = (string) preg_replace_callback(
            '/\{\{(\d+)\}\}/',
            fn (array $m): string => $variables[(int) $m[1]] ?? '',
            $definition['body'],
        );

        return isset($definition['footer']) ? $body . "\n\n" . $definition['footer'] : $body;
    }

    /** Counts distinct {{n}} placeholders, so a repeated one is not double-counted. */
    public static function placeholderCount(string $body): int
    {
        preg_match_all('/\{\{(\d+)\}\}/', $body, $matches);

        return count(array_unique($matches[1]));
    }
}
