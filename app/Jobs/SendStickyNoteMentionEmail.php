<?php

namespace App\Jobs;

use App\Models\StickyNoteMention;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendStickyNoteMentionEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300, 900];

    public function __construct(public int $mentionId) {}

    public function handle(): void
    {
        $mention = StickyNoteMention::with([
            'user',
            'note.contact',
            'note.mediaGroup',
            'note.creator',
        ])->find($this->mentionId);

        if (! $mention || $mention->email_status === StickyNoteMention::STATUS_SENT) {
            return;
        }

        $note = $mention->note;
        if (! $note || ! $mention->user) {
            return;
        }

        $mention->increment('email_attempts');
        $mention->update([
            'email_status' => StickyNoteMention::STATUS_SENDING,
            'email_failed_at' => null,
            'email_failure_reason' => null,
        ]);
        $mention->refresh();

        $contact = $note->contact;
        $contactName = $contact ? trim($contact->firstname.' '.$contact->lastname) : 'Not specified';

        try {
            Mail::send('emails.sticky_note_mention', [
                'recipient_name' => $mention->user->name,
                'sender_name' => $note->creator?->name ?? 'A colleague',
                'note_content' => $note->content,
                'contact_name' => $contactName ?: 'Not specified',
                'location_label' => $note->mediaGroup?->title
                    ? 'Folder: '.$note->mediaGroup->title
                    : 'Contact profile',
                'mentioned_at' => $mention->created_at ?? $note->created_at ?? now(),
                'destination_url' => $this->destinationUrl($note),
            ], function ($mail) use ($mention) {
                $mail->to($mention->user->email)->subject('You were mentioned in a sticky note');
                if (config('mail.support.address')) {
                    $mail->replyTo(config('mail.support.address'), 'Medcon Support');
                }
            });

            $mention->update([
                'email_status' => StickyNoteMention::STATUS_SENT,
                'email_sent_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $mention->update([
                'email_status' => StickyNoteMention::STATUS_FAILED,
                'email_failed_at' => now(),
                'email_failure_reason' => mb_substr($exception->getMessage(), 0, 2000),
            ]);
            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        StickyNoteMention::whereKey($this->mentionId)
            ->where('email_status', '!=', StickyNoteMention::STATUS_SENT)
            ->update([
                'email_status' => StickyNoteMention::STATUS_FAILED,
                'email_failed_at' => now(),
                'email_failure_reason' => mb_substr($exception->getMessage(), 0, 2000),
            ]);
    }

    private function destinationUrl(\App\Models\StickyNote $note): string
    {
        $query = ['tab' => 'profile', 'note' => $note->id];
        if ($note->media_group_id) {
            $query['folder'] = $note->media_group_id;
        }

        return rtrim((string) config('mentions.frontend_url'), '/')
            .'/contacts/'.$note->contact_id
            .'?'.http_build_query($query);
    }
}
