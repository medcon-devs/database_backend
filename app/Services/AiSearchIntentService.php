<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class AiSearchIntentService
{
    public function __construct(private ReferenceDataService $referenceData) {}

    /**
     * Convert a natural-language request into the existing contact-search
     * contract. OpenAI only interprets the query; it never accesses the DB.
     */
    public function interpret(string $query): array
    {
        // Presence/absence filters are deterministic database instructions.
        // Handle them locally so an external model cannot turn SQL-style
        // wording such as "phone not null" into an identity text search.
        [$explicitPhoneFilter] = $this->extractPhoneFilter($query);
        if ($explicitPhoneFilter !== 'any') {
            return $this->interpretLocally($query);
        }

        $apiKey = config('services.openai.api_key');
        $provider = config('services.openai.provider', 'local');

        if ($provider === 'openai' && $apiKey) {
            try {
                return $this->interpretWithOpenAI($query, $apiKey);
            } catch (Throwable $exception) {
                Log::warning('AI search interpretation failed; using local parser.', [
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $this->interpretLocally($query);
    }

    private function interpretWithOpenAI(string $query, string $apiKey): array
    {
        $countries = array_values(array_filter($this->referenceData->countries()));
        $specialities = array_values(array_filter($this->referenceData->specialities()));

        $systemPrompt = implode("\n", [
            'Extract a medical-contact database search intent from the user request.',
            'The user may write in English or Arabic and names may be misspelled.',
            'search_text must contain only useful identity terms: person name, email, or phone.',
            'organization_terms must contain employer, company, clinic, or hospital clues, including text after working at, working in, or working for.',
            'phone_filter must be has_phone when the user asks for contacts whose phone is present or not null, no_phone when the phone is missing, otherwise any.',
            'Do not put filler words such as find, doctor, looking for, or show me in search_text.',
            'Use only values from the supplied country and speciality lists.',
            'Use an empty string or empty array when information was not supplied.',
            'Never invent a person or database result.',
            'Countries: '.implode(', ', $countries),
            'Specialities: '.implode(', ', $specialities),
        ]);

        $response = Http::acceptJson()
            ->withToken($apiKey)
            ->timeout(max(3, (int) config('services.openai.timeout', 12)))
            ->post(rtrim(config('services.openai.base_url'), '/').'/responses', [
                'model' => config('services.openai.model', 'gpt-4o-mini'),
                'store' => false,
                'input' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $query],
                ],
                'max_output_tokens' => 300,
                'text' => [
                    'format' => [
                        'type' => 'json_schema',
                        'name' => 'contact_search_intent',
                        'strict' => true,
                        'schema' => $this->intentSchema(),
                    ],
                ],
            ]);

        $response->throw();
        $decoded = json_decode($this->responseText($response->json()), true);

        if (! is_array($decoded)) {
            throw new RuntimeException('OpenAI returned an invalid search intent.');
        }

        return $this->sanitizeIntent($query, $decoded, 'openai');
    }

    private function interpretLocally(string $query): array
    {
        $working = $query;
        $countries = [];
        $specialities = [];
        $fuzzyMatches = 0;

        [$phoneFilter, $working] = $this->extractPhoneFilter($working);

        $email = $this->extractEmail($query);
        $phone = $email === null ? $this->extractPhone($query) : null;

        foreach ($this->countryCandidates() as $candidate => $canonical) {
            if ($this->containsPhrase($working, $candidate)) {
                $countries[] = $canonical;
                $working = $this->removePhrase($working, $candidate);
            }
        }

        foreach ($this->specialityCandidates() as $candidate => $canonical) {
            if ($this->containsPhrase($working, $candidate)) {
                $specialities[] = $canonical;
                $working = $this->removePhrase($working, $candidate);
            }
        }

        if ($countries === []) {
            $match = $this->bestFuzzyCandidate($working, $this->countryCandidates());
            if ($match !== null) {
                $countries[] = $match['canonical'];
                $working = $this->removePhrase($working, $match['phrase']);
                $fuzzyMatches++;
            }
        }

        if ($specialities === []) {
            $match = $this->bestFuzzyCandidate($working, $this->specialityCandidates());
            if ($match !== null) {
                $specialities[] = $match['canonical'];
                $working = $this->removePhrase($working, $match['phrase']);
                $fuzzyMatches++;
            }
        }

        [$organizationTerms, $working] = $this->extractOrganizationTerms($working);

        $working = $this->removeNoisePhrases($working);
        $searchText = $email ?? $phone ?? $this->cleanSearchText($working);

        // If no structure could be recognized, let Typesense fuzzy-search the
        // original query rather than returning every contact.
        if (
            $searchText === ''
            && $countries === []
            && $specialities === []
            && $organizationTerms === []
            && $phoneFilter === 'any'
        ) {
            $searchText = trim($query);
        }

        $countries = array_values(array_unique($countries));
        $specialities = array_values(array_unique($specialities));

        return [
            'original_query' => $query,
            'search_text' => $searchText,
            'countries' => $countries,
            'specialities' => $specialities,
            'organization_terms' => $organizationTerms,
            'phone_filter' => $phoneFilter,
            'explanation' => $this->localExplanation(
                $searchText,
                $countries,
                $specialities,
                $organizationTerms,
                $phoneFilter
            ),
            'confidence' => $this->localConfidence(
                $searchText,
                $countries,
                $specialities,
                $fuzzyMatches,
                $email !== null || $phone !== null
            ),
            'parser' => 'local',
        ];
    }

    private function extractEmail(string $query): ?string
    {
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', $query, $matches) !== 1) {
            return null;
        }

        return mb_strtolower($matches[0]);
    }

    private function extractPhone(string $query): ?string
    {
        if (preg_match('/(?<!\w)(?:\+?\d[\d\s().-]{6,}\d)(?!\w)/u', $query, $matches) !== 1) {
            return null;
        }

        return trim($matches[0]);
    }

    private function extractPhoneFilter(string $working): array
    {
        $filters = [
            'has_phone' => [
                '/\b(?:phone|phone number|mobile|mobile number)\s+(?:is\s+)?not\s+(?:null|empty|blank|missing)\b/iu',
                '/\b(?:with|has|have|having)\s+(?:a\s+)?(?:phone|phone number|mobile|mobile number)\b/iu',
                '/\b(?:phone|phone number|mobile|mobile number)\s+(?:exists|available|present)\b/iu',
                '/(?:لديه|لديها|عنده|عندها|معه|معها)\s+(?:رقم\s+)?(?:هاتف|تلفون|موبايل)/u',
                '/(?:رقم\s+)?(?:الهاتف|التلفون|الموبايل)\s+(?:موجود|متوفر|غير\s+فارغ)/u',
            ],
            'no_phone' => [
                '/\b(?:phone|phone number|mobile|mobile number)\s+(?:is\s+)?(?:null|empty|blank|missing)\b/iu',
                '/\b(?:without|no|missing)\s+(?:a\s+)?(?:phone|phone number|mobile|mobile number)\b/iu',
                '/(?:بدون|لا\s+يوجد|ليس\s+لديه|ليس\s+لديها)\s+(?:رقم\s+)?(?:هاتف|تلفون|موبايل)/u',
            ],
        ];

        foreach ($filters as $filter => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $working) !== 1) {
                    continue;
                }

                return [$filter, preg_replace($pattern, ' ', $working) ?? $working];
            }
        }

        return ['any', $working];
    }

    private function removeNoisePhrases(string $working): string
    {
        $phrases = config('reference_data.search_noise_phrases', []);
        usort($phrases, fn ($left, $right) => mb_strlen($right) <=> mb_strlen($left));

        foreach ($phrases as $phrase) {
            $working = $this->removePhrase($working, $phrase);
        }

        return $working;
    }

    private function cleanSearchText(string $working): string
    {
        $working = preg_replace('/[\[\]{}()"\'`]+/u', ' ', $working) ?? $working;
        $working = preg_replace('/\s+/u', ' ', $working) ?? $working;

        return mb_substr(trim($working, " \t\n\r\0\x0B,.;:!?-/\\"), 0, 150);
    }

    /**
     * Find one likely misspelled country or speciality without treating short
     * person names as structured filters.
     */
    private function bestFuzzyCandidate(string $working, array $candidates): ?array
    {
        $workingKey = $this->comparisonKey($working);
        $tokens = array_values(array_filter(explode(' ', $workingKey)));
        $best = null;

        foreach ($candidates as $candidate => $canonical) {
            if ($canonical === 'Other') {
                continue;
            }

            $candidateKey = $this->comparisonKey($candidate);
            $candidateTokens = array_values(array_filter(explode(' ', $candidateKey)));
            $tokenCount = count($candidateTokens);
            $candidateLength = strlen(str_replace(' ', '', $candidateKey));

            if ($candidateLength < 6 || $candidateLength > 32 || $tokenCount < 1 || $tokenCount > 3) {
                continue;
            }

            for ($index = 0; $index <= count($tokens) - $tokenCount; $index++) {
                $phrase = implode(' ', array_slice($tokens, $index, $tokenCount));
                $phraseLength = strlen(str_replace(' ', '', $phrase));

                if (abs($candidateLength - $phraseLength) > max(2, (int) floor($candidateLength * 0.25))) {
                    continue;
                }

                $distance = levenshtein($phrase, $candidateKey);
                $similarity = 1 - ($distance / max(strlen($phrase), strlen($candidateKey), 1));
                $threshold = $candidateLength >= 10 ? 0.78 : 0.82;

                if ($similarity < $threshold || ($best !== null && $similarity <= $best['score'])) {
                    continue;
                }

                $best = [
                    'canonical' => $canonical,
                    'phrase' => $phrase,
                    'score' => $similarity,
                ];
            }
        }

        return $best;
    }

    private function localConfidence(
        string $searchText,
        array $countries,
        array $specialities,
        int $fuzzyMatches,
        bool $hasExactIdentifier
    ): float {
        $confidence = 0.62;
        $confidence += $searchText !== '' ? 0.10 : 0;
        $confidence += $countries !== [] ? 0.08 : 0;
        $confidence += $specialities !== [] ? 0.10 : 0;
        $confidence += $hasExactIdentifier ? 0.08 : 0;
        $confidence -= $fuzzyMatches * 0.04;

        return round(max(0.55, min(0.94, $confidence)), 2);
    }

    private function localExplanation(
        string $searchText,
        array $countries,
        array $specialities,
        array $organizationTerms = [],
        string $phoneFilter = 'any'
    ): string
    {
        $parts = [];

        if ($searchText !== '') {
            $parts[] = 'identity "'.$searchText.'"';
        }
        if ($countries !== []) {
            $parts[] = 'country '.implode(', ', $countries);
        }
        if ($specialities !== []) {
            $parts[] = 'speciality '.implode(', ', $specialities);
        }
        if ($organizationTerms !== []) {
            $parts[] = 'organization '.implode(', ', $organizationTerms);
        }
        if ($phoneFilter === 'has_phone') {
            $parts[] = 'phone number is available';
        } elseif ($phoneFilter === 'no_phone') {
            $parts[] = 'phone number is missing';
        }

        return $parts === []
            ? 'Medcon smart local search kept the original text for typo-tolerant matching.'
            : 'Medcon smart local search recognized '.implode('; ', $parts).'.';
    }

    private function sanitizeIntent(string $originalQuery, array $intent, string $parser): array
    {
        $allowedCountries = array_flip(array_filter($this->referenceData->countries()));
        $allowedSpecialities = array_flip(array_filter($this->referenceData->specialities()));

        $countries = collect($intent['countries'] ?? [])
            ->map(fn ($value) => $this->referenceData->normalizeCountry((string) $value))
            ->filter(fn ($value) => $value !== null && isset($allowedCountries[$value]))
            ->unique()
            ->values()
            ->all();

        $specialities = collect($intent['specialities'] ?? [])
            ->map(fn ($value) => $this->referenceData->normalizeSpeciality((string) $value))
            ->filter(fn ($value) => isset($allowedSpecialities[$value]))
            ->unique()
            ->values()
            ->all();

        $organizationTerms = collect($intent['organization_terms'] ?? [])
            ->map(fn ($value) => mb_substr(trim((string) $value), 0, 80))
            ->filter()
            ->unique(fn ($value) => mb_strtolower($value))
            ->values()
            ->all();
        $phoneFilter = in_array($intent['phone_filter'] ?? null, ['any', 'has_phone', 'no_phone'], true)
            ? $intent['phone_filter']
            : 'any';

        return [
            'original_query' => $originalQuery,
            'search_text' => mb_substr(trim((string) ($intent['search_text'] ?? '')), 0, 150),
            'countries' => $countries,
            'specialities' => $specialities,
            'organization_terms' => $organizationTerms,
            'phone_filter' => $phoneFilter,
            'explanation' => mb_substr(trim((string) ($intent['explanation'] ?? '')), 0, 300),
            'confidence' => max(0, min(1, (float) ($intent['confidence'] ?? 0))),
            'parser' => $parser,
        ];
    }

    private function countryCandidates(): array
    {
        $candidates = [];
        foreach (config('reference_data.countries', []) as $canonical => $aliases) {
            $candidates[$canonical] = $canonical;
            foreach ($aliases as $alias) {
                $candidates[$alias] = $canonical;
            }
        }
        foreach ($this->referenceData->countries() as $country) {
            if ($country !== '') {
                $candidates[$country] = $country;
            }
        }

        return $this->longestFirst($candidates);
    }

    private function specialityCandidates(): array
    {
        $candidates = [];
        foreach (config('reference_data.search_speciality_aliases', []) as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                $candidates[$alias] = $canonical;
            }
        }
        foreach (config('reference_data.specialities', []) as $canonical => $aliases) {
            $candidates[$canonical] = $canonical;
            foreach ($aliases as $alias) {
                $candidates[$alias] = $canonical;
            }
        }
        foreach ($this->referenceData->specialities() as $speciality) {
            if ($speciality !== '' && $speciality !== 'Other') {
                $candidates[$speciality] = $speciality;
            }
        }

        return $this->longestFirst($candidates);
    }

    private function longestFirst(array $candidates): array
    {
        uksort($candidates, fn ($left, $right) => mb_strlen($right) <=> mb_strlen($left));

        return $candidates;
    }

    private function containsPhrase(string $text, string $phrase): bool
    {
        $textKey = $this->comparisonKey($text);
        $phraseKey = $this->comparisonKey($phrase);

        return $phraseKey !== '' && str_contains(" $textKey ", " $phraseKey ");
    }

    private function removePhrase(string $text, string $phrase): string
    {
        $pattern = '/(?<![\pL\pN])'.preg_quote($phrase, '/').'(?![\pL\pN])/iu';

        return preg_replace($pattern, ' ', $text) ?? $text;
    }

    private function comparisonKey(string $value): string
    {
        $ascii = function_exists('transliterator_transliterate')
            ? transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $value)
            : mb_strtolower($value);

        return trim(preg_replace('/[^a-z0-9@+._-]+/', ' ', $ascii ?? $value) ?? '');
    }

    /**
     * Separate employer clues from the person's identity. The actual matching
     * is performed later against the email domain with typo tolerance.
     */
    private function extractOrganizationTerms(string $working): array
    {
        $patterns = [
            '/\b(?:work(?:ing|s|ed)?|employed)\s+(?:at|in|for|by)\s+([\pL\pN][\pL\pN._-]{1,79})/iu',
            '/(?:يعمل|تعمل|اعمل|شغال|شغالة)\s+(?:في|مع|لدى)\s+([\pL\pN][\pL\pN._-]{1,79})/u',
        ];
        $terms = [];

        foreach ($patterns as $pattern) {
            while (preg_match($pattern, $working, $matches) === 1) {
                $terms[] = trim($matches[1]);
                $working = preg_replace($pattern, ' ', $working, 1) ?? $working;
            }
        }

        return [array_values(array_unique($terms)), $working];
    }

    private function responseText(array $response): string
    {
        if (isset($response['output_text']) && is_string($response['output_text'])) {
            return $response['output_text'];
        }

        foreach ($response['output'] ?? [] as $output) {
            foreach ($output['content'] ?? [] as $content) {
                if (($content['type'] ?? null) === 'output_text' && isset($content['text'])) {
                    return $content['text'];
                }
            }
        }

        throw new RuntimeException('OpenAI response did not contain output text.');
    }

    private function intentSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'search_text' => ['type' => 'string'],
                'countries' => ['type' => 'array', 'items' => ['type' => 'string']],
                'specialities' => ['type' => 'array', 'items' => ['type' => 'string']],
                'organization_terms' => ['type' => 'array', 'items' => ['type' => 'string']],
                'phone_filter' => ['type' => 'string', 'enum' => ['any', 'has_phone', 'no_phone']],
                'explanation' => ['type' => 'string'],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
            ],
            'required' => ['search_text', 'countries', 'specialities', 'organization_terms', 'phone_filter', 'explanation', 'confidence'],
            'additionalProperties' => false,
        ];
    }
}
