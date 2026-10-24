<?php

namespace App\Support;

use App\Enums\DoseEventStatus;
use App\Models\DoseEvent;
use Carbon\CarbonImmutable;

/**
 * Плановый приём для экрана «Сегодня». Нужны загруженные prescription.drug и prescription.strength.
 * Время показывается по часовому поясу пациента. Тексты статуса и момента берёт и блок
 * «Приём препаратов» у врача (App\Support\Adherence\PatientAdherence).
 */
final class DoseEventPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(DoseEvent $event, string $timezone, CarbonImmutable $today, CarbonImmutable $now, bool $female = false): array
    {
        $prescription = $event->prescription;

        return [
            'id' => $event->id,
            'drug' => $prescription->drugName(),
            'dose' => $prescription->doseLabel($event->tablets),
            'instruction' => $prescription->instruction,
            'day' => $event->local_date->eq($today) ? 'сегодня' : 'вчера',
            'time' => self::clock($event->scheduled_at, $timezone),
            'status' => $event->status->value,
            'status_text' => self::statusText($event, $timezone, $female, late: false),
            'taken_late' => $event->isTakenLate(),
            'skip_comment' => $event->skip_comment,
            // Отметка после закрытия окна: «20.10 в 9:05». Если приём отмечен «Принял» в момент
            // приёма, время отметки повторяло бы время приёма.
            'marked_late' => $event->isMarkedAfterWindow() && $event->marked_at !== null
                && ($event->taken_at === null || ! $event->marked_at->eq($event->taken_at))
                ? self::moment($event->marked_at, $event->local_date, $timezone)
                : null,
            // У «другого» препарата пациента справочника нет, и текста тоже.
            'missed_dose' => $event->status === DoseEventStatus::Missed ? $prescription->drug?->missed_dose : null,
            'due' => $now->gte($event->dueFrom()),
            'due_from' => self::clock($event->dueFrom(), $timezone),
            // Начальные значения формы отметки.
            'taken_time' => ($event->taken_at ?? $event->scheduled_at)->setTimezone($timezone)->format('H:i'),
            'skip_reason' => $event->skip_reason?->value,
        ];
    }

    /**
     * Местное время без ведущего нуля: «8:05».
     */
    public static function clock(CarbonImmutable $time, string $timezone): string
    {
        return $time->setTimezone($timezone)->format('G:i');
    }

    /**
     * «Принят в 8:05», «Принят поздно, в 10:30», «Не принят: забыл», «Без отметки». У запланированного — null.
     * Причина у пациентки в женском роде: «Не принят: забыла». Пациенту «поздно» не пишем ($late = false):
     * время приёма видно и так, а оценка звучит упрёком.
     */
    public static function statusText(DoseEvent $event, string $timezone, bool $female = false, bool $late = true): ?string
    {
        return match ($event->status) {
            DoseEventStatus::Taken => $event->taken_at === null ? null
                : ($late && $event->isTakenLate() ? 'Принят поздно, ' : 'Принят ').self::moment($event->taken_at, $event->local_date, $timezone),
            DoseEventStatus::Skipped => 'Не принят: '.mb_lcfirst((string) $event->skip_reason?->label($female)),
            DoseEventStatus::Missed => DoseEventStatus::Missed->label(),
            DoseEventStatus::Scheduled => null,
        };
    }

    /**
     * Момент относительно дня приёма: «в 8:05», а если это другой день — «20.10 в 0:30».
     */
    public static function moment(CarbonImmutable $time, CarbonImmutable $localDate, string $timezone): string
    {
        $local = $time->setTimezone($timezone);
        $clock = 'в '.$local->format('G:i');

        return $local->toDateString() === $localDate->toDateString() ? $clock : $local->format('d.m').' '.$clock;
    }
}
