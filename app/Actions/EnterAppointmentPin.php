<?php

namespace App\Actions;

use App\Events\InvitationChanged;
use App\Exceptions\AccessLinkUnavailable;
use App\Models\AccessLink;
use App\Models\User;
use App\Support\IssuedAccessLink;
use DateInterval;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Пациент ввёл PIN врача. Верный PIN сгорает, а запись становится обычным приглашением
 * с новой ссылкой на регистрацию.
 */
class EnterAppointmentPin
{
    /**
     * @throws AccessLinkUnavailable
     */
    public function handle(User $doctor, string $pin): IssuedAccessLink
    {
        return DB::transaction(function () use ($doctor, $pin): IssuedAccessLink {
            // Блокировка строки: два одновременных ввода одного PIN дадут одну ссылку.
            $invitation = AccessLink::query()
                ->awaitingPin()
                ->where('doctor_id', $doctor->id)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($invitation === null || ! hash_equals((string) $invitation->pin_hash, AccessLink::hashPin($doctor->id, $pin))) {
                throw new AccessLinkUnavailable;
            }

            $token = Str::random(IssueAccessLink::TOKEN_LENGTH);

            $invitation->update([
                'token_hash' => AccessLink::hashToken($token),
                'pin_entered_at' => now(),
                // Дальше как у обычного приглашения: форму можно дозаполнить позже.
                'expires_at' => now()->add(new DateInterval(InvitePatient::LIFETIME)),
            ]);

            InvitationChanged::dispatch($invitation);

            return new IssuedAccessLink($invitation, $token);
        });
    }
}
