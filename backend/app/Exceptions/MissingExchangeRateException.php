<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thrown when a non-EUR amount has to be converted but no rate is available and
 * none was supplied. Renders as a validation-shaped 422 so the frontend can
 * point the user at the exchange rate field.
 */
class MissingExchangeRateException extends Exception
{
    public function __construct(public readonly string $currency, public readonly ?string $date = null)
    {
        parent::__construct(
            "No exchange rate is stored for {$currency}. Sync the rates or enter the rate used on this record.",
        );
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => ['exchange_rate' => [$this->getMessage()]],
        ], 422);
    }
}
