<?php

namespace App\Services;

use App\Models\PayableInvoice;
use App\Models\Payment;
use App\Models\ReceivableDeduction;
use App\Models\ReceivableInvoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adding, correcting and removing the lines that settle an invoice.
 *
 * A settled invoice is not a closed one: a wrong amount, a payment entered
 * twice or a payment against the wrong invoice all have to be fixable after the
 * fact. The rule this service keeps is that a correction *edits the line that
 * was wrong* — it never books a compensating opposite entry, because two rows
 * that cancel out would both show up as real movements in every report and in
 * the bank match.
 *
 * The one invariant everything here defends: the lines settling an invoice can
 * never add up to more than the invoice. Paid/remaining/status stay derived
 * (rule 1) — they are recomputed from the lines after every change, never set.
 */
class InvoiceSettlementService
{
    /** Rounding slack, so 0.1 + 0.2 never reads as an overpayment. */
    private const TOLERANCE = 0.001;

    /**
     * Record a new payment against an invoice.
     *
     * @param  array<string, mixed>  $data
     */
    public function recordPayment(PayableInvoice|ReceivableInvoice $invoice, array $data): Payment
    {
        $this->assertFits($invoice, (float) $data['amount'], $this->settled($invoice));

        return DB::transaction(function () use ($invoice, $data): Payment {
            $payment = $invoice->payments()->create($data);
            $invoice->recalculate();

            return $payment;
        });
    }

    /**
     * Correct an existing payment in place.
     *
     * @param  array<string, mixed>  $data
     */
    public function updatePayment(
        PayableInvoice|ReceivableInvoice $invoice,
        Payment $payment,
        array $data,
    ): Payment {
        $this->assertBelongsTo($invoice, $payment);

        $amount = (float) ($data['amount'] ?? $payment->amount);
        // The line being edited is taken out of the total it is measured against,
        // so raising a payment from 100 to 120 is judged on the other lines only.
        $this->assertFits($invoice, $amount, $this->settled($invoice) - (float) $payment->amount);

        return DB::transaction(function () use ($invoice, $payment, $data): Payment {
            $payment->update($data);
            $invoice->recalculate();

            return $payment->refresh();
        });
    }

    /**
     * Remove a payment — the way a duplicate or misapplied entry is undone.
     *
     * Both halves of the settlement come apart together, inside one transaction:
     * the invoice's paid/remaining/status are recomputed from the lines that are
     * left (so the last payment leaving flips it back to unpaid), and the bank
     * movement this payment was matched to stops being matched — the link lives
     * on the payment row, so it goes with it rather than being cleaned up
     * afterwards by a second write that could fail on its own.
     *
     * Whether that movement is also *deleted* is the caller's decision, and it
     * defaults to no. A matched movement is usually a line the operator entered
     * from a real bank statement: the money did leave the account whatever
     * happens to the invoice it was pointed at, and erasing it would put the
     * app's balance out of step with the bank's. `$deleteBankTransaction` is for
     * the other case — the movement was only ever entered to record this
     * payment, and without it the row means nothing.
     *
     * @param  bool  $deleteBankTransaction  also remove the matched bank movement
     *
     * @throws ValidationException when that movement still settles other invoices
     */
    public function deletePayment(
        PayableInvoice|ReceivableInvoice $invoice,
        Payment $payment,
        bool $deleteBankTransaction = false,
    ): void {
        $this->assertBelongsTo($invoice, $payment);

        DB::transaction(function () use ($invoice, $payment, $deleteBankTransaction): void {
            // Resolved before the payment goes: afterwards there is nothing left
            // to read the link from.
            $transaction = $payment->bankTransaction;

            $payment->delete();
            $invoice->recalculate();

            if (! $deleteBankTransaction || ! $transaction) {
                return;
            }

            // Any payment still pointing at it is another invoice this movement
            // settles. Deleting it would silently unpay that one too, so the
            // request is refused and the whole transaction rolls back.
            if ($transaction->payments()->exists()) {
                throw ValidationException::withMessages([
                    'delete_bank_transaction' => [
                        'That bank movement still settles other invoices. Remove those payments first, or '
                        .'keep the movement and only remove this payment.',
                    ],
                ]);
            }

            $transaction->delete();
        });
    }

    /** @param array<string, mixed> $data */
    public function recordDeduction(ReceivableInvoice $invoice, array $data): ReceivableDeduction
    {
        $this->assertFits($invoice, (float) $data['amount'], $this->settled($invoice));

        return DB::transaction(function () use ($invoice, $data): ReceivableDeduction {
            $deduction = $invoice->deductions()->create($data);
            $invoice->recalculate();

            return $deduction;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateDeduction(
        ReceivableInvoice $invoice,
        ReceivableDeduction $deduction,
        array $data,
    ): ReceivableDeduction {
        $this->assertOwnsDeduction($invoice, $deduction);

        $amount = (float) ($data['amount'] ?? $deduction->amount);
        $this->assertFits($invoice, $amount, $this->settled($invoice) - (float) $deduction->amount);

        return DB::transaction(function () use ($invoice, $deduction, $data): ReceivableDeduction {
            $deduction->update($data);
            $invoice->recalculate();

            return $deduction->refresh();
        });
    }

    public function deleteDeduction(ReceivableInvoice $invoice, ReceivableDeduction $deduction): void
    {
        $this->assertOwnsDeduction($invoice, $deduction);

        DB::transaction(function () use ($invoice, $deduction): void {
            $deduction->delete();
            $invoice->recalculate();
        });
    }

    /**
     * Guard an edit of the invoice itself: its amount may be corrected freely,
     * including on a paid invoice, but not below what has already been settled.
     *
     * Refusing rather than silently allowing a negative balance is the point —
     * the user is told to correct the payment that is wrong, which leaves a
     * history that still adds up.
     *
     * @param  array<string, mixed>  $data  the validated update payload
     */
    public function assertAmountCoversSettlements(PayableInvoice|ReceivableInvoice $invoice, array $data): void
    {
        $field = $invoice instanceof PayableInvoice ? 'original_amount' : 'invoice_amount';

        if (! array_key_exists($field, $data)) {
            return;
        }

        $settled = round($this->settled($invoice), 2);

        if ((float) $data[$field] + self::TOLERANCE >= $settled) {
            return;
        }

        throw ValidationException::withMessages([
            $field => [sprintf(
                'This invoice already has %s settled against it. Correct or remove those entries before '
                .'lowering the amount below them.',
                number_format($settled, 2),
            )],
        ]);
    }

    /** What has been settled so far: payments, plus deductions on a receivable. */
    private function settled(PayableInvoice|ReceivableInvoice $invoice): float
    {
        $settled = (float) $invoice->payments()->sum('amount');

        if ($invoice instanceof ReceivableInvoice) {
            $settled += (float) $invoice->deductions()->sum('amount');
        }

        return round($settled, 2);
    }

    private function invoiceAmount(PayableInvoice|ReceivableInvoice $invoice): float
    {
        return round(
            (float) ($invoice instanceof PayableInvoice ? $invoice->original_amount : $invoice->invoice_amount),
            2,
        );
    }

    private function assertFits(PayableInvoice|ReceivableInvoice $invoice, float $amount, float $alreadySettled): void
    {
        $headroom = round($this->invoiceAmount($invoice) - $alreadySettled, 2);

        if ($amount <= $headroom + self::TOLERANCE) {
            return;
        }

        throw ValidationException::withMessages([
            'amount' => [sprintf('Amount exceeds the remaining balance (%s).', number_format(max($headroom, 0), 2))],
        ]);
    }

    private function assertBelongsTo(PayableInvoice|ReceivableInvoice $invoice, Payment $payment): void
    {
        $this->assertOwnership(
            $payment->payable_type === $invoice::class && (int) $payment->payable_id === (int) $invoice->id,
            $payment,
        );
    }

    private function assertOwnsDeduction(ReceivableInvoice $invoice, ReceivableDeduction $deduction): void
    {
        $this->assertOwnership((int) $deduction->receivable_invoice_id === (int) $invoice->id, $deduction);
    }

    /** A line reached through the wrong invoice is not found, never someone else's to edit. */
    private function assertOwnership(bool $owns, Model $line): void
    {
        abort_unless($owns, 404, 'This entry does not belong to that invoice.');
    }
}
