<?php

namespace App\Http\Controllers\Concerns;

use App\Contracts\BooksBankMovement;
use App\Models\Payment;
use App\Services\CurrencyConverter;
use App\Services\PaymentBankMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recording, correcting and removing a settlement line on the modules that are
 * not payables or receivables: rent, utility bills, salaries, flight tickets,
 * travel expenses and loans.
 *
 * Those two invoice modules have InvoiceSettlementService; everything here is
 * the same set of rules for the other six, whose parents all have the same shape
 * (`remaining_amount` in EUR, a morphed payments relation, a `recalculate()`).
 * Keeping it in one place is what stops the modules from drifting apart — which
 * they had: a correction meant deleting the whole obligation or booking a
 * compensating opposite entry, and no module but the two invoice ones could put
 * a settlement into the bank ledger at all, so paying rent or a worker settled
 * the record while every balance on the dashboard stayed exactly where it was.
 *
 * Three rules hold for every module here:
 *
 * - **A correction edits the line that was wrong.** Never a compensating
 *   opposite entry — two rows that cancel out both read as real money in every
 *   report and in the bank match.
 * - **Headroom is measured in EUR**, on both sides, because that is what the
 *   parent's `remaining_amount` is (rule 5).
 * - **The bank movement follows its line**, and only if this app wrote it: a
 *   movement typed off a statement is the bank's record, not the module's.
 */
trait SettlesWithPayments
{
    /** Rounding slack, so 0.1 + 0.2 never reads as an overpayment. */
    private const TOLERANCE = 0.001;

    /**
     * Record a settlement against an obligation, optionally booking the bank or
     * cash movement it represents.
     *
     * @param  array<string, mixed>  $data  the validated payload, without the booking flag
     * @param  string  $currency  the currency the line is entered in — the
     *                            obligation's own for rent, bills and wages;
     *                            EUR where the module settles in EUR
     */
    protected function recordSettlement(
        Model&BooksBankMovement $parent,
        array $data,
        string $currency,
        bool $book = false,
    ): Payment {
        $data += ['currency' => $currency];

        $this->assertFits($parent, $this->settlementInEur($data, $currency), (float) $parent->remaining_amount);

        return DB::transaction(function () use ($parent, $data, $book): Payment {
            $payment = $parent->payments()->create($data);

            if ($book) {
                app(PaymentBankMovement::class)->create($parent, $payment);
            }

            $parent->recalculate();

            return $payment;
        });
    }

    /**
     * Restate a settlement line, then rebuild the obligation from the lines that
     * now exist.
     *
     * The line being edited is added back to the headroom before the new amount
     * is measured, so raising a payment from 100 to 120 is judged against the
     * *other* lines — exactly as correcting an invoice payment is.
     *
     * @param  array<string, mixed>  $data
     * @param  bool  $book  book a movement for a line that was recorded without
     *                      one; correcting a line that already has one always
     *                      carries the correction through instead
     */
    protected function correctSettlement(
        Model&BooksBankMovement $parent,
        Payment $payment,
        array $data,
        bool $book = false,
    ): Payment {
        $this->assertPaymentBelongsTo($parent, $payment);

        $amount = $this->settlementInEur($data, (string) ($data['currency'] ?? $payment->currency), $payment);
        $this->assertFits(
            $parent,
            $amount,
            round((float) $parent->remaining_amount + (float) $payment->amount_eur, 2),
        );

        return DB::transaction(function () use ($parent, $payment, $data, $book): Payment {
            $movements = app(PaymentBankMovement::class);
            $before = $movements->linked($payment);

            $payment->update($data);
            // A movement this line generated follows the correction; one the
            // operator typed off a statement is left exactly as the bank has it.
            $movements->sync($parent, $payment, $before);

            // Booking after the fact, for a line first recorded without one: the
            // alternative was deleting it and re-entering it, which loses its
            // author and its audit history.
            if ($book && $payment->fresh()->bank_transaction_id === null) {
                $movements->create($parent, $payment);
            }

            $parent->recalculate();

            return $payment->refresh();
        });
    }

    /**
     * Remove a settlement line. The obligation's paid/remaining/status follow
     * from what is left, so the last one leaving reopens it.
     *
     * A movement this app generated goes with the line — it was never
     * independent evidence, and one left behind would keep inflating the
     * dashboard with money nothing accounts for. A movement typed off a
     * statement stays, and merely stops being matched.
     *
     * @return int|null the movement that was removed with it, if any
     */
    protected function removeSettlement(Model&BooksBankMovement $parent, Payment $payment): ?int
    {
        $this->assertPaymentBelongsTo($parent, $payment);

        return DB::transaction(function () use ($parent, $payment): ?int {
            $removed = app(PaymentBankMovement::class)->discard($payment);

            $payment->delete();
            $parent->recalculate();

            return $removed;
        });
    }

    /**
     * Drop every settlement on an obligation, on the way to deleting it.
     *
     * The movements those lines generated go with them, for the same reason:
     * deleting an obligation must not leave money on the dashboard that no
     * record explains. Movements typed off a statement are only released.
     *
     * @return list<int> ids of the generated movements that were removed
     */
    protected function releaseSettlements(Model $parent): array
    {
        $movements = app(PaymentBankMovement::class);
        $removed = [];

        foreach ($parent->payments()->get() as $payment) {
            $id = $movements->discard($payment);

            if ($id !== null) {
                $removed[] = $id;
            }

            $payment->delete();
        }

        return $removed;
    }

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
     * What a payload is worth in the accounting currency — the only currency the
     * headroom checks speak.
     *
     * @param  array<string, mixed>  $data
     */
    private function settlementInEur(array $data, string $currency, ?Payment $payment = null): float
    {
        $date = $data['payment_date'] ?? optional($payment?->payment_date)->toDateString();

        return (float) app(CurrencyConverter::class)->toEur(
            $data['amount'] ?? $payment?->amount ?? 0,
            $currency,
            $data['exchange_rate'] ?? $payment?->exchange_rate,
            $date === null ? null : (string) $date,
        )['amount_eur'];
    }

    private function assertFits(Model $parent, float $amount, float $headroom): void
    {
        if ($amount <= $headroom + self::TOLERANCE) {
            return;
        }

        throw ValidationException::withMessages([
            'amount' => [sprintf('Amount exceeds the remaining balance (%s).', number_format(max($headroom, 0), 2))],
        ]);
    }
}
