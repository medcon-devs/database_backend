<?php

namespace App\Jobs;

use App\Models\NotificationDelivery;
use App\Services\MentionContextService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendMentionEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300, 900];

    public function __construct(public int $deliveryId)
    {
    }

    public function handle(MentionContextService $contexts): void
    {
        $delivery = NotificationDelivery::with(['mention.user', 'mention.message.sender'])->find($this->deliveryId);
        if (! $delivery || $delivery->status === 'sent') {
            return;
        }

        $delivery->increment('attempts');
        $delivery->update([
            'status' => 'sending',
            'failed_at' => null,
            'failure_reason' => null,
        ]);
        $delivery->refresh();
        $mention = $delivery->mention;
        $message = $mention->message;
        $group = $contexts->resolve($message->context_type, (int) $message->context_id);
        $url = $contexts->frontendUrl($group, $message->id);
        $contactName = trim($group->contact->firstname.' '.$group->contact->lastname);

        try {
            Mail::send('emails.mention', [
                'recipient_name' => $mention->user->name,
                'sender_name' => $message->sender->name,
                'mention_message' => $message->message,
                'contact_name' => $contactName,
                'media_group_title' => $group->title,
                'mentioned_at' => $message->created_at,
                'destination_url' => $url,
            ], function ($mail) use ($mention) {
                $mail->to($mention->user->email)->subject('You were mentioned in a doctor file');
                if (config('mail.support.address')) {
                    $mail->replyTo(config('mail.support.address'), 'Medcon Support');
                }
            });

            $delivery->update(['status' => 'sent', 'sent_at' => now()]);
        } catch (Throwable $exception) {
            $delivery->update([
                'status' => 'failed',
                'failed_at' => now(),
                'failure_reason' => mb_substr($exception->getMessage(), 0, 2000),
            ]);
            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        NotificationDelivery::whereKey($this->deliveryId)->where('status', '!=', 'sent')->update([
            'status' => 'failed',
            'failed_at' => now(),
            'failure_reason' => mb_substr($exception->getMessage(), 0, 2000),
        ]);
    }
}
