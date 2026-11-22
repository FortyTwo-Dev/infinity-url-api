<?php

declare(strict_types=1);

namespace App\Mail;

final class LinkBlocked extends TransactionalEmail
{
    public function __construct(public readonly string $reason, string $url)
    {
        parent::__construct($url);
    }

    protected function mailSubject(): string
    {
        return 'Your link has been blocked';
    }

    protected function intro(): string
    {
        return 'A link created on your account has been blocked for abuse: '.$this->reason;
    }

    protected function ctaLabel(): string
    {
        return 'Review my links';
    }

    protected function expiry(): string
    {
        return 'The statistics of the link are kept. Reply to this message if you contest the decision.';
    }

    protected function textBody(): string
    {
        return "Hello,\n\nA link created on your account has been blocked for abuse: ".$this->reason
            ."\n\n".$this->url
            ."\n\nThe statistics of the link are kept. Reply to this message if you contest the decision.\n\nInfinity Project\n";
    }
}
