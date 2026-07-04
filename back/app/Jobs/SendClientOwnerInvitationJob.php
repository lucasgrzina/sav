<?php

namespace App\Jobs;

use App\Mail\ClientOwnerInvitationEmail;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as QueueableTrait;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SendClientOwnerInvitationJob implements ShouldQueue
{
    use QueueableTrait, InteractsWithQueue, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 60;

    public function __construct(
        public readonly int    $userId,
        public readonly string $clientName,
    ) {}

    public function handle(): void
    {
        $user = User::find($this->userId);

        if (!$user) {
            Log::warning('SendClientOwnerInvitationJob: usuario no encontrado', [
                'user_id' => $this->userId,
            ]);
            return;
        }

        // Regenerar token si expiró
        if (!$user->verification_link_token || now()->isAfter($user->verification_link_expires_at)) {
            $expirationHours = (int) config('auth.invitation_link_expiration_hours', 72);
            $user->verification_link_token      = Str::random(64);
            $user->verification_link_expires_at = now()->addHours($expirationHours);
            $user->save();
        }

        try {
            Mail::to($user->email)->send(
                new ClientOwnerInvitationEmail($user, $this->clientName)
            );
        } catch (\Exception $e) {
            Log::error('SendClientOwnerInvitationJob: fallo al enviar email', [
                'user_id' => $this->userId,
                'error'   => $e->getMessage(),
            ]);
            throw $e; // relanza para que el job reintente
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendClientOwnerInvitationJob falló definitivamente', [
            'user_id' => $this->userId,
            'error'   => $exception->getMessage(),
        ]);
    }
}
