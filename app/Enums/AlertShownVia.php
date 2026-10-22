<?php

namespace App\Enums;

/**
 * Как алерт появился на экране врача: событием в открытом кабинете или когда врач открыл
 * страницу кабинета (из пуша или сам).
 */
enum AlertShownVia: string
{
    case Realtime = 'realtime';
    case Page = 'page';
}
