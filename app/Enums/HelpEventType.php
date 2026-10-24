<?php

namespace App\Enums;

/**
 * Что пациент сделал на экране помощи без «Сообщить врачу». Врач видит это тихой строкой
 * в карточке.
 */
enum HelpEventType: string
{
    case Opened = 'opened';
    case Call112 = 'call_112';
    case CallCrisisLine = 'call_crisis_line';

    /**
     * Часть строки для врача, без рода пациента. Нажатие на звонок ещё не значит, что разговор
     * состоялся: звонит телефон пациента.
     */
    public function label(): string
    {
        return match ($this) {
            self::Opened => 'открыт экран помощи',
            self::Call112 => 'нажата кнопка «Позвонить 112»',
            self::CallCrisisLine => 'нажата кнопка кризисной линии',
        };
    }
}
