<?php

namespace App\Services\Import;

use App\Support\Import\ImportCatalogue;
use App\Support\Import\ImportColumn;
use App\Support\Import\ImportEntity;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Builds the blank workbook a user fills in for one entity.
 *
 * Generated from {@see ImportCatalogue}, so the headers are
 * by construction the headers the importer reads back: there is no second
 * description of the format that could fall out of step with the first.
 *
 * The file carries three things beyond the headers, because the point is that
 * the user should not have to guess: an example row (deleted before uploading),
 * a dropdown on every column with a fixed set of values, and an instructions
 * sheet naming each column, whether it is required and what it accepts.
 */
class TemplateGenerator
{
    /** Row holding the headers; the sheet is read back from the row after it. */
    public const HEADER_ROW = 1;

    /**
     * Marks the sample row so the reader can tell it from real data.
     *
     * The sample is well-formed by construction, so without a marker it parsed
     * as a valid row and imported — a user who filled in below it and left it in
     * place created a fake invoice. Recognising it by its values instead would
     * be guesswork, and would drop a real row that happened to match, so the
     * template says outright which row is the sample. The column is hidden and
     * the reader never reports it as unknown.
     */
    public const EXAMPLE_MARKER_HEADER = '__example_row__';

    public const EXAMPLE_MARKER_VALUE = 'template-sample';

    private const EXAMPLE_ROW = 2;

    /** @return string absolute path to a temporary .xlsx */
    public function generate(ImportEntity $entity): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(mb_substr($entity->label, 0, 31));

        foreach ($entity->columns as $index => $column) {
            $letter = Coordinate::stringFromColumnIndex($index + 1);

            // A required column is marked in the header, and the header itself
            // stays exactly the string the reader matches on.
            $sheet->setCellValue($letter.self::HEADER_ROW, $column->header);
            $sheet->getComment($letter.self::HEADER_ROW)->getText()->createTextRun($this->commentFor($column));

            if ($column->example !== null) {
                $sheet->setCellValueExplicit(
                    $letter.self::EXAMPLE_ROW,
                    $column->example,
                    DataType::TYPE_STRING,
                );
            }

            $this->applyValidation($sheet, $letter, $column);
            $sheet->getColumnDimension($letter)->setWidth(max(14, min(34, mb_strlen($column->header) + 6)));
        }

        $this->markExampleRow($sheet, count($entity->columns) + 1);

        $lastColumn = Coordinate::stringFromColumnIndex(count($entity->columns));

        $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8EAF6']],
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle("A2:{$lastColumn}2")->getFont()->getColor()->setRGB('9AA0A6');
        $sheet->getRowDimension(self::HEADER_ROW)->setRowHeight(22);
        $sheet->freezePane('A2');

        $this->addInstructions($spreadsheet, $entity);
        $spreadsheet->setActiveSheetIndex(0);

        $path = tempnam(sys_get_temp_dir(), 'template').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    /** Tag the sample row in a hidden column so the reader can leave it out. */
    private function markExampleRow(Worksheet $sheet, int $columnIndex): void
    {
        $letter = Coordinate::stringFromColumnIndex($columnIndex);

        $sheet->setCellValueExplicit(
            $letter.self::HEADER_ROW,
            self::EXAMPLE_MARKER_HEADER,
            DataType::TYPE_STRING,
        );
        $sheet->setCellValueExplicit(
            $letter.self::EXAMPLE_ROW,
            self::EXAMPLE_MARKER_VALUE,
            DataType::TYPE_STRING,
        );

        $sheet->getColumnDimension($letter)->setVisible(false);
    }

    public function filename(ImportEntity $entity): string
    {
        return 'import-template-'.str_replace('_', '-', $entity->key).'.xlsx';
    }

    /** A dropdown, so a canonical value cannot be mistyped into a free-text label. */
    private function applyValidation($sheet, string $letter, ImportColumn $column): void
    {
        if ($column->type === 'enum') {
            $list = '"'.implode(',', $column->values).'"';
        } elseif ($column->type === 'boolean') {
            $list = '"yes,no"';
        } else {
            return;
        }

        for ($row = self::EXAMPLE_ROW; $row <= 500; $row++) {
            $validation = $sheet->getCell($letter.$row)->getDataValidation();
            $validation->setType(DataValidation::TYPE_LIST);
            $validation->setErrorStyle(DataValidation::STYLE_INFORMATION);
            $validation->setAllowBlank(! $column->required);
            $validation->setShowDropDown(true);
            $validation->setShowErrorMessage(true);
            $validation->setErrorTitle('Value not accepted');
            $validation->setError('Pick one of the listed values.');
            $validation->setFormula1($list);
        }
    }

    private function commentFor(ImportColumn $column): string
    {
        return implode("\n", array_filter([
            $column->required ? 'Required.' : 'Optional.',
            $column->format(),
            $column->help,
        ]));
    }

    private function addInstructions(Spreadsheet $spreadsheet, ImportEntity $entity): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('How to fill this in');

        $sheet->setCellValue('A1', $entity->label);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $intro = array_filter([
            $entity->description,
            'Fill in one row per record, starting on row 2 of the first sheet.',
            'Row 2 holds an example — delete it before uploading.',
            'Leave a cell empty when you have no value for it; only the required columns must be filled.',
            'Column headings must not be renamed: they are what the importer matches on.',
        ]);

        $row = 3;
        foreach ($intro as $line) {
            $sheet->setCellValue("A{$row}", $line);
            $row++;
        }

        $row++;
        foreach (['Column', 'Required', 'Format', 'Notes'] as $index => $heading) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1).$row, $heading);
        }
        $sheet->getStyle("A{$row}:D{$row}")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EFEFEF']],
        ]);

        foreach ($entity->columns as $column) {
            $row++;
            $sheet->setCellValue("A{$row}", $column->header);
            $sheet->setCellValue("B{$row}", $column->required ? 'Yes' : 'No');
            $sheet->setCellValue("C{$row}", $column->format());
            $sheet->setCellValue("D{$row}", $column->help ?? '');
        }

        foreach (['A' => 30, 'B' => 12, 'C' => 46, 'D' => 70] as $letter => $width) {
            $sheet->getColumnDimension($letter)->setWidth($width);
        }
        $sheet->getStyle("A1:D{$row}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
    }
}
