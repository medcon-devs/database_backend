<?php

namespace App\Services;

use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppCampaignRecipient;
use App\Models\WhatsAppMessage;
use Illuminate\Database\Eloquent\Builder;

class WhatsAppReportService
{
    public function __construct(private WhatsAppPhoneService $phones) {}

    public function generalResponses(array $filters): Builder
    {
        return $this->applyResponseFilters(
            WhatsAppMessage::query()
                ->with([
                    'contact:id,firstname,lastname,email,phone',
                    'campaign:id,name,template_name,template_language',
                    'contextMessage:id,whatsapp_message_id,whatsapp_campaign_id,template_name,template_language',
                    'contextMessage.campaign:id,name,template_name,template_language',
                ])
                ->where('direction', 'inbound')
                ->where(function ($query) {
                    $query->where('type', 'button')
                        ->orWhere(function ($interactive) {
                            $interactive->where('type', 'interactive')
                                ->where(function ($reply) {
                                    $reply->whereNotNull('metadata->button_reply_id')
                                        ->orWhereNotNull('metadata->list_reply_id');
                                });
                        });
                }),
            $filters
        );
    }

    public function campaignResponses(WhatsAppCampaign $campaign, array $filters): Builder
    {
        return $this->applyResponseFilters(
            WhatsAppMessage::query()
                ->with([
                    'contact:id,firstname,lastname,email,phone',
                    'campaign:id,name,template_name,template_language',
                    'contextMessage:id,whatsapp_message_id,whatsapp_campaign_id,template_name,template_language',
                    'contextMessage.campaign:id,name,template_name,template_language',
                ])
                ->where('whatsapp_campaign_id', $campaign->id)
                ->where('direction', 'inbound'),
            $filters
        );
    }

    public function campaignRecipients(WhatsAppCampaign $campaign, array $filters): Builder
    {
        return WhatsAppCampaignRecipient::query()
            ->with('contact:id,firstname,lastname,email,phone')
            ->where('whatsapp_campaign_id', $campaign->id)
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['responded']), function ($query) use ($filters) {
                return $filters['responded']
                    ? $query->where('response_count', '>', 0)
                    : $query->where('response_count', 0);
            });
    }

    private function applyResponseFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when(filled($filters['phone'] ?? null), function ($query) use ($filters) {
                $query->where('phone', $this->phones->normalize($filters['phone']));
            })
            ->when(isset($filters['contact_id']), fn ($query) => $query->where('csv_data_id', $filters['contact_id']))
            ->when(isset($filters['from']), fn ($query) => $query->where('received_at', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($query) => $query->where('received_at', '<=', $filters['to']))
            ->when(
                isset($filters['template_name']) || isset($filters['template_language']),
                fn ($query) => $this->applyTemplateFilters($query, $filters)
            );
    }

    public function attribution(WhatsAppMessage $message): array
    {
        $context = $message->contextMessage;
        $campaign = $message->campaign ?: $context?->campaign;
        $hasDirectTemplate = filled($context?->template_name);

        return [
            'template_name' => $hasDirectTemplate ? $context->template_name : $campaign?->template_name,
            'template_language' => $hasDirectTemplate ? $context->template_language : $campaign?->template_language,
            'campaign' => $campaign,
        ];
    }

    private function applyTemplateFilters(Builder $query, array $filters): Builder
    {
        return $query->where(function ($attributed) use ($filters) {
            $attributed->whereHas('contextMessage', function ($context) use ($filters) {
                $context->whereNotNull('template_name');
                $this->applyTemplateValues($context, $filters);
            })->orWhere(function ($fallback) use ($filters) {
                $fallback->whereDoesntHave('contextMessage', fn ($context) => $context->whereNotNull('template_name'))
                    ->where(function ($campaignSource) use ($filters) {
                        $campaignSource->whereHas('campaign', fn ($campaign) => $this->applyTemplateValues($campaign, $filters))
                            ->orWhereHas('contextMessage.campaign', fn ($campaign) => $this->applyTemplateValues($campaign, $filters));
                    });
            });
        });
    }

    private function applyTemplateValues(Builder $query, array $filters): void
    {
        $query
            ->when(isset($filters['template_name']), fn ($query) => $query->where('template_name', $filters['template_name']))
            ->when(isset($filters['template_language']), fn ($query) => $query->where('template_language', $filters['template_language']));
    }
}
