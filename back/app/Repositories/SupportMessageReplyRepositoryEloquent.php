<?php

namespace App\Repositories;

use App\Contracts\Repositories\SupportMessageReplyRepositoryInterface;
use App\Models\SupportMessageReply;

class SupportMessageReplyRepositoryEloquent extends BaseRepositoryEloquent implements SupportMessageReplyRepositoryInterface
{
    protected function model(): string
    {
        return SupportMessageReply::class;
    }

    public function create(array $data): SupportMessageReply
    {
        /** @var SupportMessageReply */
        return parent::create($data);
    }
}
