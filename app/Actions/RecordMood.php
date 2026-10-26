<?php

namespace App\Actions;

use App\Enums\Mood;
use App\Events\PatientSignalsChanged;
use App\Models\DoseEvent;
use App\Models\MoodMark;
use App\Models\User;
use App\Support\DoseEventPresenter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Пациент отмечает настроение лицом после отметки приёма: одно на время приёма (группу)
 * сегодня. Повторный выбор заменяет прежний, повторное касание того же лица снимает выбор.
 * Врачу меняется только метка в списке, если началась или прервалась серия грустных дней:
 * алертов и пушей нет.
 */
class RecordMood
{
    /**
     * @throws ValidationException время приёма сегодня не отмечено или такого приёма нет
     */
    public function handle(User $patient, string $slot, Mood $mood): MoodMark
    {
        // Лица видит психиатр: без него (пациент без врача со своим препаратом, пациент терапевта)
        // смотреть их некому.
        $doctor = $patient->currentDoctor()->with('doctorProfile')->first();

        if ($doctor === null || $doctor->isTherapist()) {
            throw ValidationException::withMessages(['slot' => 'Настроение отмечается, когда вас ведёт психиатр.']);
        }

        $today = $patient->localToday();
        $timezone = $patient->localTimezone();

        // Лица — только после отметки «Принял» или «Не принял» у плановых приёмов этого времени.
        $marked = $patient->doseEvents()
            ->where('local_date', $today->toDateString())
            ->get()
            ->contains(fn (DoseEvent $event): bool => $event->status->isMarked()
                && DoseEventPresenter::clock($event->scheduled_at, $timezone) === $slot);

        if (! $marked) {
            throw ValidationException::withMessages(['slot' => 'Настроение отмечается после отметки приёма в это время.']);
        }

        return DB::transaction(function () use ($patient, $today, $slot, $mood): MoodMark {
            $mark = MoodMark::query()->updateOrCreate(
                ['patient_id' => $patient->id, 'local_date' => $today->toDateString(), 'slot' => $slot],
                ['mood' => $mood],
            );

            PatientSignalsChanged::dispatchForDoctorOf($patient);

            return $mark;
        });
    }

    /**
     * Снять выбор за сегодня: лицо нажато по ошибке. Того, что пациент не выбирал, врач не видит.
     */
    public function clear(User $patient, string $slot): void
    {
        $deleted = MoodMark::query()
            ->where('patient_id', $patient->id)
            ->where('local_date', $patient->localToday()->toDateString())
            ->where('slot', $slot)
            ->delete();

        if ($deleted > 0) {
            PatientSignalsChanged::dispatchForDoctorOf($patient);
        }
    }
}
