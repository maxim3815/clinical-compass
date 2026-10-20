<?php

namespace App\Notifications\Concerns;

use App\Models\DoseEvent;
use App\Support\DoseEventPresenter;
use Illuminate\Database\Eloquent\Collection;

/**
 * Общее для пушей о приёмах одного пациента на одно время: заголовок, препараты, метка.
 * Нужны загруженные prescription.drug, prescription.strength и patient.patientProfile.
 */
trait DescribesDoses
{
    /**
     * Время приёма по часовому поясу пациента: «8:00».
     *
     * @param  Collection<int, DoseEvent>  $doses
     */
    private function clock(Collection $doses): string
    {
        $dose = $doses->firstOrFail();

        return DoseEventPresenter::clock($dose->scheduled_at, $dose->patient->localTimezone());
    }

    /**
     * Препарат с дозой: «Авиандр, 1 таблетка, 20 мг».
     */
    private function drugLine(DoseEvent $dose): string
    {
        return $dose->prescription->drugName().', '.$dose->prescription->doseLabel($dose->tablets);
    }

    /**
     * Приёмы по названию препарата, как на экране «Сегодня».
     *
     * @param  Collection<int, DoseEvent>  $doses
     * @return Collection<int, DoseEvent>
     */
    private function sorted(Collection $doses): Collection
    {
        return $doses->sortBy(fn (DoseEvent $dose): string => $dose->prescription->drugName())->values();
    }

    /**
     * Метка пуша: новый пуш о тех же приёмах заменяет прежний, а не ложится рядом.
     * Она же — тема в сервисе пушей: недоставленный прежний пуш заменяется там же.
     *
     * @param  Collection<int, DoseEvent>  $doses
     */
    private function tag(Collection $doses): string
    {
        return 'dose-'.$doses->firstOrFail()->scheduled_at->getTimestamp();
    }

    /**
     * Куда ведёт нажатие: «Сегодня» с выделенными приёмами.
     *
     * @param  Collection<int, DoseEvent>  $doses
     */
    private function url(Collection $doses): string
    {
        return route('patient.home', ['doses' => implode(',', $doses->modelKeys())], absolute: false);
    }
}
