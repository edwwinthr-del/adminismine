<?php

namespace App\Contracts;

use App\Models\BankTransaction;

/**
 * One line that settles something: a polymorphic `payments` row, or a payout
 * that is itself the whole settlement (social assistance, which has no separate
 * obligation to point at).
 *
 * It carries what the movement needs about the money — when, how much, in which
 * currency, through which account — plus the link to the movement it booked or
 * matched. {@see BooksBankMovement} supplies the rest: category, direction and
 * counterparty.
 */
interface SettlementLine
{
    /** The date the money moved, as `Y-m-d`. */
    public function lineDate(): ?string;

    /** The amount in the line's own currency, unsigned — direction is the record's. */
    public function lineAmount(): float;

    public function lineCurrency(): ?string;

    /** The rate this line was priced with, so the movement prices it identically. */
    public function lineExchangeRate(): int|float|string|null;

    public function lineExchangeRateDate(): ?string;

    /** `cash`, `nlb`, `lovcen` or `other` — `other` names no account and books nothing. */
    public function lineMethod(): ?string;

    /** The operator's own reference for this line, copied to the movement. */
    public function lineReference(): ?string;

    public function linkedMovementId(): ?int;

    /** Point this line at a movement, or at none. Persists immediately. */
    public function linkMovement(?int $movementId): void;

    /**
     * Whether any settlement line other than this one points at the movement.
     *
     * A generated movement belongs to exactly one line: it exists to say "this
     * settlement moved money". If a second line also claims it, neither may
     * rewrite or delete it — that gap is how a movement could be rewritten to a
     * smaller amount while the record that booked it still read as settled.
     * Both kinds of line are counted, since a payment and a payout can point at
     * the same row.
     */
    public function movementHasOtherClaims(BankTransaction $movement): bool;
}
