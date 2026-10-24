<?php

namespace App\Actions;

use App\Enums\UserRole;
use App\Events\PatientSignalsChanged;
use App\Models\AdverseEvent;
use App\Models\AuditLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Врач отметил побочный эффект просмотренным: пациент уходит из очереди «Требуют внимания».
 * Срочный побочный эффект с открытым алертом так не отмечается: его разбирают решением по алерту.
 */
class ReviewAdverseEvent
{
    /**
     * @return bool false, если уже просмотрен или ждёт решения по алерту
     */
    public function handle(User $doctor, AdverseEvent $event): bool
    {
        if ($doctor->role !== UserRole::Doctor) {
            throw new InvalidArgumentException('Побочный эффект просматривает врач.');
        }

        return DB::transaction(function () use ($doctor, $event): bool {
            $event = AdverseEvent::query()->with(['alert', 'patient'])->lockForUpdate()->findOrFail($event->id);

            if ($event->reviewed_at !== null || ($event->alert !== null && $event->alert->closed_at === null)) {
                return false;
            }

            $event->update(['reviewed_at' => CarbonImmutable::now(), 'reviewed_by_id' => $doctor->id]);

            AuditLog::record($doctor, 'adverse_event.reviewed', $event->patient, $event, null, [
                'name' => $event->name,
                'term' => $event->term,
                'level' => $event->level?->value,
                'severity' => $event->severity->value,
            ]);

            PatientSignalsChanged::dispatch($doctor);

            return true;
        });
    }
}
