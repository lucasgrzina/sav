<?php

namespace App\Services;

use App\Contracts\Repositories\SupportMessageRepositoryInterface;
use App\Contracts\Repositories\SupportMessageReplyRepositoryInterface;
use App\Events\SupportMessageClosedEvent;
use App\Events\SupportMessageCreatedEvent;
use App\Events\SupportMessageRepliedEvent;
use App\Models\SupportMessage;
use App\Models\SupportMessageReply;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SupportMessageService
{
    public function __construct(
        private SupportMessageRepositoryInterface $supportMessageRepository,
        private SupportMessageReplyRepositoryInterface $replyRepository,
    ) {}

    public function list(User $user, int $perPage = 15): LengthAwarePaginator
    {
        if ($user->can('support-messages.read')) {
            return $this->supportMessageRepository->listAll($perPage);
        }

        return $this->supportMessageRepository->listForUser($user, $perPage);
    }

    public function findByGuid(string $guid): ?SupportMessage
    {
        return $this->supportMessageRepository->findByGuid($guid);
    }

    public function findByGuidWithReplies(string $guid): ?SupportMessage
    {
        return $this->supportMessageRepository->findByGuidWithReplies($guid);
    }

    public function create(User $user, array $data): SupportMessage
    {
        $message = $this->supportMessageRepository->create([
            'user_id'  => $user->id,
            'subject'  => $data['subject'],
            'body'     => $data['body'],
            'priority' => $data['priority'],
            'category' => $data['category'],
        ]);

        event(new SupportMessageCreatedEvent($message));

        return $message;
    }

    public function addReply(SupportMessage $message, User $user, array $data): SupportMessageReply
    {
        return DB::transaction(function () use ($message, $user, $data) {
            $reply = $this->replyRepository->create([
                'support_message_id' => $message->id,
                'user_id'            => $user->id,
                'body'               => $data['body'],
            ]);

            if ($message->status === 'open' && $user->can('support-messages.reply')) {
                $this->supportMessageRepository->updateStatus($message, 'in_progress');
            }

            $reply->load('author');

            event(new SupportMessageRepliedEvent($message, $reply));

            return $reply;
        });
    }

    public function close(SupportMessage $message, User $user): SupportMessage
    {
        return DB::transaction(function () use ($message, $user) {
            $closed = $this->supportMessageRepository->close($message, [
                'status'    => 'closed',
                'closed_at' => now(),
                'closed_by' => $user->id,
            ]);

            event(new SupportMessageClosedEvent($closed));

            return $closed;
        });
    }

    public function destroy(SupportMessage $message): void
    {
        $this->supportMessageRepository->destroy($message);
    }
}
