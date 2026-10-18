<?php

namespace App\Actions;

use App\Enums\AccessLinkPurpose;
use App\Enums\UserRole;
use App\Models\User;
use App\Support\IssuedAccessLink;
use DateInterval;
use InvalidArgumentException;

/**
 * Приглашение пациента: ссылка на регистрацию, которая привяжет пациента к врачу.
 */
class InvitePatient
{
    public const string LIFETIME = 'P7D';

    public function __construct(private readonly IssueAccessLink $issueAccessLink) {}

    public function handle(User $doctor, ?string $patientName = null): IssuedAccessLink
    {
        if ($doctor->role !== UserRole::Doctor) {
            throw new InvalidArgumentException('Пригласить пациента может только врач.');
        }

        return $this->issueAccessLink->handle(
            doctor: $doctor,
            purpose: AccessLinkPurpose::Invitation,
            lifetime: new DateInterval(self::LIFETIME),
            patientName: $patientName,
        );
    }
}
