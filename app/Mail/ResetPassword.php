<?php

declare(strict_types=1);

namespace App\Mail;

final class ResetPassword extends TransactionalEmail
{
    protected function mailSubject(): string
    {
        return 'Reset your password';
    }

    protected function intro(): string
    {
        return 'A password reset was requested for your Infinity URL account.';
    }

    protected function ctaLabel(): string
    {
        return 'Choose a new password';
    }

    protected function expiry(): string
    {
        return 'This link expires in one hour. If you did not request it, ignore this message.';
    }

    protected function textBody(): string
    {
        return "Hello,\n\nA password reset was requested for your Infinity URL account.\n"
            ."Choose a new password:\n".$this->url
            ."\n\nThis link expires in one hour. If you did not request it, ignore this message.\n\nInfinity Project\n";
    }
}
