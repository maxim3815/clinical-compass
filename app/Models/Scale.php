<?php

namespace App\Models;

use App\Enums\DoctorKind;
use App\Enums\ScaleRater;
use App\Support\Assessments\AssessmentCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Шкала из database/data/scales/<код>.yaml: вопросы, варианты ответа с баллами, степени по сумме,
 * источник. Шкалу пациента (GAD-7, PHQ-9) он заполняет сам по календарю, шкалу врача (HARS, HADS)
 * врач заполняет на приёме: у неё нет нейтрального названия для пациента и календаря.
 *
 * Варианты ответа общие для шкалы или свои у каждого вопроса (HADS). У шкалы с подшкалами
 * (HADS, тревога и депрессия) сумма и степень считаются по каждой подшкале, общей степени нет.
 *
 * @phpstan-type ScaleOption array{score: int, label: string}
 * @phpstan-type ScaleBand array{min: int, max: int, label: string}
 * @phpstan-type Subscore array{code: string, total: int, band: string}
 *
 * @property int $id
 * @property string $code
 * @property string $name «GAD-7»
 * @property string $title Полное название для врача.
 * @property ScaleRater $rater
 * @property DoctorKind|null $doctor_kind Вид врача, который заполняет шкалу врача; пусто — любой.
 * @property string|null $patient_title Нейтральное название для пациента: «Беспокойство». У шкалы врача пусто.
 * @property string $instruction
 * @property string|null $prompt Строка над вопросами для того, кто отвечает на экране врача (HADS).
 * @property list<ScaleOption> $options Общие варианты; пусто, если они свои у каждого вопроса.
 * @property list<array{text: string, hint?: string|null, highlight?: bool, subscale?: string, options?: list<ScaleOption>}> $items
 * @property list<ScaleBand> $bands Степени по сумме; пусто у шкалы с подшкалами.
 * @property list<array{code: string, name: string, bands: list<ScaleBand>}>|null $subscales
 * @property list<int>|null $schedule_days
 * @property int|null $schedule_then_every
 * @property string $source_original
 * @property string $source_validation
 * @property string $source_url
 * @property string $source_license
 * @property string|null $source_note
 * @property string $version
 * @property int $position
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'code',
    'name',
    'title',
    'rater',
    'doctor_kind',
    'patient_title',
    'instruction',
    'prompt',
    'options',
    'items',
    'bands',
    'subscales',
    'schedule_days',
    'schedule_then_every',
    'source_original',
    'source_validation',
    'source_url',
    'source_license',
    'source_note',
    'version',
    'position',
    'is_active',
])]
class Scale extends Model
{
    public const string CODE_PATTERN = '[a-z][a-z0-9_]*';

    /**
     * Варианты ответа на вопрос (номер с 1): свои у вопроса или общие для шкалы.
     *
     * @return list<ScaleOption>
     */
    public function optionsFor(int $item): array
    {
        return $this->items[$item - 1]['options'] ?? $this->options;
    }

    /**
     * Наибольшая сумма: на каждый вопрос — вариант с наибольшим баллом. С подшкалой — только
     * по её вопросам.
     */
    public function maxTotal(?string $subscale = null): int
    {
        $max = 0;

        foreach ($this->items as $index => $item) {
            if ($subscale === null || ($item['subscale'] ?? null) === $subscale) {
                $max += max([0, ...array_column($this->optionsFor($index + 1), 'score')]);
            }
        }

        return $max;
    }

    public function hasSubscales(): bool
    {
        return ($this->subscales ?? []) !== [];
    }

    /**
     * Подшкала по коду: название и степени; null — такой подшкалы нет.
     *
     * @return array{code: string, name: string, bands: list<ScaleBand>}|null
     */
    public function subscale(string $code): ?array
    {
        foreach ($this->subscales ?? [] as $subscale) {
            if ($subscale['code'] === $code) {
                return $subscale;
            }
        }

        return null;
    }

    /**
     * Название подшкалы с шкалой: «HADS, тревога».
     */
    public function subscaleName(string $code): string
    {
        return "{$this->name}, ".($this->subscale($code)['name'] ?? $code);
    }

    /**
     * Сумма баллов по ответам на все вопросы.
     *
     * @param  list<int>  $scores  балл на каждый вопрос по порядку
     */
    public function total(array $scores): int
    {
        if (count($scores) !== count($this->items)) {
            throw new InvalidArgumentException("{$this->name}: нужен ответ на каждый из ".count($this->items).' вопросов.');
        }

        foreach ($scores as $index => $score) {
            if (! in_array($score, array_column($this->optionsFor($index + 1), 'score'), true)) {
                throw new InvalidArgumentException("{$this->name}: у вопроса ".($index + 1)." нет варианта ответа с баллом {$score}.");
            }
        }

        return array_sum($scores);
    }

    /**
     * Итог заполнения: сумма и степень, у шкалы с подшкалами — ещё сумма и степень каждой
     * подшкалы. Степень всей такой шкалы — степени подшкал через точку с запятой.
     *
     * @param  list<int>  $scores  балл на каждый вопрос по порядку
     * @return array{total: int, band: string, subscores: list<Subscore>|null}
     */
    public function score(array $scores): array
    {
        $total = $this->total($scores);

        if (! $this->hasSubscales()) {
            return ['total' => $total, 'band' => $this->band($total), 'subscores' => null];
        }

        $subscores = [];

        foreach ($this->subscales ?? [] as $subscale) {
            $sum = 0;

            foreach ($scores as $index => $score) {
                if (($this->items[$index]['subscale'] ?? null) === $subscale['code']) {
                    $sum += $score;
                }
            }

            $subscores[] = ['code' => $subscale['code'], 'total' => $sum, 'band' => self::bandIn($subscale['bands'], $sum, $this->subscaleName($subscale['code']))];
        }

        return [
            'total' => $total,
            'band' => implode('; ', array_map(fn (array $subscore): string => $subscore['band'], $subscores)),
            'subscores' => $subscores,
        ];
    }

    /**
     * Степень по сумме: «умеренная тревога». У шкалы с подшкалами общей степени нет.
     */
    public function band(int $total): string
    {
        return self::bandIn($this->bands, $total, $this->name);
    }

    /**
     * Подпись выбранного варианта ответа: «Почти каждый день». У HADS варианты свои у вопроса.
     */
    public function optionLabel(int $score, int $item = 1): string
    {
        foreach ($this->optionsFor($item) as $option) {
            if ($option['score'] === $score) {
                return $option['label'];
            }
        }

        return (string) $score;
    }

    public function calendar(): AssessmentCalendar
    {
        if ($this->schedule_days === null || $this->schedule_then_every === null) {
            throw new InvalidArgumentException("{$this->name}: у шкалы врача нет календаря.");
        }

        return new AssessmentCalendar($this->schedule_days, $this->schedule_then_every);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Шкалы, которые заполняет пациент.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function forPatients(Builder $query): void
    {
        $query->where('rater', ScaleRater::Patient);
    }

    /**
     * Шкалы, которые врач заполняет на приёме. С видом врача — только его шкалы: психиатр
     * заполняет HARS, терапевт — HADS.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function forDoctors(Builder $query, ?DoctorKind $kind = null): void
    {
        $query->where('rater', ScaleRater::Doctor)
            ->when($kind !== null, fn (Builder $query) => $query->where(fn (Builder $query) => $query->whereNull('doctor_kind')->orWhere('doctor_kind', $kind)));
    }

    /**
     * Этот вид врача заполняет шкалу на приёме.
     */
    public function isRatableBy(?DoctorKind $kind): bool
    {
        return $this->rater === ScaleRater::Doctor && $this->is_active && ($this->doctor_kind === null || $this->doctor_kind === $kind);
    }

    /**
     * @param  list<ScaleBand>  $bands
     */
    private static function bandIn(array $bands, int $total, string $name): string
    {
        foreach ($bands as $band) {
            if ($total >= $band['min'] && $total <= $band['max']) {
                return $band['label'];
            }
        }

        throw new InvalidArgumentException("{$name}: сумма {$total} вне степеней шкалы.");
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'options' => 'array',
            'items' => 'array',
            'bands' => 'array',
            'subscales' => 'array',
            'schedule_days' => 'array',
            'rater' => ScaleRater::class,
            'doctor_kind' => DoctorKind::class,
            'is_active' => 'boolean',
        ];
    }
}
