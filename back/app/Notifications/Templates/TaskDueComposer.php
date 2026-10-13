<?php

namespace App\Notifications\Templates;

use App\Notifications\Data\Payloads\ProgramTaskPayload;
use App\Notifications\Enums\AlertType;
use InvalidArgumentException;

/**
 * Single place that words a program.task_due WhatsApp message. The builder (real send) and the
 * program preview both go through compose(), so the previewed text is the text that goes out.
 * Only v1 is implemented; the version parameter and effectiveVersion() are the seam for v2.
 */
final class TaskDueComposer
{
    public function compose(TaskDueContext $ctx, string $recipientName, int $version): ComposedMessage
    {
        if ($version !== 1) {
            throw new InvalidArgumentException("TaskDueComposer: la versión {$version} del mensaje todavía no está implementada");
        }

        $variables = [
            1 => $recipientName,
            2 => $ctx->protocolName,
            3 => (string) $ctx->freeText,
        ];

        return new ComposedMessage(
            version: $version,
            variables: $variables,
            text: WhatsappTemplateCatalog::render(AlertType::ProgramTaskDue, $version, $variables),
        );
    }

    /**
     * Version to use for an alert; `null` means an alert that is about to be created. Always 1
     * until the v2 template is implemented and approved by the provider.
     */
    public function effectiveVersion(?ProgramTaskPayload $payload = null): int
    {
        return 1;
    }
}
