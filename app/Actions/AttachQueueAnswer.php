<?php

namespace App\Actions;

use App\Enums\ScaleRater;
use App\Events\PatientSignalsChanged;
use App\Events\QueueAnswersChanged;
use App\Jobs\CheckAlertRules;
use App\Models\Assessment;
use App\Models\AuditLog;
use App\Models\ObservationType;
use App\Models\QueueAnswer;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Пациент зарегистрировался у терапевта по PIN, и ответы из очереди переходят в его аккаунт:
 * опросники становятся обычными заполнениями с исходным временем ответа, HADS — заполнением шкалы
 * врача, признаки — фактами профиля. Заполнение дня 0 этим закрыто.
 *
 * Правила алертинга проверяются заново, теперь уже с пациентом. Если терапевт ответы открывал,
 * алерт создаётся принятым: плашка и звук посреди приёма не нужны, решение — в карточке.
 */
class AttachQueueAnswer
{
    public function __construct(private readonly RecordObservation $recordObservation) {}

    /**
     * @return bool false — ответы уже привязаны, истекли или удалены
     */
    public function handle(QueueAnswer $answer, User $patient): bool
    {
        return DB::transaction(function () use ($answer, $patient): bool {
            $answer = QueueAnswer::query()->whereKey($answer->id)->lockForUpdate()->first();

            if ($answer === null || ! $answer->isPending()) {
                return false;
            }

            $therapist = $answer->doctor;
            $timezone = $patient->localTimezone();
            /** @var Collection<int, Assessment> $created */
            $created = new Collection;

            foreach ($answer->assessments() as $draft) {
                $assessment = Assessment::query()->create([
                    'patient_id' => $patient->id,
                    'scale_id' => $draft->scale_id,
                    'scale_version' => $draft->scale_version,
                    'total' => $draft->total,
                    'band' => $draft->band,
                    'subscores' => $draft->subscores,
                    'local_date' => QueueAnswer::localDate($draft->submitted_at, $timezone),
                    'submitted_at' => $draft->submitted_at,
                    'rated_by_id' => $draft->rated_by_id,
                ]);

                $assessment->answers()->createMany($draft->answers->map(fn ($answer): array => [
                    'item' => $answer->item,
                    'score' => $answer->score,
                ])->all());

                $created->push($assessment->setRelation('scale', $draft->scale));
            }

            $doctorScale = $created->first(fn (Assessment $assessment): bool => $assessment->scale->rater === ScaleRater::Doctor);
            $types = ObservationType::query()->whereIn('code', array_keys($answer->facts ?? []))->get()->keyBy('code');

            foreach ($answer->facts ?? [] as $code => $value) {
                $this->recordObservation->handle($therapist, $patient, $types[$code], $value, null, $doctorScale->local_date ?? QueueAnswer::localDate($answer->submitted_at, $timezone));
            }

            if ($doctorScale !== null) {
                AuditLog::record($therapist, 'assessment.rated', $patient, $doctorScale, null, [
                    'scale' => $doctorScale->scale->code,
                    'total' => $doctorScale->total,
                    'band' => $doctorScale->band,
                    'subscores' => $doctorScale->subscores,
                    'scores' => $answer->doctor_scale['scores'] ?? [],
                    'facts' => $answer->facts ?? [],
                    'queue_code' => $answer->code,
                ]);
            }

            $answer->update(['patient_id' => $patient->id, 'attached_at' => now()]);

            AuditLog::record($patient, 'queue_answer.attached', $patient, $answer, null, [
                'code' => $answer->code,
                'assessments' => $created->pluck('id')->all(),
            ]);

            CheckAlertRules::dispatch(
                $created->filter(fn (Assessment $assessment): bool => $assessment->rated_by_id === null)->values(),
                $answer->opened_at === null ? null : $therapist->id,
                $answer->opened_at,
            );

            // Строка с кодом в «Ответили сегодня» становится строкой пациента.
            PatientSignalsChanged::dispatch($therapist);
            QueueAnswersChanged::dispatch($therapist);

            return true;
        });
    }
}
