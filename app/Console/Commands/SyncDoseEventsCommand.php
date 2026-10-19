<?php

namespace App\Console\Commands;

use App\Actions\SyncDoseEvents;
use App\Enums\PrescriptionKind;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class SyncDoseEventsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'app:dose-events:sync';

    /**
     * @var string
     */
    protected $description = 'Создать плановые приёмы на сегодня и завтра для всех пациентов';

    public function handle(SyncDoseEvents $syncDoseEvents): int
    {
        $patients = 0;

        // Пациенты с незакрытыми регулярными назначениями. Запас в день: даты окончания
        // считаются по поясу пациента, а он может быть восточнее UTC.
        User::query()
            ->where('role', UserRole::Patient)
            ->whereHas('prescriptions', fn (Builder $query) => $query
                ->whereNull('closed_at')
                ->where('kind', PrescriptionKind::Regular)
                ->where(fn (Builder $query) => $query
                    ->whereNull('ends_on')
                    ->orWhere('ends_on', '>=', now()->subDay()->toDateString())))
            ->lazyById()
            ->each(function (User $patient) use ($syncDoseEvents, &$patients): void {
                $syncDoseEvents->handle($patient);
                $patients++;
            });

        $this->components->info("Плановые приёмы обновлены. Пациентов: {$patients}.");

        return self::SUCCESS;
    }
}
