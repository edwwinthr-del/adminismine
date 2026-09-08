<?php

namespace App\Services\Reports;

use App\Models\CompanySettings;
use App\Models\User;
use App\Reports\Report;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Renders any report to Excel or PDF. Both formats carry the same header block —
 * company name, report title, date range, generated date and who generated it —
 * and PDFs of reports that ask for one get a signature/stamp area.
 *
 * Nothing here knows about a specific report: a new report gets both exports for
 * free the moment it is in the registry.
 */
class ReportExporter
{
    /**
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $headings  column headings in the user's own language,
     *                                  supplied by the frontend; falls back to the
     *                                  report's own label key where absent
     */
    public function excel(
        Report $report,
        array $rows,
        array $filters,
        User $user,
        string $title,
        array $headings = [],
    ): string {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(mb_substr($title, 0, 31));

        $columns = $report->columns();
        $lastColumn = $this->columnLetter(count($columns));

        $company = CompanySettings::current();

        $sheet->setCellValue('A1', $company->company_name);
        $sheet->setCellValue('A2', $title);
        $sheet->setCellValue('A3', $this->rangeLine($filters));
        $sheet->setCellValue('A4', "Generated {$this->generatedAt()} by {$user->name}");
        $sheet->mergeCells("A1:{$lastColumn}1");
        $sheet->mergeCells("A2:{$lastColumn}2");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('A3:A4')->getFont()->setSize(9);

        $headerRow = 6;
        foreach ($columns as $index => $column) {
            $cell = $this->columnLetter($index + 1).$headerRow;
            $sheet->setCellValue($cell, $this->heading($column, $headings, $index));
        }

        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EFEFEF']],
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN]],
        ]);

        $rowNumber = $headerRow + 1;
        foreach ($rows as $row) {
            foreach ($columns as $index => $column) {
                $cell = $this->columnLetter($index + 1).$rowNumber;
                $value = $row[$column['key']] ?? null;

                $sheet->setCellValue($cell, $value);

                if (in_array($column['type'], ['money', 'number'], true)) {
                    $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(
                        $column['type'] === 'money' ? '#,##0.00' : '#,##0.###',
                    );
                }
            }
            $rowNumber++;
        }

        $totals = $report->totals($rows);
        if ($totals !== []) {
            $sheet->setCellValue("A{$rowNumber}", 'Total');
            foreach ($columns as $index => $column) {
                if (! array_key_exists($column['key'], $totals)) {
                    continue;
                }
                $cell = $this->columnLetter($index + 1).$rowNumber;
                $sheet->setCellValue($cell, $totals[$column['key']]);
                $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('#,##0.00');
            }
            $sheet->getStyle("A{$rowNumber}:{$lastColumn}{$rowNumber}")->getFont()->setBold(true);
        }

        foreach (range(1, count($columns)) as $index) {
            $sheet->getColumnDimension($this->columnLetter($index))->setAutoSize(true);
        }
        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$rowNumber}")
            ->getAlignment()->setVertical(Alignment::VERTICAL_TOP);

        $path = tempnam(sys_get_temp_dir(), 'report').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $headings
     */
    public function pdf(
        Report $report,
        array $rows,
        array $filters,
        User $user,
        string $title,
        array $headings = [],
    ): string {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($report, $rows, $filters, $user, $title, $headings), 'UTF-8');
        $dompdf->setPaper('a4', count($report->columns()) > 6 ? 'landscape' : 'portrait');
        $dompdf->render();

        $path = tempnam(sys_get_temp_dir(), 'report').'.pdf';
        file_put_contents($path, $dompdf->output());

        return $path;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $headings
     */
    private function html(
        Report $report,
        array $rows,
        array $filters,
        User $user,
        string $title,
        array $headings = [],
    ): string {
        $company = CompanySettings::current();
        $columns = $report->columns();
        $totals = $report->totals($rows);

        $head = implode('', array_map(
            fn (int $index, array $column): string => '<th class="'.($this->isNumeric($column) ? 'num' : '').'">'
                .e($this->heading($column, $headings, $index)).'</th>',
            array_keys($columns),
            $columns,
        ));

        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr>';
            foreach ($columns as $column) {
                $value = $row[$column['key']] ?? null;
                $body .= '<td class="'.($this->isNumeric($column) ? 'num' : '').'">'
                    .e($this->format($value, $column['type'])).'</td>';
            }
            $body .= '</tr>';
        }

        $footer = '';
        if ($totals !== []) {
            $footer = '<tr class="totals">';
            foreach ($columns as $index => $column) {
                $value = $totals[$column['key']] ?? ($index === 0 ? 'Total' : '');
                $footer .= '<td class="'.($this->isNumeric($column) ? 'num' : '').'">'
                    .e(is_numeric($value) ? number_format((float) $value, 2) : (string) $value).'</td>';
            }
            $footer .= '</tr>';
        }

        // The signature/stamp block the spec asks for on important reports.
        $signature = $report->needsSignature() ? '
            <table class="sign">
                <tr>
                    <td><div class="line"></div><span>Prepared by</span></td>
                    <td><div class="line"></div><span>Approved by</span></td>
                    <td><div class="stamp">Stamp</div></td>
                </tr>
            </table>' : '';

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
            @page { margin: 18mm 12mm; }
            body { font-family: "DejaVu Sans", sans-serif; font-size: 9px; color: #111; }
            .company { font-size: 15px; font-weight: bold; }
            .title { font-size: 12px; font-weight: bold; margin-top: 2px; }
            .meta { font-size: 8px; color: #555; margin-top: 3px; }
            hr { border: 0; border-top: 1px solid #bbb; margin: 8px 0 10px; }
            table.data { width: 100%; border-collapse: collapse; }
            table.data th, table.data td { border-bottom: 1px solid #ddd; padding: 4px 5px; text-align: left; }
            table.data th { background: #f0f0f0; font-size: 8px; text-transform: uppercase; }
            table.data td.num, table.data th.num { text-align: right; }
            tr.totals td { font-weight: bold; border-top: 1px solid #999; }
            table.sign { width: 100%; margin-top: 34px; border-collapse: collapse; }
            table.sign td { width: 33%; padding-right: 16px; vertical-align: bottom; }
            .line { border-bottom: 1px solid #333; height: 28px; }
            .sign span { font-size: 8px; color: #555; }
            .stamp { border: 1px dashed #999; height: 58px; text-align: center;
                     line-height: 58px; color: #999; font-size: 8px; }
        </style></head><body>
            <div class="company">'.e($company->company_name).'</div>
            <div class="title">'.e($title).'</div>
            <div class="meta">'.e($this->rangeLine($filters)).'</div>
            <div class="meta">Generated '.e($this->generatedAt()).' by '.e($user->name).'</div>
            <hr>
            <table class="data"><thead><tr>'.$head.'</tr></thead><tbody>'.$body.$footer.'</tbody></table>
            '.$signature.'
        </body></html>';
    }

    private function isNumeric(array $column): bool
    {
        return in_array($column['type'], ['money', 'number'], true);
    }

    private function format(mixed $value, string $type): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return match ($type) {
            'money' => number_format((float) $value, 2),
            'number' => rtrim(rtrim(number_format((float) $value, 3), '0'), '.'),
            'month' => substr((string) $value, 0, 7),
            default => (string) $value,
        };
    }

    /**
     * The heading for one column: what the screen showed, or the report's own
     * label key where the caller supplied nothing.
     *
     * @param  array<string, mixed>  $column
     * @param  list<string>  $headings
     */
    private function heading(array $column, array $headings, int $index): string
    {
        $supplied = trim((string) ($headings[$index] ?? ''));

        return $supplied !== '' ? $supplied : (string) $column['label'];
    }

    /** @param array<string, mixed> $filters */
    private function rangeLine(array $filters): string
    {
        if (! empty($filters['month'])) {
            return 'Period: '.substr((string) $filters['month'], 0, 7);
        }
        if (! empty($filters['year'])) {
            return 'Period: '.$filters['year'];
        }

        $from = $filters['date_from'] ?? null;
        $to = $filters['date_to'] ?? null;

        if ($from === null && $to === null) {
            return 'Period: all records';
        }

        return 'Period: '.($from ?? '…').' — '.($to ?? '…');
    }

    private function generatedAt(): string
    {
        return Carbon::now()->format('Y-m-d H:i');
    }

    private function columnLetter(int $index): string
    {
        return Coordinate::stringFromColumnIndex($index);
    }
}
