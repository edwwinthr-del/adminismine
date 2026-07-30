<?php

namespace App\Providers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

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

        // Super Admin bypasses every permission check, including permissions
        // added in future modules.
        Gate::before(function ($user, string $ability) {
            return $user->hasRole('Super Admin') ? true : null;
        });
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
