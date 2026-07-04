<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ClientOwnerInvitationEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User   $user,
        public string $clientName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Acceso a ' . $this->clientName . ' en ' . config('app.name'),
        );
    }

    public function content(): Content
    {
        $frontendUrl     = rtrim(config('app.frontend_url'), '/');
        $expirationHours = (int) config('auth.invitation_link_expiration_hours', 72);
        $invitationUrl   = $frontendUrl
            . '/invitacion'
            . '?token=' . urlencode($this->user->verification_link_token)
            . '&email=' . urlencode($this->user->email);

        return new Content(
            view: 'emails.client-owner-invitation',
            with: [
                'firstName'       => $this->user->first_name,
                'clientName'      => $this->clientName,
                'invitationUrl'   => $invitationUrl,
                'expirationHours' => $expirationHours,
            ],
        );
    }
}
