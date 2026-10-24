<?php

namespace App\Models;

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
 * источник. Шкалу пациента (GAD-7, PHQ-9) он заполняет сам по календарю, шкалу врача (HARS)
 * врач заполняет на приёме: у неё нет нейтрального названия для пациента и календаря.
 *
 * @property int $id
 * @property string $code
 * @property string $name «GAD-7»
 * @property string $title Полное название для врача.
 * @property ScaleRater $rater
 * @property string|null $patient_title Нейтральное название для пациента: «Беспокойство». У шкалы врача пусто.
 * @property string $instruction
 * @property list<array{score: int, label: string}> $options
 * @property list<array{text: string, hint?: string|null, highlight?: bool}> $items
 * @property list<array{min: int, max: int, label: string}> $bands
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
    'patient_title',
    'instruction',
    'options',
    'items',
    'bands',
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
     * Наибольшая сумма: на каждый вопрос — вариант с наибольшим баллом.
     */
    public function maxTotal(): int
    {
        return count($this->items) * max([0, ...array_column($this->options, 'score')]);
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

        $allowed = array_column($this->options, 'score');

        foreach ($scores as $score) {
            if (! in_array($score, $allowed, true)) {
                throw new InvalidArgumentException("{$this->name}: балла {$score} нет среди вариантов ответа.");
            }
        }

        return array_sum($scores);
    }

    /**
     * Степень по сумме: «умеренная тревога».
     */
    public function band(int $total): string
    {
        foreach ($this->bands as $band) {
            if ($total >= $band['min'] && $total <= $band['max']) {
                return $band['label'];
            }
        }

        throw new InvalidArgumentException("{$this->name}: сумма {$total} вне степеней шкалы.");
    }

    /**
     * Подпись выбранного варианта ответа: «Почти каждый день».
     */
    public function optionLabel(int $score): string
    {
        foreach ($this->options as $option) {
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
     * Шкалы, которые врач заполняет на приёме.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function forDoctors(Builder $query): void
    {
        $query->where('rater', ScaleRater::Doctor);
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
            'schedule_days' => 'array',
            'rater' => ScaleRater::class,
            'is_active' => 'boolean',
        ];
    }
}
