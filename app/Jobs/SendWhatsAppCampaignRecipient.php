<?php

namespace App\Jobs;

use App\Models\WhatsAppCampaignRecipient;
use App\Services\WhatsAppCloudApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SendWhatsAppCampaignRecipient implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public int $recipientId) {}

    public function handle(WhatsAppCloudApiService $whatsApp): void
    {
        $recipient = WhatsAppCampaignRecipient::with('campaign')->findOrFail($this->recipientId);
        if ($recipient->status !== 'pending' || ! in_array($recipient->campaign->status, ['sending', 'scheduled'], true)) {
            return;
        }

        try {
            $message = $whatsApp->sendTemplate(
                $recipient->phone,
                $recipient->campaign->template_name,
                $recipient->campaign->template_language,
                $recipient->components ?? [],
                $recipient->csv_data_id,
                $recipient->campaign->id,
                $recipient->id
            );
            $recipient->update([
                'whatsapp_message_record_id' => $message->id,
                'status' => 'accepted',
            ]);
        } catch (Throwable $exception) {
            $finalAttempt = $this->attempts() >= $this->tries;
            $recipient->update($finalAttempt ? [
                'status' => 'failed', 'error_code' => method_exists($exception, 'providerCode') ? $exception->providerCode() : 'JOB_FAILED',
                'error_message' => mb_substr($exception->getMessage(), 0, 2000), 'failed_at' => now(),
            ] : ['status' => 'pending']);
            throw $exception;
        } finally {
            $campaign = $recipient->campaign;
            if (! $campaign->recipients()->whereIn('status', ['pending', 'sending'])->exists()) {
                $campaign->update(['status' => 'completed', 'completed_at' => now()]);
            }
        }
    }
}
