<?php

use App\Enums\PrescriptionCloseReason;
use App\Models\AsNeededDose;
use App\Models\Prescription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 12:00', 'Europe/Moscow'));
    $this->doctor = User::factory()->doctor()->create();
    $this->patient = User::factory()->patientOf($this->doctor)->create();
    $this->hydroxyzine = catalogDrug('Гидроксизин', [25 => true]);
    // По ½ таблетки, не больше 2 раз в сутки.
    $this->prescription = Prescription::factory()
        ->for($this->patient, 'patient')
        ->for($this->hydroxyzine->strengths()->sole(), 'strength')
        ->asNeeded('0.5', 2)
        ->create(['starts_on' => '2026-10-18']);
});

/**
 * Записанные приёмы «по необходимости»: местная дата и момент по Москве.
 *
 * @return list<string>
 */
function asNeededLog(User $patient): array
{
    return $patient->asNeededDoses()
        ->orderBy('taken_at')
        ->get()
        ->map(fn (AsNeededDose $dose): string => $dose->local_date->toDateString().' '.$dose->taken_at->setTimezone('Europe/Moscow')->format('H:i').' '.$dose->tablets)
        ->all();
}

test('patient records an as-needed dose now', function () {
    $this->actingAs($this->patient)
        ->post(route('patient.as-needed-doses.store', $this->prescription), ['day' => 'today'])
        ->assertRedirect(route('patient.home'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Приём записан.']);

    expect(asNeededLog($this->patient))->toBe(['2026-10-19 12:00 0.5'])
        ->and($this->patient->asNeededDoses()->sole()->prescription_id)->toBe($this->prescription->id);

    $this->get(route('patient.home'))
        ->assertInertia(fn (Assert $page) => $page->where('asNeeded', [[
            'id' => $this->prescription->id,
            'drug' => 'Гидроксизин',
            'dose' => '½ таблетки, 12,5 мг',
            'instruction' => $this->prescription->instruction,
            'max_daily_doses' => 2,
            'taken_today' => 1,
            'doses' => [['id' => $this->patient->asNeededDoses()->value('id'), 'day' => 'Сегодня', 'time' => '12:00']],
        ]]));
});

test('patient records the time of an as-needed dose today or yesterday', function () {
    $this->actingAs($this->patient);

    $this->post(route('patient.as-needed-doses.store', $this->prescription), ['day' => 'today', 'time' => '09:15'])->assertSessionHasNoErrors();
    $this->post(route('patient.as-needed-doses.store', $this->prescription), ['day' => 'yesterday', 'time' => '23:40'])->assertSessionHasNoErrors();

    expect(asNeededLog($this->patient))->toBe([
        '2026-10-18 23:40 0.5',
        '2026-10-19 09:15 0.5',
    ]);

    $this->get(route('patient.home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('asNeeded.0.taken_today', 1)
            ->where('asNeeded.0.doses.0.day', 'Сегодня')
            ->where('asNeeded.0.doses.0.time', '9:15')
            ->where('asNeeded.0.doses.1.day', 'Вчера')
            ->where('asNeeded.0.doses.1.time', '23:40'),
        );
});

test('an as-needed dose is not recorded in the future, without time for yesterday or before the prescription', function (array $data, array $errors) {
    $this->prescription->update(['starts_on' => '2026-10-19']);

    $this->actingAs($this->patient)
        ->post(route('patient.as-needed-doses.store', $this->prescription), $data)
        ->assertSessionHasErrors($errors);

    expect($this->patient->asNeededDoses()->count())->toBe(0);
})->with([
    'future' => [['day' => 'today', 'time' => '12:01'], ['time' => 'Это время ещё не наступило.']],
    'yesterday without time' => [['day' => 'yesterday'], ['time' => 'Укажите время приёма.']],
    'before the prescription' => [['day' => 'yesterday', 'time' => '10:00'], ['day' => 'В этот день назначение ещё не действовало.']],
    'unknown day' => [['day' => 'tomorrow'], ['day' => 'Выберите день.']],
    'bad time' => [['day' => 'today', 'time' => '25:00'], ['time' => 'Укажите время в формате ЧЧ:ММ.']],
]);

test('a dose over the daily limit is recorded after the patient confirms it', function () {
    $this->actingAs($this->patient);
    $this->post(route('patient.as-needed-doses.store', $this->prescription), ['day' => 'today', 'time' => '08:00']);
    // Вчерашний приём в лимит сегодняшних суток не входит.
    $this->post(route('patient.as-needed-doses.store', $this->prescription), ['day' => 'yesterday', 'time' => '22:00']);
    $this->post(route('patient.as-needed-doses.store', $this->prescription), ['day' => 'today'])->assertSessionHasNoErrors();

    $this->post(route('patient.as-needed-doses.store', $this->prescription), ['day' => 'today'])
        ->assertSessionHasErrors(['confirmed' => 'Сегодня вы уже приняли препарат 2 раза, а врач разрешил не больше 2 раз в сутки.']);

    expect($this->patient->asNeededDoses()->count())->toBe(3);

    $this->post(route('patient.as-needed-doses.store', $this->prescription), ['day' => 'today', 'confirmed' => true])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Приём записан.']);

    $this->get(route('patient.home'))
        ->assertInertia(fn (Assert $page) => $page->where('asNeeded.0.taken_today', 3));
});

test('the daily limit counts doses of the drug across versions of the prescription', function () {
    AsNeededDose::factory()->for($this->prescription)->count(2)->create(['local_date' => '2026-10-19', 'taken_at' => now()->subHours(2)]);
    $this->prescription->update(['closed_at' => now()->subHour(), 'close_reason' => PrescriptionCloseReason::Changed]);
    $changed = Prescription::factory()
        ->for($this->patient, 'patient')
        ->for($this->hydroxyzine->strengths()->sole(), 'strength')
        ->asNeeded('1', 3)
        ->create(['previous_id' => $this->prescription->id, 'starts_on' => '2026-10-19']);
    $this->actingAs($this->patient);

    $this->get(route('patient.home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('asNeeded.0.id', $changed->id)
            ->where('asNeeded.0.taken_today', 2)
            ->count('asNeeded.0.doses', 2),
        );

    $this->post(route('patient.as-needed-doses.store', $changed), ['day' => 'today'])->assertSessionHasNoErrors();
    $this->post(route('patient.as-needed-doses.store', $changed), ['day' => 'today'])
        ->assertSessionHasErrors(['confirmed' => 'Сегодня вы уже приняли препарат 3 раза, а врач разрешил не больше 3 раз в сутки.']);
});

test('a changed, cancelled or upcoming prescription does not take as-needed doses', function (Closure $close) {
    $close($this->prescription);

    $this->actingAs($this->patient)
        ->post(route('patient.as-needed-doses.store', $this->prescription), ['day' => 'today'])
        ->assertRedirect(route('patient.home'))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Назначение уже изменилось или закончилось. Проверьте актуальные назначения.']);

    expect($this->patient->asNeededDoses()->count())->toBe(0);
})->with([
    'changed' => fn (Prescription $prescription) => $prescription->update(['closed_at' => now(), 'close_reason' => PrescriptionCloseReason::Changed]),
    'cancelled' => fn (Prescription $prescription) => $prescription->update(['closed_at' => now(), 'close_reason' => PrescriptionCloseReason::Cancelled]),
    'completed' => fn (Prescription $prescription) => $prescription->update(['ends_on' => '2026-10-18']),
    'upcoming' => fn (Prescription $prescription) => $prescription->update(['starts_on' => '2026-10-20']),
]);

test('regular and foreign prescriptions are not found', function () {
    $regular = regularPrescription($this->patient, catalogDrug('Сертралин', [50 => true])->strengths()->sole());
    $this->actingAs($this->patient);

    $this->post(route('patient.as-needed-doses.store', $regular), ['day' => 'today'])->assertNotFound();

    $this->actingAs(User::factory()->patient()->create())
        ->post(route('patient.as-needed-doses.store', $this->prescription), ['day' => 'today'])
        ->assertNotFound();

    expect(AsNeededDose::query()->count())->toBe(0);
});

test('patient cancels as-needed doses of today and yesterday only', function () {
    $today = AsNeededDose::factory()->for($this->prescription)->create(['local_date' => '2026-10-19']);
    $yesterday = AsNeededDose::factory()->for($this->prescription)->create(['local_date' => '2026-10-18', 'taken_at' => now()->subDay()]);
    $old = AsNeededDose::factory()->for($this->prescription)->create(['local_date' => '2026-10-17', 'taken_at' => now()->subDays(2)]);
    $this->actingAs($this->patient);

    foreach ([$today, $yesterday] as $dose) {
        $this->delete(route('patient.as-needed-doses.destroy', $dose))
            ->assertRedirect(route('patient.home'))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Запись о приёме удалена.']);
    }

    $this->delete(route('patient.as-needed-doses.destroy', $old))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Отмечать можно только приёмы за сегодня и вчера.']);

    $this->actingAs(User::factory()->patient()->create())
        ->delete(route('patient.as-needed-doses.destroy', $old))
        ->assertNotFound();

    expect(AsNeededDose::query()->pluck('id')->all())->toBe([$old->id]);
});

test('patient records doses of an own medication without a daily limit', function () {
    $own = ownPrescription($this->patient, times: [], attributes: [
        'kind' => 'as_needed',
        'dose' => '½ таблетки',
        'max_daily_doses' => null,
        'starts_on' => '2026-10-18',
    ]);
    $this->actingAs($this->patient);

    foreach (range(1, 4) as $attempt) {
        $this->post(route('patient.as-needed-doses.store', $own), ['day' => 'today'])->assertSessionHasNoErrors();
    }

    expect($own->asNeededDoses()->count())->toBe(4)
        ->and($own->asNeededDoses()->whereNotNull('tablets')->exists())->toBeFalse();

    $this->get(route('patient.home'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('asNeeded', 2)
            ->where('asNeeded.1.id', $own->id)
            ->where('asNeeded.1.drug', 'Фенибут')
            ->where('asNeeded.1.dose', '½ таблетки')
            ->where('asNeeded.1.instruction', null)
            ->where('asNeeded.1.max_daily_doses', null)
            ->where('asNeeded.1.taken_today', 4),
        );
});

test('the daily limit of another drug added by the patient counts doses of the same name', function () {
    $previous = ownPrescription($this->patient, times: [], attributes: [
        'kind' => 'as_needed',
        'max_daily_doses' => 2,
        'starts_on' => '2026-10-18',
        'closed_at' => now()->subHour(),
        'close_reason' => PrescriptionCloseReason::Changed,
    ]);
    AsNeededDose::factory()->for($previous)->create(['tablets' => null, 'taken_at' => now()->subHours(2)]);
    $changed = ownPrescription($this->patient, times: [], attributes: ['kind' => 'as_needed', 'max_daily_doses' => 1, 'previous_id' => $previous->id]);
    // Приём другого препарата в лимит не входит.
    $another = ownPrescription($this->patient, times: [], attributes: ['kind' => 'as_needed', 'drug_name' => 'Афобазол', 'max_daily_doses' => 1]);
    $this->actingAs($this->patient);

    $this->post(route('patient.as-needed-doses.store', $changed), ['day' => 'today'])
        ->assertSessionHasErrors(['confirmed' => 'Сегодня вы уже приняли препарат 1 раз, а по назначению можно не больше 1 раза в сутки.']);
    $this->post(route('patient.as-needed-doses.store', $another), ['day' => 'today'])->assertSessionHasNoErrors();
});

test('the daily limit of a catalog drug counts doses of the medication added by the patient', function () {
    // Пациент добавил тот же препарат раньше, чем его назначил врач.
    $own = ownPrescription($this->patient, $this->hydroxyzine, times: [], attributes: ['kind' => 'as_needed', 'max_daily_doses' => null, 'starts_on' => '2026-10-18']);
    AsNeededDose::factory()->for($own)->count(2)->create(['tablets' => null, 'taken_at' => now()->subHour()]);
    $this->actingAs($this->patient);

    $this->get(route('patient.home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('asNeeded.0.id', $this->prescription->id)
            ->where('asNeeded.0.taken_today', 2)
            ->where('asNeeded.1.id', $own->id)
            ->where('asNeeded.1.taken_today', 2),
        );

    $this->post(route('patient.as-needed-doses.store', $this->prescription), ['day' => 'today'])
        ->assertSessionHasErrors(['confirmed' => 'Сегодня вы уже приняли препарат 2 раза, а врач разрешил не больше 2 раз в сутки.']);
});
