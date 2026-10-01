<?php

namespace App\Notifications;

use App\Models\PostTarget;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class PostTargetFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public PostTarget $target) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * Build the queued email sent alongside the in-app database row.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $platform = $this->target->socialAccount->platform;
        $workspaceSlug = $this->target->post?->workspace?->slug;

        $message = (new MailMessage)
            ->subject('Publish failed on '.$platform->label())
            ->line('Your post did not publish to '.$this->target->socialAccount->display_name.'.');

        if ($workspaceSlug !== null) {
            $message->action(
                'View post',
                route('workspace.posts.show', [
                    'workspace' => $workspaceSlug,
                    'post' => $this->target->post_id,
                ]),
            );
        }

        return $message;
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $platform = $this->target->socialAccount->platform;

        return [
            'title' => 'Publish failed on '.$platform->label(),
            'message' => 'Your post did not publish to '.$this->target->socialAccount->display_name.'.',
            'platform' => $platform->value,
            'account_display_name' => $this->target->socialAccount->display_name,
            'error' => $this->target->error_message !== null
                ? Str::limit($this->target->error_message, 300)
                : null,
            'post_id' => $this->target->post_id,
            'workspace_slug' => $this->target->post->workspace->slug,
        ];
    }
}
