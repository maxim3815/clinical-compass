<?php

namespace App\Actions;

use App\Enums\AccessLinkPurpose;
use App\Models\AccessLink;
use App\Models\User;
use App\Support\IssuedAccessLink;
use DateInterval;
use Illuminate\Support\Str;

/**
 * Выдаёт одноразовую ссылку от имени врача.
 */
class IssueAccessLink
{
    public const int TOKEN_LENGTH = 40;

    public function handle(
        User $doctor,
        AccessLinkPurpose $purpose,
        DateInterval $lifetime,
        ?User $patient = null,
        ?string $patientName = null,
    ): IssuedAccessLink {
        $token = Str::random(self::TOKEN_LENGTH);

        $link = AccessLink::query()->create([
            'purpose' => $purpose,
            'doctor_id' => $doctor->id,
            'patient_id' => $patient?->id,
            'token_hash' => AccessLink::hashToken($token),
            'patient_name' => $patientName,
            'expires_at' => now()->add($lifetime),
        ]);

        return new IssuedAccessLink($link, $token);
    }
}
