<?php

use App\Enums\DoseEventStatus;
use App\Enums\DoseSkipReason;
use App\Models\DoseEvent;
use App\Models\User;
use Carbon\CarbonImmutable;

test('doctor sees how the patient takes the drug and opens a day with a skipped dose', function () {
    // Время приложения переводится только вперёд: срок cookie сессии считается по нему.
    $day = CarbonImmutable::now('Europe/Moscow')->addDay()->startOfDay();
    $this->travelTo($day->setTime(12, 0));
    $doctor = User::factory()->doctor()->create(['login' => 'doctor']);
    $patient = User::factory()->patientOf($doctor)->create(['name' => 'Соколов Михаил Игоревич']);
    // Сегодня десятый день курса.
    $start = $day->subDays(9);
    $prescription = timedPrescription($patient, catalogDrug('Авиандр', [20 => false])->strengths()->sole(), ['morning' => '08:00', 'evening' => '20:00'], [
        'prescriber_id' => $doctor->id,
        'starts_on' => $start->toDateString(),
        'effective_at' => $start,
    ]);

    // Утром принимает всегда, вечером через день, на четвёртый день вечером не принял из-за тошноты.
    foreach (range(0, 9) as $i) {
        $date = $start->addDays($i);

        foreach (['morning' => 8, 'evening' => 20] as $timeOfDay => $hour) {
            $scheduledAt = $date->setTime($hour, 0)->utc();
            $status = match (true) {
                $timeOfDay === 'morning' || $i % 2 === 0 => DoseEventStatus::Taken,
                $i === 9 => DoseEventStatus::Scheduled,
                $i === 3 => DoseEventStatus::Skipped,
                default => DoseEventStatus::Missed,
            };

            DoseEvent::factory()->for($prescription)->create([
                'time_of_day' => $timeOfDay,
                'local_date' => $date->toDateString(),
                'scheduled_at' => $scheduledAt,
                'window_closes_at' => $scheduledAt->addHours(6),
                'status' => $status,
                'taken_at' => $status === DoseEventStatus::Taken ? $scheduledAt->addMinutes(5) : null,
                'marked_at' => $status->isMarked() ? $scheduledAt->addMinutes(5) : null,
                'skip_reason' => $status === DoseEventStatus::Skipped ? DoseSkipReason::Unwell : null,
                'skip_comment' => $status === DoseEventStatus::Skipped ? 'Тошнило' : null,
            ]);
        }
    }

    $skippedDay = $start->addDays(3);

    visit(route('login'))
        ->fill('login', 'doctor')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/doctor/patients')
        ->click('Соколов Михаил Игоревич')
        ->assertSee('Приём препаратов')
        ->assertSee('Курс с '.$start->isoFormat('D MMMM').', день 10')
        ->assertSee('Дни 8–14')
        ->assertSee('идёт')
        ->assertSee('утром 100%')
        ->assertSee('вечером 57%')
        ->assertSee('плохо себя чувствовал или побочные эффекты — 1')
        ->assertDontSee('Тошнило')
        ->click('[data-date="'.$skippedDay->toDateString().'"]')
        ->assertSee($skippedDay->isoFormat('D MMMM').': Часть не принята')
        ->assertSee('Вечером, 20:00: Не принят: плохо себя чувствовал или побочные эффекты')
        ->assertSee('Тошнило')
        ->assertNoJavaScriptErrors();
});
