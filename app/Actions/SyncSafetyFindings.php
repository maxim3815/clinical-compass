<?php

namespace App\Actions;

use App\Actions\Concerns\DecidesSafetyFindings;
use App\Events\PatientSignalsChanged;
use App\Models\Prescription;
use App\Models\SafetyFinding;
use App\Models\User;
use App\Support\Safety\PatientSafetyData;
use App\Support\Safety\RuleResult;
use App\Support\Safety\SafetyDecision;
use App\Support\Safety\SafetyEngine;
use Illuminate\Support\Facades\DB;

/**
 * Находки по всем препаратам, которые пациент принимает: создаёт новые, закрывает те, условие
 * которых больше не выполняется, и переносит находки изменённого назначения на новую версию.
 * Вызывается после любого изменения данных пациента и после загрузки правил. Повторный вызов
 * ничего не меняет.
 */
class SyncSafetyFindings
{
    use DecidesSafetyFindings;

    public function __construct(private readonly SafetyEngine $engine) {}

    /**
     * @param  SafetyDecision|null  $decision  обоснование врача к красным находкам, которые он видел в форме
     */
    public function handle(User $patient, ?SafetyDecision $decision = null): void
    {
        DB::transaction(function () use ($patient, $decision): void {
            // Синхронизации одного пациента идут по очереди, как у плановых приёмов.
            $patient = User::query()->whereKey($patient->id)->lockForUpdate()->firstOrFail();
            $data = PatientSafetyData::load($patient);
            $now = now();

            $results = [];

            foreach ($data->medications as $medication) {
                foreach ($this->engine->check($data, $medication, $data->othersThan($medication))->results as $result) {
                    $results[$result->key()] = $result;
                }
            }

            $currentOf = $this->currentVersions($patient, $data);
            $changed = false;

            $open = SafetyFinding::query()
                ->where('patient_id', $patient->id)
                ->open()
                ->with('rule')
                ->orderBy('id')
                ->get();

            foreach ($open as $finding) {
                $current = $currentOf[$finding->prescription_id] ?? null;
                $key = RuleResult::keyOf($finding->rule_id, $current);
                $result = $current === null ? null : ($results[$key] ?? null);

                if ($result === null) {
                    $finding->update(['closed_at' => $now]);
                    $changed = true;

                    continue;
                }

                unset($results[$key]);

                $finding->fill([
                    'prescription_id' => $current,
                    'level' => $result->rule->level,
                    'evidence' => $result->evidence,
                    'related_prescription_ids' => $result->relatedPrescriptionIds,
                ]);
                $changed = $changed || $finding->isDirty('level');
                $finding->save();

                if ($decision?->covers($key) && $finding->awaitsDecision()) {
                    $this->decide($finding, $decision);
                    $changed = true;
                }
            }

            foreach ($results as $key => $result) {
                $finding = SafetyFinding::query()->create([
                    'patient_id' => $patient->id,
                    'prescription_id' => $result->target->prescriptionId,
                    'rule_id' => $result->rule->id,
                    'rule_version' => $result->rule->version,
                    'level' => $result->rule->level,
                    'evidence' => $result->evidence,
                    'related_prescription_ids' => $result->relatedPrescriptionIds,
                    'opened_at' => $now,
                ]);
                $changed = true;

                if ($decision?->covers($key) && $finding->awaitsDecision()) {
                    $this->decide($finding, $decision);
                }
            }

            if ($changed) {
                PatientSignalsChanged::dispatchForDoctorOf($patient);
            }
        });
    }

    /**
     * Версия назначения => действующая версия того же назначения. Находка изменённого назначения
     * переходит к новой версии вместе с решением врача.
     *
     * @return array<int, int>
     */
    private function currentVersions(User $patient, PatientSafetyData $data): array
    {
        $previous = Prescription::query()
            ->where('patient_id', $patient->id)
            ->whereNotNull('previous_id')
            ->pluck('previous_id', 'id')
            ->all();

        $currentOf = [];

        foreach ($data->medications as $medication) {
            for ($id = $medication->prescriptionId; $id !== null; $id = $previous[$id] ?? null) {
                $currentOf[$id] = (int) $medication->prescriptionId;
            }
        }

        return $currentOf;
    }
}
