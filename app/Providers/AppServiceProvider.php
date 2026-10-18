<?php

namespace App\Providers;

use App\Models\User;
use App\Policies\PatientPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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

        // Пользователь как объект проверки прав пока бывает только пациентом.
        Gate::policy(User::class, PatientPolicy::class);

        RateLimiter::for('access-link', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
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

        // Без uncompromised(): пароли не уходят на проверку во внешний сервис.
        Password::defaults(fn (): Password => Password::min(8));
    }
}
