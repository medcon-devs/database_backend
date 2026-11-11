<?php

namespace App\Jobs;

use App\Helper\_EmailHelper;
use App\Models\SpeakerDocumentDownload;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendSpeakerDownloadNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 3;

    public function __construct(public int $downloadId) {}

    public function handle(): void
    {
        $download = SpeakerDocumentDownload::with(['speaker.contact', 'file.group', 'user'])->findOrFail($this->downloadId);
        $tenant = $download->user->microsoft_tenant_id;
        User::query()->where('is_banned', false)
            ->where(fn ($query) => $tenant === null ? $query->whereNull('microsoft_tenant_id') : $query->where('microsoft_tenant_id', $tenant))
            ->get()->filter(fn (User $user) => $user->hasPermission('speaker_notifications.receive'))
            ->each(fn (User $recipient) => _EmailHelper::sendEmail([
                'manager_name' => $recipient->name,
                'downloaded_by_name' => $download->user->name,
                'downloaded_by_email' => $download->user->email,
                'speaker_name' => trim($download->speaker->contact->firstname.' '.$download->speaker->contact->lastname),
                'document_name' => $download->file->file_name ?: 'Speaker document',
                'document_category' => $download->category,
                'downloaded_at' => $download->downloaded_at,
            ], 'emails.speaker_document_downloaded', $recipient->email, 'Sensitive speaker document downloaded'));
    }
}
