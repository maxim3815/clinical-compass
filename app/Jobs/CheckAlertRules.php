<?php

namespace App\Jobs;

use App\Enums\AlertShownVia;
use App\Events\AlertsChanged;
use App\Models\Alert;
use App\Models\Assessment;
use App\Support\Alerts\AlertDelivery;
use App\Support\Alerts\AlertEngine;
use App\Support\Alerts\AlertMatch;
use Carbon\CarbonImmutable;
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
     * @param  int|null  $acknowledgedById  врач уже видел ответы (из очереди, до регистрации): алерт
     *                                      создаётся принятым, без плашки, звука и пуша
     */
    public function __construct(
        public Collection $assessments,
        public ?int $acknowledgedById = null,
        public ?CarbonImmutable $acknowledgedAt = null,
    ) {
        // Своя очередь: воркер берёт её раньше остальных, алерт не ждёт напоминаний и событий.
        $this->onQueue('alerts');
    }

    public function handle(AlertEngine $engine, AlertDelivery $delivery): void
    {
        foreach ($engine->check($this->assessments) as $match) {
            $alert = $this->create($match);

            if ($alert === null) {
                continue;
            }

            // Принятый алерт только появляется в карточке: терапевт уже видел эти ответы.
            DB::afterCommit(fn () => $alert->acknowledged_at !== null
                ? AlertsChanged::dispatch($alert->doctor)
                : $delivery->deliver($alert));
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
            ...($this->acknowledgedById === null ? [] : [
                'shown_at' => $this->acknowledgedAt,
                'shown_via' => AlertShownVia::Page,
                'acknowledged_at' => $this->acknowledgedAt,
                'acknowledged_by_id' => $this->acknowledgedById,
            ]),
        ]);

        return $alert->wasRecentlyCreated ? $alert : null;
    }
}
