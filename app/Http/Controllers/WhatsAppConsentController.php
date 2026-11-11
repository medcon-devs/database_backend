<?php

namespace App\Http\Controllers;

use App\Services\WhatsAppConsentService;
use App\Services\WhatsAppPhoneService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WhatsAppConsentController extends Controller
{
    public function store(
        Request $request,
        WhatsAppConsentService $consents,
        WhatsAppPhoneService $phones
    ) {
        $input = $request->validate([
            'phone' => ['required', 'string', 'max:32', function ($attribute, $value, $fail) use ($phones) {
                if (! $phones->isValid((string) $value)) {
                    $fail('The WhatsApp phone number is invalid.');
                }
            }],
            'action' => ['required', Rule::in(['opt_in', 'opt_out'])],
            'source_domain' => ['required', 'string', 'max:255', function ($attribute, $value, $fail) {
                if (! $this->isValidDomain((string) $value)) {
                    $fail('The source domain format is invalid.');
                }
            }],
            'consent_text' => ['nullable', 'required_if:action,opt_in', 'string', 'max:2000'],
            'consent_version' => ['nullable', 'string', 'max:30'],
            'evidence_reference' => ['nullable', 'string', 'max:255'],
        ]);

        $phone = $phones->normalize($input['phone']);
        $consent = $consents->recordExternal(
            $phone,
            $input['action'],
            strtolower($input['source_domain']),
            $input['consent_text'] ?? null,
            $input['consent_version'] ?? null,
            $input['evidence_reference'] ?? null,
            [
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
                'origin' => mb_substr((string) $request->header('Origin'), 0, 255),
            ]
        );

        return response()->json([
            'data' => [
                'phone' => $consent->phone,
                'status' => $consent->status,
                'scope' => $consent->scope,
                'source' => $consent->source,
                'source_domain' => $consent->source_domain,
                'consent_version' => $consent->consent_version,
                'evidence_reference' => $consent->evidence_reference,
                'opted_in_at' => $consent->opted_in_at,
                'opted_out_at' => $consent->opted_out_at,
            ],
            'status_code' => 200,
            'message' => $consent->status === 'opted_in'
                ? 'WhatsApp opt-in recorded successfully.'
                : 'WhatsApp opt-out recorded successfully.',
            'status' => true,
        ]);
    }

    private function isValidDomain(string $domain): bool
    {
        $domain = strtolower(trim($domain));
        if ($domain === 'localhost') {
            return true;
        }

        return filter_var('https://'.$domain, FILTER_VALIDATE_URL) !== false
            && parse_url('https://'.$domain, PHP_URL_HOST) === $domain
            && str_contains($domain, '.');
    }
}
