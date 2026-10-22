<?php

namespace App\Support;

use App\Models\AccessLink;

/**
 * Только что выданный PIN. В открытом виде он есть только здесь, в БД лежит его HMAC.
 */
final readonly class IssuedAppointmentPin
{
    public function __construct(
        public AccessLink $link,
        public string $pin,
    ) {}
}
