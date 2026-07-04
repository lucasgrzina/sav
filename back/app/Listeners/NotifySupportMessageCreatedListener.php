<?php

namespace App\Listeners;

use App\Events\SupportMessageCreatedEvent;
use App\Models\User;
use App\Services\NotificationService;

class NotifySupportMessageCreatedListener
{
    public function __construct(
        private NotificationService $notificationService,
    ) {}

    public function handle(SupportMessageCreatedEvent $event): void
    {
        $message = $event->message->loadMissing('sender');

        $recipientIds = User::permission('support-messages.reply')
            ->pluck('id')
            ->reject(fn($id) => $id === $message->user_id)
            ->values()
            ->toArray();

        if (empty($recipientIds)) {
            return;
        }

        $senderName = trim("{$message->sender->first_name} {$message->sender->last_name}");

        $this->notificationService->store([
            'payload'  => [
                'title'                => 'Nuevo mensaje de soporte',
                'description'          => "{$senderName} envió un nuevo mensaje: \"{$message->subject}\"",
                'type'                 => 'info',
                'subtype'              => 'new_support_message',
                'support_message_guid' => $message->guid,
                'url'                  => "/support-messages/{$message->guid}",
            ],
            'user_ids' => $recipientIds,
        ]);
    }
}
