<?php

namespace App\Actions;

use App\Actions\Concerns\MarksDoses;
use App\Exceptions\DoseNotMarkable;
use App\Models\AsNeededDose;
use App\Models\User;
use InvalidArgumentException;

/**
 * Пациент удаляет запись о приёме «по необходимости» за сегодня или вчера.
 */
class CancelAsNeededDose
{
    use MarksDoses;

    /**
     * @throws DoseNotMarkable
     */
    public function handle(User $patient, AsNeededDose $dose): void
    {
        if ($dose->patient_id !== $patient->id) {
            throw new InvalidArgumentException('Пациент удаляет только свои записи.');
        }

        $this->ensureRecent($patient, $dose->local_date);

        $dose->delete();
    }
}
