<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPasswordNotification extends ResetPassword
{
    /**
     * Build the branded mail representation of the notification.
     */
    public function toMail($notifiable): MailMessage
    {
        if (static::$toMailCallback) {
            return call_user_func(static::$toMailCallback, $notifiable, $this->token);
        }

        $expire = (int) config('auth.passwords.users.expire', 60);

        $greeting = ! empty($notifiable->name)
            ? __('Hello :name,', ['name' => $notifiable->name])
            : __('Hello,');

        return (new MailMessage)
            ->subject(__('Reset your SkaleUp BD password'))
            ->greeting($greeting)
            ->line(__('We received a request to reset the password for your SkaleUp BD account.'))
            ->line(__('Tap the button below to choose a new password.'))
            ->action(__('Reset password'), $this->resetUrl($notifiable))
            ->line(__('This link will expire in :count minutes, so please use it soon.', ['count' => $expire]))
            ->line(__('If you did not ask for this, you can safely ignore this email — your password will not change.'))
            ->salutation(__('— The SkaleUp BD team'));
    }
}
