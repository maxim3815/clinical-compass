<?php

namespace App\Actions;

use App\Enums\AccessLinkPurpose;
use App\Enums\UserRole;
use App\Models\AccessLink;
use App\Models\QueueAnswer;
use App\Models\User;
use App\Support\IssuedAppointmentPin;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * PIN для приглашения на приёме. У врача действует один PIN: новый гасит прежний.
 *
 * PIN со страницы ответов из очереди привязан к ним: после регистрации ответы и HARS переходят
 * этому пациенту, с какого бы телефона он ни регистрировался.
 */
class IssueAppointmentPin
{
    public const int LIFETIME_MINUTES = 15;

    public function handle(User $doctor, ?QueueAnswer $queueAnswer = null): IssuedAppointmentPin
    {
        if ($doctor->role !== UserRole::Doctor) {
            throw new InvalidArgumentException('Пригласить пациента может только врач.');
        }

        if ($queueAnswer !== null && ($queueAnswer->doctor_id !== $doctor->id || ! $queueAnswer->isPending())) {
            throw new InvalidArgumentException('PIN привязывают только к своим непривязанным ответам из очереди.');
        }

        return DB::transaction(function () use ($doctor, $queueAnswer): IssuedAppointmentPin {
            // Два нажатия подряд не оставят двух действующих PIN: второе ждёт первое.
            User::query()->whereKey($doctor->id)->lockForUpdate()->first();

            AccessLink::query()
                ->awaitingPin()
                ->where('doctor_id', $doctor->id)
                ->update(['revoked_at' => now()]);

            $pin = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);

            $link = AccessLink::query()->create([
                'purpose' => AccessLinkPurpose::Invitation,
                'doctor_id' => $doctor->id,
                'pin_hash' => AccessLink::hashPin($doctor->id, $pin),
                'queue_answer_id' => $queueAnswer?->id,
                'expires_at' => now()->addMinutes(self::LIFETIME_MINUTES),
            ]);

            return new IssuedAppointmentPin($link, $pin);
        });
    }
}
