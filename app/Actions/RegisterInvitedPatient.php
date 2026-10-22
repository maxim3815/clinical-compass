<?php

namespace App\Actions;

use App\Enums\AccessLinkPurpose;
use App\Events\InvitationChanged;
use App\Exceptions\AccessLinkUnavailable;
use App\Models\AccessLink;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Регистрирует пациента по приглашению и привязывает его к пригласившему врачу.
 * Данные формы должны быть уже проверены.
 */
class RegisterInvitedPatient
{
    public function __construct(private readonly CreatePatient $createPatient) {}

    /**
     * @throws AccessLinkUnavailable
     */
    public function handle(
        string $token,
        string $name,
        string $login,
        string $password,
        ?DateTimeInterface $birthDate = null,
    ): User {
        return DB::transaction(function () use ($token, $name, $login, $password, $birthDate): User {
            // Блокировка строки: второй одновременный запрос по той же ссылке дождётся
            // окончания первого и увидит ссылку уже использованной.
            $invitation = AccessLink::query()
                ->where('purpose', AccessLinkPurpose::Invitation)
                ->where('token_hash', AccessLink::hashToken($token))
                ->lockForUpdate()
                ->first();

            if ($invitation === null || ! $invitation->isActive()) {
                throw new AccessLinkUnavailable;
            }

            $patient = $this->createPatient->handle(
                doctor: $invitation->doctor,
                name: $name,
                login: $login,
                password: $password,
                birthDate: $birthDate,
                consentedAt: now(),
            );

            $invitation->update([
                'patient_id' => $patient->id,
                'used_at' => now(),
            ]);

            InvitationChanged::dispatch($invitation);

            return $patient;
        });
    }
}
