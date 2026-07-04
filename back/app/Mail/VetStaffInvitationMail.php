<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VetStaffInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User   $user,
        public string $vetName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Invitación al equipo de ' . $this->vetName . ' en ' . config('app.name'),
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
            view: 'emails.vet-staff-invitation',
            with: [
                'firstName'       => $this->user->first_name,
                'vetName'         => $this->vetName,
                'invitationUrl'   => $invitationUrl,
                'expirationHours' => $expirationHours,
            ],
        );
    }
}
