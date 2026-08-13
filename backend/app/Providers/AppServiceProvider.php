<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerAuditColumnsMacro();
        $this->registerRateLimiters();

        // Super Admin bypasses every permission check, including permissions
        // added in future modules.
        Gate::before(function ($user, string $ability) {
            return $user->hasRole('Super Admin') ? true : null;
        });
    }

    /**
     * Laravel 11+ leaves the api group unthrottled unless `throttleApi()` is
     * called, so `/api/login` accepted attempts as fast as they could be sent —
     * and, because there is no mail transport and no lockout, a password was the
     * only thing between an attacker and an account. Keyed on the email being
     * tried as well as the address it comes from, so one attacker cannot lock a
     * real user out by exhausting their allowance from elsewhere.
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(10)
            ->by(Str::lower((string) $request->input('email')).'|'.$request->ip()));
    }

    /**
     * Every financial table carries created_by, updated_by, source, notes
     * (see PROJECT_LLM_APP_PROMPT.md "Database Model"). This macro keeps that
     * shape in one place: `$table->auditColumns();`
     */
    private function registerAuditColumnsMacro(): void
    {
        Blueprint::macro('auditColumns', function (): void {
            /** @var Blueprint $this */
            $this->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $this->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $this->string('source')->nullable();   // e.g. manual, excel_import, assistant
            $this->text('notes')->nullable();
        });
    }
}
