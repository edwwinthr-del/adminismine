<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Payment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Correcting and removing a settlement line on the modules that are not
 * payables or receivables.
 *
 * Those two were modelled properly — a payment can be edited or deleted,
 * because a wrong amount or a line entered twice is a bookkeeping error and the
 * fix is to correct the line that is wrong. Every other module that takes
 * payments (rent, utility bills, salaries, travel expenses, flight tickets,
 * loan repayments) shipped `POST` and nothing else: once a payment was in, the
 * only ways out were deleting the whole obligation — losing its history and its
 * author — or booking a compensating opposite entry, which the domain rules
 * forbid outright, because two rows that cancel out both read as real money in
 * every report and in the bank match.
 *
 * The parents all have the same shape (`remaining_amount` plus a morphed
 * payments relation and a `recalculate()`), so the rule lives here once rather
 * than being restated six times.
 */
trait CorrectsPayments
{
    /** Rounding slack, matching InvoiceSettlementService. */
    private const TOLERANCE = 0.001;

    /**
     * A payment reached through the wrong parent is not found, rather than
     * forbidden — the caller has no business knowing the row exists.
     */
    protected function assertPaymentBelongsTo(Model $parent, Payment $payment): void
    {
        $matches = (int) $payment->payable_id === (int) $parent->getKey()
            && $payment->payable_type === $parent->getMorphClass();

        abort_unless($matches, 404);
    }

    /**
     * Restate a settlement line, then rebuild the parent's figures from the
     * lines that now exist.
     *
     * The line being edited is added back to the headroom before the new amount
     * is measured, so raising a payment from 100 to 120 is judged against the
     * *other* lines — exactly as correcting an invoice payment is.
     *
     * @param  array<string, mixed>  $data
     */
    protected function correctPayment(Model $parent, Payment $payment, array $data): Payment
    {
        $this->assertPaymentBelongsTo($parent, $payment);

        $headroom = round((float) $parent->remaining_amount + (float) $payment->amount, 2);

        if (isset($data['amount']) && (float) $data['amount'] > $headroom + self::TOLERANCE) {
            throw ValidationException::withMessages([
                'amount' => [sprintf(
                    'Amount exceeds the remaining balance (%s).',
                    number_format(max($headroom, 0), 2),
                )],
            ]);
        }

        return DB::transaction(function () use ($parent, $payment, $data): Payment {
            $payment->update($data);
            $parent->recalculate();

            return $payment->refresh();
        });
    }

    /**
     * Remove a settlement line. The parent's paid/remaining/status follow from
     * what is left, so the last payment leaving reopens the obligation.
     */
    protected function removePayment(Model $parent, Payment $payment): void
    {
        $this->assertPaymentBelongsTo($parent, $payment);

        DB::transaction(function () use ($parent, $payment): void {
            $payment->delete();
            $parent->recalculate();
        });
    }
}
