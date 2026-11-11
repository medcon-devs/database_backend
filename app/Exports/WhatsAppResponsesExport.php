<?php

namespace App\Exports;

use App\Models\WhatsAppMessage;
use App\Services\WhatsAppReportService;
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

class WhatsAppResponsesExport implements FromQuery, WithColumnFormatting, WithColumnWidths, WithEvents, WithHeadings, WithMapping, WithTitle
{
    public function __construct(private Builder $responses) {}

    public function query(): Builder
    {
        return $this->responses->oldest('received_at')->oldest('id');
    }

    public function headings(): array
    {
        return [
            'Response ID', 'Template Name', 'Template Language', 'Campaign ID', 'Campaign Name',
            'Contact ID', 'Contact Name', 'Phone', 'Email', 'Response Content', 'Response Type', 'Received Time',
        ];
    }

    public function title(): string
    {
        return 'Responses';
    }

    public function map($message): array
    {
        /** @var WhatsAppMessage $message */
        $contact = $message->contact;
        $attribution = app(WhatsAppReportService::class)->attribution($message);
        $campaign = $attribution['campaign'];

        return [
            $message->id,
            $attribution['template_name'],
            $attribution['template_language'],
            $campaign?->id,
            $campaign?->name,
            $message->csv_data_id,
            trim(implode(' ', array_filter([$contact?->firstname, $contact?->lastname]))),
            $message->phone,
            $contact?->email,
            $message->content,
            $message->type,
            $message->received_at ? Date::dateTimeToExcel($message->received_at) : null,
        ];
    }

    public function columnFormats(): array
    {
        return ['L' => NumberFormat::FORMAT_DATE_DATETIME];
    }

    public function columnWidths(): array
    {
        return ['A' => 14, 'B' => 28, 'C' => 18, 'D' => 14, 'E' => 28, 'F' => 14, 'G' => 26, 'H' => 20, 'I' => 30, 'J' => 48, 'K' => 18, 'L' => 22];
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event) {
            $sheet = $event->sheet->getDelegate();
            $sheet->freezePane('A2');
            $sheet->setAutoFilter($sheet->calculateWorksheetDimension());
            $sheet->setShowGridlines(false);
            $sheet->getRowDimension(1)->setRowHeight(26);
            $sheet->getStyle('A1:L1')->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '123B5D']],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $sheet->getStyle('J:J')->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        }];
    }
}
