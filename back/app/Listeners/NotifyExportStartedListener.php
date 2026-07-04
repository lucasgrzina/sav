<?php

namespace App\Listeners;

use App\Events\ExportStartedEvent;
use App\Services\NotificationService;

class NotifyExportStartedListener
{
    public function __construct(
        private NotificationService $notificationService,
    ) {}

    public function handle(ExportStartedEvent $event): void
    {
        $export = $event->export;

        $this->notificationService->store([
            'payload'  => [
                'title'       => 'Exportación en proceso',
                'description' => "Estamos generando tu exportación de {$export->type->label()}. Te avisaremos cuando esté lista.",
                'type'        => 'info',
                'subtype'     => 'export_started',
                'export_guid' => $export->guid,
                'export_type' => $export->type->value,
            ],
            'user_ids' => [$export->user_id],
        ]);
    }
}
