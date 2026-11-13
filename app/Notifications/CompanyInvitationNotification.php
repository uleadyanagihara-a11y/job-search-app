<?php

namespace App\Notifications;

use App\Models\Company;
use App\Support\FrontendUrl;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * 企業担当者の招待メール（docs/domain-design.md 2 章）。宛先はまだ user ではないため、
 * `Notification::route('mail', $email)` で送る。
 *
 * URL は `${FRONTEND_URL}/invitations/{token}`。email は URL に入れない（lookup API が返す）。
 */
class CompanyInvitationNotification extends Notification
{
    public function __construct(
        private readonly Company $company,
        #[\SensitiveParameter] private readonly string $token,
    ) {}

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
            ->subject(__('Invitation to :company', ['company' => $this->company->name]))
            ->line(__('You have been invited to manage job postings for :company.', ['company' => $this->company->name]))
            ->action(__('Accept Invitation'), FrontendUrl::to('/invitations/'.$this->token))
            ->line(__('This invitation will expire in :count days.', [
                'count' => intdiv((int) config('auth.invitations.expire'), 60 * 24),
            ]))
            ->line(__('If you were not expecting this invitation, no further action is required.'));
    }
}
