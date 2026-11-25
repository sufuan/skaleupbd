<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Registration verification code by email (owner order, 2026-11-22).
 * Sent only when the platform switch registration.verify_enabled is ON
 * and the chosen channel includes email. Renders through the branded
 * mail theme like every other platform email.
 */
class RegistrationOtpNotification extends Notification
{
    public function __construct(public readonly string $code) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Your Scalenow Bd verification code'))
            ->markdown('emails.registration-otp', [
                'code' => $this->code,
                'name' => $notifiable->name,
            ]);
    }
}
