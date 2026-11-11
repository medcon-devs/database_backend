<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ContactSortingService
{
    public const FIELDS = [
        'id',
        'firstname',
        'lastname',
        'email',
        'phone',
        'country',
        'speciality',
        'created_at',
        'updated_at',
    ];

    public function rules(): array
    {
        return [
            'sort_by' => 'nullable|string|in:'.implode(',', self::FIELDS),
            'sort_direction' => 'nullable|string|in:asc,desc',
        ];
    }

    public function apply(
        Builder $query,
        ?string $sortBy,
        ?string $sortDirection,
        string $defaultField = 'created_at',
        string $defaultDirection = 'desc',
        ?string $table = null
    ): Builder {
        $field = in_array($sortBy, self::FIELDS, true) ? $sortBy : $defaultField;
        $direction = in_array($sortDirection, ['asc', 'desc'], true)
            ? $sortDirection
            : $defaultDirection;
        $column = $table ? $table.'.'.$field : $field;

        $query->orderBy($column, $direction);

        if ($field !== 'id') {
            $query->orderBy($table ? $table.'.id' : 'id', 'asc');
        }

        return $query;
    }

    public function sortRanked(Collection $contacts, ?string $sortBy, ?string $sortDirection): Collection
    {
        if (! in_array($sortBy, self::FIELDS, true)) {
            return $contacts;
        }

        $direction = $sortDirection === 'desc' ? 'desc' : 'asc';

        return $contacts->sort(function (array $left, array $right) use ($sortBy, $direction) {
            $leftValue = $left[$sortBy] ?? '';
            $rightValue = $right[$sortBy] ?? '';
            $comparison = is_numeric($leftValue) && is_numeric($rightValue)
                ? $leftValue <=> $rightValue
                : strnatcasecmp((string) $leftValue, (string) $rightValue);

            if ($comparison !== 0) {
                return $direction === 'desc' ? -$comparison : $comparison;
            }

            return ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0));
        })->values();
    }
}
