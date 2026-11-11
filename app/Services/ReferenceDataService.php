<?php

namespace App\Services;

use App\Models\CSVData;
use ResourceBundle;

class ReferenceDataService
{
    private array $aliasIndexes = [];

    private ?array $countryIndex = null;

    /**
     * Return one sorted canonical option for each group of historical values.
     * An empty string is retained once so clients can filter "Not specified".
     */
    public function countries(): array
    {
        return $this->optionsFor('country', 'countries');
    }

    public function specialities(): array
    {
        return $this->optionsFor('speciality', 'specialities');
    }

    public function normalizeCountry(?string $value): ?string
    {
        return $this->normalize($value, 'countries');
    }

    public function normalizeSpeciality(?string $value): ?string
    {
        return $this->normalize($value, 'specialities');
    }

    /**
     * Expand canonical API filters back to the raw values stored in MariaDB and
     * Typesense. This keeps old imports searchable without rewriting history.
     */
    public function expandCountries(array $values): array
    {
        return $this->expandFilters($values, 'country', 'countries');
    }

    public function expandSpecialities(array $values): array
    {
        return $this->expandFilters($values, 'speciality', 'specialities');
    }

    private function optionsFor(string $column, string $group): array
    {
        $options = CSVData::query()
            ->select($column)
            ->distinct()
            ->pluck($column)
            ->map(fn ($value) => $this->normalize($value, $group));

        $hasBlank = $options->contains(null);
        $values = $options
            ->filter(fn ($value) => $value !== null)
            ->unique(fn ($value) => $this->key($value))
            ->sort(fn ($left, $right) => strnatcasecmp($left, $right))
            ->values();

        return ($hasBlank ? $values->prepend('') : $values)->all();
    }

    private function expandFilters(array $requested, string $column, string $group): array
    {
        if ($requested === []) {
            return [];
        }

        $includeBlank = collect($requested)->contains(
            fn ($value) => $this->normalize($value, $group) === null
        );

        $canonicalKeys = collect($requested)
            ->map(fn ($value) => $this->normalize($value, $group))
            ->filter()
            ->map(fn ($value) => $this->key($value))
            ->unique()
            ->flip();

        $expanded = CSVData::withTrashed()
            ->select($column)
            ->distinct()
            ->pluck($column)
            ->filter(function ($rawValue) use ($canonicalKeys, $group, $includeBlank) {
                $canonical = $this->normalize($rawValue, $group);

                return $canonical === null
                    ? $includeBlank
                    : $canonicalKeys->has($this->key($canonical));
            })
            ->map(fn ($value) => (string) $value)
            ->values();

        // Preserve an unknown value supplied by an older client even when it is
        // not currently present in the database.
        collect($requested)
            ->filter(fn ($value) => $this->normalize($value, $group) !== null)
            ->each(function ($value) use ($expanded, $group) {
                if ($expanded->contains($value)) {
                    return;
                }

                $requestedCanonical = $this->normalize($value, $group);
                $alreadyRepresented = $expanded->contains(fn ($raw) =>
                    $this->normalize($raw, $group) === $requestedCanonical
                );

                if (!$alreadyRepresented) {
                    $expanded->push((string) $value);
                }
            });

        if ($includeBlank) {
            $expanded->push('');
        }

        return $expanded->unique()->values()->all();
    }

    private function normalize(?string $value, string $group): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        $clean = str_replace([' ,', ' /', '/ '], [',', '/', '/'], $clean);
        $key = $this->key($clean);

        if ($key === '' || in_array($key, $this->emptyKeys(), true)) {
            return null;
        }

        if (isset($this->aliasIndex($group)[$key])) {
            return $this->aliasIndex($group)[$key];
        }

        // Countries are a controlled ISO/ICU reference list. Unrecognized
        // values such as company names, emails, and placeholder text should
        // not leak into the country filter API.
        if ($group === 'countries') {
            return $this->countryIndex()[$key] ?? null;
        }

        foreach (config('reference_data.speciality_patterns', []) as $rule) {
            if (preg_match($rule['pattern'], $key) === 1) {
                return $rule['canonical'];
            }
        }

        return 'Other';
    }

    private function aliasIndex(string $group): array
    {
        if (isset($this->aliasIndexes[$group])) {
            return $this->aliasIndexes[$group];
        }

        $index = [];
        foreach (config("reference_data.$group", []) as $canonical => $aliases) {
            $index[$this->key($canonical)] = $canonical;

            foreach ($aliases as $alias) {
                $index[$this->key($alias)] = $canonical;
            }
        }

        if ($group === 'specialities') {
            foreach (config('reference_data.search_speciality_aliases', []) as $canonical => $aliases) {
                $index[$this->key($canonical)] = $canonical;

                foreach ($aliases as $alias) {
                    $index[$this->key($alias)] = $canonical;
                }
            }

            foreach (config('reference_data.speciality_patterns', []) as $rule) {
                $index[$this->key($rule['canonical'])] = $rule['canonical'];
            }
        }

        return $this->aliasIndexes[$group] = $index;
    }

    private function emptyKeys(): array
    {
        return collect(config('reference_data.empty_values', []))
            ->map(fn ($value) => $this->key($value))
            ->all();
    }

    private function countryIndex(): array
    {
        if ($this->countryIndex !== null) {
            return $this->countryIndex;
        }

        $index = [];
        $bundle = ResourceBundle::create('en', 'ICUDATA-region');
        $countries = $bundle instanceof ResourceBundle
            ? $bundle->get('Countries')
            : null;

        if ($countries instanceof ResourceBundle) {
            foreach ($countries as $code => $name) {
                if (is_string($code) && preg_match('/^[A-Z]{2}$/', $code)) {
                    $index[$this->key($name)] = $name;
                }
            }

            // ICU also publishes common short and historical variants. Map
            // each variant back to the canonical label for its country code.
            foreach (['Countries%short', 'Countries%variant'] as $group) {
                $variants = $bundle->get($group);
                if (!$variants instanceof ResourceBundle) {
                    continue;
                }

                foreach ($variants as $code => $variant) {
                    $canonical = $countries->get($code);
                    if (is_string($canonical)) {
                        $index[$this->key($variant)] = $canonical;
                    }
                }
            }
        }

        return $this->countryIndex = $index;
    }

    private function key(string $value): string
    {
        $ascii = function_exists('transliterator_transliterate')
            ? transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $value)
            : mb_strtolower($value);

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $ascii ?? $value) ?? '');
    }

    private function titleCase(string $value): string
    {
        if (preg_match('/^[A-Z0-9]{2,5}$/', $value)) {
            return $value;
        }

        return mb_convert_case(mb_strtolower($value), MB_CASE_TITLE, 'UTF-8');
    }
}
