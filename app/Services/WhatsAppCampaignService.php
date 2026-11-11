<?php

namespace App\Services;

use App\Models\CSVData;
use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppCampaignRecipient;
use App\Models\WhatsAppConsent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WhatsAppCampaignService
{
    public function __construct(
        private WhatsAppPhoneService $phones,
        private WhatsAppTemplateService $templates
    ) {}

    public function create(array $input, int $creatorId): WhatsAppCampaign
    {
        $template = $this->approvedTemplate($input['template_name'], $input['template_language']);
        $input['template_category'] = $template['category'] ?? null;
        if ($input['template_category'] === 'MARKETING') {
            $input['require_opt_in'] = true;
        }

        return DB::transaction(function () use ($input, $creatorId) {
            $contacts = $this->contacts($input);
            $campaign = WhatsAppCampaign::create([
                'name' => $input['name'],
                'template_name' => $input['template_name'],
                'template_language' => $input['template_language'],
                'template_category' => $input['template_category'] ?? null,
                'components' => $input['components'] ?? [],
                'require_opt_in' => $input['require_opt_in'] ?? true,
                'contact_ids' => array_values(array_unique($input['contact_ids'] ?? [])),
                'tag_ids' => array_values(array_unique($input['tag_ids'] ?? [])),
                'scheduled_at' => $input['scheduled_at'] ?? null,
                'status' => isset($input['scheduled_at']) ? 'scheduled' : 'draft',
                'created_by' => $creatorId,
            ]);

            $this->rebuildRecipientSnapshot($campaign, $contacts);

            return $campaign->fresh(['creator:id,name,email']);
        });
    }

    public function update(WhatsAppCampaign $campaign, array $input): WhatsAppCampaign
    {
        return DB::transaction(function () use ($campaign, $input) {
            $campaign = WhatsAppCampaign::lockForUpdate()->findOrFail($campaign->id);
            if (! in_array($campaign->status, ['draft', 'scheduled'], true)) {
                throw new \LogicException('Campaign sending has already started.');
            }

            $templateChanged = array_key_exists('template_name', $input)
                || array_key_exists('template_language', $input);
            $templateName = $input['template_name'] ?? $campaign->template_name;
            $templateLanguage = $input['template_language'] ?? $campaign->template_language;
            $templateCategory = $campaign->template_category;
            if ($templateChanged) {
                $template = $this->approvedTemplate($templateName, $templateLanguage);
                $templateCategory = $template['category'] ?? null;
            }

            $requireOptIn = (bool) ($input['require_opt_in'] ?? $campaign->require_opt_in);
            if ($templateCategory === 'MARKETING') {
                $requireOptIn = true;
            }

            $contactIds = array_key_exists('contact_ids', $input)
                ? array_values(array_unique($input['contact_ids'] ?? []))
                : ($campaign->contact_ids ?? []);
            $tagIds = array_key_exists('tag_ids', $input)
                ? array_values(array_unique($input['tag_ids'] ?? []))
                : ($campaign->tag_ids ?? []);
            $components = array_key_exists('components', $input)
                ? $input['components']
                : ($campaign->components ?? []);

            $audienceChanged = array_key_exists('contact_ids', $input)
                || array_key_exists('tag_ids', $input)
                || array_key_exists('require_opt_in', $input)
                || $templateChanged;

            $campaign->update([
                'name' => $input['name'] ?? $campaign->name,
                'template_name' => $templateName,
                'template_language' => $templateLanguage,
                'template_category' => $templateCategory,
                'components' => $components,
                'require_opt_in' => $requireOptIn,
                'contact_ids' => $contactIds,
                'tag_ids' => $tagIds,
            ]);

            if ($audienceChanged) {
                if ($contactIds === [] && $tagIds === []) {
                    throw ValidationException::withMessages([
                        'contact_ids' => ['At least one contact or tag is required.'],
                    ]);
                }
                $campaign->recipients()->delete();
                $this->rebuildRecipientSnapshot($campaign, $this->contacts([
                    'contact_ids' => $contactIds,
                    'tag_ids' => $tagIds,
                ]));
            } elseif (array_key_exists('components', $input)) {
                $campaign->recipients()->orderBy('id')->each(
                    fn (WhatsAppCampaignRecipient $recipient) => $recipient->update([
                        'components' => $this->personalizeSnapshot($components, $recipient),
                    ])
                );
            }

            return $campaign->fresh(['creator:id,name,email']);
        });
    }

    public function preview(array $input): array
    {
        $template = $this->approvedTemplate($input['template_name'], $input['template_language']);
        $requireOptIn = ($template['category'] ?? null) === 'MARKETING'
            || ($input['require_opt_in'] ?? true);
        $contacts = $this->contacts($input);
        $consented = ($requireOptIn
            ? WhatsAppConsent::where('status', 'opted_in')->pluck('phone')
            : collect())->flip();
        $seen = [];
        $validUnique = 0;
        $eligible = 0;
        $invalid = 0;
        $duplicates = 0;
        $noConsent = 0;

        foreach ($contacts as $contact) {
            $phone = $this->phones->normalize((string) $contact->phone);
            if (! $this->phones->isValid($phone)) {
                $invalid++;

                continue;
            }
            if (isset($seen[$phone])) {
                $duplicates++;

                continue;
            }
            $seen[$phone] = true;
            $validUnique++;
            if ($requireOptIn && ! $consented->has($phone)) {
                $noConsent++;

                continue;
            }
            $eligible++;
        }

        $tagBreakdown = collect($input['tag_ids'] ?? [])->map(function ($tagId) {
            $contacts = CSVData::whereHas('tags', fn ($query) => $query->where('tags.id', $tagId))
                ->get(['id', 'phone']);

            return [
                'tag_id' => (int) $tagId,
                'contact_count' => $contacts->count(),
                'unique_valid_phone_count' => $contacts->pluck('phone')
                    ->map(fn ($phone) => $this->phones->normalize((string) $phone))
                    ->filter(fn ($phone) => $this->phones->isValid($phone))
                    ->unique()->count(),
            ];
        })->values();

        return [
            'template' => [
                'name' => $template['name'],
                'language' => $template['language'],
                'category' => $template['category'],
                'status' => $template['status'],
            ],
            'selected_contact_count' => $contacts->count(),
            'unique_valid_phone_count' => $validUnique,
            'eligible_recipient_count' => $eligible,
            'excluded' => [
                'invalid_or_missing_phone_count' => $invalid,
                'duplicate_phone_count' => $duplicates,
                'missing_consent_count' => $noConsent,
            ],
            'require_opt_in' => $requireOptIn,
            'tag_breakdown' => $tagBreakdown,
        ];
    }

    public function updateComponents(WhatsAppCampaign $campaign, array $components): WhatsAppCampaign
    {
        return DB::transaction(function () use ($campaign, $components) {
            $campaign->update(['components' => $components]);
            $campaign->recipients()->orderBy('id')->each(
                fn (WhatsAppCampaignRecipient $recipient) => $recipient->update([
                    'components' => $this->personalizeSnapshot($components, $recipient),
                ])
            );

            return $campaign->fresh();
        });
    }

    private function rebuildRecipientSnapshot(WhatsAppCampaign $campaign, Collection $contacts): void
    {
        $consentedPhones = ($campaign->require_opt_in
            ? WhatsAppConsent::where('status', 'opted_in')->pluck('phone')
            : collect())->flip();
        $seen = [];
        $noPhone = 0;
        $duplicatePhone = 0;
        $noConsent = 0;

        foreach ($contacts as $contact) {
            $phone = $this->phones->normalize((string) $contact->phone);
            if (! $this->phones->isValid($phone)) {
                $noPhone++;

                continue;
            }
            if (isset($seen[$phone])) {
                $duplicatePhone++;

                continue;
            }
            $seen[$phone] = true;
            if ($campaign->require_opt_in && ! $consentedPhones->has($phone)) {
                $noConsent++;

                continue;
            }

            $campaign->recipients()->create([
                'csv_data_id' => $contact->id,
                'phone' => $phone,
                'firstname' => $contact->firstname,
                'lastname' => $contact->lastname,
                'email' => $contact->email,
                'country' => $contact->country,
                'speciality' => $contact->speciality,
                'components' => $this->personalize($campaign->components ?? [], $contact),
            ]);
        }

        $campaign->update([
            'recipient_count' => $campaign->recipients()->count(),
            'excluded_no_phone_count' => $noPhone,
            'excluded_duplicate_phone_count' => $duplicatePhone,
            'excluded_no_consent_count' => $noConsent,
        ]);
    }

    private function contacts(array $input): Collection
    {
        return CSVData::query()
            ->where(function ($query) use ($input) {
                if (($input['contact_ids'] ?? []) !== []) {
                    $query->whereIn('id', $input['contact_ids']);
                }
                if (($input['tag_ids'] ?? []) !== []) {
                    $method = ($input['contact_ids'] ?? []) !== [] ? 'orWhereHas' : 'whereHas';
                    $query->{$method}('tags', fn ($tags) => $tags->whereIn('tags.id', $input['tag_ids']));
                }
            })
            ->orderBy('id')
            ->get();
    }

    private function personalize(mixed $value, CSVData $contact): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($item) => $this->personalize($item, $contact), $value);
        }
        if (! is_string($value)) {
            return $value;
        }

        return strtr($value, [
            '{{contact.id}}' => (string) $contact->id,
            '{{contact.firstname}}' => (string) $contact->firstname,
            '{{contact.lastname}}' => (string) $contact->lastname,
            '{{contact.email}}' => (string) $contact->email,
            '{{contact.phone}}' => (string) $contact->phone,
            '{{contact.country}}' => (string) $contact->country,
            '{{contact.speciality}}' => (string) $contact->speciality,
        ]);
    }

    private function personalizeSnapshot(mixed $value, WhatsAppCampaignRecipient $recipient): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($item) => $this->personalizeSnapshot($item, $recipient), $value);
        }
        if (! is_string($value)) {
            return $value;
        }

        return strtr($value, [
            '{{contact.id}}' => (string) $recipient->csv_data_id,
            '{{contact.firstname}}' => (string) $recipient->firstname,
            '{{contact.lastname}}' => (string) $recipient->lastname,
            '{{contact.email}}' => (string) $recipient->email,
            '{{contact.phone}}' => (string) $recipient->phone,
            '{{contact.country}}' => (string) $recipient->country,
            '{{contact.speciality}}' => (string) $recipient->speciality,
        ]);
    }

    private function approvedTemplate(string $name, string $language): array
    {
        $template = $this->templates->find($name, $language);
        if (! $template) {
            throw ValidationException::withMessages([
                'template_name' => ['The selected WhatsApp template and language do not exist.'],
            ]);
        }
        if (($template['status'] ?? null) !== 'APPROVED') {
            throw ValidationException::withMessages([
                'template_name' => ['Only an approved WhatsApp template can be used for a campaign.'],
            ]);
        }

        return $template;
    }
}
