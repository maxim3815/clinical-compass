<?php

namespace App\Actions;

use App\Enums\AdverseEventSeverity;
use App\Enums\AlertTrigger;
use App\Enums\SideEffectLevel;
use App\Enums\UserRole;
use App\Events\PatientSignalsChanged;
use App\Jobs\DeliverAlert;
use App\Models\AdverseEvent;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\User;
use App\Support\AdverseEvents\AdverseEventReport;
use App\Support\AdverseEvents\SideEffectCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Пациент сообщил о побочных эффектах: каждый симптом — отдельная запись. Срочный симптом даёт
 * алерт лечащему врачу тем же путём, что «Сообщить врачу»: пока алерт по побочным эффектам
 * открыт, новый срочный симптом пишется в него же и снова поднимает тревогу. Обычные симптомы
 * ждут врача в очереди «Требуют внимания» без пуша.
 *
 * @phpstan-import-type Instruction from SideEffectCatalog
 */
class ReportAdverseEvents
{
    /**
     * @param  list<int>  $symptomIds  симптомы из списка пациента (SideEffectCatalog::forPatient)
     * @param  string|null  $other  «другое» своими словами
     * @return AdverseEventReport|null null — лечащего врача нет, сообщать некому
     */
    public function handle(User $patient, array $symptomIds, ?string $other, AdverseEventSeverity $severity, CarbonImmutable $startedOn): ?AdverseEventReport
    {
        if ($patient->role !== UserRole::Patient) {
            throw new InvalidArgumentException('О побочном эффекте сообщает пациент.');
        }

        $other = $other === null || trim($other) === '' ? null : trim($other);

        if ($symptomIds === [] && $other === null) {
            throw new InvalidArgumentException('Нужен хотя бы один симптом.');
        }

        return DB::transaction(function () use ($patient, $symptomIds, $other, $severity, $startedOn): ?AdverseEventReport {
            // Отчёты одного пациента идут по очереди: открытый алерт по побочным эффектам у него один.
            User::query()->lockForUpdate()->findOrFail($patient->id);

            $doctorId = $patient->activeCareRelationship()->value('doctor_id');

            if ($doctorId === null) {
                return null;
            }

            $catalog = SideEffectCatalog::forPatient($patient);
            $events = [];
            $level = null;
            /** @var array<string, Instruction> $instructions */
            $instructions = [];

            foreach (array_unique($symptomIds) as $id) {
                $entry = $catalog[$id] ?? throw new InvalidArgumentException("Симптома {$id} нет в списке пациента.");

                $events[] = $this->record($patient, [
                    'side_effect_id' => $id,
                    'name' => $entry['name'],
                    'term' => $entry['term'],
                    'level' => $entry['level'],
                ], $severity, $startedOn);

                if ($entry['level']->isUrgent()) {
                    $level = $level === null ? $entry['level'] : SideEffectLevel::strongest($level, $entry['level']);
                }

                if ($entry['level'] === SideEffectLevel::Emergency) {
                    foreach ($entry['instructions'] as $instruction) {
                        $instructions[$instruction['drug'].'|'.$instruction['text']] = $instruction;
                    }
                }
            }

            // «Другое» приложение не разбирает: алерта по нему нет.
            if ($other !== null) {
                $events[] = $this->record($patient, ['name' => $other], $severity, $startedOn);
            }

            $urgent = array_values(array_filter($events, fn (AdverseEvent $event): bool => $event->level?->isUrgent() === true));
            $alert = $urgent === [] ? null : $this->alert($patient, $doctorId, $urgent);

            if ($alert === null) {
                PatientSignalsChanged::dispatchForDoctorOf($patient);
            }

            return new AdverseEventReport($events, $level, $alert, array_values($instructions));
        });
    }

    /**
     * @param  array<string, mixed>  $symptom
     */
    private function record(User $patient, array $symptom, AdverseEventSeverity $severity, CarbonImmutable $startedOn): AdverseEvent
    {
        return AdverseEvent::query()->create([
            ...$symptom,
            'patient_id' => $patient->id,
            'severity' => $severity,
            'started_on' => $startedOn,
            'reported_by_id' => $patient->id,
        ]);
    }

    /**
     * Алерт по срочным симптомам: новый или повтор открытого. Без действующего правила алерта нет,
     * и записи ждут врача в очереди, как обычные.
     *
     * @param  non-empty-list<AdverseEvent>  $events
     */
    private function alert(User $patient, int $doctorId, array $events): ?Alert
    {
        $rule = AlertRule::query()->active()->triggeredBy(AlertTrigger::AdverseEvent)->first();

        if ($rule === null) {
            return null;
        }

        $now = CarbonImmutable::now();
        $evidence = array_map(fn (AdverseEvent $event): array => [
            'type' => 'adverse_event',
            'name' => $event->name,
            'term' => (string) $event->term,
            'level' => $event->level?->value,
        ], $events);

        $alert = Alert::query()
            ->where('patient_id', $patient->id)
            ->where('alert_rule_id', $rule->id)
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
                'evidence' => $evidence,
                'submitted_at' => $now,
            ]);

            DeliverAlert::dispatch($alert);
        } else {
            $alert->fill([
                'evidence' => [...$alert->evidence, ...$evidence],
                'repeat_count' => $alert->repeat_count + 1,
                'repeated_at' => $now,
            ])->save();

            DeliverAlert::dispatch($alert, true);
        }

        AdverseEvent::query()->whereKey(array_map(fn (AdverseEvent $event): int => $event->id, $events))->update(['alert_id' => $alert->id]);

        foreach ($events as $event) {
            $event->alert_id = $alert->id;
        }

        return $alert;
    }
}
