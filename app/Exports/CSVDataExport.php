<?php
namespace App\Exports;

use App\Models\CSVData;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class CSVDataExport implements FromCollection, WithHeadings, ShouldAutoSize
{
    protected $countries;
    protected $specialities;
    protected $userId;
    protected $text;

    public function __construct($userId, $countries = [], $specialities = [], $text = '')
    {
        $this->userId = $userId;
        $this->countries = $countries;
        $this->specialities = $specialities;
        $this->text = $text;
    }

    public function collection()
    {
        // Query with filters: user_id, countries, specialities, and text search
        // $query = CSVData::query()
        //     ->where('user_id', $this->userId) // Filter by user_id from token
        //     ->when(!empty($this->countries), function ($query) {
        //         $query->whereIn('country', $this->countries);
        //     })
        //     ->when(!empty($this->specialities), function ($query) {
        //         $query->whereIn('speciality', $this->specialities);
        //     })
        //     ->when(!empty($this->text), function ($query) {
        //         $query->where(function ($subQuery) {
        //             $subQuery->where('email', 'LIKE', '%' . $this->text . '%')
        //                 ->orWhere('firstname', 'LIKE', '%' . $this->text . '%')
        //                 ->orWhere('lastname', 'LIKE', '%' . $this->text . '%');
        //         });
        //     })
        //     ->get();

        $query = CSVData::query() // Match queryRow behavior
        ->when(!empty($this->text), function ($query) {
            $query->where(function ($subQuery) {
                $subQuery->where('email', 'LIKE', '%' . $this->text . '%')
                    ->orWhere('firstname', 'LIKE', '%' . $this->text . '%')
                    ->orWhere('lastname', 'LIKE', '%' . $this->text . '%');
            });
        })
        ->when(!empty($this->countries), function ($query) {
            $query->whereIn('country', $this->countries);
        })
        ->when(!empty($this->specialities), function ($query) {
            $query->whereIn('speciality', $this->specialities);
        })
        ->orderBy('created_at', 'desc')
        ->get();

        // Map the data to include only required fields
        return $query->map(function ($csvData) {
            return [
                'First Name' => $csvData->firstname,
                'Last Name' => $csvData->lastname,
                'Email' => $csvData->email,
                'Country' => $csvData->country,
                'Speciality' => $csvData->speciality,
            ];
        });
    }

    public function headings(): array
    {
        return [
            'FIRST NAME',
            'LAST NAME',
            'EMAIL',
            'COUNTRY',
            'SPECIALITY',
        ];
    }
}
