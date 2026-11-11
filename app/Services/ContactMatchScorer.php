<?php

namespace App\Services;

class ContactMatchScorer
{
    public function score(array $contact, string $searchText): array
    {
        $query = $this->normalize($searchText);
        $email = mb_strtolower(trim((string) ($contact['email'] ?? '')));
        $queryEmail = mb_strtolower(trim($searchText));

        if (str_contains($queryEmail, '@')) {
            if ($email === $queryEmail) {
                return $this->result(1, ['exact_email']);
            }

            if ($email !== '' && str_contains($email, $queryEmail)) {
                return $this->result(0.96, ['partial_email']);
            }

            return $this->result(0, []);
        }

        $queryPhone = preg_replace('/\D+/', '', $searchText) ?? '';
        if (strlen($queryPhone) >= 7) {
            $phone = preg_replace('/\D+/', '', (string) ($contact['phone'] ?? '')) ?? '';

            if ($phone === $queryPhone) {
                return $this->result(1, ['exact_phone']);
            }

            if ($phone !== '' && (str_ends_with($phone, $queryPhone) || str_ends_with($queryPhone, $phone))) {
                return $this->result(0.97, ['phone_suffix']);
            }

            return $this->result(0, []);
        }

        if ($query === '') {
            return $this->result(1, ['structured_filters']);
        }

        $firstName = $this->normalize((string) ($contact['firstname'] ?? ''));
        $lastName = $this->normalize((string) ($contact['lastname'] ?? ''));
        $fullName = trim($firstName.' '.$lastName);
        $reversedName = trim($lastName.' '.$firstName);
        $queryTokens = $this->tokens($query);
        $nameTokens = $this->tokens($fullName);

        if ($nameTokens === []) {
            $emailLocalPart = str_contains($email, '@') ? explode('@', $email, 2)[0] : '';
            $emailTokens = $this->tokens($this->normalize($emailLocalPart));
            if ($emailTokens === []) {
                return $this->result(0, []);
            }

            $emailScores = array_map(
                fn (string $queryToken) => max(array_map(
                    fn (string $emailToken) => $this->similarity($queryToken, $emailToken),
                    $emailTokens
                )),
                $queryTokens
            );
            $emailScore = (array_sum($emailScores) / max(count($emailScores), 1)) * 0.91;

            return $this->result($emailScore, $emailScore >= 0.64 ? ['email_local_identity'] : [], [
                'email_local_similarity' => round($emailScore / 0.91, 4),
            ]);
        }

        $tokenScores = [];
        foreach ($queryTokens as $queryToken) {
            $tokenScores[] = max(array_map(
                fn (string $nameToken) => $this->similarity($queryToken, $nameToken),
                $nameTokens
            ));
        }

        $tokenCoverage = array_sum($tokenScores) / max(count($tokenScores), 1);
        $directFullName = $this->similarity($query, $fullName);
        $reversedFullName = $this->similarity($query, $reversedName);
        $fullNameScore = max($directFullName, $reversedFullName);
        $score = ($tokenCoverage * 0.78) + ($fullNameScore * 0.22);
        $reasons = [];

        if ($query === $fullName) {
            $score = 1;
            $reasons[] = 'exact_full_name';
        } elseif ($query === $reversedName) {
            $score = 0.99;
            $reasons[] = 'reversed_name_order';
        } elseif ($query === $firstName) {
            $score = max($score, 0.98);
            $reasons[] = 'exact_first_name';
        } elseif ($query === $lastName) {
            $score = max($score, 0.98);
            $reasons[] = 'exact_last_name';
        } elseif (min($tokenScores ?: [0]) >= 0.999) {
            $score = max($score, 0.97);
            $reasons[] = 'all_name_terms_exact';
        } elseif ($tokenCoverage >= 0.78) {
            $reasons[] = 'fuzzy_name';
        } elseif ($tokenCoverage >= 0.65) {
            $reasons[] = 'possible_name';
        }

        return $this->result($score, $reasons, [
            'token_coverage' => round($tokenCoverage, 4),
            'full_name_similarity' => round($fullNameScore, 4),
        ]);
    }

    public function threshold(string $searchText): float
    {
        if (str_contains($searchText, '@') || strlen(preg_replace('/\D+/', '', $searchText) ?? '') >= 7) {
            return 0.9;
        }

        $tokens = $this->tokens($this->normalize($searchText));
        if (count($tokens) > 1) {
            return 0.68;
        }

        $length = strlen($tokens[0] ?? '');

        return match (true) {
            $length <= 2 => 0.9,
            $length === 3 => 0.70,
            default => 0.64,
        };
    }

    public function scoreOrganization(array $contact, array $organizationTerms): array
    {
        $email = mb_strtolower(trim((string) ($contact['email'] ?? '')));
        $domain = str_contains($email, '@') ? explode('@', $email, 2)[1] : '';
        $domainTokens = $this->tokens($this->normalize($domain));

        if ($domainTokens === [] || $organizationTerms === []) {
            return $this->result(0, []);
        }

        $scores = [];
        foreach ($organizationTerms as $term) {
            $term = $this->normalize((string) $term);
            if ($term === '') {
                continue;
            }

            $scores[] = max(array_map(
                fn (string $domainToken) => $this->similarity($term, $domainToken),
                $domainTokens
            ));
        }

        if ($scores === []) {
            return $this->result(0, []);
        }

        $score = array_sum($scores) / count($scores);

        return $this->result($score, $score >= 0.65 ? ['organization_email_domain'] : [], [
            'organization_similarity' => round($score, 4),
        ]);
    }

    public function normalize(string $value): string
    {
        $normalized = function_exists('transliterator_transliterate')
            ? transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $value)
            : mb_strtolower($value);

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $normalized ?? $value) ?? '');
    }

    private function tokens(string $value): array
    {
        return array_values(array_filter(explode(' ', $value), fn (string $token) => $token !== ''));
    }

    private function similarity(string $left, string $right): float
    {
        if ($left === '' || $right === '') {
            return 0;
        }
        if ($left === $right) {
            return 1;
        }

        $maxLength = max(strlen($left), strlen($right));
        $score = 1 - (levenshtein($left, $right) / max($maxLength, 1));
        $shortestLength = min(strlen($left), strlen($right));

        if ($shortestLength >= 3 && (str_starts_with($left, $right) || str_starts_with($right, $left))) {
            $score = max($score, 0.88 + (0.08 * ($shortestLength / $maxLength)));
        } elseif ($shortestLength >= 3 && (str_contains($left, $right) || str_contains($right, $left))) {
            $score = max($score, 0.84);
        }

        if (
            $shortestLength >= 3
            && preg_match('/^[a-z]+$/', $left) === 1
            && preg_match('/^[a-z]+$/', $right) === 1
            && soundex($left) === soundex($right)
        ) {
            $score = max($score, 0.80);
        }

        return max(0, min(1, $score));
    }

    private function result(float $score, array $reasons, array $details = []): array
    {
        return [
            'score' => round(max(0, min(1, $score)), 4),
            'reasons' => $reasons,
            ...$details,
        ];
    }
}
