<?php

namespace Tests\Feature;

use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\Employee;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Loan;
use App\Models\PayableInvoice;
use App\Models\ReceivableInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Import\ImportCommitter;
use App\Services\Import\Parsers\BankMovementParser;
use App\Services\Import\Parsers\DebtListParser;
use App\Services\Import\Parsers\FlightTicketParser;
use App\Services\Import\Parsers\LoanParser;
use App\Services\Import\Parsers\SocialAssistanceParser;
use App\Support\Import\CellValue;
use App\Support\Import\ImportCatalogue;
use App\Support\Import\ImportColumn;
use App\Support\Import\LabelNormalizer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ExcelImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Admin');
        Sanctum::actingAs($user);

        return $user;
    }

    /** A small workbook shaped like the real one, written to a temp file. */
    private function workbook(array $sheets): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);

        foreach ($sheets as $name => $rows) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($name);
            $sheet->fromArray($rows, null, 'A1', true);
        }

        $path = tempnam(sys_get_temp_dir(), 'import').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'workbook.xlsx', null, null, true);
    }

    // ---- cell coercion -------------------------------------------------

    public function test_excel_serials_and_written_dates_both_parse(): void
    {
        $this->assertSame('2024-05-13', CellValue::date('13/05/2024'));
        $this->assertSame('2024-03-06', CellValue::date('06.03.2024'));
        $this->assertSame('2024-05-13', CellValue::date(45425));
        $this->assertNull(CellValue::date('not a date'));
        // A small number is a quantity, not a 1970s date serial.
        $this->assertNull(CellValue::date(12));
    }

    public function test_numbers_survive_both_decimal_separators(): void
    {
        $this->assertSame(232.9, CellValue::number('232.9'));
        $this->assertSame(232.9, CellValue::number('232,9'));
        $this->assertSame(1234.56, CellValue::number('1.234,56'));
        $this->assertSame(1234.56, CellValue::number('1,234.56'));
        $this->assertSame(-1000.0, CellValue::number(-1000));
    }

    // ---- label normalization ------------------------------------------

    public function test_workbook_labels_normalize_to_canonical_values(): void
    {
        $this->assertSame('paid', LabelNormalizer::paymentStatus('ODENDI'));
        $this->assertSame('unpaid', LabelNormalizer::paymentStatus('ODENMEDI'));
        $this->assertSame('expense', LabelNormalizer::category('GIDER'));
        // The workbook's typo has to land in the same place.
        $this->assertSame('expense', LabelNormalizer::category('GIDERR'));
        $this->assertSame('income', LabelNormalizer::category('GELIR'));
        $this->assertSame('repaid', LabelNormalizer::loanStatus('VRACENO'));
        $this->assertSame('written', LabelNormalizer::costStatus('YAZILDI'));
        $this->assertSame('not_written', LabelNormalizer::costStatus('YAZILMADI'));
        // Stray marks in the sheet must not defeat the match.
        $this->assertSame('departure', LabelNormalizer::direction('GIDIS#'));
        $this->assertSame('round_trip', LabelNormalizer::direction('GITGEL'));
        $this->assertSame('car', LabelNormalizer::expenseType('ARABA'));
        $this->assertNull(LabelNormalizer::category('SOMETHING ELSE'));
    }

    public function test_company_names_match_across_spelling_and_legal_form(): void
    {
        $key = LabelNormalizer::nameKey('DOO TADIC MIA');

        $this->assertSame($key, LabelNormalizer::nameKey('Doo  Tadić Mia'));
        $this->assertSame($key, LabelNormalizer::nameKey('TADIC MIA d.o.o.'));
        $this->assertNotSame($key, LabelNormalizer::nameKey('TADIC MIO'));
    }

    // ---- parsers -------------------------------------------------------

    public function test_the_debt_list_maps_to_payable_invoices(): void
    {
        $rows = (new DebtListParser)->parse([
            ['SIRA', 'FATURA TARİHİ', 'FİRMA ADI', 'FATURA  NO', 'AÇIKLAMA', 'BORÇ TUTAR EURO', 'KALAN BORÇ TUTARI EURO', '', 'NOTLAR'],
            [1, '06.03.2024', 'DOO TADIC MIA', '16/24', 'NAKLIYE', 600, 0, 'ODENDI', ''],
            [3, '30.04.2024', 'CASTELLANA', '2400001026-7', 'MAKINA SARF', 232.9, 232.9, 'ODENMEDI', ''],
        ]);

        $this->assertCount(2, $rows);

        $this->assertSame('payable_invoice', $rows[0]->target);
        $this->assertSame('DOO TADIC MIA', $rows[0]->mapped['supplier_name']);
        $this->assertSame('2024-03-06', $rows[0]->mapped['invoice_date']);
        $this->assertSame(600.0, $rows[0]->mapped['original_amount']);
        // Fully settled on the sheet, so the whole amount arrives as paid.
        $this->assertSame(600.0, $rows[0]->mapped['opening_paid_amount']);

        $this->assertSame(232.9, $rows[1]->mapped['original_amount']);
        $this->assertSame(0.0, $rows[1]->mapped['opening_paid_amount']);
    }

    public function test_bank_movements_keep_their_signed_account_columns(): void
    {
        $rows = (new BankMovementParser)->parse([
            ['TARİH', 'AÇIKLAMA 1', 'AÇIKLAMA 2', 'KASA', 'NLB BANK', 'LOVCEN BANK', 'FATURA NO', 'FATURA TARİHİ', 'GİDER KALEMİ', 'NOTLAR'],
            ['', '', '', 'PRETHODNO STANJE=', 0, '', '', '', '', ''],
            ['13/05/2024', 'ADMINISMINE DOO', '', '', 2200, '', '', '', 'GELIR', ''],
            ['13/05/2024', 'ADMINISMINE DOO', '', '', -1000, '', '', '', 'GIDER', ''],
        ]);

        // The opening-balance line is a position, not a movement.
        $this->assertCount(2, $rows);
        $this->assertSame(2200.0, $rows[0]->mapped['nlb_amount']);
        $this->assertSame('income', $rows[0]->mapped['category']);
        $this->assertSame(-1000.0, $rows[1]->mapped['nlb_amount']);
        $this->assertSame('expense', $rows[1]->mapped['category']);
    }

    public function test_flight_tickets_derive_the_rate_actually_paid(): void
    {
        $rows = (new FlightTicketParser)->parse([
            ['Ime radnika', 'DATUM', 'Cijena (TRY)', 'Cijena (EUR)', 'Putni Toskovi', 'TURU'],
            ['YILMAZ ODUMLU', '26/10/2024', 5180, 138.42864778193, 'YAZILMADI', 'GIDIS#'],
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame('YILMAZ ODUMLU', $rows[0]->mapped['passenger_name']);
        $this->assertSame('TRY', $rows[0]->mapped['currency']);
        $this->assertSame(5180.0, $rows[0]->mapped['amount']);
        $this->assertSame('departure', $rows[0]->mapped['direction']);
        $this->assertSame('not_written', $rows[0]->mapped['cost_status']);
        // 5180 / 138.43 ≈ 37.42, the rate the office really got.
        $this->assertEqualsWithDelta(37.42, $rows[0]->mapped['exchange_rate'], 0.01);
    }

    public function test_the_loan_sheet_reads_amounts_and_the_returned_marker(): void
    {
        $rows = (new LoanParser)->parse([
            ['NORTH-EX', '103/24', 0, '', '', '', 'VRACENO'],
            ['NORTH-EX', '165/24', -175, '', '', 45358, ''],
            ['', 'UKUPNO ZA VRATIT', -175, '', '', '', ''],
        ]);

        // The sheet's own total line is not a loan.
        $this->assertCount(2, $rows);
        $this->assertTrue($rows[0]->mapped['repaid_in_full']);
        // Amounts owed are written negative on this sheet.
        $this->assertSame(175.0, $rows[1]->mapped['original_amount']);
        $this->assertFalse($rows[1]->mapped['repaid_in_full']);
    }

    public function test_the_side_by_side_blocks_yield_separate_records(): void
    {
        $rows = (new SocialAssistanceParser)->parse([
            ['SOSYAL YARDIM 2024', '', '', '', '', '', '', 'ODENEN YOL MASRAFLARI'],
            ['BR', 'ODENEN TARIH', 'ADI', 'SOYADI', 'ODENEN MIKTAR', 'ODENEBILIR', '', 'BR', 'ODENEN TARIH', 'ADI SOYADI', 'ODENEN MIKTAR', 'DURUM', '', '', 'MJESEC', 'IME', 'UKUPNO', 'Column1'],
            [1, '29/10/2024', 'TEKIN', 'GUNDUZ', 1000, 0, '', 1, '16/10/2024', 'UJKAN KURPEJOVIC', 1000, '', '', '', '01/11/2024', 'YILMAZ ODUMLU', 462.6, 'ARABA'],
        ]);

        // One sheet row, three independent records.
        $this->assertCount(3, $rows);
        $this->assertSame('social_assistance_payment', $rows[0]->target);
        $this->assertSame('TEKIN GUNDUZ', $rows[0]->mapped['person_name']);
        $this->assertSame(2024, $rows[0]->mapped['entitlement_year']);

        $this->assertSame('travel_expense', $rows[1]->target);
        $this->assertTrue($rows[1]->mapped['settled']);

        $this->assertSame('travel_expense', $rows[2]->target);
        $this->assertSame('car', $rows[2]->mapped['expense_type']);
        $this->assertSame('written', $rows[2]->mapped['cost_status']);
        $this->assertFalse($rows[2]->mapped['settled']);
    }

    public function test_incomplete_rows_are_flagged_but_still_shown(): void
    {
        $rows = (new DebtListParser)->parse([
            ['SIRA', 'FATURA TARİHİ', 'FİRMA ADI', 'FATURA  NO', 'AÇIKLAMA', 'BORÇ TUTAR EURO'],
            [1, '', 'NO DATE DOO', '9/24', '', 500],
        ]);

        $this->assertTrue($rows[0]->hasIssue('incomplete'));
        $this->assertSame('invoice_date', $rows[0]->issues[0]['field']);
    }

    public function test_an_unrecognised_label_is_reported_not_guessed(): void
    {
        $rows = (new BankMovementParser)->parse([
            ['TARİH', 'A1', 'A2', 'KASA', 'NLB', 'LOVCEN', 'NO', 'TARIH', 'GİDER KALEMİ'],
            ['13/05/2024', 'X', '', 100, '', '', '', '', 'BLAH'],
        ]);

        $this->assertNull($rows[0]->mapped['category']);
        $this->assertTrue($rows[0]->hasIssue('unknown_label'));
    }

    // ---- upload → preview → commit -------------------------------------

    public function test_uploading_a_workbook_previews_without_writing_anything(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin();

        $file = $this->workbook([
            'BORÇ LİSTESİ' => [
                ['SIRA', 'FATURA TARİHİ', 'FİRMA ADI', 'FATURA  NO', 'AÇIKLAMA', 'BORÇ TUTAR EURO', 'KALAN BORÇ TUTARI EURO', ''],
                [1, '06.03.2024', 'DOO TADIC MIA', '16/24', 'NAKLIYE', 600, 0, 'ODENDI'],
            ],
        ]);

        $this->postJson('/api/imports', ['file' => $file])
            ->assertCreated()
            ->assertJsonPath('data.status', 'previewed')
            ->assertJsonPath('data.totals.rows', 1);

        // Parsed only — the real tables are untouched until approval.
        $this->assertSame(0, PayableInvoice::query()->count());
        $this->assertSame(0, Supplier::query()->count());
        $this->assertSame(1, ImportRow::query()->count());
    }

    public function test_committing_writes_the_records_and_derives_the_balance(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin();

        $file = $this->workbook([
            'BORÇ LİSTESİ' => [
                ['SIRA', 'FATURA TARİHİ', 'FİRMA ADI', 'FATURA  NO', 'AÇIKLAMA', 'BORÇ TUTAR EURO', 'KALAN BORÇ TUTARI EURO', ''],
                [1, '06.03.2024', 'DOO TADIC MIA', '16/24', 'NAKLIYE', 600, 0, 'ODENDI'],
                [2, '30.04.2024', 'CASTELLANA', '2400001026-7', 'SARF', 232.9, 232.9, 'ODENMEDI'],
            ],
        ]);

        $batchId = $this->postJson('/api/imports', ['file' => $file])->json('data.id');

        $this->postJson("/api/imports/{$batchId}/commit")
            ->assertOk()
            ->assertJsonPath('data.status', 'imported')
            ->assertJsonPath('meta.imported', 2);

        $this->assertSame(2, Supplier::query()->count());
        $this->assertSame(2, PayableInvoice::query()->count());

        $settled = PayableInvoice::query()->where('invoice_number', '16/24')->first();
        $this->assertSame('paid', $settled->status);
        $this->assertSame('0.00', $settled->remaining_amount);

        $open = PayableInvoice::query()->where('invoice_number', '2400001026-7')->first();
        $this->assertSame('unpaid', $open->status);
        $this->assertSame('232.90', $open->remaining_amount);
    }

    public function test_a_row_marked_skip_is_never_written(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin();

        $file = $this->workbook([
            'BORÇ LİSTESİ' => [
                ['SIRA', 'TARİH', 'FİRMA', 'NO', 'AÇIKLAMA', 'TUTAR', 'KALAN', ''],
                [1, '06.03.2024', 'KEEP DOO', '1/24', '', 100, 100, 'ODENMEDI'],
                [2, '07.03.2024', 'DROP DOO', '2/24', '', 200, 200, 'ODENMEDI'],
            ],
        ]);

        $batchId = $this->postJson('/api/imports', ['file' => $file])->json('data.id');
        $drop = ImportRow::query()->where('import_batch_id', $batchId)
            ->whereJsonContains('mapped->supplier_name', 'DROP DOO')->first();

        $this->putJson("/api/imports/{$batchId}/rows", [
            'rows' => [['id' => $drop->id, 'action' => 'skip']],
        ])->assertOk();

        $this->postJson("/api/imports/{$batchId}/commit")->assertOk();

        $this->assertSame(1, PayableInvoice::query()->count());
        $this->assertSame('KEEP DOO', Supplier::query()->first()->name);
        $this->assertSame('skipped', $drop->fresh()->status);
    }

    public function test_a_duplicate_invoice_defaults_to_skip_so_nothing_is_overwritten(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin();

        $supplier = Supplier::factory()->create(['name' => 'DOO TADIC MIA']);
        $existing = PayableInvoice::factory()->create([
            'supplier_id' => $supplier->id,
            'invoice_number' => '16/24',
            'original_amount' => 600,
        ]);
        $existing->recalculate();

        $file = $this->workbook([
            'BORÇ LİSTESİ' => [
                ['SIRA', 'TARİH', 'FİRMA', 'NO', 'AÇIKLAMA', 'TUTAR', 'KALAN', ''],
                [1, '06.03.2024', 'DOO TADIC MIA', '16/24', '', 600, 0, 'ODENDI'],
            ],
        ]);

        $batchId = $this->postJson('/api/imports', ['file' => $file])->json('data.id');
        $row = ImportRow::query()->where('import_batch_id', $batchId)->first();

        $this->assertTrue($row->hasDuplicateWarning());
        // The spec's rule: never overwrite without confirmation.
        $this->assertSame('skip', $row->action);

        $this->postJson("/api/imports/{$batchId}/commit")->assertOk();

        $this->assertSame(1, PayableInvoice::query()->count());
        $this->assertSame(1, Supplier::query()->count());
    }

    public function test_an_existing_supplier_is_reused_rather_than_duplicated(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin();

        Supplier::factory()->create(['name' => 'Doo Tadić Mia']);

        $file = $this->workbook([
            'BORÇ LİSTESİ' => [
                ['SIRA', 'TARİH', 'FİRMA', 'NO', 'AÇIKLAMA', 'TUTAR', 'KALAN', ''],
                [1, '06.03.2024', 'DOO TADIC MIA', '99/24', '', 100, 100, 'ODENMEDI'],
            ],
        ]);

        $batchId = $this->postJson('/api/imports', ['file' => $file])->json('data.id');
        $this->postJson("/api/imports/{$batchId}/commit")->assertOk();

        // Different spelling, same company.
        $this->assertSame(1, Supplier::query()->count());
        $this->assertSame(1, PayableInvoice::query()->count());
    }

    public function test_the_worker_sheet_updates_a_worker_and_never_invents_one(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin();

        $worker = Employee::factory()->create([
            'first_name' => 'BEKREK',
            'last_name' => 'GURGUR',
            'bank_account_status' => 'unknown',
        ]);

        $file = $this->workbook([
            'ISCILER ICIN BANKA HESAPLARI' => [
                ['EVRAK TEMSIL TARIHI', 'ISIM', 'DURUM', 'NOTLAR'],
                ['24/09/2024', 'BEKREK GURGUR', 'TAMAM', 'ok'],
                ['24/09/2024', 'NOBODY AT ALL', 'TAMAM', ''],
            ],
        ]);

        $batchId = $this->postJson('/api/imports', ['file' => $file])->json('data.id');
        $this->postJson("/api/imports/{$batchId}/commit")->assertOk();

        $this->assertSame('open', $worker->fresh()->bank_account_status);
        // The unmatched name creates nothing.
        $this->assertSame(1, Employee::query()->count());

        $unmatched = ImportRow::query()->where('import_batch_id', $batchId)
            ->whereJsonContains('mapped->employee_name', 'NOBODY AT ALL')->first();
        $this->assertSame('skipped', $unmatched->status);
    }

    public function test_the_loan_sheet_records_a_return_as_a_repayment(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin();

        $file = $this->workbook([
            'NORTH-EX POZAJMICA' => [
                ['NORTH-EX', '103/24', 5000, '', '', 45358, 'VRACENO'],
                ['NORTH-EX', '165/24', -175, '', '', 45358, ''],
            ],
        ]);

        $batchId = $this->postJson('/api/imports', ['file' => $file])->json('data.id');
        $this->postJson("/api/imports/{$batchId}/commit")->assertOk();

        $returned = Loan::query()->where('reference_number', '103/24')->first();
        $this->assertSame('repaid', $returned->status);
        $this->assertSame('0.00', $returned->remaining_amount);

        $open = Loan::query()->where('reference_number', '165/24')->first();
        $this->assertSame('outstanding', $open->status);
        $this->assertSame('175.00', $open->remaining_amount);
    }

    public function test_a_sheet_with_no_parser_is_reported_rather_than_ignored(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin();

        $file = $this->workbook([
            'SOMETHING NEW' => [['A', 'B'], [1, 2]],
        ]);

        $this->postJson('/api/imports', ['file' => $file])
            ->assertCreated()
            ->assertJsonPath('data.totals.unmapped_sheets', 1)
            ->assertJsonPath('data.sheet_summary.0.skipped', true);
    }

    public function test_a_committed_batch_cannot_be_committed_twice(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin();

        $file = $this->workbook([
            'BORÇ LİSTESİ' => [
                ['SIRA', 'TARİH', 'FİRMA', 'NO', 'AÇIKLAMA', 'TUTAR', 'KALAN', ''],
                [1, '06.03.2024', 'ONCE DOO', '1/24', '', 100, 100, 'ODENMEDI'],
            ],
        ]);

        $batchId = $this->postJson('/api/imports', ['file' => $file])->json('data.id');
        $this->postJson("/api/imports/{$batchId}/commit")->assertOk();
        $this->postJson("/api/imports/{$batchId}/commit")->assertStatus(422);

        $this->assertSame(1, PayableInvoice::query()->count());
    }

    public function test_cancelling_leaves_the_data_untouched(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin();

        $file = $this->workbook([
            'BORÇ LİSTESİ' => [
                ['SIRA', 'TARİH', 'FİRMA', 'NO', 'AÇIKLAMA', 'TUTAR', 'KALAN', ''],
                [1, '06.03.2024', 'NEVER DOO', '1/24', '', 100, 100, 'ODENMEDI'],
            ],
        ]);

        $batchId = $this->postJson('/api/imports', ['file' => $file])->json('data.id');
        $this->postJson("/api/imports/{$batchId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(0, PayableInvoice::query()->count());
        $this->assertSame(0, Supplier::query()->count());
    }

    public function test_the_uniprom_statement_splits_invoices_from_payments(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin();

        $file = $this->workbook([
            'UNIPROM ALACAKLAR' => [
                ['SIRA', 'ACIKLAMA', 'İLERLEME', 'FATURA NO', 'FATURA  TARİHİ', 'KESİLEN FATURA TUTARI', 'ALINAN ÖDEME', 'NOT'],
                [1, '1. GALERİ TAMİRAT FATURASI', '', '1', '29.03.2024', 93646.82, '', ''],
                [2, 'ÖDEME', '', '1', '22.04.2024', '', 66000, ''],
            ],
        ]);

        $batchId = $this->postJson('/api/imports', ['file' => $file])->json('data.id');
        $this->postJson("/api/imports/{$batchId}/commit")->assertOk();

        $client = Client::query()->first();
        $this->assertSame('UNIPROM', $client->name);

        $invoice = ReceivableInvoice::query()->where('client_id', $client->id)->first();
        $this->assertSame('93646.82', $invoice->invoice_amount);
        // The payment row settles part of it, so the balance is derived.
        $this->assertSame('66000.00', $invoice->received_amount);
        $this->assertSame('27646.82', $invoice->remaining_amount);
        $this->assertSame('partial', $invoice->status);
    }

    public function test_the_master_sheet_flags_every_row_for_review(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin();

        $file = $this->workbook([
            'SELIM' => [
                ['SIRA', 'ODEME TARİHİ', 'FATURA TARIHI', 'FİRMA ADI', 'FATURA  NO', 'TUTAR EURO', '', 'TARIH', 'CEKILEN PARA'],
                [1, 45474, '', 'NORTH-EX', '33/2024', 4020.26, '', 45425, 1000],
            ],
        ]);

        $batchId = $this->postJson('/api/imports', ['file' => $file])->json('data.id');
        $rows = ImportRow::query()->where('import_batch_id', $batchId)->get();

        // One sheet row: an invoice and a cash withdrawal.
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertTrue(collect($row->issues)->contains(fn (array $i): bool => $i['type'] === 'review'));
        }

        $this->postJson("/api/imports/{$batchId}/commit")->assertOk();

        $cash = BankTransaction::query()->first();
        // Money leaving the cash box is negative.
        $this->assertSame('-1000.00', $cash->cash_amount);
    }

    public function test_import_endpoints_require_the_imports_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Viewer');
        Sanctum::actingAs($user);

        $this->getJson('/api/imports')->assertForbidden();
        $this->postJson('/api/imports', [])->assertForbidden();

        $batch = ImportBatch::create(['original_name' => 'x.xlsx', 'file_path' => 'imports/x.xlsx']);
        $this->postJson("/api/imports/{$batch->id}/commit")->assertForbidden();
    }

    public function test_a_file_that_is_not_a_workbook_is_rejected(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin();

        $this->postJson('/api/imports', ['file' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain')])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    /** The committer is the only thing that writes, so it is worth its own guard. */
    public function test_the_committer_reports_failures_without_aborting_the_batch(): void
    {
        $batch = ImportBatch::create(['original_name' => 'x.xlsx', 'file_path' => 'imports/x.xlsx']);

        ImportRow::create([
            'import_batch_id' => $batch->id,
            'sheet_name' => 'BORÇ LİSTESİ',
            'row_number' => 2,
            'target' => 'payable_invoice',
            'raw' => [],
            // No amount: nothing to write, but not an error either.
            'mapped' => ['supplier_name' => 'NO AMOUNT DOO'],
        ]);
        ImportRow::create([
            'import_batch_id' => $batch->id,
            'sheet_name' => 'BORÇ LİSTESİ',
            'row_number' => 3,
            'target' => 'payable_invoice',
            'raw' => [],
            'mapped' => ['supplier_name' => 'FINE DOO', 'original_amount' => 50, 'invoice_date' => '2026-01-01'],
        ]);

        $result = app(ImportCommitter::class)->commit($batch);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame('imported', $batch->fresh()->status);
    }

    // ---- entity templates ----------------------------------------------

    /** A sheet in template shape: header row from the catalogue, then data. */
    private function templateFile(string $entityKey, array $dataRows, ?array $headers = null): UploadedFile
    {
        $entity = ImportCatalogue::find($entityKey);
        $headers ??= array_map(fn (ImportColumn $column): string => $column->header, $entity->columns);

        return $this->workbook([$entity->label => array_merge([$headers], $dataRows)]);
    }

    /** Someone who may run imports, but only into payables. */
    private function actingAsPayablesOnlyImporter(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['imports.manage', 'payables.view', 'payables.create']);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_the_catalogue_only_lists_entities_the_user_may_write(): void
    {
        $this->actingAsPayablesOnlyImporter();

        $keys = collect($this->getJson('/api/imports/entities')->assertOk()->json('data'))->pluck('key');

        $this->assertContains('payable_invoice', $keys);
        // No employees.manage, so worker imports are not offered at all.
        $this->assertNotContains('employee', $keys);
    }

    public function test_a_generated_template_carries_exactly_the_headers_the_importer_reads(): void
    {
        $this->actingAsAdmin();

        $response = $this->get('/api/imports/template?entity=payable_invoice');
        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'downloaded').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        $sheet = IOFactory::createReaderForFile($path)->load($path)->getSheet(0);
        $headers = array_filter($sheet->rangeToArray('A1:Z1', null, true, false)[0] ?? []);

        $expected = array_map(
            fn (ImportColumn $column): string => $column->header,
            ImportCatalogue::find('payable_invoice')->columns,
        );

        $this->assertSame($expected, array_values($headers));
        @unlink($path);
    }

    public function test_a_filled_in_template_previews_and_imports(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin();

        $file = $this->templateFile('payable_invoice', [
            ['Acme Doo', 'F-1', '2026-03-06', '2026-04-06', 'Parts', 'parts', 'EUR', '1250.00', '250.00', null],
        ]);

        $batch = $this->postJson('/api/imports', ['file' => $file, 'entity' => 'payable_invoice'])
            ->assertCreated()->json('data');

        $this->assertSame('payable_invoice', $batch['entity']);
        $this->assertSame(1, $batch['totals']['rows']);
        $this->assertSame(0, $batch['totals']['invalid']);

        $this->postJson("/api/imports/{$batch['id']}/commit")->assertOk();

        $invoice = PayableInvoice::query()->firstOrFail();
        $this->assertSame('F-1', $invoice->invoice_number);
        $this->assertSame('2026-04-06', $invoice->due_date->toDateString());
        $this->assertSame('1250.00', $invoice->original_amount);
        // The "already paid" column became a real payment, so the balance is derived.
        $this->assertSame('250.00', $invoice->paid_amount);
        $this->assertSame('partial', $invoice->status);
    }

    public function test_invalid_rows_are_reported_and_valid_ones_still_import(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin();

        $file = $this->templateFile('payable_invoice', [
            ['Acme Doo', 'F-1', '2026-03-06', null, null, null, 'EUR', '1250.00', null, null],
            // No supplier and an amount that is not a number.
            [null, 'F-2', '2026-03-06', null, null, null, 'EUR', 'twelve', null, null],
        ]);

        $batch = $this->postJson('/api/imports', ['file' => $file, 'entity' => 'payable_invoice'])
            ->assertCreated()->json('data');

        $this->assertSame(2, $batch['totals']['rows']);
        $this->assertSame(1, $batch['totals']['invalid']);

        $rows = collect($this->getJson("/api/imports/{$batch['id']}")->json('rows'));
        $bad = $rows->firstWhere('row_number', 3);

        $this->assertSame('skip', $bad['action'], 'an invalid row must not be armed for import');
        $this->assertNotEmpty(collect($bad['issues'])->where('type', 'invalid'));

        $this->postJson("/api/imports/{$batch['id']}/commit")->assertOk();

        // The good row landed; the bad one was reported, not written.
        $this->assertSame(1, PayableInvoice::count());
        $this->assertSame('F-1', PayableInvoice::query()->value('invoice_number'));
    }

    public function test_a_file_missing_a_required_column_is_refused_as_a_whole(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin();

        // The Amount column has been deleted from the template.
        $file = $this->templateFile(
            'payable_invoice',
            [['Acme Doo', 'F-1', '2026-03-06']],
            ['Supplier', 'Invoice number', 'Invoice date'],
        );

        $batch = $this->postJson('/api/imports', ['file' => $file, 'entity' => 'payable_invoice'])
            ->assertCreated()->json('data');

        $this->assertSame(0, $batch['totals']['rows']);
        $this->assertStringContainsString('Amount', $batch['error']);
    }

    public function test_headers_may_be_reordered_and_still_read(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin();

        $file = $this->templateFile(
            'payable_invoice',
            [['1250.00', '2026-03-06', 'Acme Doo']],
            ['Amount', 'Invoice date', 'Supplier'],
        );

        $batch = $this->postJson('/api/imports', ['file' => $file, 'entity' => 'payable_invoice'])
            ->assertCreated()->json('data');

        $this->postJson("/api/imports/{$batch['id']}/commit")->assertOk();

        $this->assertSame('Acme Doo', Supplier::query()->value('name'));
        $this->assertSame('1250.00', PayableInvoice::query()->value('original_amount'));
    }

    public function test_choosing_an_entity_requires_that_module_s_permission(): void
    {
        Storage::fake('local');
        $this->actingAsPayablesOnlyImporter();

        $file = $this->templateFile('employee', [['Ahmet', 'Yilmaz']], ['First name', 'Last name']);

        // `imports.manage` opens the module; writing workers still needs its own.
        $this->postJson('/api/imports', ['file' => $file, 'entity' => 'employee'])->assertForbidden();
        $this->get('/api/imports/template?entity=employee')->assertForbidden();
    }

    public function test_a_worker_who_already_exists_is_flagged_rather_than_duplicated(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin();

        Employee::factory()->create(['first_name' => 'Ahmet', 'last_name' => 'Yilmaz']);

        $file = $this->templateFile('employee', [['Ahmet', 'Yilmaz']], ['First name', 'Last name']);

        $batch = $this->postJson('/api/imports', ['file' => $file, 'entity' => 'employee'])
            ->assertCreated()->json('data');

        $this->assertSame(1, $batch['totals']['duplicates']);

        $row = collect($this->getJson("/api/imports/{$batch['id']}")->json('rows'))->first();
        $this->assertSame('skip', $row['action']);
    }
}
