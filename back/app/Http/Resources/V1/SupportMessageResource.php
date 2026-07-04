<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupportMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'guid'       => $this->guid,
            'subject'    => $this->subject,
            'body'       => $this->body,
            'status'     => $this->status,
            'priority'   => $this->priority,
            'category'   => $this->category,
            'closed_at'  => $this->closed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'sender'     => $this->whenLoaded('sender', fn () => [
                'guid' => $this->sender->guid,
                'name' => $this->sender->name,
            ]),
            'closer'     => $this->whenLoaded('closer', fn () => $this->closer ? [
                'guid' => $this->closer->guid,
                'name' => $this->closer->name,
            ] : null),
            'replies'    => $this->whenLoaded('replies',
                fn () => SupportMessageReplyResource::collection($this->replies)
            ),
        ];
    }
}
