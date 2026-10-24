<?php

namespace App\Jobs;

use App\Models\Alert;
use App\Models\Assessment;
use App\Support\Alerts\AlertDelivery;
use App\Support\Alerts\AlertEngine;
use App\Support\Alerts\AlertMatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Проверка правил алертинга по опросникам, отправленным вместе. Задача ставится в очередь
 * в той же транзакции, что и ответы: очередь лежит в PostgreSQL, поэтому ответы и проверка
 * сохраняются вместе или не сохраняются вовсе.
 *
 * Сработавшее правило создаёт алерт лечащему врачу, а доставка идёт тут же, без новых задач:
 * событие в открытый кабинет и пуш (AlertDelivery).
 */
class CheckAlertRules implements ShouldQueue
{
    use Queueable;

    /**
     * Повтор безопасен: второй алерт на то же заполнение не создаётся.
     */
    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [1, 5];

    /**
     * @param  Collection<int, Assessment>  $assessments
     */
    public function __construct(public Collection $assessments)
    {
        // Своя очередь: воркер берёт её раньше остальных, алерт не ждёт напоминаний и событий.
        $this->onQueue('alerts');
    }

    public function handle(AlertEngine $engine, AlertDelivery $delivery): void
    {
        foreach ($engine->check($this->assessments) as $match) {
            $alert = $this->create($match);

            if ($alert !== null) {
                DB::afterCommit(fn () => $delivery->deliver($alert));
            }
        }
    }

    /**
     * Алерт текущему лечащему врачу. Null — алерт на это заполнение уже есть или врача нет.
     */
    private function create(AlertMatch $match): ?Alert
    {
        $patient = $match->assessment->patient;
        $doctorId = $patient->activeCareRelationship?->doctor_id;

        if ($doctorId === null) {
            return null;
        }

        $alert = Alert::query()->createOrFirst([
            'alert_rule_id' => $match->rule->id,
            'assessment_id' => $match->assessment->id,
        ], [
            'patient_id' => $patient->id,
            'doctor_id' => $doctorId,
            'rule_version' => $match->rule->version,
            'level' => $match->rule->level,
            'evidence' => $match->evidence,
            'submitted_at' => $match->assessment->submitted_at,
        ]);

        return $alert->wasRecentlyCreated ? $alert : null;
    }
}
