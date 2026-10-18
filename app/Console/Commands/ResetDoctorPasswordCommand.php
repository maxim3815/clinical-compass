<?php

namespace App\Console\Commands;

use App\Actions\SetNewPassword;
use App\Concerns\PasswordValidationRules;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

use function Laravel\Prompts\password;

class ResetDoctorPasswordCommand extends Command
{
    use PasswordValidationRules;

    /**
     * @var string
     */
    protected $signature = 'app:doctor:reset-password {login : Логин врача}';

    /**
     * @var string
     */
    protected $description = 'Задать врачу новый пароль';

    public function handle(SetNewPassword $setNewPassword): int
    {
        $login = $this->argument('login');
        $login = Str::lower(is_string($login) ? $login : '');

        $doctor = User::query()
            ->where('login', $login)
            ->where('role', UserRole::Doctor)
            ->first();

        if ($doctor === null) {
            $this->components->error("Врач с логином «{$login}» не найден.");

            return self::FAILURE;
        }

        $validator = Validator::make([
            'password' => password('Новый пароль'),
            'password_confirmation' => password('Повторите пароль'),
        ], [
            'password' => $this->passwordRules(),
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $setNewPassword->handle($doctor, $validator->validated()['password']);

        $this->components->info("Пароль врача {$doctor->login} изменён, его сессии завершены.");

        return self::SUCCESS;
    }
}
