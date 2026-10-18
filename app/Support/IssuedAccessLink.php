<?php

namespace App\Support;

use App\Models\AccessLink;

/**
 * Только что выданная ссылка. Токен в открытом виде есть только здесь, в БД лежит его хэш.
 */
final readonly class IssuedAccessLink
{
    public function __construct(
        public AccessLink $link,
        public string $token,
    ) {}
}
