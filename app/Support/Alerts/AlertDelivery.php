<?php

namespace App\Support\Alerts;

use App\Events\AlertsChanged;
use App\Models\Alert;
use App\Notifications\UrgentAlert;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Доставка алерта лечащему врачу: событие в открытый кабинет и пуш. Доставка не повторяется:
 * если Reverb или сервис пушей недоступны, врач увидит алерт, когда откроет кабинет, а метка
 * шага останется пустой.
 *
 * Метки «событие отправлено» и «пуш отправлен» ставятся по первой доставке: по ним считается
 * время доставки. Повтор («Сообщить врачу» или новый срочный побочный эффект) доставляется так же,
 * но метки не трогает.
 */
final class AlertDelivery
{
    public function deliver(Alert $alert, bool $repeat = false): void
    {
        // Повтор получает тот, кто лечащий врач сейчас: при передаче пациента открытый алерт
        // переходит к новому врачу вместе с карточкой.
        $doctor = $repeat ? $alert->patient->activeCareRelationship?->doctor : $alert->doctor;

        if ($doctor === null) {
            return;
        }

        try {
            AlertsChanged::dispatch($doctor, true);

            if (! $repeat) {
                $alert->update(['broadcast_at' => CarbonImmutable::now()]);
            }
        } catch (Throwable $e) {
            report($e);
        }

        try {
            if ($doctor->pushSubscriptions()->exists()) {
                $doctor->notifyNow(new UrgentAlert($alert, $repeat));

                if (! $repeat) {
                    $alert->update(['pushed_at' => CarbonImmutable::now()]);
                }
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
