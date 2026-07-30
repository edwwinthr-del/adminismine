<?php

use App\Jobs\ScanNotificationsJob;
use App\Jobs\SyncExchangeRatesJob;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
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
