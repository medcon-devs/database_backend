<?php

namespace App\Console\Commands;

use App\Jobs\SendWhatsAppCampaignRecipient;
use App\Models\WhatsAppCampaign;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchScheduledWhatsAppCampaigns extends Command
{
    protected $signature = 'whatsapp:dispatch-scheduled-campaigns';

    protected $description = 'Queue WhatsApp campaigns whose scheduled time has arrived';

    public function handle(): int
    {
        WhatsAppCampaign::where('status', 'scheduled')->where('scheduled_at', '<=', now())
            ->orderBy('id')->each(function (WhatsAppCampaign $campaign) {
                DB::transaction(function () use ($campaign) {
                    $locked = WhatsAppCampaign::lockForUpdate()->find($campaign->id);
                    if (! $locked || $locked->status !== 'scheduled') {
                        return;
                    }
                    $locked->update(['status' => 'sending', 'started_at' => now()]);
                    $locked->recipients()->where('status', 'pending')->pluck('id')
                        ->each(fn ($id) => SendWhatsAppCampaignRecipient::dispatch($id)->onQueue('whatsapp'));
                });
            });

        return self::SUCCESS;
    }
}
