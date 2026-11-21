<?php

declare(strict_types=1);

namespace App\Mail;

final class VerifyEmail extends TransactionalEmail
{
    protected function mailSubject(): string
    {
        return 'Verify your email address';
    }

    protected function intro(): string
    {
        return 'Confirm your email address for Infinity URL:';
    }

    protected function ctaLabel(): string
    {
        return 'Verify my email address';
    }

    protected function expiry(): string
    {
        return 'This link expires in 24 hours. If you did not create an account, ignore this message.';
    }

    protected function textBody(): string
    {
        return "Hello,\n\nConfirm your email address for Infinity URL:\n".$this->url
            ."\n\nThis link expires in 24 hours. If you did not create an account, ignore this message.\n\nInfinity Project\n";
    }
}
