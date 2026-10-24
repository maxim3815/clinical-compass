<?php

namespace App\Actions;

use App\Enums\AlertDecision;
use App\Enums\UserRole;
use App\Events\AlertsChanged;
use App\Models\AdverseEvent;
use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Врач закрывает алерт решением, у «Угрозы нет» — с комментарием. Решение пишется в алерт
 * и в журнал аудита со снимком правила. Непринятый алерт сначала принимается. Побочные эффекты
 * этого алерта становятся просмотренными.
 */
class CloseAlert
{
    public function __construct(private readonly AcknowledgeAlert $acknowledgeAlert) {}

    /**
     * @return bool false, если алерт уже закрыт
     */
    public function handle(User $doctor, Alert $alert, AlertDecision $decision, ?string $comment): bool
    {
        if ($doctor->role !== UserRole::Doctor) {
            throw new InvalidArgumentException('Алерт закрывает только врач.');
        }

        $comment = $comment === null || trim($comment) === '' ? null : trim($comment);

        if ($decision->requiresComment() && $comment === null) {
            throw new InvalidArgumentException('У решения «'.$decision->label().'» нужен комментарий.');
        }

        return DB::transaction(function () use ($doctor, $alert, $decision, $comment): bool {
            $this->acknowledgeAlert->handle($doctor, $alert);
            $alert = Alert::query()->lockForUpdate()->findOrFail($alert->id);

            if ($alert->closed_at !== null) {
                return false;
            }

            $alert->fill([
                'closed_at' => CarbonImmutable::now(),
                'closed_by_id' => $doctor->id,
                'decision' => $decision,
                'decision_comment' => $comment,
            ])->save();

            // Срочные побочные эффекты врач разобрал вместе с алертом.
            AdverseEvent::query()
                ->where('alert_id', $alert->id)
                ->awaitingReview()
                ->update(['reviewed_at' => $alert->closed_at, 'reviewed_by_id' => $doctor->id]);

            $alert->loadMissing(['rule', 'patient']);

            AuditLog::record($doctor, 'alert.closed', $alert->patient, $alert, null, [
                'rule' => $alert->rule->snapshot(),
                'evidence' => $alert->evidence,
                'decision' => $decision->value,
                'decision_comment' => $comment,
            ]);

            AlertsChanged::dispatch($doctor);

            return true;
        });
    }
}
