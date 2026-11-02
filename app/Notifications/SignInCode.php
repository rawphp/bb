<?php

namespace App\Notifications;

use App\Domain\Account\SignInCodeCheck;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class SignInCode extends Notification
{
    public function __construct(public readonly string $code) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Bodybuilder sign-in code: '.$this->code)
            ->line('Enter this code to sign in:')
            ->line('**'.$this->code.'**')
            ->line('It works for '.SignInCodeCheck::VALID_MINUTES.' minutes. If you did not ask for it, you can ignore this email.');
    }
}
