<?php

namespace App\Jobs;

use App\Helper\_EmailHelper;
use App\Models\ContactDocumentDownload;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendContactDownloadNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $downloadId) {}

    public function handle(): void
    {
        $download = ContactDocumentDownload::with(['contact', 'file.group', 'user'])->findOrFail($this->downloadId);
        $tenant = $download->user->microsoft_tenant_id;

        User::query()
            ->where('is_banned', false)
            ->where(fn ($query) => $tenant === null
                ? $query->whereNull('microsoft_tenant_id')
                : $query->where('microsoft_tenant_id', $tenant))
            ->get()
            ->filter(fn (User $user) => $user->hasAnyPermission(['contact_notifications.receive', 'speaker_notifications.receive']))
            ->each(fn (User $recipient) => _EmailHelper::sendEmail([
                'manager_name' => $recipient->name,
                'downloaded_by_name' => $download->user->name,
                'downloaded_by_email' => $download->user->email,
                'contact_name' => trim($download->contact->firstname.' '.$download->contact->lastname),
                'document_name' => $download->file->file_name ?: 'Contact document',
                'document_category' => $download->category,
                'downloaded_at' => $download->downloaded_at,
            ], 'emails.contact_document_downloaded', $recipient->email, 'Sensitive contact document downloaded'));
    }
}
