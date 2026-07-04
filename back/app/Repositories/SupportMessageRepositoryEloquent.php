<?php

namespace App\Repositories;

use App\Contracts\Repositories\SupportMessageRepositoryInterface;
use App\Models\SupportMessage;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class SupportMessageRepositoryEloquent extends BaseRepositoryEloquent implements SupportMessageRepositoryInterface
{
    protected function model(): string
    {
        return SupportMessage::class;
    }

    public function create(array $data): SupportMessage
    {
        /** @var SupportMessage */
        return parent::create($data);
    }

    public function findByGuid(string $guid): ?SupportMessage
    {
        return $this->newQuery()->where('guid', $guid)->first();
    }

    public function findByGuidWithReplies(string $guid): ?SupportMessage
    {
        return $this->newQuery()
            ->with(['sender', 'closer', 'replies.author'])
            ->where('guid', $guid)
            ->first();
    }

    public function listForUser(User $user, int $perPage): LengthAwarePaginator
    {
        return $this->newQuery()
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    public function listAll(int $perPage): LengthAwarePaginator
    {
        return $this->newQuery()
            ->with('sender')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    public function updateStatus(SupportMessage $message, string $status): SupportMessage
    {
        $message->status = $status;
        $message->save();

        return $message;
    }

    public function close(SupportMessage $message, array $data): SupportMessage
    {
        $message->fill($data);
        $message->save();

        return $message;
    }
}
