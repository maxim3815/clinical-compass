<?php

namespace App\Console\Commands;

use App\Actions\SyncSafetyFindings;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class SyncSafetyFindingsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'app:safety:sync';

    /**
     * @var string
     */
    protected $description = 'Пересчитать находки проверки безопасности у всех пациентов';

    public function handle(SyncSafetyFindings $syncSafetyFindings): int
    {
        $patients = 0;

        // Пациенты с незакрытыми назначениями или открытыми находками: назначение могло завершиться
        // по дате, а возраст — смениться. Запас в день, как у плановых приёмов.
        User::query()
            ->where('role', UserRole::Patient)
            ->where(fn (Builder $query) => $query
                ->whereHas('prescriptions', fn (Builder $query) => $query
                    ->whereNull('closed_at')
                    ->where(fn (Builder $query) => $query
                        ->whereNull('ends_on')
                        ->orWhere('ends_on', '>=', now()->subDay()->toDateString())))
                ->orWhereHas('safetyFindings', fn (Builder $query) => $query->whereNull('closed_at')))
            ->lazyById()
            ->each(function (User $patient) use ($syncSafetyFindings, &$patients): void {
                $syncSafetyFindings->handle($patient);
                $patients++;
            });

        $this->components->info("Находки проверки безопасности пересчитаны. Пациентов: {$patients}.");

        return self::SUCCESS;
    }
}
