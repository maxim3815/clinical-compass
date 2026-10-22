<?php

namespace Database\Seeders\Demo;

use App\Models\Scale;
use InvalidArgumentException;

/**
 * Демо-ответы на опросники: сумма на сроке календаря из значений профиля и раскладка суммы
 * по вопросам.
 */
final class DemoAnswers
{
    /**
     * Насколько охотно каждый вопрос получает баллы: средние по пунктам русскоязычных версий
     * (Золотарева, 2023, таблицы 1 статей о GAD-7 и PHQ-9). Отмеченный вопрос (9-й PHQ-9,
     * мысли о смерти) в демо-данных всегда 0, иначе у демо-пациентов появятся алерты.
     */
    private const array WEIGHTS = [
        'gad7' => [1.15, 0.78, 0.95, 0.86, 0.73, 0.96, 0.75],
        'phq9' => [0.98, 0.86, 1.16, 1.50, 0.87, 0.85, 0.91, 0.43, 0.34],
    ];

    /**
     * Сумма на дне календаря: значение профиля на этом дне, между значениями — по прямой,
     * до первого и после последнего — ближайшее значение [медик].
     *
     * @param  array<int, int>  $anchors  день календаря => сумма из профиля
     */
    public static function total(array $anchors, int $day): int
    {
        ksort($anchors);
        $days = array_keys($anchors);
        $first = array_key_first($anchors) ?? throw new InvalidArgumentException('Нет ни одного значения профиля.');

        if ($day <= $first) {
            return $anchors[$first];
        }

        foreach ($days as $index => $from) {
            $to = $days[$index + 1] ?? null;

            if ($to === null) {
                return $anchors[$from];
            }

            if ($day <= $to) {
                return (int) round($anchors[$from] + ($anchors[$to] - $anchors[$from]) * ($day - $from) / ($to - $from));
            }
        }

        return $anchors[array_key_last($anchors)];
    }

    /**
     * Баллы по вопросам с заданной суммой: по одному баллу тому вопросу, у которого вес
     * на уже полученный балл больше (метод д'Ондта), не больше наибольшего балла варианта.
     *
     * @return list<int>
     */
    public static function split(Scale $scale, int $total): array
    {
        $weights = self::WEIGHTS[$scale->code] ?? array_fill(0, count($scale->items), 1.0);
        $max = max([0, ...array_column($scale->options, 'score')]);

        foreach ($scale->items as $index => $item) {
            if ($item['highlight'] ?? false) {
                $weights[$index] = 0.0;
            }
        }

        $scores = array_fill(0, count($scale->items), 0);

        for ($left = $total; $left > 0; $left--) {
            $best = null;

            foreach ($weights as $index => $weight) {
                if ($weight > 0 && $scores[$index] < $max && ($best === null || $weight / ($scores[$index] + 1) > $weights[$best] / ($scores[$best] + 1))) {
                    $best = $index;
                }
            }

            if ($best === null) {
                throw new InvalidArgumentException("{$scale->name}: сумму {$total} не разложить по вопросам.");
            }

            $scores[$best]++;
        }

        return array_values($scores);
    }
}
