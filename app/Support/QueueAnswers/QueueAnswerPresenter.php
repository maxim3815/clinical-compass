<?php

namespace App\Support\QueueAnswers;

use App\Enums\ReferralCategory;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Assessment;
use App\Models\AssessmentAnswer;
use App\Models\ObservationType;
use App\Models\QueueAnswer;
use App\Models\User;
use App\Support\Assessments\PatientAssessments;
use App\Support\DisplayTime;
use App\Support\Referrals\ReferralEngine;
use Illuminate\Database\Eloquent\Collection;

/**
 * Ответы из очереди у терапевта: красная плашка, строки «Ответили сегодня» и страница кода.
 */
final class QueueAnswerPresenter
{
    /**
     * Ответы с сигналом по 9-му вопросу, которые терапевт ещё не открыл: красная плашка на всех
     * страницах кабинета. Сначала новые.
     *
     * @return list<array{id: int, code: string, rule: string, trigger: string, at: string}>
     */
    public static function banner(User $doctor): array
    {
        if (! $doctor->isTherapist()) {
            return [];
        }

        $answers = QueueAnswer::query()
            ->pending()
            ->where('doctor_id', $doctor->id)
            ->whereNotNull('alert')
            ->whereNull('opened_at')
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->get();

        $rules = AlertRule::query()
            ->whereIn('id', $answers->map(fn (QueueAnswer $answer): int => (int) $answer->alert['rule_id'])->unique()->all())
            ->pluck('name', 'id');

        return array_values($answers->map(fn (QueueAnswer $answer): array => [
            'id' => $answer->id,
            'code' => $answer->code,
            'rule' => (string) ($rules[$answer->alert['rule_id'] ?? 0] ?? 'Алерт'),
            'trigger' => self::trigger($answer),
            'at' => DisplayTime::format($answer->submitted_at),
        ])->all());
    }

    /**
     * Непривязанные ответы терапевта для «Ответили сегодня»: суммы, 9-й вопрос и подсказка.
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(User $doctor, ReferralEngine $engine): array
    {
        return array_values(QueueAnswer::query()
            ->pending()
            ->where('doctor_id', $doctor->id)
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->get()
            ->map(function (QueueAnswer $answer) use ($engine): array {
                $assessments = $answer->assessments(withDoctorScale: false);
                $suggestion = $engine->check($answer->referralData());

                return [
                    'kind' => 'queue',
                    'id' => $answer->id,
                    'code' => $answer->code,
                    'at' => $answer->submitted_at->setTimezone(DisplayTime::TIMEZONE)->format('H:i'),
                    'timestamp' => $answer->submitted_at->getTimestamp(),
                    'scales' => self::totals($assessments),
                    'highlight' => self::highlighted($assessments),
                    'alert' => $answer->alert !== null,
                    // Красная плашка ещё горит: терапевт ответы не открывал.
                    'unopened' => $answer->awaitsOpening(),
                    'suggestion' => $suggestion->category?->value,
                    'scales_missing' => array_column($suggestion->missing, 'name'),
                    'decision' => $answer->isDeclinedFor($suggestion->category) ? ['kind' => 'declined', 'category' => null] : null,
                    // Терапевт уже открывал ответы: время для строки списка.
                    'opened_at' => $answer->opened_at?->setTimezone(DisplayTime::TIMEZONE)->format('H:i'),
                ];
            })
            ->all());
    }

    /**
     * Страница кода у терапевта. Решение «не направлять» устарело, если подсказка с тех пор выросла.
     *
     * @return array<string, mixed>
     */
    public static function present(QueueAnswer $answer, ?ReferralCategory $suggestion = null): array
    {
        return [
            'id' => $answer->id,
            'code' => $answer->code,
            'submitted_at' => $answer->submitted_at->setTimezone(DisplayTime::TIMEZONE)->format('H:i'),
            'expires_at' => $answer->expiresLabel(),
            'alert' => $answer->alert === null ? null : [
                'rule' => (string) (AlertRule::query()->whereKey($answer->alert['rule_id'])->value('name') ?? 'Алерт'),
                'trigger' => self::trigger($answer),
            ],
            'declined' => $answer->declined_at === null ? null : [
                'at' => DisplayTime::format($answer->declined_at),
                'comment' => $answer->decline_comment,
                'suggestion' => $answer->declined_suggestion?->value,
                'current' => $answer->isDeclinedFor($suggestion),
            ],
            // Признаки, которые терапевт отметил вместе с HARS.
            'facts' => self::facts($answer),
        ];
    }

    /**
     * Ответы на каждый вопрос: опросники пациента и HARS, если терапевт её заполнил.
     *
     * @return list<array<string, mixed>>
     */
    public static function fillings(QueueAnswer $answer): array
    {
        $raters = User::query()->whereKey($answer->doctor_scale['rated_by_id'] ?? 0)->get()->keyBy('id');

        return array_values($answer->assessments()
            ->each(fn (Assessment $assessment) => $assessment->setRelation('ratedBy', $raters[$assessment->rated_by_id ?? 0] ?? null))
            ->map(fn (Assessment $assessment, int $index): array => [
                ...PatientAssessments::presentFilled($assessment),
                // Заполнения не записаны в базу: ключ для списка — номер по порядку.
                'id' => $index + 1,
                'submitted_at' => $assessment->rated_by_id === null ? null : DisplayTime::format($assessment->submitted_at),
            ])
            ->all());
    }

    /**
     * @return list<array{name: string, value: bool}>
     */
    private static function facts(QueueAnswer $answer): array
    {
        $names = ObservationType::query()->whereIn('code', array_keys($answer->facts ?? []))->pluck('name', 'code');
        $facts = [];

        foreach ($answer->facts ?? [] as $code => $value) {
            if (isset($names[$code])) {
                $facts[] = ['name' => (string) $names[$code], 'value' => $value];
            }
        }

        return $facts;
    }

    /**
     * Что сработало: «PHQ-9, вопрос 9: «несколько дней»».
     */
    private static function trigger(QueueAnswer $answer): string
    {
        return (new Alert(['evidence' => $answer->alert['evidence'] ?? []]))->trigger();
    }

    /**
     * @param  Collection<int, Assessment>  $assessments
     * @return list<array{name: string, total: int, max: int, band: string}>
     */
    private static function totals(Collection $assessments): array
    {
        return array_values($assessments
            ->sortBy(fn (Assessment $assessment): int => $assessment->scale->position)
            ->map(fn (Assessment $assessment): array => [
                'name' => $assessment->scale->name,
                'total' => $assessment->total,
                'max' => $assessment->scale->maxTotal(),
                'band' => $assessment->band,
            ])
            ->all());
    }

    /**
     * Ответ на отмеченный вопрос (9-й PHQ-9) не «совсем нет».
     *
     * @param  Collection<int, Assessment>  $assessments
     */
    private static function highlighted(Collection $assessments): bool
    {
        return $assessments->contains(fn (Assessment $assessment): bool => $assessment->answers->contains(
            fn (AssessmentAnswer $answer): bool => ($assessment->scale->items[$answer->item - 1]['highlight'] ?? false) && $answer->score > 0,
        ));
    }
}
