<?php

namespace App\Actions;

use App\Enums\AccessLinkPurpose;
use App\Models\AccessLink;
use App\Models\User;
use App\Support\IssuedAccessLink;
use DateInterval;
use Illuminate\Support\Facades\DB;

/**
 * Ссылка, по которой пациент сам задаёт новый пароль. Действующей остаётся только
 * последняя выданная ссылка. Право врача на пациента проверяет вызывающий.
 */
class IssuePasswordResetLink
{
    public const string LIFETIME = 'PT24H';

    public function __construct(private readonly IssueAccessLink $issueAccessLink) {}

    public function handle(User $doctor, User $patient): IssuedAccessLink
    {
        return DB::transaction(function () use ($doctor, $patient): IssuedAccessLink {
            AccessLink::query()
                ->where('purpose', AccessLinkPurpose::PasswordReset)
                ->where('patient_id', $patient->id)
                ->whereNull('used_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            return $this->issueAccessLink->handle(
                doctor: $doctor,
                purpose: AccessLinkPurpose::PasswordReset,
                lifetime: new DateInterval(self::LIFETIME),
                patient: $patient,
            );
        });
    }
}
