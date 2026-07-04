<?php

namespace App\Listeners;

use App\Events\ExportCompletedEvent;
use App\Services\NotificationService;

class NotifyExportCompletedListener
{
    public function __construct(
        private NotificationService $notificationService,
    ) {}

    public function handle(ExportCompletedEvent $event): void
    {
        $export = $event->export;

        $this->notificationService->store([
            'payload'  => [
                'title'       => 'Exportación lista',
                'description' => "Tu exportación de {$export->type->label()} ({$export->format->value}) está lista para descargar.",
                'type'        => 'success',
                'subtype'     => 'export_ready',
                'export_guid' => $export->guid,
                'url'         => "/exports/{$export->guid}/download",
            ],
            'user_ids' => [$export->user_id],
        ]);
    }
}
