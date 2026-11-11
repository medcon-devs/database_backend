<?php

namespace App\Exports;

use App\Models\WhatsAppCampaignRecipient;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class WhatsAppCampaignRecipientsExport implements FromQuery, WithColumnFormatting, WithColumnWidths, WithEvents, WithHeadings, WithMapping, WithTitle
{
    public function __construct(private Builder $recipients) {}

    public function query(): Builder
    {
        return $this->recipients->oldest('id');
    }

    public function headings(): array
    {
        return [
            'Recipient ID', 'Contact ID', 'Name', 'Phone', 'Email', 'Delivery Status',
            'Response Count', 'Last Response', 'Last Response Time', 'Error Code',
            'Error Message', 'Sent Time', 'Delivered Time', 'Read Time', 'Failed Time',
        ];
    }

    public function title(): string
    {
        return 'Recipients';
    }

    public function map($recipient): array
    {
        /** @var WhatsAppCampaignRecipient $recipient */
        $name = trim(implode(' ', array_filter([
            $recipient->firstname ?: $recipient->contact?->firstname,
            $recipient->lastname ?: $recipient->contact?->lastname,
        ])));

        return [
            $recipient->id,
            $recipient->csv_data_id,
            $name,
            $recipient->phone,
            $recipient->email ?: $recipient->contact?->email,
            $recipient->status,
            $recipient->response_count,
            $recipient->last_response_content,
            $this->excelDate($recipient->last_responded_at),
            $recipient->error_code,
            $recipient->error_message,
            $this->excelDate($recipient->sent_at),
            $this->excelDate($recipient->delivered_at),
            $this->excelDate($recipient->read_at),
            $this->excelDate($recipient->failed_at),
        ];
    }

    public function columnFormats(): array
    {
        return [
            'I' => NumberFormat::FORMAT_DATE_DATETIME,
            'L' => NumberFormat::FORMAT_DATE_DATETIME,
            'M' => NumberFormat::FORMAT_DATE_DATETIME,
            'N' => NumberFormat::FORMAT_DATE_DATETIME,
            'O' => NumberFormat::FORMAT_DATE_DATETIME,
        ];
    }

    public function columnWidths(): array
    {
        return ['A' => 14, 'B' => 14, 'C' => 26, 'D' => 20, 'E' => 30, 'F' => 18, 'G' => 16, 'H' => 42, 'I' => 22, 'J' => 16, 'K' => 42, 'L' => 22, 'M' => 22, 'N' => 22, 'O' => 22];
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event) {
            $sheet = $event->sheet->getDelegate();
            $sheet->freezePane('A2');
            $sheet->setAutoFilter($sheet->calculateWorksheetDimension());
            $sheet->setShowGridlines(false);
            $sheet->getRowDimension(1)->setRowHeight(26);
            $sheet->getStyle('A1:O1')->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '123B5D']],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $sheet->getStyle('H:K')->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        }];
    }

    private function excelDate($value): ?float
    {
        return $value ? Date::dateTimeToExcel($value) : null;
    }
}
