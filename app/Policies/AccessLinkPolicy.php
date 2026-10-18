<?php

namespace App\Policies;

use App\Models\AccessLink;
use App\Models\User;

class AccessLinkPolicy
{
    /**
     * Отозвать можно только свою ссылку.
     */
    public function revoke(User $user, AccessLink $link): bool
    {
        return $link->doctor_id === $user->id;
    }
}
