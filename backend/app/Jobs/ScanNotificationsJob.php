<?php

namespace App\Jobs;

use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ScanNotificationsJob implements ShouldQueue
{
    use Queueable;

    public function handle(NotificationDispatcher $dispatcher): void
    {
        $dispatcher->scan();
    }
}
