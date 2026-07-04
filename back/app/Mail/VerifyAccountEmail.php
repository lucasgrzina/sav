<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VerifyAccountEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $code,
        public string $token,
        public int $expirationMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Verificá tu cuenta - ' . config('app.name'));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.verify-account',
            with: [
                'firstName'         => $this->user->first_name,
                'code'              => $this->code,
                'token'             => $this->token,
                'expirationMinutes' => $this->expirationMinutes,
            ],
        );
    }
}
