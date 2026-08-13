<?php

namespace App\Services;

use App\Models\PayableInvoice;
use App\Models\Payment;
use App\Models\ReceivableDeduction;
use App\Models\ReceivableInvoice;
use App\Support\Currencies;
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
 *
 * A settlement may also be the record that money moved through an account, in
 * which case the bank/cash movement is written, corrected and removed alongside
 * the payment, inside the same transaction — see {@see PaymentBankMovement} for
 * why that is opt-in per payment rather than automatic.
 */
class InvoiceSettlementService
{
    /** Rounding slack, so 0.1 + 0.2 never reads as an overpayment. */
    private const TOLERANCE = 0.001;

    public function __construct(private readonly PaymentBankMovement $movements) {}

    /**
     * Record a new payment against an invoice.
     *
     * @param  array<string, mixed>  $data
     * @param  bool  $bookMovement  also write the bank/cash movement this payment
     *                              records, and match the payment to it
     */
    public function recordPayment(
        PayableInvoice|ReceivableInvoice $invoice,
        array $data,
        bool $bookMovement = false,
    ): Payment {
        $this->assertFits($invoice, $this->amountInEur($invoice, $data), $this->settled($invoice));

        return DB::transaction(function () use ($invoice, $data, $bookMovement): Payment {
            $payment = $invoice->payments()->create($data);

            if ($bookMovement) {
                $this->movements->create($invoice, $payment);
            }

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
        bool $book = false,
    ): Payment {
        $this->assertBelongsTo($invoice, $payment);

        $amount = $this->amountInEur($invoice, $data, $payment);
        // The line being edited is taken out of the total it is measured against,
        // so raising a payment from 100 to 120 is judged on the other lines only.
        $this->assertFits($invoice, $amount, $this->settled($invoice) - (float) $payment->amount_eur);

        return DB::transaction(function () use ($invoice, $payment, $data, $book): Payment {
            $before = $this->movements->linked($payment);

            $payment->update($data);
            // A movement this payment generated follows the correction; one the
            // operator typed off a statement is left exactly as the bank has it.
            $this->movements->sync($invoice, $payment, $before);

            // Booking after the fact, for a payment first recorded without one:
            // the alternative was deleting the line and re-entering it, which
            // loses its author and its audit history.
            if ($book && $payment->fresh()->bank_transaction_id === null) {
                $this->movements->create($invoice, $payment);
            }

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
     * Whether that movement is also *deleted* depends on where it came from:
     *
     * - One this app generated from the payment ({@see PaymentBankMovement})
     *   always goes with it. It was never independent evidence — it existed to
     *   say "this payment moved money", and a movement left behind after its
     *   payment is removed would keep inflating every balance on the dashboard
     *   with money nothing accounts for.
     * - One the operator typed off a real bank statement stays by default. The
     *   money did leave the account whatever happens to the invoice it was
     *   pointed at, and erasing it would put the app's balance out of step with
     *   the bank's. `$deleteBankTransaction` is the caller's override for when
     *   that row was only ever entered to record this payment.
     *
     * @param  bool  $deleteBankTransaction  also remove a hand-entered matched movement
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
            // Ownership, not merely "generated": a generated row that another
            // payment also points at is not this payment's to take away. Asking
            // to remove it unconditionally made the refusal below fire for both
            // payments, so neither could ever be deleted.
            $remove = $deleteBankTransaction || PaymentBankMovement::isOwnedBy($transaction, $payment);

            $payment->delete();
            $invoice->recalculate();

            if (! $remove || ! $transaction) {
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

    /**
     * Drop every payment settling an invoice, on the way to deleting it.
     *
     * The movements those payments generated go with them, for the reason above
     * — otherwise deleting an invoice would leave money on the dashboard that
     * no record explains. Movements the operator typed off a statement are only
     * released (the FK nulls the link), because they are the bank's record and
     * not this invoice's to erase.
     *
     * @return list<int> ids of the generated movements that were removed
     */
    public function releasePayments(PayableInvoice|ReceivableInvoice $invoice): array
    {
        $removed = [];

        foreach ($invoice->payments()->get() as $payment) {
            $id = $this->movements->discard($payment);

            if ($id !== null) {
                $removed[] = $id;
            }

            $payment->delete();
        }

        return $removed;
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

        // The settled total is EUR, so the proposed new amount is priced the
        // same way before they are compared — otherwise lowering a TRY invoice
        // would be judged against a EUR figure.
        $proposed = (float) app(CurrencyConverter::class)->toEur(
            $data[$field],
            (string) ($data['currency'] ?? $invoice->currency ?? Currencies::BASE),
            $data['exchange_rate'] ?? $invoice->exchange_rate,
            optional($invoice->invoice_date)->toDateString(),
        )['amount_eur'];

        if ($proposed + self::TOLERANCE >= $settled) {
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
        // EUR, matching recalculate() and the invoice's own amount_eur. Summing
        // the original-currency column here would compare TRY against EUR the
        // moment an invoice or a payment was not in the accounting currency.
        $settled = (float) $invoice->payments()->sum('amount_eur');

        if ($invoice instanceof ReceivableInvoice) {
            $settled += (float) $invoice->deductions()->sum('amount');
        }

        return round($settled, 2);
    }

    /** The invoice's total in EUR — what every headroom check measures against. */
    private function invoiceAmount(PayableInvoice|ReceivableInvoice $invoice): float
    {
        return round((float) $invoice->amount_eur, 2);
    }

    /**
     * A payment payload's value in the accounting currency.
     *
     * Everything the guards compare is EUR, so a payment stated in another
     * currency has to be priced before it can be measured against the invoice.
     * The precedence is the converter's: a rate on the request wins, otherwise
     * the newest stored one on or before the payment's date.
     *
     * @param  array<string, mixed>  $data
     */
    private function amountInEur(PayableInvoice|ReceivableInvoice $invoice, array $data, ?Payment $payment = null): float
    {
        $currency = $data['currency'] ?? $payment?->currency ?? Currencies::BASE;
        $date = $data['payment_date'] ?? optional($payment?->payment_date)->toDateString();

        return (float) app(CurrencyConverter::class)->toEur(
            $data['amount'] ?? $payment?->amount ?? 0,
            (string) $currency,
            $data['exchange_rate'] ?? $payment?->exchange_rate,
            $date === null ? null : (string) $date,
        )['amount_eur'];
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
