<?php

namespace App\Services;

use App\Models\CSVData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class SmartContactSearchService
{
    private const MAX_CANDIDATES = 15000;

    public function __construct(
        private ReferenceDataService $referenceData,
        private ContactMatchScorer $scorer,
        private ContactSortingService $sorting
    ) {}

    public function search(
        array $intent,
        int $page,
        int $perPage,
        ?string $sortBy = null,
        ?string $sortDirection = null
    ): array
    {
        $startedAt = microtime(true);
        $searchText = trim((string) ($intent['search_text'] ?? ''));
        $organizationTerms = array_values(array_filter($intent['organization_terms'] ?? []));
        // Keep AI results consistent with the regular contacts endpoint. The
        // application exposes soft-deleted contacts so authorized users can
        // find and restore them.
        $query = CSVData::withTrashed();
        $this->applyStructuredFilters($query, $intent);

        if ($searchText === '' && $organizationTerms === []) {
            $this->sorting->apply($query, $sortBy, $sortDirection, 'created_at', 'desc');
            $contacts = $query->paginate($perPage, ['*'], 'page', $page);

            return $this->paginationPayload(
                $contacts->items(),
                $contacts->total(),
                $page,
                $perPage,
                $startedAt,
                0,
                false,
                'structured_filters'
            );
        }

        $candidateQuery = clone $query;
        if ($searchText !== '') {
            $this->applyCandidateSearch($candidateQuery, $searchText);
        }
        if ($organizationTerms !== []) {
            $this->applyOrganizationCandidateSearch($candidateQuery, $organizationTerms);
        }
        $candidates = $candidateQuery
            ->select(['id', 'firstname', 'lastname', 'email', 'phone', 'country', 'speciality', 'user_id', 'created_at', 'updated_at'])
            ->orderByDesc('updated_at')
            ->limit(self::MAX_CANDIDATES + 1)
            ->get();
        $candidateTruncated = $candidates->count() > self::MAX_CANDIDATES;
        $candidates = $candidates->take(self::MAX_CANDIDATES);
        $threshold = $searchText === '' ? 0 : $this->scorer->threshold($searchText);

        $ranked = $candidates
            ->map(function (CSVData $contact) use ($searchText, $organizationTerms, $threshold) {
                $data = $contact->toArray();
                $identityMatch = $this->scorer->score($data, $searchText);
                $organizationMatch = $this->scorer->scoreOrganization($data, $organizationTerms);
                $hasOrganizationFilter = $organizationTerms !== [];
                $accepted = $identityMatch['score'] >= $threshold
                    && (! $hasOrganizationFilter || $organizationMatch['score'] >= 0.65);

                $score = $hasOrganizationFilter
                    ? ($searchText === ''
                        ? $organizationMatch['score']
                        : ($identityMatch['score'] * 0.75) + ($organizationMatch['score'] * 0.25))
                    : $identityMatch['score'];
                $match = [
                    'score' => round($score, 4),
                    'reasons' => array_values(array_unique(array_merge(
                        $identityMatch['reasons'] ?? [],
                        $organizationMatch['reasons'] ?? []
                    ))),
                    ...array_diff_key($identityMatch, ['score' => true, 'reasons' => true]),
                    ...array_diff_key($organizationMatch, ['score' => true, 'reasons' => true]),
                ];
                $data['search_match'] = $match;
                $data['_search_accepted'] = $accepted;

                return $data;
            })
            ->filter(fn (array $contact) => $contact['_search_accepted'])
            ->map(function (array $contact) {
                unset($contact['_search_accepted']);

                return $contact;
            })
            ->sort(function (array $left, array $right) {
                $scoreComparison = $right['search_match']['score'] <=> $left['search_match']['score'];

                return $scoreComparison !== 0
                    ? $scoreComparison
                    : strcmp((string) $right['updated_at'], (string) $left['updated_at']);
            })
            ->values();

        $ranked = $this->sorting->sortRanked($ranked, $sortBy, $sortDirection);

        $total = $ranked->count();
        $items = $ranked->slice(($page - 1) * $perPage, $perPage)->values()->all();

        return $this->paginationPayload(
            $items,
            $total,
            $page,
            $perPage,
            $startedAt,
            $candidates->count(),
            $candidateTruncated,
            'medcon_fuzzy_v2'
        );
    }

    private function applyCandidateSearch(Builder $query, string $searchText): void
    {
        if (str_contains($searchText, '@')) {
            $query->where('email', 'like', '%'.mb_strtolower($searchText).'%');

            return;
        }

        $phone = preg_replace('/\D+/', '', $searchText) ?? '';
        if (strlen($phone) >= 7) {
            $normalizedPhone = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '+', ''), '-', ''), '(', ''), ')', ''), '.', '')";
            $query->whereRaw($normalizedPhone.' like ?', ['%'.$phone.'%']);

            return;
        }

        $rawTokens = preg_split('/[^\pL\pN]+/u', mb_strtolower($searchText), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($rawTokens === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $driver = DB::connection()->getDriverName();

        $query->where(function (Builder $query) use ($rawTokens, $driver) {
            foreach ($rawTokens as $rawToken) {
                $query->orWhere('firstname', 'like', '%'.$rawToken.'%')
                    ->orWhere('lastname', 'like', '%'.$rawToken.'%')
                    ->orWhere('email', 'like', $rawToken.'%');

                if (mb_strlen($rawToken) >= 3) {
                    $prefix = mb_substr($rawToken, 0, 2);
                    $query->orWhere('firstname', 'like', $prefix.'%')
                        ->orWhere('lastname', 'like', $prefix.'%');
                }

                $asciiToken = $this->scorer->normalize($rawToken);
                if (
                    in_array($driver, ['mysql', 'mariadb'], true)
                    && strlen($asciiToken) >= 3
                    && preg_match('/^[a-z]+$/', $asciiToken) === 1
                ) {
                    $query->orWhereRaw('SOUNDEX(firstname) = SOUNDEX(?)', [$asciiToken])
                        ->orWhereRaw('SOUNDEX(lastname) = SOUNDEX(?)', [$asciiToken]);
                }
            }
        });
    }

    private function applyOrganizationCandidateSearch(Builder $query, array $organizationTerms): void
    {
        $query->where(function (Builder $query) use ($organizationTerms) {
            foreach ($organizationTerms as $term) {
                $normalized = str_replace(' ', '', $this->scorer->normalize((string) $term));
                if ($normalized === '') {
                    continue;
                }

                // A three-character prefix keeps typo variants such as
                // "medcan" and "medcon" in the candidate set.
                $prefix = mb_substr($normalized, 0, min(3, mb_strlen($normalized)));
                $query->orWhere('email', 'like', '%@%'.$prefix.'%');
            }
        });
    }

    private function applyStructuredFilters(Builder $query, array $intent): void
    {
        $countries = $this->referenceData->expandCountries($intent['countries'] ?? []);
        $specialities = $this->referenceData->expandSpecialities($intent['specialities'] ?? []);

        $this->applyNullableFilter($query, 'country', $countries);
        $this->applyNullableFilter($query, 'speciality', $specialities);
        $this->applyPhoneFilter($query, (string) ($intent['phone_filter'] ?? 'any'));
    }

    private function applyPhoneFilter(Builder $query, string $filter): void
    {
        if ($filter === 'has_phone') {
            $query->whereNotNull('phone')->whereRaw("TRIM(phone) <> ''");

            return;
        }

        if ($filter === 'no_phone') {
            $query->where(function (Builder $query) {
                $query->whereNull('phone')->orWhereRaw("TRIM(phone) = ''");
            });
        }
    }

    private function applyNullableFilter(Builder $query, string $column, array $values): void
    {
        if ($values === []) {
            return;
        }

        $query->where(function (Builder $query) use ($column, $values) {
            $nonEmptyValues = array_values(array_filter($values, fn ($value) => $value !== ''));

            if ($nonEmptyValues !== []) {
                $query->whereIn($column, $nonEmptyValues);
            }

            if (in_array('', $values, true)) {
                $query->orWhereNull($column)->orWhere($column, '');
            }
        });
    }

    private function paginationPayload(
        array $items,
        int $total,
        int $page,
        int $perPage,
        float $startedAt,
        int $candidateCount,
        bool $candidateTruncated,
        string $engine
    ): array {
        $totalPages = max(1, (int) ceil($total / $perPage));

        return [
            'data' => $items,
            'status_code' => 200,
            'message' => $total === 0 ? 'No matching contacts found.' : 'Contacts ranked successfully.',
            'status' => true,
            'perPage' => $perPage,
            'currentPage' => $page,
            'total' => $total,
            'totalPages' => $totalPages,
            'is_next' => $page < $totalPages,
            'search_meta' => [
                'engine' => $engine,
                'candidate_count' => $candidateCount,
                'candidate_truncated' => $candidateTruncated,
                'total_is_estimate' => $candidateTruncated,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ],
        ];
    }
}
