<?php

namespace App\Listeners;

use App\Events\ExportFailedEvent;
use App\Services\NotificationService;

class NotifyExportFailedListener
{
    public function __construct(
        private NotificationService $notificationService,
    ) {}

    public function handle(ExportFailedEvent $event): void
    {
        $export = $event->export;

        $this->notificationService->store([
            'payload'  => [
                'title'       => 'Error en exportación',
                'description' => "Hubo un error al generar tu exportación de {$export->type->label()}.",
                'type'        => 'error',
                'subtype'     => 'export_failed',
                'export_guid' => $export->guid,
                'export_type' => $export->type->value,
            ],
            'user_ids' => [$export->user_id],
        ]);
    }
}
