<?php

namespace App\Support\Alerts;

use App\Models\AlertRule;
use App\Models\Assessment;
use Illuminate\Database\Eloquent\Collection;

/**
 * Проверка правил алертинга по опросникам, которые пациент отправил вместе. Ядро ничего не знает
 * о конкретных шкалах и порогах: они в условиях правил.
 *
 * @phpstan-import-type Evidence from \App\Models\Alert
 */
final readonly class AlertEngine
{
    public function __construct(private AlertLanguage $language) {}

    /**
     * Сработавшие действующие правила. Правило про опросник, которого среди отправленных нет,
     * не проверяется.
     *
     * @param  Collection<int, Assessment>  $assessments
     * @return list<AlertMatch>
     */
    public function check(Collection $assessments): array
    {
        $assessments->loadMissing(['scale', 'answers']);
        $submission = new AlertSubmission($assessments);
        $matches = [];

        foreach (AlertRule::query()->active()->orderBy('code')->get() as $rule) {
            $references = $this->language->references($rule->condition);
            $scales = array_values(array_unique(array_column($references, 1)));

            if ($scales === [] || array_any($scales, fn (string $scale): bool => ! $submission->has($scale))) {
                continue;
            }

            if ($this->language->evaluateFor($rule->condition, $submission)) {
                $matches[] = new AlertMatch(
                    $rule,
                    $submission->assessment($scales[0]),
                    array_map(fn (array $reference): array => $this->evidence($reference, $submission), $references),
                );
            }
        }

        return $matches;
    }

    /**
     * @param  array{0: 'answer', 1: string, 2: int}|array{0: 'total', 1: string}  $reference
     * @return Evidence
     */
    private function evidence(array $reference, AlertSubmission $submission): array
    {
        $scale = $submission->assessment($reference[1])->scale;

        if ($reference[0] === 'total') {
            return [
                'type' => 'total',
                'scale' => $scale->code,
                'name' => $scale->name,
                'total' => $submission->total($scale->code),
                'max' => $scale->maxTotal(),
            ];
        }

        $score = $submission->answer($scale->code, $reference[2]);

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
}
