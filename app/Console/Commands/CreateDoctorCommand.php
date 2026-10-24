<?php

namespace App\Console\Commands;

use App\Actions\CreateDoctor;
use App\Concerns\LoginValidationRules;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\DoctorKind;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class CreateDoctorCommand extends Command
{
    use LoginValidationRules, PasswordValidationRules, ProfileValidationRules;

    /**
     * @var string
     */
    protected $signature = 'app:doctor:create
        {--name= : ФИО врача}
        {--login= : Логин}
        {--password= : Пароль. Без опции спрашивается скрытым вводом}
        {--therapist : Терапевт: кабинет для отбора и направления к психиатру}
        {--specialty= : Специальность для пациента. По умолчанию «Психиатр» или «Терапевт»}';

    /**
     * @var string
     */
    protected $description = 'Создать врача';

    public function handle(CreateDoctor $createDoctor): int
    {
        $name = $this->stringOption('name') ?? text('ФИО');
        $login = $this->stringOption('login') ?? text('Логин');
        $password = $this->stringOption('password');
        $confirmation = $password;

        if ($password === null) {
            $password = password('Пароль');
            $confirmation = password('Повторите пароль');
        }

        $validator = Validator::make([
            'name' => $name,
            'login' => Str::lower($login),
            'password' => $password,
            'password_confirmation' => $confirmation,
            'specialty' => $this->stringOption('specialty'),
        ], [
            'name' => $this->nameRules(),
            'login' => $this->loginRules(),
            'password' => $this->passwordRules(),
            'specialty' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        /** @var array{name: string, login: string, password: string, specialty: string|null} $data */
        $data = $validator->validated();
        $kind = $this->option('therapist') === true ? DoctorKind::Therapist : DoctorKind::Psychiatrist;

        $doctor = $createDoctor->handle($data['name'], $data['login'], $data['password'], $data['specialty'], $kind);

        $this->components->info("Врач создан: {$doctor->name}, {$doctor->doctorProfile?->specialty}, логин {$doctor->login}.");

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) ? $value : null;
    }
}
