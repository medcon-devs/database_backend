<?php

namespace App\Exports;

use App\Models\CSVData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class CSVDataExport implements FromCollection, ShouldAutoSize, WithHeadings
{
    public function __construct(
        protected int $userId,
        protected array $countries = [],
        protected array $specialities = [],
        protected string $text = '',
        protected array $includeTagIds = [],
        protected array $excludeTagIds = [],
        protected string $sortBy = 'created_at',
        protected string $sortDirection = 'desc',
        protected ?string $tenantId = null,
        protected bool $scopeTenant = false
    ) {}

    public function collection(): Collection
    {
        return $this->query()
            ->orderBy($this->sortBy, $this->sortDirection)
            ->when($this->sortBy !== 'id', fn (Builder $query) => $query->orderBy('id'))
            ->get()
            ->map(fn (CSVData $csvData) => [
                'First Name' => $csvData->firstname,
                'Last Name' => $csvData->lastname,
                'Email' => $csvData->email,
                'Phone' => $csvData->phone,
                'Country' => $csvData->country,
                'Speciality' => $csvData->speciality,
            ]);
    }

    public function count(): int
    {
        return $this->query()->count();
    }

    public function query(): Builder
    {
        return CSVData::query()
            ->when($this->scopeTenant && $this->tenantId === null, fn (Builder $query) => $query->whereNull('microsoft_tenant_id'))
            ->when($this->scopeTenant && $this->tenantId !== null, fn (Builder $query) => $query->where('microsoft_tenant_id', $this->tenantId))
            ->when($this->text !== '', function (Builder $query) {
                $query->where(function (Builder $query) {
                    $query->where('email', 'like', '%'.$this->text.'%')
                        ->orWhere('firstname', 'like', '%'.$this->text.'%')
                        ->orWhere('lastname', 'like', '%'.$this->text.'%')
                        ->orWhere('phone', 'like', '%'.$this->text.'%');
                });
            })
            ->when($this->countries !== [], function (Builder $query) {
                $this->applyNullableFilter($query, 'country', $this->countries);
            })
            ->when($this->specialities !== [], function (Builder $query) {
                $this->applyNullableFilter($query, 'speciality', $this->specialities);
            })
            ->when($this->includeTagIds !== [], function (Builder $query) {
                $query->whereHas('tags', function (Builder $tagQuery) {
                    $tagQuery->whereIn('tags.id', $this->includeTagIds);
                });
            })
            ->when($this->excludeTagIds !== [], function (Builder $query) {
                $query->whereDoesntHave('tags', function (Builder $tagQuery) {
                    $tagQuery->whereIn('tags.id', $this->excludeTagIds);
                });
            });
    }

    public function headings(): array
    {
        return [
            'FIRST NAME',
            'LAST NAME',
            'EMAIL',
            'PHONE',
            'COUNTRY',
            'SPECIALITY',
        ];
    }

    private function applyNullableFilter(Builder $query, string $column, array $values): void
    {
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
}
