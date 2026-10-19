<?php

use App\Actions\SyncDoseEvents;
use App\Enums\RussianTimezone;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

test('profile page is displayed', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/Profile'));
});

test('name can be updated', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Петрова Мария Ивановна',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->name)->toBe('Петрова Мария Ивановна');
});

test('login can not be changed', function () {
    $user = User::factory()->create(['login' => 'patient.ivanov']);

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'login' => 'someone.else',
        ])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->login)->toBe('patient.ivanov');
});

test('name is required and the error is in russian', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('profile.edit'))
        ->patch(route('profile.update'), ['name' => ''])
        ->assertSessionHasErrors(['name' => 'Поле ФИО обязательно.'])
        ->assertRedirect(route('profile.edit'));
});

test('appearance page is displayed', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('appearance.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/Appearance'));
});

test('patient profile page has the birth date and the russian timezones', function () {
    $patient = User::factory()->patient()->create();
    $patient->patientProfile?->update(['birth_date' => '1984-03-12', 'timezone' => 'Asia/Omsk']);

    $this->actingAs($patient)
        ->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('patientProfile', ['birth_date' => '1984-03-12', 'timezone' => 'Asia/Omsk'])
            ->has('timezones', 11)
            ->where('timezones.1', ['value' => 'Europe/Moscow', 'label' => 'Москва (UTC+3)']),
        );
});

test('doctor profile page has no patient fields', function () {
    $this->actingAs(User::factory()->doctor()->create())
        ->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('patientProfile', null)
            ->where('timezones', []),
        );
});

test('patient changes name, birth date and timezone', function () {
    $patient = User::factory()->patient()->create();

    $this->actingAs($patient)
        ->patch(route('profile.update'), [
            'name' => 'Петрова Мария Ивановна',
            'birth_date' => '1990-04-15',
            'timezone' => 'Asia/Yekaterinburg',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $patient->refresh();

    expect($patient->name)->toBe('Петрова Мария Ивановна')
        ->and($patient->patientProfile?->birth_date?->toDateString())->toBe('1990-04-15')
        ->and($patient->patientProfile?->timezone)->toBe(RussianTimezone::Yekaterinburg);
});

test('changing the timezone moves future dose events', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 09:00', 'UTC'));
    $patient = User::factory()->patient()->create();
    timedPrescription($patient, catalogDrug('Гидроксизин')->strengths()->sole(), ['morning' => '08:00', 'evening' => '20:00'], [
        'starts_on' => '2026-10-12',
        'effective_at' => now()->subWeek(),
    ]);
    app(SyncDoseEvents::class)->handle($patient);

    $this->actingAs($patient)
        ->patch(route('profile.update'), ['name' => $patient->name, 'timezone' => 'Asia/Yekaterinburg'])
        ->assertSessionHasNoErrors();

    // Утренний приём по Москве уже прошёл и остаётся, будущие переезжают на время Екатеринбурга.
    expect(doseSchedule($patient))->toBe([
        '2026-10-19 morning 2026-10-19 05:00',
        '2026-10-19 evening 2026-10-19 15:00',
        '2026-10-20 morning 2026-10-20 03:00',
        '2026-10-20 evening 2026-10-20 15:00',
    ]);
});

test('patient can clear the birth date', function () {
    $patient = User::factory()->patient()->create();

    $this->actingAs($patient)
        ->patch(route('profile.update'), [
            'name' => $patient->name,
            'birth_date' => '',
            'timezone' => 'Europe/Moscow',
        ])
        ->assertSessionHasNoErrors();

    expect($patient->refresh()->patientProfile?->birth_date)->toBeNull();
});

test('patient timezone is accepted only from the russian list', function (string $timezone) {
    $patient = User::factory()->patient()->create();

    $this->actingAs($patient)
        ->patch(route('profile.update'), [
            'name' => $patient->name,
            'timezone' => $timezone,
        ])
        ->assertSessionHasErrors('timezone');

    expect($patient->refresh()->patientProfile?->timezone)->toBe(RussianTimezone::Moscow);
})->with(['Europe/London', 'UTC', 'Asia/Novosibirsk', 'Москва', '']);

test('patient birth date can not be in the future', function () {
    $patient = User::factory()->patient()->create();

    $this->actingAs($patient)
        ->patch(route('profile.update'), [
            'name' => $patient->name,
            'birth_date' => now()->addDay()->toDateString(),
            'timezone' => 'Europe/Moscow',
        ])
        ->assertSessionHasErrors(['birth_date' => 'Дата рождения должна быть раньше сегодняшнего дня.']);
});

test('doctor profile ignores patient fields', function () {
    $doctor = User::factory()->doctor()->create();

    $this->actingAs($doctor)
        ->patch(route('profile.update'), [
            'name' => 'Смирнова Анна Сергеевна',
            'timezone' => 'Europe/London',
        ])
        ->assertSessionHasNoErrors();

    expect($doctor->refresh()->name)->toBe('Смирнова Анна Сергеевна');
});

test('timezone error is in russian', function () {
    $this->actingAs(User::factory()->patient()->create())
        ->patch(route('profile.update'), ['name' => 'Петрова Мария Ивановна', 'timezone' => 'Europe/London'])
        ->assertSessionHasErrors(['timezone' => 'Значение поля часовой пояс отсутствует в списке разрешённых.']);
});
