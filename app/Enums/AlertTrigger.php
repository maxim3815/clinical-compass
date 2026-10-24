<?php

namespace App\Enums;

/**
 * Что поднимает алерт правила: ответы опросника по условию правила или кнопка «Сообщить врачу»
 * на экране помощи пациента.
 */
enum AlertTrigger: string
{
    case Answers = 'answers';
    case HelpRequest = 'help_request';
}
