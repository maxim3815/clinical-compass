<?php

namespace App\Actions;

use App\Enums\AccessLinkPurpose;
use App\Exceptions\AccessLinkUnavailable;
use App\Models\AccessLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Пациент задаёт новый пароль по ссылке от врача. Пароль должен быть уже проверен.
 */
class ResetPasswordByLink
{
    public function __construct(private readonly SetNewPassword $setNewPassword) {}

    /**
     * @throws AccessLinkUnavailable
     */
    public function handle(string $token, string $password): User
    {
        return DB::transaction(function () use ($token, $password): User {
            // Блокировка строки, как у приглашения: ссылка срабатывает ровно один раз.
            $link = AccessLink::query()
                ->where('purpose', AccessLinkPurpose::PasswordReset)
                ->where('token_hash', AccessLink::hashToken($token))
                ->lockForUpdate()
                ->first();

            $patient = $link?->patient;

            if ($link === null || $patient === null || ! $link->isActive()) {
                throw new AccessLinkUnavailable;
            }

            $this->setNewPassword->handle($patient, $password);
            $link->update(['used_at' => now()]);

            return $patient;
        });
    }
}
