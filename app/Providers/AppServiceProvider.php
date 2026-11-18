<?php

namespace App\Providers;

use App\Models\Activity;
use App\Models\ClassSession;
use App\Models\Debt;
use App\Models\DebtPayment;
use App\Models\Product;
use App\Models\RoutineStep;
use App\Models\Task;
use App\Models\User;
use App\Models\VitaminDose;
use App\Models\Want;
use App\Services\Ai\AiClient;
use App\Services\Ai\GeminiClient;
use App\Services\LinkPreview\DnsHostResolver;
use App\Services\LinkPreview\HostResolver;
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
        // Real DNS for link previews; tests swap in a fake.
        $this->app->bind(HostResolver::class, DnsHostResolver::class);

        // The AI provider behind its interface (D-026); tests swap in a fake.
        $this->app->bind(AiClient::class, GeminiClient::class);
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
            'activity' => Activity::class,
            'class_session' => ClassSession::class,
            'debt' => Debt::class,
            'debt_payment' => DebtPayment::class,
            'product' => Product::class,
            'routine_step' => RoutineStep::class,
            'task' => Task::class,
            'user' => User::class,
            'vitamin_dose' => VitaminDose::class,
            'want' => Want::class,
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
