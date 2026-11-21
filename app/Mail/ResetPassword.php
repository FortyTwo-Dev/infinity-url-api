<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class ResetPassword extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $url)
    {
        $this->text('mail.plain', ['body' => $this->textBody()]);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reset your password',
            from: new Address('noreply@infinityproject.fr', 'Infinity Project'),
            replyTo: [new Address('contact@infinityproject.fr', 'Infinity Project')],
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->htmlBody());
    }

    private function htmlBody(): string
    {
        $url = htmlspecialchars($this->url, ENT_QUOTES);

        return <<<HTML
            <!doctype html>
            <html lang="en">
            <body style="font-family: system-ui, sans-serif; line-height: 1.5; color: #111;">
            <p>Hello,</p>
            <p>A password reset was requested for your Infinity URL account.</p>
            <p><a href="{$url}">Choose a new password</a></p>
            <p>This link expires in one hour. If you did not request it, ignore this message.</p>
            <p>Infinity Project</p>
            </body>
            </html>
            HTML;
    }

    private function textBody(): string
    {
        return "Hello,\n\nA password reset was requested for your Infinity URL account.\n"
            ."Choose a new password:\n".$this->url
            ."\n\nThis link expires in one hour. If you did not request it, ignore this message.\n\nInfinity Project\n";
    }
}
