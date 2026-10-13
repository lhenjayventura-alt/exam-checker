<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Grading\GraderRegistry;
use App\Services\Grading\IdentificationGrader;
use App\Services\Grading\MultipleChoiceGrader;
use App\Services\Grading\NumericalProblemGrader;
use App\Services\Grading\TrueFalseGrader;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(GraderRegistry::class, fn ($app) => new GraderRegistry([
            $app->make(MultipleChoiceGrader::class),
            $app->make(TrueFalseGrader::class),
            $app->make(IdentificationGrader::class),
            $app->make(NumericalProblemGrader::class),
        ]));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('admin', fn (User $user) => $user->isAdmin());
        Gate::define('manage-students', fn (User $user) => $user->isAdmin() || $user->isTeacher());

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('api-login', fn (Request $request) => Limit::perMinute(10)->by($request->ip().'|'.$request->input('email')));
    }
}
