<?php

namespace App\Notifications\Data;

use App\Notifications\Enums\AlertType;

final readonly class TemplateContent implements MessageContent
{
    /**
     * Provider-agnostic template reference: it names WHICH template to use, never HOW a
     * given provider identifies it. Each gateway resolves $type to its own identifier —
     * a Content API contentSid for Twilio, a name + language pair for Meta-based providers.
     *
     * Placeholders are ordinal and 1-based, matching {{1}}, {{2}} in the provider template.
     * Note that PHP coerces numeric string keys to integers, so ["1" => ...] is stored as
     * [1 => ...] — the keys are ints, and starting at 1 (not 0) is also what keeps
     * json_encode emitting a JSON object rather than an array for Twilio's contentVariables.
     *
     * @param array<int,string> $variables
     */
    public function __construct(
        public AlertType $type,
        public array $variables,
    ) {}
}
