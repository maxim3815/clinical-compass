<?php

namespace App\Actions;

use App\Enums\AlertTrigger;
use App\Enums\UserRole;
use App\Jobs\DeliverAlert;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Пациент нажал «Сообщить врачу» на экране помощи: алерт лечащему врачу тем же путём, что по
 * ответам опросника. Подтверждения и отмены нет: ложная тревога дешевле пропущенной.
 *
 * Пока алерт по кнопке открыт, повторное нажатие пишется в него же и снова поднимает тревогу
 * у врача. Нажатия чаще раза в минуту (config help.repeat_seconds) врача не дёргают.
 */
class RequestHelp
{
    /**
     * Можно ли пациенту «Сообщить врачу»: есть лечащий врач и действующее правило по кнопке.
     */
    public static function available(User $patient): bool
    {
        return $patient->activeCareRelationship()->exists()
            && AlertRule::query()->active()->triggeredBy(AlertTrigger::HelpRequest)->exists();
    }

    /**
     * @return Alert|null алерт, в который записано нажатие; null — врача или правила нет
     */
    public function handle(User $patient): ?Alert
    {
        if ($patient->role !== UserRole::Patient) {
            throw new InvalidArgumentException('Сообщить врачу может только пациент.');
        }

        return DB::transaction(function () use ($patient): ?Alert {
            // Нажатия одного пациента идут по очереди: открытый алерт по кнопке у него один.
            User::query()->lockForUpdate()->findOrFail($patient->id);

            $doctorId = $patient->activeCareRelationship()->value('doctor_id');
            $rule = AlertRule::query()->active()->triggeredBy(AlertTrigger::HelpRequest)->first();

            if ($doctorId === null || $rule === null) {
                return null;
            }

            $now = CarbonImmutable::now();
            $alert = Alert::query()
                ->where('patient_id', $patient->id)
                ->where('alert_rule_id', $rule->id)
                ->whereNull('assessment_id')
                ->open()
                ->first();

            if ($alert === null) {
                $alert = Alert::query()->create([
                    'patient_id' => $patient->id,
                    'doctor_id' => $doctorId,
                    'alert_rule_id' => $rule->id,
                    'rule_version' => $rule->version,
                    'level' => $rule->level,
                    'assessment_id' => null,
                    'evidence' => [['type' => 'help_request']],
                    'submitted_at' => $now,
                ]);

                DeliverAlert::dispatch($alert);

                return $alert;
            }

            $last = $alert->repeated_at ?? $alert->submitted_at;

            if ($last->diffInSeconds($now) < (int) config('help.repeat_seconds')) {
                return $alert;
            }

            $alert->fill([
                'repeat_count' => $alert->repeat_count + 1,
                'repeated_at' => $now,
            ])->save();

            DeliverAlert::dispatch($alert, true);

            return $alert;
        });
    }
}
