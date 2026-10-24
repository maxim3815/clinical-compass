<?php

namespace App\Support\Alerts;

use App\Enums\AlertShownVia;
use App\Models\Alert;
use App\Models\CareRelationship;
use App\Models\User;
use App\Support\DisplayTime;
use App\Support\PersonName;
use Illuminate\Database\Eloquent\Builder;

/**
 * Алерты для врача: красная плашка на всех страницах кабинета, причина в очереди «Требуют
 * внимания» и история в карточке пациента. Алерты видит текущий лечащий врач пациента:
 * при передаче пациента открытые алерты переходят к новому врачу.
 *
 * Вид алерта (`kind`): `answers` — сработало правило по ответам опросника, `help_request` —
 * пациент нажал «Сообщить врачу», `adverse_event` — пациент сообщил о срочном побочном эффекте.
 * У двух последних есть повторы (`repeats`).
 */
final class AlertPresenter
{
    /**
     * Алерты пациентов врача, которые ждут «Принять», сначала новые: по ним красная плашка.
     *
     * @return list<array<string, mixed>>
     */
    public static function banner(User $doctor): array
    {
        return array_values(self::ofPatientsOf($doctor)
            ->awaitingAcknowledgement()
            ->with(['patient', 'rule'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Alert $alert): array => [
                'id' => $alert->id,
                'patient_id' => $alert->patient_id,
                'patient' => $alert->patient->name,
                'rule' => $alert->rule->name,
                'trigger' => $alert->trigger(),
                'kind' => self::kind($alert),
                'at' => DisplayTime::format($alert->submitted_at),
                'repeats' => self::repeats($alert),
                'shown' => $alert->shown_at !== null,
            ])
            ->all());
    }

    /**
     * Открытые алерты пациентов врача: причина в очереди «Требуют внимания».
     *
     * @return array<int, list<array{rule: string, trigger: string, kind: string, acknowledged: bool, repeated: bool}>> id пациента => алерты, сначала новые
     */
    public static function openByPatient(User $doctor): array
    {
        return self::ofPatientsOf($doctor)
            ->open()
            ->with('rule')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('patient_id')
            ->map(fn ($alerts): array => array_values($alerts->map(fn (Alert $alert): array => [
                'rule' => $alert->rule->name,
                'trigger' => $alert->trigger(),
                'kind' => self::kind($alert),
                'acknowledged' => ! $alert->awaitsAcknowledgement(),
                // Пациент снова нажимал «Сообщить врачу» или сообщал о срочном побочном эффекте
                // после того, как врач уже принимал алерт.
                'repeated' => $alert->acknowledged_at !== null && $alert->repeat_count > 0,
            ])->all()))
            ->all();
    }

    /**
     * История алертов пациента в карточке, сначала новые.
     *
     * @return list<array<string, mixed>>
     */
    public static function forPatient(User $patient): array
    {
        return array_values($patient->alerts()
            ->with(['rule', 'acknowledgedBy', 'closedBy'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Alert $alert): array => self::present($alert))
            ->all());
    }

    /**
     * Длительность для врача: «0,35 с», «1,8 с», «12 с», «3 мин 20 с», «2 ч 5 мин».
     */
    public static function duration(float $seconds): string
    {
        if ($seconds < 1) {
            return number_format($seconds, 2, ',', '').' с';
        }

        if ($seconds < 10) {
            return number_format($seconds, 1, ',', '').' с';
        }

        $seconds = (int) round($seconds);

        if ($seconds < 60) {
            return "{$seconds} с";
        }

        if ($seconds < 3600) {
            return intdiv($seconds, 60).' мин'.($seconds % 60 > 0 ? ' '.($seconds % 60).' с' : '');
        }

        $minutes = intdiv($seconds % 3600, 60);

        return intdiv($seconds, 3600).' ч'.($minutes > 0 ? " {$minutes} мин" : '');
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(Alert $alert): array
    {
        $delivery = $alert->deliverySeconds();

        return [
            'id' => $alert->id,
            'rule' => $alert->rule->name,
            'text' => $alert->rule->text,
            'trigger' => $alert->trigger(),
            // Вопросы целиком: в строке «что сработало» только номер вопроса.
            'answers' => array_values(array_map(
                fn (array $entry): string => "{$entry['item']}. {$entry['text']}: «".mb_lcfirst($entry['label']).'»',
                array_filter($alert->evidence, fn (array $entry): bool => $entry['type'] === 'answer'),
            )),
            // Срочные побочные эффекты: термин, слова пациента и что ему показано.
            'symptoms' => array_map(
                fn (array $entry): string => "{$entry['term']}: «{$entry['name']}»"
                    .($entry['level'] === 'emergency' ? ', пациенту показано «Позвоните 112»' : ''),
                $alert->adverseEvidence(),
            ),
            'source' => $alert->rule->source(),
            'kind' => self::kind($alert),
            'submitted_at' => DisplayTime::format($alert->submitted_at),
            'repeats' => self::repeats($alert),
            // Пациент снова нажал «Сообщить врачу» после «Принять»: кнопка «Принять» нужна снова.
            'awaits_acknowledgement' => $alert->awaitsAcknowledgement(),
            'shown' => $alert->shown_at === null || $delivery === null ? null : [
                'at' => DisplayTime::format($alert->shown_at),
                'after' => self::duration($delivery),
                'realtime' => $alert->shown_via === AlertShownVia::Realtime,
            ],
            'pushed' => $alert->pushed_at !== null,
            'acknowledged' => $alert->acknowledged_at === null ? null : [
                'at' => DisplayTime::format($alert->acknowledged_at),
                'by' => $alert->acknowledgedBy === null ? null : PersonName::short($alert->acknowledgedBy->name),
            ],
            'closed' => $alert->closed_at === null || $alert->decision === null ? null : [
                'at' => DisplayTime::format($alert->closed_at),
                'by' => $alert->closedBy === null ? null : PersonName::short($alert->closedBy->name),
                'decision' => $alert->decision->label(),
                'comment' => $alert->decision_comment,
            ],
        ];
    }

    private static function kind(Alert $alert): string
    {
        return $alert->kind()->value;
    }

    /**
     * Повторы: сколько раз пациент снова нажал «Сообщить врачу» или сообщил о срочном побочном
     * эффекте и когда последний.
     *
     * @return array{count: int, at: string}|null
     */
    private static function repeats(Alert $alert): ?array
    {
        return $alert->repeated_at === null ? null : [
            'count' => $alert->repeat_count,
            'at' => DisplayTime::format($alert->repeated_at),
        ];
    }

    /**
     * @return Builder<Alert>
     */
    private static function ofPatientsOf(User $doctor): Builder
    {
        return Alert::query()->whereIn('patient_id', CareRelationship::query()
            ->active()
            ->where('doctor_id', $doctor->id)
            ->select('patient_id'));
    }
}
