<?php

namespace App\Enums;

/**
 * Что поднимает алерт правила: ответы опросника по условию правила, кнопка «Сообщить врачу»
 * на экране помощи пациента или срочный побочный эффект, о котором сообщил пациент.
 */
enum AlertTrigger: string
{
    case Answers = 'answers';
    case HelpRequest = 'help_request';
    case AdverseEvent = 'adverse_event';
}
