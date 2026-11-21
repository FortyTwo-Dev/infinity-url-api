<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Shared HTML skeleton of the four transactional messages. Each concrete
 * message only supplies its subject, introduction, call to action and expiry
 * sentence, so the layout and the sender headers exist once.
 */
abstract class TransactionalEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $url)
    {
        $this->text('mail.plain', ['body' => $this->textBody()]);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->mailSubject(),
            from: new Address('noreply@infinityproject.fr', 'Infinity Project'),
            replyTo: [new Address('contact@infinityproject.fr', 'Infinity Project')],
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->htmlBody());
    }

    abstract protected function mailSubject(): string;

    abstract protected function intro(): string;

    abstract protected function ctaLabel(): string;

    abstract protected function expiry(): string;

    abstract protected function textBody(): string;

    private function htmlBody(): string
    {
        $url = htmlspecialchars($this->url, ENT_QUOTES);
        $intro = htmlspecialchars($this->intro(), ENT_QUOTES);
        $cta = htmlspecialchars($this->ctaLabel(), ENT_QUOTES);
        $expiry = htmlspecialchars($this->expiry(), ENT_QUOTES);

        return <<<HTML
            <!doctype html>
            <html lang="en">
            <body style="font-family: system-ui, sans-serif; line-height: 1.5; color: #111;">
            <p>Hello,</p>
            <p>{$intro}</p>
            <p><a href="{$url}">{$cta}</a></p>
            <p>{$expiry}</p>
            <p>Infinity Project</p>
            </body>
            </html>
            HTML;
    }
}
