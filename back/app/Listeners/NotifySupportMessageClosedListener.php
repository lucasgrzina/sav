<?php

namespace App\Listeners;

use App\Events\SupportMessageClosedEvent;
use App\Services\NotificationService;

class NotifySupportMessageClosedListener
{
    public function __construct(
        private NotificationService $notificationService,
    ) {}

    public function handle(SupportMessageClosedEvent $event): void
    {
        $message = $event->message->loadMissing('sender');

        $this->notificationService->store([
            'payload'  => [
                'title'                => 'Tu ticket fue cerrado',
                'description'          => "Tu mensaje \"{$message->subject}\" fue cerrado por el equipo de soporte.",
                'type'                 => 'info',
                'subtype'              => 'support_message_closed',
                'support_message_guid' => $message->guid,
                'url'                  => "/support-messages/{$message->guid}",
            ],
            'user_ids' => [$message->user_id],
        ]);
    }
}
