<?php

namespace App\Actions;

use App\Enums\AdverseEventSeverity;
use App\Enums\UserRole;
use App\Models\AdverseEvent;
use App\Models\AuditLog;
use App\Models\SideEffect;
use App\Models\User;
use App\Support\AdverseEvents\SideEffectCatalog;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Врач записал побочный эффект со слов пациента на приёме. Алерта и очереди нет: врач уже знает,
 * запись сразу просмотрена. Группа — по нынешним препаратам пациента, если симптом есть в их
 * листках. Пациент видит запись в своём списке.
 */
class AddAdverseEvent
{
    public function handle(User $doctor, User $patient, ?SideEffect $symptom, ?string $other, AdverseEventSeverity $severity, CarbonImmutable $startedOn, ?string $comment): AdverseEvent
    {
        if ($doctor->role !== UserRole::Doctor) {
            throw new InvalidArgumentException('Запись со слов пациента добавляет врач.');
        }

        $other = $other === null || trim($other) === '' ? null : trim($other);
        $comment = $comment === null || trim($comment) === '' ? null : trim($comment);

        if (($symptom === null) === ($other === null)) {
            throw new InvalidArgumentException('Нужен симптом из списка или свой текст, одно из двух.');
        }

        $now = CarbonImmutable::now();
        $event = AdverseEvent::query()->create([
            'patient_id' => $patient->id,
            'side_effect_id' => $symptom?->id,
            'name' => $symptom->name ?? $other,
            'term' => $symptom?->term,
            'level' => $symptom === null ? null : (SideEffectCatalog::forPatient($patient)[$symptom->id]['level'] ?? null),
            'severity' => $severity,
            'started_on' => $startedOn,
            'reported_by_id' => $doctor->id,
            'reviewed_at' => $now,
            'reviewed_by_id' => $doctor->id,
            'comment' => $comment,
        ]);

        AuditLog::record($doctor, 'adverse_event.added', $patient, $event, null, [
            'name' => $event->name,
            'term' => $event->term,
            'severity' => $severity->value,
            'started_on' => $startedOn->toDateString(),
            'comment' => $comment,
        ]);

        return $event;
    }
}
