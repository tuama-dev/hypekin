<?php

namespace App\Notifications;

use App\Models\PostTarget;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
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
        return ['database'];
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
