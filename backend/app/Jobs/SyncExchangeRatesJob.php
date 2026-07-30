<?php

namespace App\Jobs;

use App\Services\ExchangeRateService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncExchangeRatesJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int,string>  $quotes
     */
    public function __construct(public array $quotes = ['TRY']) {}

    public function handle(ExchangeRateService $service): void
    {
        $service->sync($this->quotes);
    }
}
