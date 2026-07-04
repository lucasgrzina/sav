<?php

namespace App\Contracts\Repositories;

use App\Models\SupportMessageReply;

interface SupportMessageReplyRepositoryInterface
{
    public function create(array $data): SupportMessageReply;
}
