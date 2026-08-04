<?php

namespace App\Services;

use App\Models\BankTransaction;
use App\Models\PayableInvoice;
use App\Models\Payment;
use App\Models\ReceivableInvoice;

/**
 * The bank or cash movement behind an invoice payment.
 *
 * Why this exists: every money figure on the dashboard — the three account
 * balances, the income/expense split, the cashflow trend, the recent list — is
 * summed from `bank_transactions` and from nothing else. Recording a payment
 * against an invoice wrote a `payments` row and stopped there, so telling the
 * app that 95,000 EUR had arrived in NLB moved the invoice to `paid` and left
 * every balance exactly where it was. The money existed to the receivables
 * module and nowhere else.
 *
 * There are two honest ways to close that gap, and the operator picks per
 * payment because they are different claims about the world:
 *
 * - **Book it.** The payment is itself the record that money moved. This class
 *   writes the movement, stamps it `source = invoice_payment`, and from then on
 *   it follows its payment: correcting the amount corrects it, deleting the
 *   payment deletes it.
 * - **Match it.** The movement was already typed off a real bank statement and
 *   the payment merely points at it. Nothing here ever rewrites such a row —
 *   the money moved whatever later happens to the invoice, and editing it to
 *   follow an invoice would put the app's balance out of step with the bank's.
 *
 * `source` is what tells the two apart. A generated row carries the marker
 * rather than being recognised by "has a payment attached", because a
 * hand-entered movement acquires payments too the moment it is matched.
 */
class PaymentBankMovement
{
    /** Marks a movement this app wrote from a payment, not one an operator typed. */
    public const SOURCE = 'invoice_payment';

    /**
     * Payment methods that name an account column. `other` names none — it is
     * how a settlement that did not move money through these three accounts (an
     * offset, a correction, cash outside the till) is recorded, so there is
     * nothing to book and {@see supports()} says so.
     *
     * @var array<string, string>
     */
    public const ACCOUNT_COLUMNS = [
        'cash' => 'cash_amount',
        'nlb' => 'nlb_amount',
        'lovcen' => 'lovcen_amount',
    ];

    /** Whether a payment method corresponds to an account a movement can be booked against. */
    public static function supports(?string $method): bool
    {
        return $method !== null && array_key_exists($method, self::ACCOUNT_COLUMNS);
    }

    /** Whether this movement was written from a payment (and so may be rewritten with it). */
    public static function isGenerated(?BankTransaction $movement): bool
    {
        return $movement !== null && $movement->source === self::SOURCE;
    }

    /**
     * Write the movement a payment records, and link the payment to it.
     *
     * The direction is the invoice's, not the operator's to state: money
     * arriving against a receivable is income, money leaving against a payable
     * is an expense. The sign follows from that category — BankTransaction
     * normalises it on save — so the magnitude entered on the payment is the
     * magnitude stored, and a receipt can never be booked as a withdrawal.
     */
    public function create(PayableInvoice|ReceivableInvoice $invoice, Payment $payment): BankTransaction
    {
        $movement = BankTransaction::create([
            'date' => $payment->payment_date,
            'category' => $this->category($invoice),
            'currency' => $this->currency($invoice, $payment),
            'source' => self::SOURCE,
            ...$this->party($invoice),
            ...$this->describe($invoice, $payment),
            ...$this->amounts($payment),
        ]);

        $payment->update(['bank_transaction_id' => $movement->id]);

        return $movement;
    }

    /**
     * Carry an edited payment through to the movement it generated.
     *
     * Only ever touches a row this class wrote. A payment pointing at a
     * hand-entered movement is left alone, and so is a payment pointing at
     * nothing — both are correct states, not stale ones.
     *
     * @param  BankTransaction|null  $previous  what the payment was linked to before
     *                                          the edit, so a generated row it has
     *                                          just been pointed away from can go
     *                                          with the link instead of being
     *                                          stranded on the dashboard
     */
    public function sync(
        PayableInvoice|ReceivableInvoice $invoice,
        Payment $payment,
        ?BankTransaction $previous = null,
    ): void {
        // Read through the id rather than the relation: the payment was just
        // updated, and a cached relation would still hold the old movement.
        $movement = $this->linked($payment);

        if (self::isGenerated($previous) && (int) $previous->id !== (int) $payment->bank_transaction_id) {
            $previous->delete();
        }

        if (! self::isGenerated($movement)) {
            return;
        }

        // A payment switched to `other` no longer claims to have moved money
        // through an account, so the movement it wrote is withdrawn rather than
        // left behind at its old amount.
        if (! self::supports($payment->method)) {
            $payment->update(['bank_transaction_id' => null]);
            $movement->delete();

            return;
        }

        $movement->update([
            'date' => $payment->payment_date,
            'currency' => $this->currency($invoice, $payment),
            ...$this->describe($invoice, $payment),
            // Every column is rewritten, not just the one in use: moving a
            // payment from NLB to cash has to empty the column it left.
            ...$this->amounts($payment),
        ]);
    }

    /**
     * Remove the movement a payment generated, if it generated one.
     *
     * Used when the payment itself goes. Returns the id that was removed so the
     * caller can record it, since afterwards there is nothing left to read.
     */
    public function discard(Payment $payment): ?int
    {
        $movement = $this->linked($payment);

        if (! self::isGenerated($movement)) {
            return null;
        }

        $id = $movement->id;
        $movement->delete();

        return $id;
    }

    /** The movement a payment currently points at, read fresh from its id. */
    public function linked(Payment $payment): ?BankTransaction
    {
        return $payment->bank_transaction_id === null
            ? null
            : BankTransaction::find($payment->bank_transaction_id);
    }

    private function category(PayableInvoice|ReceivableInvoice $invoice): string
    {
        return $invoice instanceof ReceivableInvoice ? 'income' : 'expense';
    }

    /**
     * The payment's currency, falling back to the invoice's.
     *
     * A payment created without one takes the column default in the database
     * and so reads as null on the model that was just written — the movement
     * would then be inserted with an explicit null against a NOT NULL column.
     */
    private function currency(PayableInvoice|ReceivableInvoice $invoice, Payment $payment): string
    {
        return $payment->currency ?: ($invoice->currency ?: 'EUR');
    }

    /** @return array<string, int|null> */
    private function party(PayableInvoice|ReceivableInvoice $invoice): array
    {
        return $invoice instanceof ReceivableInvoice
            ? ['client_id' => $invoice->client_id]
            : ['supplier_id' => $invoice->supplier_id];
    }

    /**
     * The movement's own text, taken from the records rather than composed.
     *
     * Nothing here writes a sentence: an invoice number, the invoice's own
     * description and the payment's reference are data the operator typed, so
     * the row reads the same in all three languages (rule 4) and no generated
     * prose ends up in a financial record.
     *
     * @return array<string, string|null>
     */
    private function describe(PayableInvoice|ReceivableInvoice $invoice, Payment $payment): array
    {
        return [
            'description_1' => $invoice->invoice_number ?: $invoice->description,
            'description_2' => $payment->reference,
        ];
    }

    /**
     * The payment's amount in its account's column, every other column zeroed.
     *
     * @return array<string, float>
     */
    private function amounts(Payment $payment): array
    {
        $columns = array_fill_keys(BankTransaction::AMOUNT_COLUMNS, 0.0);
        $columns[self::ACCOUNT_COLUMNS[$payment->method]] = abs((float) $payment->amount);

        return $columns;
    }
}
