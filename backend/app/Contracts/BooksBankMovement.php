<?php

namespace App\Contracts;

use App\Models\BankTransaction;

/**
 * A record whose settlements may be booked into the bank ledger — the *reason*
 * money moved, as opposed to the line that says it did ({@see SettlementLine}).
 *
 * Every money figure on the dashboard is summed from `bank_transactions`, so a
 * settlement recorded against its own module and nowhere else closes the
 * obligation while leaving every balance untouched. This interface is what lets
 * one service book that movement for an invoice, a rent obligation, a salary, a
 * ticket, a travel expense, a loan or a social assistance payout, instead of
 * payables and receivables being the only two modules that reach the ledger.
 *
 * The record answers three questions the line cannot: which ledger category the
 * movement belongs to, which way the money went, and whose it was.
 */
interface BooksBankMovement
{
    /**
     * The movement's category — one of {@see BankTransaction::CATEGORIES}.
     * Canonical and language-neutral, translated only for display (rule 4).
     */
    public function movementCategory(): string;

    /**
     * Which way money moves when this record is settled: `1` in, `-1` out.
     *
     * Stated by the record rather than taken from the caller, because it follows
     * from what the record *is*: paying rent or a worker is money out whoever
     * enters it, and a receivable being settled is money in. Only `income` and
     * `expense` categories have their sign normalised by BankTransaction, so for
     * `payroll`, `housing`, `travel` and `loan` this is the only thing keeping a
     * payout from being added to the balance instead of subtracted from it.
     */
    public function movementDirection(): int;

    /**
     * The counterparty columns to stamp on the movement — `supplier_id` and/or
     * `client_id`, or an empty array when the other side is a worker, a house or
     * a landlord rather than a registered supplier or client.
     *
     * @return array<string, int|null>
     */
    public function movementParty(): array;

    /**
     * The movement's own text: an invoice number, a house name, a worker's name
     * — always something an operator typed, never a composed sentence, so the
     * row reads the same in all three languages (rule 4).
     */
    public function movementDescription(): ?string;

    /** The record's currency, used when the settling line does not state one. */
    public function movementCurrency(): ?string;
}
