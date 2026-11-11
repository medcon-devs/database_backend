<?php

namespace App\Services;

use App\Models\WhatsAppCampaignRecipient;
use App\Models\WhatsAppMessage;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class WhatsAppWebhookService
{
    private const STATUS_RANK = [
        'accepted' => 0,
        'sent' => 1,
        'delivered' => 2,
        'read' => 3,
    ];

    public function __construct(
        private WhatsAppPhoneService $phones,
        private WhatsAppConsentService $consents
    ) {}

    public function process(array $payload): void
    {
        DB::transaction(function () use ($payload) {
            foreach ($payload['entry'] ?? [] as $entry) {
                foreach ($entry['changes'] ?? [] as $change) {
                    if (($change['field'] ?? null) !== 'messages') {
                        continue;
                    }

                    $value = $change['value'] ?? [];
                    foreach ($value['statuses'] ?? [] as $status) {
                        $this->processStatus($status);
                    }
                    foreach ($value['messages'] ?? [] as $message) {
                        $this->processInboundMessage($message);
                    }
                }
            }
        });
    }

    private function processStatus(array $status): void
    {
        $providerId = (string) ($status['id'] ?? '');
        if ($providerId === '') {
            return;
        }

        $state = (string) ($status['status'] ?? 'unknown');
        $occurredAt = $this->timestamp($status['timestamp'] ?? null);
        $message = WhatsAppMessage::firstOrNew(['whatsapp_message_id' => $providerId]);

        if (! $message->exists) {
            $message->fill([
                'phone' => $this->phones->normalize((string) ($status['recipient_id'] ?? '')),
                'direction' => 'outbound',
                'type' => 'unknown',
                'status' => $state,
            ]);
        } elseif (! $this->shouldAdvance($message->status, $state)) {
            return;
        }

        $message->status = $state;
        if (in_array($state, ['sent', 'delivered', 'read'], true)) {
            $message->{$state.'_at'} = $occurredAt;
        }
        if ($state === 'failed') {
            $message->failed_at = $occurredAt;
            $message->error_code = (string) data_get($status, 'errors.0.code', '');
            $message->error_message = mb_substr((string) data_get(
                $status,
                'errors.0.error_data.details',
                data_get($status, 'errors.0.title', 'WhatsApp delivery failed.')
            ), 0, 2000);
        }
        $message->save();
        if ($message->whatsapp_campaign_recipient_id) {
            $updates = ['status' => $state];
            if (in_array($state, ['sent', 'delivered', 'read'], true)) {
                $updates[$state.'_at'] = $occurredAt;
            }
            if ($state === 'failed') {
                $updates['failed_at'] = $occurredAt;
                $updates['error_code'] = $message->error_code;
                $updates['error_message'] = $message->error_message;
            }
            WhatsAppCampaignRecipient::whereKey($message->whatsapp_campaign_recipient_id)->update($updates);
        }
    }

    private function processInboundMessage(array $incoming): void
    {
        $providerId = (string) ($incoming['id'] ?? '');
        $phone = $this->phones->normalize((string) ($incoming['from'] ?? ''));
        if ($providerId === '' || $phone === '') {
            return;
        }

        $content = $this->content($incoming);
        $replyToProviderId = (string) data_get($incoming, 'context.id', '');
        $outbound = $replyToProviderId === '' ? null : WhatsAppMessage::where('whatsapp_message_id', $replyToProviderId)->first();
        $message = WhatsAppMessage::firstOrCreate(
            ['whatsapp_message_id' => $providerId],
            [
                'csv_data_id' => $this->phones->findContactId($phone),
                'whatsapp_campaign_id' => $outbound?->whatsapp_campaign_id,
                'whatsapp_campaign_recipient_id' => $outbound?->whatsapp_campaign_recipient_id,
                'reply_to_whatsapp_message_id' => $replyToProviderId ?: null,
                'phone' => $phone,
                'direction' => 'inbound',
                'type' => (string) ($incoming['type'] ?? 'unknown'),
                'status' => 'received',
                'content' => $content,
                'metadata' => $this->inboundMetadata($incoming),
                'received_at' => $this->timestamp($incoming['timestamp'] ?? null),
            ]
        );

        if (! $message->wasRecentlyCreated) {
            return;
        }

        if ($message->whatsapp_campaign_recipient_id) {
            $recipient = WhatsAppCampaignRecipient::find($message->whatsapp_campaign_recipient_id);
            $recipient?->update([
                'response_count' => $recipient->response_count + 1,
                'last_response_content' => $content,
                'last_responded_at' => $message->received_at ?? now(),
            ]);
        }

        $this->consents->handleInbound(
            $phone,
            $content,
            $message,
            $message->received_at ?? now()
        );
    }

    private function content(array $message): string
    {
        return trim((string) match ($message['type'] ?? null) {
            'text' => data_get($message, 'text.body', ''),
            'button' => data_get($message, 'button.text', data_get($message, 'button.payload', '')),
            'interactive' => data_get(
                $message,
                'interactive.button_reply.title',
                data_get($message, 'interactive.list_reply.title', '')
            ),
            default => '',
        });
    }

    private function inboundMetadata(array $message): array
    {
        return array_filter([
            'button_payload' => data_get($message, 'button.payload'),
            'button_reply_id' => data_get($message, 'interactive.button_reply.id'),
            'list_reply_id' => data_get($message, 'interactive.list_reply.id'),
            'context_message_id' => data_get($message, 'context.id'),
            'context_from' => data_get($message, 'context.from'),
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function shouldAdvance(?string $current, string $incoming): bool
    {
        if ($incoming === 'failed') {
            return true;
        }
        if ($current === 'failed') {
            return false;
        }

        return (self::STATUS_RANK[$incoming] ?? -1) > (self::STATUS_RANK[$current] ?? -1);
    }

    private function timestamp($value): Carbon
    {
        return is_numeric($value)
            ? Carbon::createFromTimestampUTC((int) $value)
            : now();
    }
}
