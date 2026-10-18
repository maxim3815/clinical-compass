<?php

use App\Actions\CreatePatient;
use App\Enums\UserRole;
use App\Models\User;

test('patient can be attached only to a doctor', function () {
    $notDoctor = new User(['role' => UserRole::Patient]);

    expect(fn () => (new CreatePatient)->handle($notDoctor, 'Иванов Пётр Алексеевич', 'patient.new', 'secret-password'))
        ->toThrow(InvalidArgumentException::class);
});
