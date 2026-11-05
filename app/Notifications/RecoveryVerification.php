<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RecoveryVerification extends Notification
{
    public function __construct(public string $url) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)->subject('Verify your HRMS recovery email')->line('Confirm this address for password recovery.')->action('Verify email', $this->url)->line('This link expires in 30 minutes.');
    }
}
