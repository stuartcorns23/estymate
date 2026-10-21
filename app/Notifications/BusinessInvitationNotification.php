<?php

namespace App\Notifications;

use App\Models\BusinessInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BusinessInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(public BusinessInvitation $invitation) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $business = $this->invitation->business;

        return (new MailMessage)
            ->subject("You've been invited to join {$business->name}")
            ->line("You've been invited to join {$business->name} on ".config('app.name').'.')
            ->line('Create an account or log in with this email address, then accept the invitation.')
            ->action('Accept invitation', route('invitations.accept', $this->invitation->token));
    }
}
