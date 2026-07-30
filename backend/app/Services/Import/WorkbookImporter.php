<?php

namespace App\Services\Import;

use App\Models\Client;
use App\Models\Employee;
use App\Models\House;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\PayableInvoice;
use App\Models\ReceivableInvoice;
use App\Models\Supplier;
use App\Services\Import\Parsers\BankMovementParser;
use App\Services\Import\Parsers\DebtListParser;
use App\Services\Import\Parsers\FlightTicketParser;
use App\Services\Import\Parsers\LoanParser;
use App\Services\Import\Parsers\MasterLedgerParser;
use App\Services\Import\Parsers\ReceivableParser;
use App\Services\Import\Parsers\SocialAssistanceParser;
use App\Services\Import\Parsers\UnipromStatementParser;
use App\Services\Import\Parsers\WorkerBankAccountParser;
use App\Support\Import\CellValue;
use App\Support\Import\ImportEntity;
use App\Support\Import\LabelNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Reads a workbook into `import_rows` — and nothing else. No business table is
 * touched until someone approves the preview (see ImportCommitter).
 */
class WorkbookImporter
{
    /** @var list<class-string<SheetParser>> */
    private const PARSERS = [
        DebtListParser::class,
        ReceivableParser::class,
        UnipromStatementParser::class,
        BankMovementParser::class,
        MasterLedgerParser::class,
        WorkerBankAccountParser::class,
        LoanParser::class,
        FlightTicketParser::class,
        SocialAssistanceParser::class,
    ];

    public function __construct(private readonly TemplateSheetReader $reader) {}

    /**
     * @param  ImportEntity|null  $entity  when given, the file is read as a filled-in
     *                                     template for that one entity; otherwise
     *                                     each sheet is matched to a parser by name
     *                                     (how the original workbook is imported).
     */
    public function preview(ImportBatch $batch, string $absolutePath, ?ImportEntity $entity = null): ImportBatch
    {
        $reader = IOFactory::createReaderForFile($absolutePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($absolutePath);

        if ($entity !== null) {
            return $this->previewTemplate($batch, $spreadsheet, $entity);
        }

        $summary = [];
        $totals = ['rows' => 0, 'incomplete' => 0, 'duplicates' => 0, 'unknown_labels' => 0, 'unmapped_sheets' => 0];

        DB::transaction(function () use ($spreadsheet, $batch, &$summary, &$totals): void {
            foreach ($spreadsheet->getSheetNames() as $sheetName) {
                $parser = $this->parserFor($sheetName);

                if ($parser === null) {
                    // Reported rather than skipped in silence: the user should
                    // know a sheet went unread.
                    $summary[] = ['sheet' => $sheetName, 'target' => null, 'rows' => 0, 'skipped' => true];
                    $totals['unmapped_sheets']++;

                    continue;
                }

                $sheet = $spreadsheet->getSheetByName($sheetName);
                $cells = $sheet->rangeToArray(
                    'A1:'.$sheet->getHighestColumn().$sheet->getHighestRow(),
                    null,
                    true,
                    false,
                );

                $parsed = $parser->parse(array_values($cells));
                $this->flagDuplicates($parsed);

                foreach ($parsed as $row) {
                    ImportRow::create([
                        'import_batch_id' => $batch->id,
                        // Preserved verbatim, along with the row number.
                        'sheet_name' => $sheetName,
                        'row_number' => $row->rowNumber,
                        'target' => $row->target,
                        'raw' => $row->raw,
                        'mapped' => $row->mapped,
                        'issues' => $row->issues,
                        // A row that looks like an existing record defaults to
                        // skip, so nothing is overwritten without a decision.
                        'action' => $row->hasIssue('duplicate') ? 'skip' : 'create',
                    ]);

                    $totals['rows']++;
                    $totals['incomplete'] += $row->hasIssue('incomplete') ? 1 : 0;
                    $totals['duplicates'] += $row->hasIssue('duplicate') ? 1 : 0;
                    $totals['unknown_labels'] += $row->hasIssue('unknown_label') ? 1 : 0;
                }

                $summary[] = [
                    'sheet' => $sheetName,
                    'rows' => count($parsed),
                    'targets' => array_count_values(array_map(fn (ParsedRow $r): string => $r->target, $parsed)),
                    'skipped' => false,
                ];
            }

            $batch->forceFill([
                'sheet_summary' => $summary,
                'totals' => $totals,
                'status' => 'previewed',
            ])->save();
        });

        return $batch->fresh();
    }

    /**
     * The chosen-entity path: one sheet, read by its header row and validated
     * against the entity's own rules.
     *
     * Rows that fail validation stay in the preview with their errors and are
     * defaulted to `skip`, so approving the batch imports every valid row and
     * leaves the rest reported rather than half-written.
     */
    private function previewTemplate(ImportBatch $batch, Spreadsheet $spreadsheet, ImportEntity $entity): ImportBatch
    {
        $sheet = $spreadsheet->getSheet(0);
        $sheetName = $sheet->getTitle();

        $cells = $sheet->rangeToArray(
            'A1:'.$sheet->getHighestColumn().$sheet->getHighestRow(),
            null,
            true,
            false,
        );

        $result = $this->reader->read($entity, array_values($cells));
        $parsed = $result['rows'];
        $this->flagDuplicates($parsed);

        $totals = [
            'rows' => 0,
            'incomplete' => 0,
            'duplicates' => 0,
            'unknown_labels' => 0,
            'unmapped_sheets' => 0,
            'invalid' => 0,
        ];

        DB::transaction(function () use ($batch, $parsed, $sheetName, $entity, $result, &$totals): void {
            foreach ($parsed as $row) {
                $invalid = $row->hasIssue('invalid');

                ImportRow::create([
                    'import_batch_id' => $batch->id,
                    'sheet_name' => $sheetName,
                    'row_number' => $row->rowNumber,
                    'target' => $row->target,
                    'raw' => $row->raw,
                    'mapped' => $row->mapped,
                    'issues' => $row->issues,
                    // Anything that would not save cleanly starts switched off.
                    'action' => $invalid || $row->hasIssue('duplicate') ? 'skip' : 'create',
                ]);

                $totals['rows']++;
                $totals['invalid'] += $invalid ? 1 : 0;
                $totals['duplicates'] += $row->hasIssue('duplicate') ? 1 : 0;
                $totals['incomplete'] += $row->hasIssue('incomplete') ? 1 : 0;
            }

            $batch->forceFill([
                'entity' => $entity->key,
                'sheet_summary' => [[
                    'sheet' => $sheetName,
                    'rows' => count($parsed),
                    'targets' => [$entity->target => count($parsed)],
                    'skipped' => false,
                    'missing_columns' => $result['missing_columns'],
                    'unknown_columns' => $result['unknown_columns'],
                ]],
                'totals' => $totals,
                'status' => 'previewed',
                // A file with a required column missing cannot be judged row by
                // row, so the batch itself says what is wrong with the file.
                'error' => $result['missing_columns'] === []
                    ? null
                    : 'Missing required columns: '.implode(', ', $result['missing_columns']),
            ])->save();
        });

        return $batch->fresh();
    }

    private function parserFor(string $sheetName): ?SheetParser
    {
        $key = CellValue::key($sheetName);

        foreach (self::PARSERS as $class) {
            $names = array_map(fn (string $name): ?string => CellValue::key($name), $class::sheetNames());

            if (! in_array($key, $names, true)) {
                continue;
            }

            return match ($class) {
                ReceivableParser::class => ReceivableParser::forSheet($sheetName),
                MasterLedgerParser::class => MasterLedgerParser::forSheet($sheetName),
                default => new $class,
            };
        }

        return null;
    }

    /**
     * Point at records that already exist, so the user is asked before anything
     * is written twice. Names are matched on a normalized key, invoices on their
     * number — both the spec's "detect duplicate suppliers, clients, workers and
     * houses" and the re-import case.
     *
     * @param  list<ParsedRow>  $rows
     */
    private function flagDuplicates(array $rows): void
    {
        $suppliers = $this->nameIndex(Supplier::query()->pluck('name', 'id'));
        $clients = $this->nameIndex(Client::query()->pluck('name', 'id'));
        $houses = $this->nameIndex(House::query()->pluck('name', 'id'));
        $employees = $this->nameIndex(
            Employee::query()->get(['id', 'first_name', 'last_name'])
                ->mapWithKeys(fn (Employee $e): array => [$e->id => $e->full_name]),
        );

        $payableNumbers = PayableInvoice::query()->whereNotNull('invoice_number')
            ->pluck('id', 'invoice_number');
        $receivableNumbers = ReceivableInvoice::query()->whereNotNull('invoice_number')
            ->pluck('id', 'invoice_number');

        foreach ($rows as $row) {
            match ($row->target) {
                'payable_invoice' => $this->flagInvoice($row, $suppliers, 'supplier_name', 'supplier', $payableNumbers),
                'receivable_invoice' => $this->flagInvoice($row, $clients, 'client_name', 'client', $receivableNumbers),
                'client_payment' => $this->flagExisting($row, $clients, 'client_name', 'client'),
                'employee_bank_account' => $this->flagWorker($row, $employees),
                'social_assistance_payment', 'travel_expense', 'flight_ticket' => $this->flagExisting(
                    $row,
                    $employees,
                    $row->target === 'flight_ticket' ? 'passenger_name' : 'person_name',
                    'employee',
                    matchIsGood: true,
                ),
                'house' => $this->flagExisting($row, $houses, 'name', 'house'),
                'supplier' => $this->flagExisting($row, $suppliers, 'name', 'supplier'),
                'client' => $this->flagExisting($row, $clients, 'name', 'client'),
                'employee' => $this->flagNewEmployee($row, $employees),
                default => null,
            };
        }
    }

    /** @param Collection<int, string> $names */
    private function nameIndex($names): array
    {
        $index = [];

        foreach ($names as $id => $name) {
            $key = LabelNormalizer::nameKey($name);

            if ($key !== null) {
                $index[$key] = (int) $id;
            }
        }

        return $index;
    }

    private function flagInvoice(ParsedRow $row, array $index, string $field, string $label, $numbers): void
    {
        $this->flagExisting($row, $index, $field, $label, matchIsGood: true);

        $number = $row->mapped['invoice_number'] ?? null;

        if ($number !== null && isset($numbers[$number])) {
            $row->withIssue(
                'duplicate',
                "Invoice {$number} already exists.",
                ['field' => 'invoice_number', 'record_id' => (int) $numbers[$number]],
            );
        }
    }

    /**
     * @param  bool  $matchIsGood  when true, finding the party is reuse (good) and
     *                             only a miss is worth mentioning; when false the
     *                             match itself is the duplicate.
     */
    private function flagExisting(
        ParsedRow $row,
        array $index,
        string $field,
        string $label,
        bool $matchIsGood = false,
    ): void {
        $key = LabelNormalizer::nameKey($row->mapped[$field] ?? null);

        if ($key === null) {
            return;
        }

        if (isset($index[$key])) {
            if (! $matchIsGood) {
                $row->withIssue(
                    'duplicate',
                    "A {$label} named '{$row->mapped[$field]}' already exists.",
                    ['field' => $field, 'record_id' => $index[$key]],
                );
            } else {
                $row->withIssue(
                    'match',
                    "Will be linked to the existing {$label}.",
                    ['field' => $field, 'record_id' => $index[$key]],
                );
            }

            return;
        }

        if ($matchIsGood) {
            $row->withIssue('new_party', "No {$label} named '{$row->mapped[$field]}' yet — one will be created.", [
                'field' => $field,
            ]);
        }
    }

    /**
     * A worker being created: the name is split across two columns here, so the
     * duplicate check joins them before comparing.
     */
    private function flagNewEmployee(ParsedRow $row, array $employees): void
    {
        $name = trim(($row->mapped['first_name'] ?? '').' '.($row->mapped['last_name'] ?? ''));
        $key = LabelNormalizer::nameKey($name);

        if ($key === null || ! isset($employees[$key])) {
            return;
        }

        $row->withIssue('duplicate', "A worker named '{$name}' already exists.", [
            'field' => 'last_name',
            'record_id' => $employees[$key],
        ]);
    }

    private function flagWorker(ParsedRow $row, array $employees): void
    {
        $key = LabelNormalizer::nameKey($row->mapped['employee_name'] ?? null);

        if ($key === null || isset($employees[$key])) {
            return;
        }

        // This sheet only ever updates a worker, so an unmatched name cannot be
        // acted on — it is flagged rather than creating a half-empty worker.
        $row->withIssue(
            'unmatched',
            "No worker matches '{$row->mapped['employee_name']}'.",
            ['field' => 'employee_name'],
        );
    }
}
