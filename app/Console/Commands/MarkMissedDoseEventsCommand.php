<?php

namespace App\Console\Commands;

use App\Enums\DoseEventStatus;
use App\Models\DoseEvent;
use Illuminate\Console\Command;

class MarkMissedDoseEventsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'app:dose-events:mark-missed';

    /**
     * @var string
     */
    protected $description = 'Отметить пропущенными плановые приёмы, у которых закрылось окно';

    public function handle(): int
    {
        $missed = DoseEvent::query()
            ->where('status', DoseEventStatus::Scheduled)
            ->where('window_closes_at', '<=', now())
            ->update(['status' => DoseEventStatus::Missed]);

        $this->components->info("Пропущенных приёмов: {$missed}.");

        return self::SUCCESS;
    }
}
