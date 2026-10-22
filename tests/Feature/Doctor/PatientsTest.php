<?php

use App\Http\Controllers\Doctor\PatientController;
use App\Models\CareRelationship;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

test('doctor cabinet opens the patient list', function () {
    $this->actingAs(User::factory()->doctor()->create())
        ->get(route('doctor.home'))
        ->assertRedirect(route('doctor.patients.index'));
});

test('patient can not open the patient list', function () {
    $this->actingAs(User::factory()->patient()->create())
        ->get(route('doctor.patients.index'))
        ->assertForbidden();
});

test('doctor sees only own patients with an active relationship', function () {
    $doctor = User::factory()->doctor()->create();
    $own = User::factory()->patientOf($doctor)->create();
    $former = User::factory()->patient()->create();
    CareRelationship::factory()->ended()->create(['doctor_id' => $doctor->id, 'patient_id' => $former->id]);
    User::factory()->patientOf(User::factory()->doctor()->create())->create();

    $this->actingAs($doctor)
        ->get(route('doctor.patients.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('doctor/patients/Index')
            ->where('patients.total', 1)
            ->where('patients.data.0.id', $own->id)
            ->where('search', ''),
        );
});

test('patient row shows age and the date the observation started', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18 12:00', 'UTC'));
    $doctor = User::factory()->doctor()->create();
    $patient = User::factory()->patientOf($doctor)->create(['name' => 'Иванов Пётр Алексеевич']);
    $patient->patientProfile?->update(['birth_date' => '1984-03-12']);
    // 30 сентября 22:30 UTC — уже 1 октября по Москве.
    CareRelationship::query()->where('patient_id', $patient->id)->update(['started_at' => '2026-09-30 22:30:00']);

    $this->actingAs($doctor)
        ->get(route('doctor.patients.index'))
        ->assertInertia(fn (Assert $page) => $page->where('patients.data.0', [
            'id' => $patient->id,
            'name' => 'Иванов Пётр Алексеевич',
            'age' => 42,
            'diagnosis' => null,
            'started_at' => '01.10.2026',
            'adherence' => ['share' => null, 'low_share' => false, 'consecutive_misses' => null, 'raised' => false],
            'alert' => false,
            'contraindications' => 0,
            'unread' => 0,
        ]));
});

test('age is empty when the birth date is unknown', function () {
    $doctor = User::factory()->doctor()->create();
    User::factory()->patientOf($doctor)->create()->patientProfile?->update(['birth_date' => null]);

    $this->actingAs($doctor)
        ->get(route('doctor.patients.index'))
        ->assertInertia(fn (Assert $page) => $page->where('patients.data.0.age', null));
});

test('patients are sorted by name', function () {
    $doctor = User::factory()->doctor()->create();

    foreach (['Соколов Михаил Игоревич', 'Иванов Пётр Алексеевич', 'Кузнецова Елена Викторовна'] as $name) {
        User::factory()->patientOf($doctor)->create(['name' => $name]);
    }

    $this->actingAs($doctor)
        ->get(route('doctor.patients.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('patients.data.0.name', 'Иванов Пётр Алексеевич')
            ->where('patients.data.1.name', 'Кузнецова Елена Викторовна')
            ->where('patients.data.2.name', 'Соколов Михаил Игоревич'),
        );
});

test('search finds a patient by part of the name in any case', function (string $search) {
    $doctor = User::factory()->doctor()->create();
    $patient = User::factory()->patientOf($doctor)->create(['name' => 'Кузнецова Елена Викторовна']);
    User::factory()->patientOf($doctor)->create(['name' => 'Иванов Пётр Алексеевич']);

    $this->actingAs($doctor)
        ->get(route('doctor.patients.index', ['search' => $search]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('patients.total', 1)
            ->where('patients.data.0.id', $patient->id)
            ->where('search', trim($search)),
        );
})->with(['кузнецова', 'ЕЛЕНА', 'вИкТоР', '  елена  ']);

test('search looks only through own patients', function () {
    $doctor = User::factory()->doctor()->create();
    User::factory()->patientOf(User::factory()->doctor()->create())->create(['name' => 'Морозова Ольга Павловна']);

    $this->actingAs($doctor)
        ->get(route('doctor.patients.index', ['search' => 'Морозова']))
        ->assertInertia(fn (Assert $page) => $page->where('patients.total', 0));
});

test('like wildcards in the search are matched literally', function (string $search) {
    $doctor = User::factory()->doctor()->create();
    User::factory()->patientOf($doctor)->create(['name' => 'Иванов Пётр Алексеевич']);

    $this->actingAs($doctor)
        ->get(route('doctor.patients.index', ['search' => $search]))
        ->assertInertia(fn (Assert $page) => $page->where('patients.total', 0));
})->with(['%', '_', 'Иван_в']);

test('list is paginated by 20 patients and keeps the search', function () {
    $doctor = User::factory()->doctor()->create();

    foreach (range(1, PatientController::PER_PAGE + 1) as $number) {
        User::factory()->patientOf($doctor)->create(['name' => sprintf('Пациент %02d', $number)]);
    }

    $this->actingAs($doctor)
        ->get(route('doctor.patients.index', ['search' => 'пациент']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('patients.data', 20)
            ->where('patients.total', 21)
            ->where('patients.last_page', 2)
            ->where('patients.data.0.name', 'Пациент 01')
            ->where('patients.next_page_url', route('doctor.patients.index', ['search' => 'пациент', 'page' => 2])),
        );

    $this->get(route('doctor.patients.index', ['search' => 'пациент', 'page' => 2]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('patients.data', 1)
            ->where('patients.data.0.name', 'Пациент 21'),
        );
});

test('doctor opens the card of own patient', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18 12:00', 'UTC'));
    $doctor = User::factory()->doctor()->create();
    $patient = User::factory()->patientOf($doctor)->create(['name' => 'Иванов Пётр Алексеевич', 'login' => 'ivanov']);
    $patient->patientProfile?->update(['birth_date' => '1984-03-12', 'timezone' => 'Asia/Yekaterinburg']);
    CareRelationship::query()->where('patient_id', $patient->id)->update(['started_at' => '2026-09-30 22:30:00']);

    $this->actingAs($doctor)
        ->get(route('doctor.patients.show', $patient))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('doctor/patients/Show')
            ->where('patient', [
                'id' => $patient->id,
                'name' => 'Иванов Пётр Алексеевич',
                'login' => 'ivanov',
                'birth_date' => '12.03.1984',
                'age' => 42,
                'started_at' => '01.10.2026',
                'timezone' => 'Екатеринбург (UTC+5)',
            ]),
        );
});

test('card of a patient the doctor does not treat is not found', function (Closure $makePatient) {
    $doctor = User::factory()->doctor()->create();

    $this->actingAs($doctor)
        ->get(route('doctor.patients.show', $makePatient($doctor)))
        ->assertNotFound();
})->with([
    'patient of another doctor' => fn () => User::factory()->patientOf(User::factory()->doctor()->create())->create(),
    'former patient' => function (User $doctor) {
        $patient = User::factory()->patient()->create();
        CareRelationship::factory()->ended()->create(['doctor_id' => $doctor->id, 'patient_id' => $patient->id]);

        return $patient;
    },
    'a doctor' => fn (User $doctor) => $doctor,
    'missing user' => fn () => 999999,
]);

test('card address with something other than an id is not found', function () {
    $this->actingAs(User::factory()->doctor()->create())
        ->get('/doctor/patients/abc')
        ->assertNotFound();
});
