<?php

namespace App\Actions;

use App\Enums\HelpEventType;
use App\Enums\UserRole;
use App\Models\User;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Запись тихой строки для врача: пациент открыл экран помощи или нажал на нём звонок.
 * Повторное открытие в течение config help.opened_minutes — тот же заход: перезагрузка
 * и возврат после «Сообщить врачу» новой строки не дают.
 */
class RecordHelpEvent
{
    public function handle(User $patient, HelpEventType $type): void
    {
        if ($patient->role !== UserRole::Patient) {
            throw new InvalidArgumentException('Экран помощи есть только у пациента.');
        }

        if ($type === HelpEventType::Opened && $patient->helpEvents()
            ->where('type', $type->value)
            ->where('created_at', '>', CarbonImmutable::now()->subMinutes((int) config('help.opened_minutes'))->format('Y-m-d H:i:s.v'))
            ->exists()) {
            return;
        }

        $patient->helpEvents()->create(['type' => $type]);
    }
}
