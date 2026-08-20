<?php

namespace App\Models\Concerns;

use App\Contracts\BooksBankMovement;

/**
 * The two parts of {@see BooksBankMovement} that are the same for
 * almost every record: a movement is priced in the record's own currency, and
 * most records have no supplier or client on the other side — a house, a worker
 * or a landlord is not a registered counterparty.
 *
 * Category, direction and description differ per record and stay on the models,
 * where they read as statements about what the record is.
 */
trait BooksMovements
{
    public function movementCurrency(): ?string
    {
        return $this->currency;
    }

    /** @return array<string, int|null> */
    public function movementParty(): array
    {
        return [];
    }
}
