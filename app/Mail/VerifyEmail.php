<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class VerifyEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $url)
    {
        $this->text('mail.plain', ['body' => $this->textBody()]);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Verify your email address',
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
            <p>Confirm your email address for Infinity URL:</p>
            <p><a href="{$url}">Verify my email address</a></p>
            <p>This link expires in 24 hours. If you did not create an account, ignore this message.</p>
            <p>Infinity Project</p>
            </body>
            </html>
            HTML;
    }

    private function textBody(): string
    {
        return "Hello,\n\nConfirm your email address for Infinity URL:\n".$this->url
            ."\n\nThis link expires in 24 hours. If you did not create an account, ignore this message.\n\nInfinity Project\n";
    }
}
