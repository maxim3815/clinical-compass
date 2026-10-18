<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Задаёт пользователю новый пароль, когда старый забыт или мог утечь: заодно завершает
 * все его сессии и сбрасывает «Запомнить меня». Пароль должен быть уже проверен.
 */
class SetNewPassword
{
    public function handle(User $user, string $password): void
    {
        DB::transaction(function () use ($user, $password): void {
            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
            ])->save();

            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        });
    }
}
