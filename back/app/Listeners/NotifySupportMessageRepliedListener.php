<?php

namespace App\Listeners;

use App\Events\SupportMessageRepliedEvent;
use App\Models\User;
use App\Services\NotificationService;

class NotifySupportMessageRepliedListener
{
    public function __construct(
        private NotificationService $notificationService,
    ) {}

    public function handle(SupportMessageRepliedEvent $event): void
    {
        $message = $event->message->loadMissing('sender');
        $reply   = $event->reply->loadMissing('author');
        $author  = $reply->author;
        $isStaff = $author->can('support-messages.reply');

        if ($isStaff) {
            $recipientIds = [$message->user_id];
            $description  = "El equipo de soporte respondió tu mensaje: \"{$message->subject}\"";
        } else {
            $authorName   = trim("{$author->first_name} {$author->last_name}");
            $description  = "{$authorName} respondió en: \"{$message->subject}\"";
            $recipientIds = User::permission('support-messages.reply')
                ->pluck('id')
                ->reject(fn($id) => $id === $author->id)
                ->values()
                ->toArray();
        }

        if (empty($recipientIds)) {
            return;
        }

        $this->notificationService->store([
            'payload'  => [
                'title'                => 'Nueva respuesta en mensaje de soporte',
                'description'          => $description,
                'type'                 => 'info',
                'subtype'              => 'support_message_replied',
                'support_message_guid' => $message->guid,
                'url'                  => "/support-messages/{$message->guid}",
            ],
            'user_ids' => $recipientIds,
        ]);
    }
}
