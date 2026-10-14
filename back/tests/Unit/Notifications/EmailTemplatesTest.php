<?php

namespace Tests\Unit\Notifications;

use App\Mail\AlertMail;
use App\Mail\ClientStaffInvitationMail;
use App\Mail\ForgotPasswordEmail;
use App\Mail\VerifyAccountEmail;
use App\Mail\VetStaffInvitationMail;
use App\Models\Program;
use App\Models\Protocol;
use App\Models\User;
use App\Notifications\Builders\ProgramPdfShareMessageBuilder;
use App\Notifications\Data\EmailContent;
use App\Notifications\Data\OutboundMessage;
use App\Notifications\Data\Recipient;
use App\Notifications\Enums\Channel;
use App\Notifications\Gateways\Mail\MailGateway;
use App\Notifications\Models\Alert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailTemplatesTest extends TestCase
{
    private const PDF_URL = 'https://sav.test/v1/programs/shared/abc?expires=1&signature=xyz';

    private function user(): User
    {
        $user = new User(['first_name' => 'Ana <b>', 'email' => 'ana@example.com']);
        $user->verification_link_token = 'tok-123';

        return $user;
    }

    /**
     * Sends through the in-memory array mailer and returns the multipart bodies.
     *
     * @return array{0: string, 1: string} html and text parts
     */
    private function render(\Illuminate\Mail\Mailable $mail): array
    {
        Mail::mailer('array')->to('dest@example.com')->send($mail);

        return $this->lastMessageParts();
    }

    /** @return array{0: string, 1: string} */
    private function lastMessageParts(): array
    {
        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        $email = $messages->last()->getOriginalMessage();

        return [(string) $email->getHtmlBody(), (string) $email->getTextBody()];
    }

    private function assertBranded(string $html): void
    {
        $this->assertStringContainsString(rtrim(config('app.url'), '/') . '/images/logo.png', $html);
        $this->assertStringContainsString('role="presentation"', $html);
        $this->assertFileExists(public_path('images/logo.png'));
    }

    public function test_pdf_share_email_renders_cta_button_visible_url_and_logo(): void
    {
        $program = new Program();
        $program->setRelation('protocol', new Protocol(['name' => 'Plan "Aftosa"']));
        $alert = new Alert();
        $alert->setRelation('subject', $program);
        $alert->payload = ['pdf_share_url' => self::PDF_URL];

        $content = (new ProgramPdfShareMessageBuilder())->build(
            $alert,
            new Recipient(userId: 1, phone: null, name: 'Juan', channel: Channel::Email, email: 'juan@example.com'),
        );

        $this->assertInstanceOf(EmailContent::class, $content);
        $this->assertSame(self::PDF_URL, $content->actionUrl);

        $recipient = new Recipient(userId: 1, phone: null, name: 'Juan', channel: Channel::Email, email: 'juan@example.com');
        (new MailGateway())->send(new OutboundMessage(
            recipient: $recipient,
            content: $content,
            channel: Channel::Email,
            idempotencyKey: 'k',
        ));

        [$html, $text] = $this->lastMessageParts();

        $escapedUrl = e(self::PDF_URL);
        // Button href + visible fallback URL, both present in the HTML part.
        $this->assertSame(2, substr_count($html, 'href="' . $escapedUrl . '"'));
        $this->assertStringContainsString('>' . $escapedUrl . '</a>', $html);
        $this->assertStringContainsString('Descargar PDF', $html);
        $this->assertStringContainsString('Plan &quot;Aftosa&quot;', $html);
        $this->assertBranded($html);
        $this->assertStringContainsString(self::PDF_URL, $text);
    }

    public function test_alert_mail_escapes_dynamic_values_and_omits_button_without_action(): void
    {
        [$html, $text] = $this->render(new AlertMail('Asunto', "Hola <script>alert(1)</script>\nLínea 2"));

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('Si el botón no funciona', $html);
        $this->assertStringContainsString('Línea 2', $text);
        $this->assertBranded($html);
    }

    public function test_verification_and_recovery_emails_show_code_in_both_parts(): void
    {
        foreach ([VerifyAccountEmail::class, ForgotPasswordEmail::class] as $class) {
            [$html, $text] = $this->render(new $class($this->user(), '123456', 'tok', 15));

            $this->assertStringContainsString('123456', $html);
            $this->assertStringContainsString('123456', $text);
            $this->assertStringContainsString('Ana &lt;b&gt;', $html);
            $this->assertBranded($html);
        }
    }

    public function test_invitation_emails_render_button_with_invitation_url(): void
    {
        config(['app.frontend_url' => 'https://front.test']);
        $url = 'https://front.test/invitacion?token=tok-123&email=ana%40example.com';

        foreach ([new VetStaffInvitationMail($this->user(), 'Vet Sur'), new ClientStaffInvitationMail($this->user(), 'Cliente Norte')] as $mail) {
            [$html, $text] = $this->render($mail);

            $this->assertStringContainsString('href="' . e($url) . '"', $html);
            $this->assertStringContainsString($url, $text);
            $this->assertBranded($html);
        }
    }
}
