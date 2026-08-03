<?php

use App\Jobs\ScanNotificationsJob;
use App\Jobs\SyncExchangeRatesJob;
use App\Models\BankTransaction;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Pull TRY (and any future currencies) once a day. Requires a running scheduler.
Schedule::job(new SyncExchangeRatesJob(['TRY']))->dailyAt('06:00');

// Re-check what is overdue, unpaid, unapproved or expiring, and clear whatever
// has been dealt with since. Runs after the rate sync so amounts are current.
Schedule::job(new ScanNotificationsJob)->dailyAt('06:30');

Artisan::command('notifications:scan', function (NotificationDispatcher $dispatcher) {
    $result = $dispatcher->scan();

    $this->info("Raised {$result['created']} notification(s), resolved {$result['resolved']}.");
})->purpose('Generate in-app notifications from the current data');

/*
 * Bring historical movements into line with BankTransaction::DIRECTIONS.
 *
 * Every row written from now on is normalised by the model, but rows entered
 * before that — an expense typed as +300 — still read as income in the balance
 * and in every total derived from it. This is a report by default and only
 * writes with --apply, because it rewrites money the operator entered by hand
 * and that decision is theirs to make, not a migration's.
 */
Artisan::command('bank:normalize-signs {--apply : write the corrections instead of only listing them}', function () {
    $wrong = BankTransaction::query()
        ->whereIn('category', array_keys(BankTransaction::DIRECTIONS))
        ->get()
        ->filter(function (BankTransaction $transaction): bool {
            $sign = BankTransaction::DIRECTIONS[$transaction->category];

            foreach (BankTransaction::AMOUNT_COLUMNS as $column) {
                $amount = (float) $transaction->{$column};

                if ($amount !== 0.0 && ($amount <=> 0) !== $sign) {
                    return true;
                }
            }

            return false;
        });

    if ($wrong->isEmpty()) {
        $this->info('Every income/expense movement already carries the right sign.');

        return;
    }

    $this->table(
        ['id', 'date', 'category', 'cash', 'nlb', 'lovcen', 'net'],
        $wrong->map(fn (BankTransaction $t): array => [
            $t->id,
            $t->date?->toDateString(),
            $t->category,
            $t->cash_amount,
            $t->nlb_amount,
            $t->lovcen_amount,
            $t->net_amount,
        ])->all(),
    );

    if (! $this->option('apply')) {
        $this->warn("{$wrong->count()} movement(s) contradict their category. Re-run with --apply to correct them.");

        return;
    }

    // The model's saving hook does the correcting; touching the row is enough.
    DB::transaction(fn () => $wrong->each->save());

    $this->info("Corrected {$wrong->count()} movement(s).");
})->purpose('Report (or fix) bank movements whose sign contradicts their category');
