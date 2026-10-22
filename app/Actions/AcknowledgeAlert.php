<?php

namespace App\Actions;

use App\Enums\UserRole;
use App\Events\AlertsChanged;
use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Врач принял алерт: красная плашка уходит, алерт остаётся открытым до решения.
 */
class AcknowledgeAlert
{
    /**
     * @return bool false, если алерт уже приняли или закрыли
     */
    public function handle(User $doctor, Alert $alert): bool
    {
        if ($doctor->role !== UserRole::Doctor) {
            throw new InvalidArgumentException('Алерт принимает только врач.');
        }

        return DB::transaction(function () use ($doctor, $alert): bool {
            $alert = Alert::query()->lockForUpdate()->findOrFail($alert->id);

            if ($alert->acknowledged_at !== null || $alert->closed_at !== null) {
                return false;
            }

            $alert->fill([
                'acknowledged_at' => CarbonImmutable::now(),
                'acknowledged_by_id' => $doctor->id,
            ])->save();

            $alert->loadMissing(['rule', 'patient']);

            AuditLog::record($doctor, 'alert.acknowledged', $alert->patient, $alert, null, [
                'rule' => $alert->rule->snapshot(),
                'evidence' => $alert->evidence,
            ]);

            AlertsChanged::dispatch($doctor);

            return true;
        });
    }
}
