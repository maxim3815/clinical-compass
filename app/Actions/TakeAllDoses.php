<?php

namespace App\Actions;

use App\Actions\Concerns\MarksDoses;
use App\Enums\DoseEventStatus;
use App\Exceptions\DoseNotMarkable;
use App\Models\DoseEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * «Принял всё»: приёмы на одно время отмечаются принятыми сейчас. Уже отмеченные не меняются.
 * Если хотя бы один приём отметить нельзя, не отмечается ни один.
 */
class TakeAllDoses
{
    use MarksDoses;

    /**
     * @param  iterable<DoseEvent>  $doses
     * @return int сколько приёмов отмечено
     *
     * @throws DoseNotMarkable
     */
    public function handle(User $patient, iterable $doses): int
    {
        return DB::transaction(function () use ($patient, $doses): int {
            $patient = $this->lockPatient($patient);
            $now = CarbonImmutable::now();
            $marked = 0;

            foreach ($this->lockDoses($patient, $doses) as $dose) {
                if ($dose->status->isMarked()) {
                    continue;
                }

                $this->ensureDue($patient, $dose, $now);

                $dose->update([
                    'status' => DoseEventStatus::Taken,
                    'taken_at' => $now,
                    'marked_at' => $now,
                ]);
                $marked++;
            }

            return $marked;
        });
    }
}
