<?php

namespace App\Services;

use App\Exceptions\WhatsAppApiException;
use App\Models\WhatsAppMessage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class WhatsAppCloudApiService
{
    public function __construct(private WhatsAppPhoneService $phones) {}

    public function sendTemplate(
        string $to,
        string $templateName,
        string $language,
        array $components = [],
        ?int $contactId = null,
        ?int $campaignId = null,
        ?int $campaignRecipientId = null
    ): WhatsAppMessage {
        $this->assertConfigured();

        $phone = $this->phones->normalize($to);
        if (! $this->phones->isValid($phone)) {
            throw new WhatsAppApiException('The WhatsApp recipient number is invalid.', 'INVALID_PHONE', 422);
        }

        $message = WhatsAppMessage::create([
            'csv_data_id' => $contactId,
            'whatsapp_campaign_id' => $campaignId,
            'whatsapp_campaign_recipient_id' => $campaignRecipientId,
            'phone' => $phone,
            'direction' => 'outbound',
            'type' => 'template',
            'template_name' => $templateName,
            'template_language' => $language,
            'status' => 'sending',
            'metadata' => $components === [] ? null : ['components' => $components],
        ]);

        $template = [
            'name' => $templateName,
            'language' => ['code' => $language],
        ];
        if ($components !== []) {
            $template['components'] = $components;
        }

        try {
            $response = Http::acceptJson()
                ->withToken((string) config('services.whatsapp.access_token'))
                ->timeout(max(3, (int) config('services.whatsapp.timeout', 15)))
                ->post($this->messagesUrl(), [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $phone,
                    'type' => 'template',
                    'template' => $template,
                ]);

            if (! $response->successful()) {
                $providerCode = (string) ($response->json('error.code') ?? $response->status());
                $providerMessage = (string) ($response->json('error.message') ?? 'WhatsApp rejected the message.');
                $this->markFailed($message, $providerCode, $providerMessage);

                throw new WhatsAppApiException(
                    $providerCode === '190'
                        ? 'The Meta access token is invalid or expired.'
                        : 'WhatsApp could not accept the message.',
                    $providerCode,
                    $response->status() >= 500 ? 502 : 422
                );
            }

            $whatsAppMessageId = $response->json('messages.0.id');
            if (! is_string($whatsAppMessageId) || $whatsAppMessageId === '') {
                $this->markFailed($message, 'INVALID_RESPONSE', 'Meta response did not contain a message ID.');
                throw new WhatsAppApiException('WhatsApp returned an invalid response.', 'INVALID_RESPONSE');
            }

            $message->update([
                'whatsapp_message_id' => $whatsAppMessageId,
                'status' => 'accepted',
            ]);

            return $message->fresh();
        } catch (WhatsAppApiException $exception) {
            throw $exception;
        } catch (ConnectionException $exception) {
            $this->markFailed($message, 'CONNECTION_ERROR', 'Could not connect to Meta Graph API.');
            throw new WhatsAppApiException('Unable to reach WhatsApp. Please try again.', 'CONNECTION_ERROR');
        } catch (Throwable $exception) {
            $this->markFailed($message, 'UNEXPECTED_ERROR', 'Unexpected WhatsApp integration failure.');
            Log::error('Unexpected WhatsApp send failure.', [
                'message_record_id' => $message->id,
                'exception' => $exception::class,
            ]);

            throw new WhatsAppApiException('The WhatsApp message could not be sent.', 'UNEXPECTED_ERROR');
        }
    }

    public function sendText(string $to, string $text, ?int $contactId = null): WhatsAppMessage
    {
        $this->assertConfigured();
        $phone = $this->phones->normalize($to);
        if (! $this->phones->isValid($phone)) {
            throw new WhatsAppApiException('The WhatsApp recipient number is invalid.', 'INVALID_PHONE', 422);
        }

        $message = WhatsAppMessage::create([
            'csv_data_id' => $contactId,
            'phone' => $phone,
            'direction' => 'outbound',
            'type' => 'text',
            'status' => 'sending',
            'content' => $text,
        ]);

        try {
            $response = Http::acceptJson()->withToken((string) config('services.whatsapp.access_token'))
                ->timeout(max(3, (int) config('services.whatsapp.timeout', 15)))
                ->post($this->messagesUrl(), [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $phone,
                    'type' => 'text',
                    'text' => ['preview_url' => true, 'body' => $text],
                ]);
            if (! $response->successful()) {
                $code = (string) ($response->json('error.code') ?? $response->status());
                $error = (string) ($response->json('error.message') ?? 'WhatsApp rejected the message.');
                $this->markFailed($message, $code, $error);
                throw new WhatsAppApiException($error, $code, $response->status() >= 500 ? 502 : 422);
            }
            $providerId = $response->json('messages.0.id');
            if (! is_string($providerId) || $providerId === '') {
                throw new WhatsAppApiException('WhatsApp returned an invalid response.', 'INVALID_RESPONSE');
            }
            $message->update(['whatsapp_message_id' => $providerId, 'status' => 'accepted']);

            return $message->fresh();
        } catch (WhatsAppApiException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->markFailed($message, 'SEND_FAILED', $exception->getMessage());
            throw new WhatsAppApiException('The WhatsApp message could not be sent.', 'SEND_FAILED');
        }
    }

    private function assertConfigured(): void
    {
        if (! config('services.whatsapp.enabled')) {
            throw new WhatsAppApiException('WhatsApp integration is disabled.', 'DISABLED', 503);
        }

        foreach (['graph_version', 'phone_number_id', 'access_token'] as $key) {
            if (! filled(config("services.whatsapp.{$key}"))) {
                throw new WhatsAppApiException(
                    'WhatsApp integration is not fully configured.',
                    'NOT_CONFIGURED',
                    503
                );
            }
        }
    }

    private function messagesUrl(): string
    {
        $version = trim((string) config('services.whatsapp.graph_version'), '/');
        $phoneNumberId = trim((string) config('services.whatsapp.phone_number_id'));

        return "https://graph.facebook.com/{$version}/{$phoneNumberId}/messages";
    }

    private function markFailed(WhatsAppMessage $message, string $code, string $error): void
    {
        $message->update([
            'status' => 'failed',
            'error_code' => mb_substr($code, 0, 100),
            'error_message' => mb_substr($error, 0, 2000),
            'failed_at' => now(),
        ]);
    }
}
