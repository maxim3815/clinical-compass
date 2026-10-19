<?php

namespace App\Actions;

use App\Actions\Concerns\MarksDoses;
use App\Enums\DoseEventStatus;
use App\Enums\DoseSkipReason;
use App\Exceptions\DoseNotMarkable;
use App\Models\DoseEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Пациент отмечает, что не принял плановый приём, и указывает причину. Прежняя отметка заменяется.
 */
class SkipDose
{
    use MarksDoses;

    /**
     * @throws DoseNotMarkable
     */
    public function handle(User $patient, DoseEvent $dose, DoseSkipReason $reason, ?string $comment = null): void
    {
        if ($reason === DoseSkipReason::Other && $comment === null) {
            throw new InvalidArgumentException('Для причины «Другое» нужен комментарий.');
        }

        DB::transaction(function () use ($patient, $dose, $reason, $comment): void {
            $patient = $this->lockPatient($patient);
            $dose = $this->lockDoses($patient, [$dose])->sole();
            $now = CarbonImmutable::now();

            $this->ensureDue($patient, $dose, $now);

            $dose->update([
                'status' => DoseEventStatus::Skipped,
                'taken_at' => null,
                'marked_at' => $now,
                'skip_reason' => $reason,
                'skip_comment' => $comment,
            ]);
        });
    }
}
