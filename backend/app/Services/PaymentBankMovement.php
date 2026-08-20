<?php

namespace App\Services;

use App\Contracts\BooksBankMovement;
use App\Contracts\SettlementLine;
use App\Models\BankTransaction;

/**
 * The bank or cash movement behind a settlement.
 *
 * Why this exists: every money figure on the dashboard — the three account
 * balances, the income/expense split, the cashflow trend, the recent list — is
 * summed from `bank_transactions` and from nothing else. Recording a settlement
 * against its own module wrote a `payments` row and stopped there, so telling
 * the app that 95,000 EUR had arrived in NLB moved the invoice to `paid` and
 * left every balance exactly where it was. The money existed to the receivables
 * module and nowhere else — and the same held for paying rent, a utility bill,
 * a worker's salary, a plane ticket, a travel expense, a loan instalment or a
 * social assistance payout.
 *
 * There are two honest ways to close that gap, and the operator picks per
 * settlement because they are different claims about the world:
 *
 * - **Book it.** The settlement is itself the record that money moved. This
 *   class writes the movement, stamps it {@see SOURCE}, and from then on it
 *   follows its line: correcting the amount corrects it, deleting the line
 *   deletes it.
 * - **Match it.** The movement was already typed off a real bank statement and
 *   the settlement merely points at it. Nothing here ever rewrites such a row —
 *   the money moved whatever later happens to the obligation, and editing it to
 *   follow one would put the app's balance out of step with the bank's.
 *
 * `source` is what tells the two apart. A generated row carries the marker
 * rather than being recognised by "has a settlement attached", because a
 * hand-entered movement acquires those too the moment it is matched.
 *
 * Nothing here knows what is being settled: the record answers that through
 * {@see BooksBankMovement} and the line through {@see SettlementLine}, which is
 * why one class serves all nine modules.
 */
class PaymentBankMovement
{
    /** Marks a movement this app wrote from a settlement, not one an operator typed. */
    public const SOURCE = 'settlement';

    /**
     * Every marker that means "generated". `invoice_payment` is what the column
     * held while payables and receivables were the only modules that could book
     * one; those rows are still owned by their payment and must keep being
     * recognised, so the value is read as legacy rather than migrated.
     *
     * @var list<string>
     */
    public const GENERATED_SOURCES = [self::SOURCE, 'invoice_payment'];

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

    /** Whether this movement was written from a settlement (and so may be rewritten with it). */
    public static function isGenerated(?BankTransaction $movement): bool
    {
        return $movement !== null && in_array($movement->source, self::GENERATED_SOURCES, true);
    }

    /**
     * Whether this line is the one that wrote this movement.
     *
     * `isGenerated()` alone answers "was this row written from *a* settlement",
     * not "from *this* one" — and that gap was enough to lose real money. A
     * second line pointed at a generated row could rewrite it to its own
     * (smaller) amount, delete it by unlinking, or invert its direction, while
     * the record that booked it still read as settled.
     */
    public static function isOwnedBy(?BankTransaction $movement, SettlementLine $line): bool
    {
        if (! self::isGenerated($movement)) {
            return false;
        }

        return ! $line->movementHasOtherClaims($movement);
    }

    /**
     * Write the movement a settlement records, and link the line to it.
     *
     * The direction is the record's, not the operator's to state: money arriving
     * against a receivable is income, money leaving for rent, a wage or a ticket
     * is an expense. The magnitude entered on the line is the magnitude stored —
     * only its sign comes from elsewhere — so a receipt can never be booked as a
     * withdrawal.
     */
    public function create(BooksBankMovement $record, SettlementLine $line): BankTransaction
    {
        $movement = BankTransaction::create([
            'date' => $line->lineDate(),
            'category' => $record->movementCategory(),
            'currency' => $this->currency($record, $line),
            'exchange_rate' => $line->lineExchangeRate(),
            'exchange_rate_date' => $line->lineExchangeRateDate(),
            'source' => self::SOURCE,
            ...$record->movementParty(),
            ...$this->describe($record, $line),
            ...$this->amounts($record, $line),
        ]);

        $line->linkMovement($movement->id);

        return $movement;
    }

    /**
     * Carry an edited settlement through to the movement it generated.
     *
     * Only ever touches a row this class wrote. A line pointing at a
     * hand-entered movement is left alone, and so is a line pointing at nothing
     * — both are correct states, not stale ones.
     *
     * @param  BankTransaction|null  $previous  what the line was linked to before
     *                                          the edit, so a generated row it has
     *                                          just been pointed away from can go
     *                                          with the link instead of being
     *                                          stranded on the dashboard
     */
    public function sync(
        BooksBankMovement $record,
        SettlementLine $line,
        ?BankTransaction $previous = null,
    ): void {
        // Read through the id rather than a relation: the line was just updated,
        // and a cached relation would still hold the old movement.
        $movement = $this->linked($line);

        if (self::isOwnedBy($previous, $line) && (int) $previous->id !== (int) $line->linkedMovementId()) {
            $previous->delete();
        }

        if (! self::isOwnedBy($movement, $line)) {
            return;
        }

        // A settlement switched to `other` no longer claims to have moved money
        // through an account, so the movement it wrote is withdrawn rather than
        // left behind at its old amount.
        if (! self::supports($line->lineMethod())) {
            $line->linkMovement(null);
            $movement->delete();

            return;
        }

        $movement->update([
            'date' => $line->lineDate(),
            'category' => $record->movementCategory(),
            'currency' => $this->currency($record, $line),
            'exchange_rate' => $line->lineExchangeRate(),
            'exchange_rate_date' => $line->lineExchangeRateDate(),
            ...$this->describe($record, $line),
            // Every column is rewritten, not just the one in use: moving a
            // settlement from NLB to cash has to empty the column it left.
            ...$this->amounts($record, $line),
        ]);
    }

    /**
     * Remove the movement a settlement generated, if it generated one.
     *
     * Used when the line itself goes. Returns the id that was removed so the
     * caller can record it, since afterwards there is nothing left to read.
     */
    public function discard(SettlementLine $line): ?int
    {
        $movement = $this->linked($line);

        if (! self::isOwnedBy($movement, $line)) {
            return null;
        }

        $id = $movement->id;
        $movement->delete();

        return $id;
    }

    /** The movement a line currently points at, read fresh from its id. */
    public function linked(SettlementLine $line): ?BankTransaction
    {
        $id = $line->linkedMovementId();

        return $id === null ? null : BankTransaction::find($id);
    }

    /**
     * The line's currency, falling back to the record's.
     *
     * A line created without one takes the column default in the database and so
     * reads as null on the model that was just written — the movement would then
     * be inserted with an explicit null against a NOT NULL column.
     */
    private function currency(BooksBankMovement $record, SettlementLine $line): string
    {
        return $line->lineCurrency() ?: ($record->movementCurrency() ?: 'EUR');
    }

    /**
     * The movement's own text, taken from the records rather than composed.
     *
     * Nothing here writes a sentence: an invoice number, a house name, a
     * worker's name and the line's reference are data the operator typed, so the
     * row reads the same in all three languages (rule 4) and no generated prose
     * ends up in a financial record.
     *
     * @return array<string, string|null>
     */
    private function describe(BooksBankMovement $record, SettlementLine $line): array
    {
        return [
            'description_1' => $record->movementDescription(),
            'description_2' => $line->lineReference(),
        ];
    }

    /**
     * The line's amount in its account's column, every other column zeroed, with
     * the record's direction as its sign.
     *
     * The sign is applied here rather than left to the category, because only
     * `income` and `expense` normalise themselves: a `payroll` or `housing` row
     * keeps whatever sign it is given, and an unsigned one would be added to the
     * balance instead of taken off it.
     *
     * @return array<string, float>
     */
    private function amounts(BooksBankMovement $record, SettlementLine $line): array
    {
        $columns = array_fill_keys(BankTransaction::AMOUNT_COLUMNS, 0.0);
        $sign = $record->movementDirection() < 0 ? -1 : 1;
        $columns[self::ACCOUNT_COLUMNS[$line->lineMethod()]] = $sign * abs($line->lineAmount());

        return $columns;
    }
}
