<?php

namespace App\Actions\Concerns;

use App\Exceptions\DoseNotMarkable;
use App\Models\DoseEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

/**
 * Общее для отметок приёма: блокировки и правила, когда приём можно отметить.
 */
trait MarksDoses
{
    /**
     * Блокирует строку пациента: в том же порядке, что синхронизация плановых приёмов
     * и изменение назначений, поэтому отметка не пересекается с пересчётом приёмов.
     */
    private function lockPatient(User $patient): User
    {
        $patient = User::query()->whereKey($patient->id)->lockForUpdate()->firstOrFail();
        $patient->load('patientProfile');

        return $patient;
    }

    /**
     * Блокирует приёмы пациента после блокировки самого пациента.
     *
     * @param  iterable<DoseEvent>  $doses
     * @return Collection<int, DoseEvent>
     */
    private function lockDoses(User $patient, iterable $doses): Collection
    {
        $ids = [];

        foreach ($doses as $dose) {
            if ($dose->patient_id !== $patient->id) {
                throw new InvalidArgumentException('Пациент отмечает только свои приёмы.');
            }

            $ids[] = $dose->id;
        }

        $locked = DoseEvent::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();

        // Приём могли удалить при пересчёте, пока пациент смотрел на страницу.
        if ($locked->count() !== count(array_unique($ids))) {
            throw DoseNotMarkable::gone();
        }

        return $locked;
    }

    /**
     * Отмечать, исправлять и отменять отметки можно за сегодня и вчера по местной дате пациента.
     *
     * @throws DoseNotMarkable
     */
    private function ensureRecent(User $patient, CarbonImmutable $localDate): void
    {
        $today = $patient->localToday();

        if (! in_array($localDate->toDateString(), [$today->toDateString(), $today->subDay()->toDateString()], true)) {
            throw DoseNotMarkable::outOfRange();
        }
    }

    /**
     * Приём за сегодня или вчера, и его время уже подошло.
     *
     * @throws DoseNotMarkable
     */
    private function ensureDue(User $patient, DoseEvent $dose, CarbonImmutable $now): void
    {
        $this->ensureRecent($patient, $dose->local_date);

        if ($now->lt($dose->dueFrom())) {
            throw DoseNotMarkable::notDue();
        }
    }
}
