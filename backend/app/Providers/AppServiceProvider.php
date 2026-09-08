<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Support\CompanyConfig;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One instance per request, so the settings row behind every payroll
        // figure is read once rather than once per attendance record.
        $this->app->singleton(CompanyConfig::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Sanctum resolves its table from the model, so the move to
        // sistem.pristupni_tokeni has to be registered here.
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

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
            $this->foreignId('created_by')->nullable()->constrained('korisnici')->nullOnDelete();
            $this->foreignId('updated_by')->nullable()->constrained('korisnici')->nullOnDelete();
            $this->string('source')->nullable();   // e.g. manual, excel_import, assistant
            $this->text('notes')->nullable();
        });
    }
}
