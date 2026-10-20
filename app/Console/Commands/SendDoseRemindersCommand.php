<?php

namespace App\Console\Commands;

use App\Enums\DoseEventStatus;
use App\Models\DoseEvent;
use App\Notifications\DoseReminder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class SendDoseRemindersCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'app:dose-events:send-reminders';

    /**
     * @var string
     */
    protected $description = 'Напомнить пуш-уведомлением о плановых приёмах, которым пора';

    public function handle(): int
    {
        $now = CarbonImmutable::now();
        $repeat = config()->integer('medications.reminders.repeat_minutes');
        $late = config()->integer('medications.reminders.late_minutes');
        // Самое позднее напоминание по расписанию — последний повтор.
        $lastRepeat = (config()->integer('medications.reminders.count') - 1) * $repeat;

        $groups = DB::transaction(function () use ($now, $late, $lastRepeat): Collection {
            // Приёмы, которые пациент сейчас отмечает, пропускаем: если он их не отметит,
            // напоминание уйдёт следующим запуском.
            $due = DoseEvent::query()
                ->where('status', DoseEventStatus::Scheduled)
                ->where('window_closes_at', '>', $now)
                ->where(fn (Builder $query) => $query
                    ->whereBetween('scheduled_at', [$now->subMinutes($lastRepeat + $late), $now])
                    ->orWhereBetween('snoozed_until', [$now->subMinutes($late), $now]))
                // Без включённых пушей напоминать некуда. Если пациент включит их через пару минут,
                // напоминание о приёме ещё придёт.
                ->whereHas('patient', fn (Builder $query) => $query->has('pushSubscriptions'))
                ->orderBy('id')
                ->lock('for update skip locked')
                ->get()
                ->filter(fn (DoseEvent $event): bool => $event->isReminderDue($now));

            if ($due->isNotEmpty()) {
                DoseEvent::query()->whereKey($due->modelKeys())->update(['reminded_at' => $now, 'snoozed_until' => null]);
            }

            return $due->groupBy(fn (DoseEvent $event): string => $event->patient_id.'@'.$event->scheduled_at->getTimestamp());
        });

        $groups->each(function (Collection $doses): void {
            $doses->load(['patient.patientProfile', 'prescription.drug', 'prescription.strength']);
            $doses->firstOrFail()->patient->notify(new DoseReminder($doses));
        });

        $this->components->info("Напоминаний: {$groups->count()}.");

        return self::SUCCESS;
    }
}
