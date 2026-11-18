<?php

namespace App\Providers;

use App\Models\Debt;
use App\Models\DebtPayment;
use App\Models\Product;
use App\Models\RoutineStep;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
        $this->configureDefaults();

        Blade::directive('money', fn (string $expression): string => "<?php echo e(money({$expression})); ?>");

        // Polymorphic *_type columns store these aliases, never class names (CLAUDE.md rule 14).
        // Every model used in a polymorphic relation must be listed here.
        Relation::enforceMorphMap([
            'debt' => Debt::class,
            'debt_payment' => DebtPayment::class,
            'product' => Product::class,
            'routine_step' => RoutineStep::class,
            'task' => Task::class,
            'user' => User::class,
        ]);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
