<?php

namespace App\Services;

use App\Models\WhatsAppConsent;
use App\Models\WhatsAppConsentEvent;
use App\Models\WhatsAppMessage;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class WhatsAppConsentService
{
    private const OPT_IN_KEYWORDS = [
        'join', 'yes', 'start', 'subscribe',
        'موافق', 'تأكيد', 'تاكيد', 'اشتراك', 'اشترك',
    ];

    private const OPT_OUT_KEYWORDS = [
        'stop', 'unsubscribe', 'cancel',
        'إلغاء', 'الغاء', 'توقف', 'وقف', 'لا ترسل',
    ];

    public function __construct(private WhatsAppPhoneService $phones) {}

    public function handleInbound(
        string $phone,
        string $content,
        WhatsAppMessage $message,
        CarbonInterface $occurredAt
    ): ?WhatsAppConsent {
        $keyword = $this->keyword($content);
        if (in_array($keyword, self::OPT_IN_KEYWORDS, true)) {
            return $this->record(
                $phone,
                WhatsAppConsent::STATUS_OPTED_IN,
                'whatsapp_keyword',
                $message,
                $occurredAt
            );
        }

        if (in_array($keyword, self::OPT_OUT_KEYWORDS, true)) {
            return $this->record(
                $phone,
                WhatsAppConsent::STATUS_OPTED_OUT,
                'whatsapp_keyword',
                $message,
                $occurredAt
            );
        }

        return null;
    }

    public function recordExternal(
        string $phone,
        string $action,
        string $sourceDomain,
        ?string $consentText = null,
        ?string $consentVersion = null,
        ?string $evidenceReference = null,
        array $metadata = []
    ): WhatsAppConsent {
        $status = $action === 'opt_in'
            ? WhatsAppConsent::STATUS_OPTED_IN
            : WhatsAppConsent::STATUS_OPTED_OUT;

        return DB::transaction(function () use (
            $phone,
            $status,
            $sourceDomain,
            $consentText,
            $consentVersion,
            $evidenceReference,
            $metadata
        ) {
            $phone = $this->phones->normalize($phone);
            $occurredAt = now();
            $consent = WhatsAppConsent::lockForUpdate()->firstOrNew(['phone' => $phone]);

            if (! $consent->csv_data_id) {
                $consent->csv_data_id = $this->phones->findContactId($phone);
            }

            $duplicateEvent = $evidenceReference !== null
                && $consent->exists
                && $consent->events()
                    ->where('event', $status)
                    ->where('source', 'external_api')
                    ->where('evidence_reference', $evidenceReference)
                    ->exists();

            if ($duplicateEvent) {
                return $consent->fresh();
            }

            $consent->fill([
                'status' => $status,
                'scope' => 'marketing',
                'source' => 'external_api',
                'source_domain' => $sourceDomain,
                'consent_text' => $consentText,
                'consent_version' => $consentVersion,
                'evidence_reference' => $evidenceReference,
                'opted_in_at' => $status === WhatsAppConsent::STATUS_OPTED_IN
                    ? $occurredAt
                    : $consent->opted_in_at,
                'opted_out_at' => $status === WhatsAppConsent::STATUS_OPTED_OUT
                    ? $occurredAt
                    : null,
            ])->save();

            $consent->events()->create([
                'event' => $status,
                'source' => 'external_api',
                'evidence_reference' => $evidenceReference,
                'consent_text' => $consentText,
                'consent_version' => $consentVersion,
                'metadata' => array_merge($metadata, ['source_domain' => $sourceDomain]),
                'occurred_at' => $occurredAt,
            ]);

            return $consent->fresh();
        });
    }

    private function record(
        string $phone,
        string $status,
        string $source,
        WhatsAppMessage $message,
        CarbonInterface $occurredAt
    ): WhatsAppConsent {
        $phone = $this->phones->normalize($phone);
        $consent = WhatsAppConsent::firstOrNew(['phone' => $phone]);

        if (! $consent->csv_data_id) {
            $consent->csv_data_id = $message->csv_data_id ?: $this->phones->findContactId($phone);
        }

        $consent->fill([
            'status' => $status,
            'scope' => 'marketing',
            'source' => $source,
            'evidence_reference' => $message->whatsapp_message_id,
            'opted_in_at' => $status === WhatsAppConsent::STATUS_OPTED_IN ? $occurredAt : $consent->opted_in_at,
            'opted_out_at' => $status === WhatsAppConsent::STATUS_OPTED_OUT ? $occurredAt : null,
        ])->save();

        WhatsAppConsentEvent::create([
            'whatsapp_consent_id' => $consent->id,
            'whatsapp_message_record_id' => $message->id,
            'event' => $status,
            'source' => $source,
            'evidence_reference' => $message->whatsapp_message_id,
            'occurred_at' => $occurredAt,
        ]);

        return $consent->fresh();
    }

    private function keyword(string $content): string
    {
        $content = mb_strtolower(trim($content));
        $content = preg_replace('/[^\pL\pN\s]+/u', '', $content) ?? $content;

        return trim(preg_replace('/\s+/u', ' ', $content) ?? $content);
    }
}
