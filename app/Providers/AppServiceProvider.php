<?php

namespace App\Providers;

use App\Models\User;
use App\Policies\PatientPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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

        // Страница ввода PIN: каждая ошибка — запрос и возврат на страницу, поэтому лимит выше,
        // чем у ссылок. Сами ошибки PIN считает App\Support\PinAttempts.
        RateLimiter::for('join', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));

        // Ответы из очереди у двери терапевта: без входа, поэтому лимит по адресу. Ошибка встаёт
        // над кнопкой отправки, а не отдельной страницей 429.
        RateLimiter::for('queue', fn (Request $request) => Limit::perHour(10)
            ->by('queue:'.$request->ip())
            ->response(fn () => back()->withErrors([
                'answers' => 'С этого телефона уже много ответов за последний час. Попробуйте позже или скажите терапевту, что не получилось.',
            ])));

        // Отправка сообщений в чат. Ответ JSON: форма показывает текст под полем.
        RateLimiter::for('chat', fn (Request $request) => Limit::perMinute(30)
            ->by('chat:'.$request->user()?->id)
            ->response(fn (Request $request, array $headers) => response()->json(
                ['message' => 'Слишком много сообщений подряд. Подождите минуту и отправьте снова.'],
                Response::HTTP_TOO_MANY_REQUESTS,
                $headers,
            )));
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
