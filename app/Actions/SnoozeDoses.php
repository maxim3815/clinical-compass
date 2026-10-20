<?php

namespace App\Actions;

use App\Actions\Concerns\MarksDoses;
use App\Enums\DoseEventStatus;
use App\Models\DoseEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * «Через 15 минут» из напоминания: следующее напоминание о приёмах группы придёт через
 * config medications.reminders.snooze_minutes. Отмеченные и пропущенные приёмы не меняются.
 */
class SnoozeDoses
{
    use MarksDoses;

    /**
     * @param  iterable<DoseEvent>  $doses
     * @return CarbonImmutable|null когда придёт напоминание; null — откладывать нечего
     */
    public function handle(User $patient, iterable $doses): ?CarbonImmutable
    {
        return DB::transaction(function () use ($patient, $doses): ?CarbonImmutable {
            $patient = $this->lockPatient($patient);
            $now = CarbonImmutable::now();
            $until = $now->addMinutes(config()->integer('medications.reminders.snooze_minutes'));

            $snoozed = $this->lockDoses($patient, $doses)
                ->filter(fn (DoseEvent $dose): bool => $dose->status === DoseEventStatus::Scheduled && $dose->window_closes_at->gt($now));

            if ($snoozed->isEmpty()) {
                return null;
            }

            DoseEvent::query()->whereKey($snoozed->modelKeys())->update(['snoozed_until' => $until]);

            return $until;
        });
    }
}
