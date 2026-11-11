<?php

namespace App\Services;

use App\Exceptions\WhatsAppApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class WhatsAppTemplateService
{
    public function all(?string $after = null, int $limit = 50): array
    {
        $this->assertConfigured();

        $query = [
            'fields' => 'id,name,status,category,language,components,quality_score,rejected_reason',
            'limit' => min(max($limit, 1), 100),
        ];

        if (filled($after)) {
            $query['after'] = $after;
        }

        return $this->request('get', $this->templatesUrl(), $query);
    }

    public function find(string $name, string $language): ?array
    {
        $this->assertConfigured();
        $result = $this->request('get', $this->templatesUrl(), [
            'name' => $name,
            'fields' => 'id,name,status,category,language,components,quality_score,rejected_reason',
            'limit' => 100,
        ]);

        foreach ($result['data'] ?? [] as $template) {
            if (($template['name'] ?? null) === $name && ($template['language'] ?? null) === $language) {
                return $template;
            }
        }

        return null;
    }

    public function create(array $input, ?UploadedFile $headerMedia = null): array
    {
        $this->assertConfigured();

        if (($input['components'] ?? []) !== []) {
            $payload = [
                'name' => $input['name'],
                'language' => $input['language'],
                'category' => $input['category'],
                'allow_category_change' => $input['allow_category_change'] ?? true,
                'components' => array_values($input['components']),
            ];

            foreach (['parameter_format', 'message_send_ttl_seconds'] as $field) {
                if (isset($input[$field])) {
                    $payload[$field] = $input[$field];
                }
            }

            return $this->request('post', $this->templatesUrl(), $payload);
        }

        $components = [];
        $headerType = $input['header_type'] ?? (filled($input['header'] ?? null) ? 'TEXT' : 'NONE');

        if (in_array($headerType, ['IMAGE', 'VIDEO', 'DOCUMENT'], true)) {
            $components[] = [
                'type' => 'HEADER',
                'format' => $headerType,
                'example' => [
                    'header_handle' => [$this->uploadTemplateMedia($headerMedia)],
                ],
            ];
        } elseif ($headerType === 'TEXT') {
            $header = [
                'type' => 'HEADER',
                'format' => 'TEXT',
                'text' => $input['header'],
            ];

            if (filled($input['header_example'] ?? null)) {
                $header['example'] = [
                    'header_text' => [$input['header_example']],
                ];
            }

            $components[] = $header;
        }

        $body = [
            'type' => 'BODY',
            'text' => $input['body'],
        ];

        if (($input['body_examples'] ?? []) !== []) {
            $body['example'] = [
                'body_text' => [array_values($input['body_examples'])],
            ];
        }

        $components[] = $body;

        if (filled($input['footer'] ?? null)) {
            $components[] = [
                'type' => 'FOOTER',
                'text' => $input['footer'],
            ];
        }

        if (($input['buttons'] ?? []) !== []) {
            $components[] = [
                'type' => 'BUTTONS',
                'buttons' => array_map(
                    function (array $button) {
                        $type = $button['type'] ?? 'QUICK_REPLY';
                        $component = [
                            'type' => $type,
                            'text' => $button['text'],
                        ];

                        foreach ([
                            'url',
                            'phone_number',
                            'example',
                            'flow_id',
                            'flow_action',
                            'navigate_screen',
                        ] as $field) {
                            if (isset($button[$field])) {
                                $component[$field] = $button[$field];
                            }
                        }

                        return $component;
                    },
                    $input['buttons']
                ),
            ];
        }

        return $this->request('post', $this->templatesUrl(), [
            'name' => $input['name'],
            'language' => $input['language'],
            'category' => $input['category'],
            'allow_category_change' => $input['allow_category_change'] ?? true,
            'components' => $components,
        ]);
    }

    private function uploadTemplateMedia(?UploadedFile $media): string
    {
        if (! $media || ! $media->isValid()) {
            throw new WhatsAppApiException(
                'The WhatsApp template header media file is invalid.',
                'INVALID_MEDIA',
                422
            );
        }

        if (! filled(config('services.whatsapp.app_id'))) {
            throw new WhatsAppApiException(
                'WhatsApp media uploads require the Meta App ID.',
                'APP_ID_NOT_CONFIGURED',
                503
            );
        }

        $mimeType = (string) ($media->getMimeType() ?: $media->getClientMimeType());
        $session = $this->request('post', $this->uploadsUrl([
            'file_length' => (int) $media->getSize(),
            'file_type' => $mimeType,
            'file_name' => $media->getClientOriginalName(),
        ]), []);
        $uploadId = $session['id'] ?? null;

        if (! is_string($uploadId) || $uploadId === '') {
            throw new WhatsAppApiException(
                'Meta did not create a media upload session.',
                'INVALID_UPLOAD_SESSION'
            );
        }

        try {
            $contents = file_get_contents($media->getRealPath());
            if ($contents === false) {
                throw new WhatsAppApiException(
                    'The template media file could not be read.',
                    'MEDIA_READ_FAILED',
                    422
                );
            }

            $response = Http::acceptJson()
                ->withHeaders([
                    'Authorization' => 'OAuth '.(string) config('services.whatsapp.access_token'),
                    'file_offset' => '0',
                ])
                ->withBody($contents, $mimeType)
                ->timeout(max(3, (int) config('services.whatsapp.timeout', 15)))
                ->post($this->graphUrl($uploadId));

            if (! $response->successful()) {
                $providerCode = (string) ($response->json('error.code') ?? $response->status());
                throw new WhatsAppApiException(
                    $providerCode === '190'
                        ? 'The Meta access token is invalid or expired.'
                        : 'Meta could not upload the WhatsApp template media.',
                    $providerCode,
                    $response->status() >= 500 ? 502 : 422
                );
            }

            $handle = $response->json('h');
            if (! is_string($handle) || $handle === '') {
                throw new WhatsAppApiException(
                    'Meta returned an invalid media upload response.',
                    'INVALID_UPLOAD_RESPONSE'
                );
            }

            return $handle;
        } catch (WhatsAppApiException $exception) {
            throw $exception;
        } catch (ConnectionException $exception) {
            throw new WhatsAppApiException(
                'Unable to upload the template media to WhatsApp.',
                'CONNECTION_ERROR'
            );
        } catch (Throwable $exception) {
            Log::error('Unexpected WhatsApp template media upload failure.', [
                'exception' => $exception::class,
            ]);

            throw new WhatsAppApiException(
                'The WhatsApp template media could not be uploaded.',
                'UNEXPECTED_UPLOAD_ERROR'
            );
        }
    }

    private function request(string $method, string $url, array $payload): array
    {
        try {
            $request = Http::acceptJson()
                ->withToken((string) config('services.whatsapp.access_token'))
                ->timeout(max(3, (int) config('services.whatsapp.timeout', 15)));

            $response = $method === 'get'
                ? $request->get($url, $payload)
                : $request->post($url, $payload);

            if (! $response->successful()) {
                $providerCode = (string) ($response->json('error.code') ?? $response->status());
                $providerMessage = $providerCode === '190'
                    ? 'The Meta access token is invalid or expired.'
                    : 'Meta could not process the WhatsApp template request.';

                throw new WhatsAppApiException(
                    $providerMessage,
                    $providerCode,
                    $response->status() >= 500 ? 502 : 422
                );
            }

            $data = $response->json();
            if (! is_array($data)) {
                throw new WhatsAppApiException(
                    'Meta returned an invalid template response.',
                    'INVALID_RESPONSE'
                );
            }

            return $data;
        } catch (WhatsAppApiException $exception) {
            throw $exception;
        } catch (ConnectionException $exception) {
            throw new WhatsAppApiException(
                'Unable to reach WhatsApp. Please try again.',
                'CONNECTION_ERROR'
            );
        } catch (Throwable $exception) {
            Log::error('Unexpected WhatsApp template API failure.', [
                'exception' => $exception::class,
            ]);

            throw new WhatsAppApiException(
                'The WhatsApp template request could not be completed.',
                'UNEXPECTED_ERROR'
            );
        }
    }

    private function assertConfigured(): void
    {
        if (! config('services.whatsapp.enabled')) {
            throw new WhatsAppApiException('WhatsApp integration is disabled.', 'DISABLED', 503);
        }

        foreach (['graph_version', 'business_account_id', 'access_token'] as $key) {
            if (! filled(config("services.whatsapp.{$key}"))) {
                throw new WhatsAppApiException(
                    'WhatsApp template management is not fully configured.',
                    'NOT_CONFIGURED',
                    503
                );
            }
        }
    }

    private function templatesUrl(): string
    {
        $businessAccountId = trim((string) config('services.whatsapp.business_account_id'));

        return $this->graphUrl("{$businessAccountId}/message_templates");
    }

    private function uploadsUrl(array $query): string
    {
        $appId = trim((string) config('services.whatsapp.app_id'));

        return $this->graphUrl("{$appId}/uploads").'?'.http_build_query($query);
    }

    private function graphUrl(string $path): string
    {
        $version = trim((string) config('services.whatsapp.graph_version'), '/');

        return 'https://graph.facebook.com/'.$version.'/'.ltrim($path, '/');
    }
}
