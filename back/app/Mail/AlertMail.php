<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $alertSubject,
        public string $body,
        public ?string $actionUrl = null,
        public ?string $actionLabel = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->alertSubject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.alert',
            text: 'emails.text.alert',
            with: [
                'alertSubject' => $this->alertSubject,
                'body' => $this->body,
                'actionUrl' => $this->actionUrl,
                'actionLabel' => $this->actionLabel,
            ],
        );
    }
}
