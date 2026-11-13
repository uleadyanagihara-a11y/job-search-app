<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * メールアドレスを変えたときに古いアドレスへ送る通知（docs/domain-api-contract.md M2.2）。
 * 乗っ取りに気付けるようにするためのもの。新しいアドレスは本文に載せない。
 */
class EmailChangedNotification extends Notification
{
    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Your email address has been changed'))
            ->line(__('The email address of your account has been changed. This address will no longer receive notifications.'))
            ->line(__('If you did not make this change, please contact support.'));
    }
}
