<?php

namespace Database\Seeders\Demo;

use App\Enums\PrescriptionCancelReason;
use App\Enums\TimeOfDay;
use Closure;

/**
 * Курс препарата демо-пациента. День 1 — дата начала курса, $today — какой день курса идёт
 * сегодня, в день запуска сидера.
 */
final readonly class DemoCourse
{
    /**
     * @param  string  $drug  код препарата в справочнике
     * @param  array<string, string>  $times  значение TimeOfDay => местное время «08:00»; у «по необходимости» пусто
     * @param  (Closure(int, TimeOfDay): DemoMark)|null  $marks  отметка регулярного приёма по дню курса
     * @param  array<int, list<string>>  $asNeeded  день курса => время приёмов «по необходимости»
     * @param  string|null  $prescribedBy  кто назначил свой препарат пациента; null — назначение врача в приложении
     */
    private function __construct(
        public string $drug,
        public int $mg,
        public int $today,
        public array $times,
        public ?Closure $marks,
        public array $asNeeded = [],
        public ?string $prescribedBy = null,
        public ?int $cancelledOn = null,
        public ?PrescriptionCancelReason $cancelReason = null,
        public ?string $cancelComment = null,
    ) {}

    /**
     * Регулярное назначение врача. Время приёма пациент выбирает вечером накануне начала курса.
     *
     * @param  array<string, string>  $times
     * @param  Closure(int, TimeOfDay): DemoMark  $marks
     */
    public static function prescribed(string $drug, int $mg, int $today, array $times, Closure $marks): self
    {
        return new self($drug, $mg, $today, $times, $marks);
    }

    /**
     * Регулярный приём препарата, который пациент добавил сам.
     *
     * @param  array<string, string>  $times
     * @param  Closure(int, TimeOfDay): DemoMark  $marks
     */
    public static function own(string $drug, int $mg, int $today, array $times, Closure $marks, string $prescribedBy): self
    {
        return new self($drug, $mg, $today, $times, $marks, prescribedBy: $prescribedBy);
    }

    /**
     * Препарат «по необходимости», который пациент добавил сам, без лимита в сутки.
     *
     * @param  array<int, list<string>>  $doses
     */
    public static function ownAsNeeded(string $drug, int $mg, int $today, array $doses, string $prescribedBy): self
    {
        return new self($drug, $mg, $today, [], null, $doses, $prescribedBy);
    }

    /**
     * Врач отменил назначение вечером в этот день курса.
     */
    public function cancelled(int $day, PrescriptionCancelReason $reason, string $comment): self
    {
        return new self(
            $this->drug,
            $this->mg,
            $this->today,
            $this->times,
            $this->marks,
            $this->asNeeded,
            $this->prescribedBy,
            $day,
            $reason,
            $comment,
        );
    }

    /**
     * Последний день курса, в который были приёмы: день отмены или сегодня.
     */
    public function lastDay(): int
    {
        return $this->cancelledOn ?? $this->today;
    }
}
