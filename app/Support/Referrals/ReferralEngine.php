<?php

namespace App\Support\Referrals;

use App\Enums\ObservationValueType;
use App\Enums\ReferralCategory;
use App\Models\ObservationType;
use App\Models\ReferralRule;
use App\Models\Scale;
use App\Models\User;

/**
 * Подсказка направления к психиатру по правилам из файла. Ядро не знает шкал и порогов: они
 * в условиях правил. Правило про шкалу, которой у пациента нет, не проверяется, а шкала попадает
 * в список недостающих.
 *
 * @phpstan-import-type ReferralEvidence from ReferralSuggestion
 * @phpstan-import-type ReferralMatchData from ReferralSuggestion
 */
final readonly class ReferralEngine
{
    public function __construct(private ReferralLanguage $language) {}

    public function suggest(User $patient): ReferralSuggestion
    {
        return $this->check(ReferralData::load($patient));
    }

    public function check(ReferralData $data): ReferralSuggestion
    {
        $matches = [];
        $missing = [];

        foreach (ReferralRule::query()->active()->orderBy('position')->get() as $rule) {
            $references = $this->language->references($rule->condition);
            $scales = array_values(array_unique(array_map(
                fn (array $reference): string => $reference[1],
                array_filter($references, fn (array $reference): bool => $reference[0] !== 'fact'),
            )));
            $absent = array_filter($scales, fn (string $scale): bool => ! $data->has($scale));

            if ($absent !== []) {
                $missing = [...$missing, ...$absent];

                continue;
            }

            if ($this->language->evaluateFor($rule->condition, $data)) {
                $matches[] = [
                    'code' => $rule->code,
                    'category' => $rule->category->value,
                    'text' => $rule->text,
                    'evidence' => array_map(fn (array $reference): array => $this->evidence($reference, $data), $references),
                    'source' => $rule->source,
                ];
            }
        }

        // Сначала «обязательно»: сортировка устойчивая, внутри остаётся порядок правил в файле.
        usort($matches, fn (array $a, array $b): int => ReferralCategory::from($b['category'])->rank() <=> ReferralCategory::from($a['category'])->rank());

        return new ReferralSuggestion(
            ReferralCategory::strongest(array_map(fn (array $match): ReferralCategory => ReferralCategory::from($match['category']), $matches)),
            $matches,
            $this->missing(array_values(array_unique($missing))),
        );
    }

    /**
     * @param  array{0: 'answer', 1: string, 2: int}|array{0: 'total', 1: string}|array{0: 'fact', 1: string}  $reference
     * @return ReferralEvidence
     */
    private function evidence(array $reference, ReferralData $data): array
    {
        if ($reference[0] === 'fact') {
            $record = $data->factRecord($reference[1]);
            $type = $record->type ?? ObservationType::query()->where('code', $reference[1])->first();

            return [
                'type' => 'fact',
                'code' => $reference[1],
                'name' => $type->name ?? $reference[1],
                'value' => match (true) {
                    $record === null => 'не отмечено',
                    $type?->value_type === ObservationValueType::Boolean => $record->value_boolean ? 'да' : 'нет',
                    default => (string) $record->value(),
                },
            ];
        }

        $assessment = $data->assessment($reference[1]);
        $scale = $assessment->scale;

        if ($reference[0] === 'total') {
            return [
                'type' => 'total',
                'scale' => $scale->code,
                'name' => $scale->name,
                'total' => $assessment->total,
                'max' => $scale->maxTotal(),
                'band' => $assessment->band,
            ];
        }

        $score = $data->answer($scale->code, $reference[2]);

        return [
            'type' => 'answer',
            'scale' => $scale->code,
            'name' => $scale->name,
            'item' => $reference[2],
            'text' => $scale->items[$reference[2] - 1]['text'],
            'score' => $score,
            'label' => $scale->optionLabel($score),
        ];
    }

    /**
     * @param  list<string>  $codes
     * @return list<array{scale: string, name: string, rater: string}>
     */
    private function missing(array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        return array_values(Scale::query()
            ->whereIn('code', $codes)
            ->orderBy('rater', 'desc')
            ->orderBy('position')
            ->get()
            ->map(fn (Scale $scale): array => ['scale' => $scale->code, 'name' => $scale->name, 'rater' => $scale->rater->value])
            ->values()
            ->all());
    }
}
